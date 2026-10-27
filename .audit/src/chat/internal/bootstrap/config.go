package bootstrap

import (
	"encoding/json"
	"io"
	"log/slog"
	"net"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/avpipeline"
	"github.com/franz-9527/workers/chat/internal/conversation"
	"github.com/franz-9527/workers/chat/internal/dependencies"
	"github.com/franz-9527/workers/chat/internal/imagepipeline"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/moderation"
	"github.com/franz-9527/workers/chat/internal/socket"
)

const maxConfigBytes = 64 << 10

// Config contains implemented lifecycle and dependency settings only.
type Config struct {
	Calls       dependencies.CallsConfig      `json:"calls"`
	AV          avpipeline.Config             `json:"av"`
	Release     ReleaseConfig                 `json:"release"`
	Reminders   dependencies.RemindersConfig  `json:"reminders"`
	Operations  dependencies.OperationsConfig `json:"operations"`
	Retention   message.RetentionConfig       `json:"retention"`
	Environment string                        `json:"env"`
	HTTP        struct {
		Listen         string `json:"listen"`
		InternalListen string `json:"internal_listen"`
	} `json:"http"`
	ShutdownTimeout string                `json:"shutdown_timeout"`
	LogLevel        string                `json:"log_level"`
	ProviderMode    string                `json:"provider_mode"`
	Dependencies    dependencies.Settings `json:"dependencies"`
	Auth            auth.Config           `json:"auth"`
	Conversations   conversation.Config   `json:"conversations"`
	Images          imagepipeline.Config  `json:"images"`
	Messages        message.Config        `json:"messages"`
	Safety          moderation.Config     `json:"safety"`
	History         message.HistoryConfig `json:"history"`
	Socket          socket.Config         `json:"socket"`
}

// Fault deliberately never wraps input, filenames, decoder or network errors.
// CLI diagnostics must not echo malformed config values or credentials.
type Fault struct {
	Code    string
	Message string
	Exit    int
}

func (f *Fault) Error() string { return f.Message }

func configFault(code, message string) *Fault {
	return &Fault{Code: code, Message: message, Exit: 2}
}

func LoadConfig(path string) (Config, *Fault) {
	cfg := Config{Retention: message.DefaultRetentionConfig(), ShutdownTimeout: "10s", LogLevel: "info", ProviderMode: "disabled", Dependencies: dependencies.Defaults()}
	info, err := os.Stat(path)
	if err != nil {
		return cfg, configFault("CHAT_CONFIG_READ_FAILED", "无法读取配置文件")
	}
	// Reject devices/FIFOs before opening: opening a FIFO can otherwise block
	// check-config indefinitely. Deployment config paths are operator-controlled.
	if !info.Mode().IsRegular() || info.Size() > maxConfigBytes {
		return cfg, configFault("CHAT_CONFIG_INVALID", "配置必须是最多64KiB的普通JSON文件")
	}
	f, err := os.Open(path)
	if err != nil {
		return cfg, configFault("CHAT_CONFIG_READ_FAILED", "无法读取配置文件")
	}
	defer f.Close()
	info, err = f.Stat()
	if err != nil || !info.Mode().IsRegular() || info.Size() > maxConfigBytes {
		return cfg, configFault("CHAT_CONFIG_INVALID", "配置必须是最多64KiB的普通JSON文件")
	}
	data, err := io.ReadAll(io.LimitReader(f, maxConfigBytes+1))
	if err != nil || len(data) > maxConfigBytes {
		return cfg, configFault("CHAT_CONFIG_INVALID", "配置读取失败或超过大小上限")
	}
	// encoding/json accepts duplicate keys by default. Reject them before typed
	// decoding so a second security setting cannot silently override the first.
	preflight := json.NewDecoder(strings.NewReader(string(data)))
	if !uniqueJSON(preflight, 0) {
		return cfg, configFault("CHAT_CONFIG_INVALID", "JSON格式错误、重复字段或嵌套过深")
	}
	if _, err := preflight.Token(); err != io.EOF {
		return cfg, configFault("CHAT_CONFIG_INVALID", "配置只能包含一个JSON对象")
	}
	decoder := json.NewDecoder(strings.NewReader(string(data)))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&cfg); err != nil {
		return cfg, configFault("CHAT_CONFIG_INVALID", "JSON字段、类型或结构不符合当前配置")
	}
	if fault := cfg.validate(); fault != nil {
		return cfg, fault
	}
	return cfg, nil
}

func uniqueJSON(d *json.Decoder, depth int) bool {
	if depth > 16 {
		return false
	}
	token, err := d.Token()
	if err != nil || token == nil {
		return false
	}
	delimiter, compound := token.(json.Delim)
	if !compound {
		return true
	}
	if delimiter != '{' && delimiter != '[' {
		return false
	}
	keys := make(map[string]bool)
	for d.More() {
		if delimiter == '{' {
			key, err := d.Token()
			name, ok := key.(string)
			if err != nil || !ok || name != strings.ToLower(name) || keys[name] {
				return false
			}
			keys[name] = true
		}
		if !uniqueJSON(d, depth+1) {
			return false
		}
	}
	end, err := d.Token()
	return err == nil && ((delimiter == '{' && end == json.Delim('}')) ||
		(delimiter == '[' && end == json.Delim(']')))
}

func (c Config) validate() *Fault {
	if c.Environment != "development" && c.Environment != "test" && c.Environment != "production" {
		return configFault("CHAT_CONFIG_ENV_INVALID", "env须为development、test或production")
	}
	if !validLoopback(c.HTTP.Listen, c.Environment) || !validLoopback(c.HTTP.InternalListen, c.Environment) {
		return configFault("CHAT_CONFIG_LISTEN_INVALID", "HTTP与内部端口必须使用回环IP及合法端口")
	}
	_, port, _ := net.SplitHostPort(c.HTTP.Listen)
	if c.HTTP.Listen == c.HTTP.InternalListen && port != "0" {
		return configFault("CHAT_CONFIG_LISTEN_CONFLICT", "HTTP与内部监听地址不能相同")
	}
	duration, err := time.ParseDuration(c.ShutdownTimeout)
	if err != nil || duration < time.Second || duration > 30*time.Second {
		return configFault("CHAT_CONFIG_TIMEOUT_INVALID", "shutdown_timeout须在1秒至30秒之间")
	}
	if _, ok := logLevel(c.LogLevel); !ok {
		return configFault("CHAT_CONFIG_LOG_INVALID", "log_level须为debug、info、warn或error")
	}
	if c.ProviderMode != "disabled" && c.ProviderMode != "fake" {
		return configFault("CHAT_PROVIDER_NOT_IMPLEMENTED", "真实业务提供者尚未实现")
	}
	if c.ProviderMode == "fake" && c.Environment == "production" {
		return configFault("CHAT_CONFIG_FAKE_FORBIDDEN", "生产环境禁止测试提供者")
	}
	if c.Dependencies.Validate(c.Environment) != nil {
		return configFault("CHAT_CONFIG_DEPENDENCY_INVALID", "依赖配置、环境隔离或资源预算不符合要求")
	}
	if c.Auth.Enabled {
		if !c.Dependencies.MySQL.Enabled || c.ProviderMode != "disabled" {
			return configFault("CHAT_CONFIG_AUTH_INVALID", "真实身份需要独立MySQL权限且不能混用测试身份")
		}
		if _, err := c.Auth.Verifier(c.Environment); err != nil {
			return configFault("CHAT_CONFIG_AUTH_INVALID", "身份公钥配置不可用")
		}
	}
	if c.Conversations.Enabled && (!c.Auth.Enabled || !c.Dependencies.MySQL.Enabled || c.ProviderMode != "disabled") {
		return configFault("CHAT_CONFIG_CONVERSATIONS_INVALID", "会话投影需要真实身份和独立MySQL权限")
	}
	if c.Safety.Validate() != nil || c.Safety.Enabled && (!c.Conversations.Enabled || !c.Auth.Enabled || c.ProviderMode != "disabled" || c.Dependencies.Timeout() < 100*time.Millisecond) {
		return configFault("CHAT_CONFIG_SAFETY_INVALID", "真实审核需要真实身份、会话、独立数据库和可信凭证文件")
	}
	if _, e := c.History.Key(); e != nil || c.History.Enabled && (!c.Conversations.Enabled || !c.Auth.Enabled || c.ProviderMode != "disabled" || c.Dependencies.Timeout() < 100*time.Millisecond) {
		return configFault("CHAT_CONFIG_HISTORY_INVALID", "历史读取需要真实身份、会话、独立数据库和游标签名密钥")
	}
	if !c.Operations.Validate(c.Environment == "production" && (c.Auth.Enabled || c.Conversations.Enabled || c.History.Enabled || c.Socket.Enabled || c.Retention.Enabled)) {
		return configFault("CHAT_OPERATIONS_INVALID", "生产受保护动作需要独立恢复门禁与代次")
	}
	if c.Retention.Validate() != nil || c.Retention.Enabled && (!c.Dependencies.MySQL.Enabled || c.ProviderMode != "disabled") {
		return configFault("CHAT_RETENTION_INVALID", "留存配置或数据库边界不符合要求")
	}
	if c.Calls.Validate() != nil || c.Calls.Enabled && (!c.Messages.Enabled || !c.Dependencies.Redis.Enabled) {
		return configFault("CHAT_CALLS_INVALID", "通话配置或组合依赖不完整")
	}
	if c.Calls.Enabled && !(c.Environment == "test" && c.Release.Staging) && !c.Release.Verify(c.Environment, c.Operations.Epoch, sourceRevision, sourceDirty, "calls") {
		return configFault("CHAT_CALLS_NOT_READY", "通话联合验收未完成")
	}
	if c.AV.Validate() != nil || c.AV.Enabled && (!c.Messages.Enabled || !c.Retention.Enabled) {
		return configFault("CHAT_AV_NOT_READY", "音视频配置或组合依赖不完整")
	}
	if c.AV.Enabled && !(c.Environment == "test" && c.Release.Staging) && (c.AV.Audio && !c.Release.Verify(c.Environment, c.Operations.Epoch, sourceRevision, sourceDirty, "audio") || c.AV.Video && !c.Release.Verify(c.Environment, c.Operations.Epoch, sourceRevision, sourceDirty, "video")) {
		return configFault("CHAT_AV_NOT_READY", "音视频联合验收未完成")
	}
	if c.Images.Enabled && !c.Messages.Enabled {
		return configFault("CHAT_IMAGES_NOT_READY", "图片必须依附完整消息用例")
	}
	if (c.Reminders.Enabled || c.Reminders.PaidSMSEnabled) && !c.Messages.Enabled {
		return configFault("CHAT_MESSAGES_NOT_READY", "提醒必须依附完整消息用例")
	}
	if c.Release.Staging && c.Environment != "test" {
		return configFault("CHAT_STAGING_FORBIDDEN", "隔离验收组合仅允许独立test环境")
	}
	if c.Messages.Enabled {
		staged := c.Environment == "test" && c.Release.Staging && c.Operations.Validate(true)
		if !staged && !c.Release.Verify(c.Environment, c.Operations.Epoch, sourceRevision, sourceDirty, "text", "governance") {
			return configFault("CHAT_MESSAGES_NOT_READY", "缺少本提交已授权的联合验收凭证")
		}
		if c.Images.Enabled && !staged && !c.Release.Verify(c.Environment, c.Operations.Epoch, sourceRevision, sourceDirty, "images") {
			return configFault("CHAT_IMAGES_NOT_READY", "图片联合验收未完成")
		}
		if c.Reminders.PaidSMSEnabled && !staged && !c.Release.Verify(c.Environment,c.Operations.Epoch,sourceRevision,sourceDirty,"paid_sms") { return configFault("CHAT_SMS_NOT_READY","付费短信联合验收未完成") }
		if c.Reminders.Enabled && !staged && !c.Release.Verify(c.Environment, c.Operations.Epoch, sourceRevision, sourceDirty, "reminders") {
			return configFault("CHAT_REMINDERS_NOT_READY", "实际提醒渠道联合验收未完成")
		}
		if !c.Auth.Enabled || !c.Conversations.Enabled || !c.Safety.Enabled || !c.History.Enabled || !c.Dependencies.MySQL.Enabled || c.ProviderMode != "disabled" || c.Dependencies.Timeout() < 100*time.Millisecond {
			return configFault("CHAT_MESSAGES_NOT_READY", "消息组合缺少真实身份、权限、审核或恢复依赖")
		}
		if (c.Reminders.Enabled || c.Reminders.PaidSMSEnabled) && !c.Dependencies.Redis.Enabled {
			return configFault("CHAT_REMINDERS_NOT_READY", "内部防重放依赖不可用")
		}
	}

	if c.Socket.Enabled && (!c.Auth.Enabled || !c.Dependencies.MySQL.Enabled || !c.Dependencies.Redis.Enabled || c.ProviderMode != "disabled") {
		return configFault("CHAT_CONFIG_SOCKET_INVALID", "WSS需要真实身份、MySQL和一次性票据Redis")
	}
	return nil
}

func validLoopback(address, environment string) bool {
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		return false
	}
	ip := net.ParseIP(host)
	n, err := strconv.Atoi(port)
	return err == nil && ip != nil && ip.IsLoopback() && n >= 0 && n <= 65535 &&
		(n != 0 || environment == "development")
}

func logLevel(value string) (slog.Level, bool) {
	levels := map[string]slog.Level{"debug": slog.LevelDebug, "info": slog.LevelInfo, "warn": slog.LevelWarn, "error": slog.LevelError}
	level, ok := levels[value]
	return level, ok
}
