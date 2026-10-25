package bootstrap

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"flag"
	"io"
	"log/slog"
	"runtime"

	"github.com/franz-9527/workers/chat/internal/dependencies"
)

var (
	sourceRevision = "unknown"
	sourceDirty    = "unknown"
)

const stage = "S23"

type versionInfo struct {
	Stage           string `json:"stage"`
	GoVersion       string `json:"go_version"`
	SourceRevision  string `json:"source_revision"`
	SourceDirty     string `json:"source_dirty"`
	ProtocolVersion int    `json:"protocol_version"`
	GOOS            string `json:"goos"`
	GOARCH          string `json:"goarch"`
}

// Run owns process composition and lifecycle; domain services own chat commands.
func Run(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	logger := newLogger(stderr, slog.LevelInfo, "cli", "unknown")
	if len(args) == 0 {
		return fail(logger, &Fault{"CHAT_USAGE", "用法: chatd version | check-config/serve/worker --config 文件.json", 2})
	}
	if args[0] == "help" || args[0] == "--help" || args[0] == "-h" {
		_, err := io.WriteString(stdout, "chatd version\nchatd check-config --config 文件.json [--probe]\nchatd serve --config 文件.json\nchatd worker --config 文件.json\nS21～S23隔离回归、留存恢复与交付工具已接入，真实发布门禁仍关闭。\n")
		if err != nil {
			return fail(logger, &Fault{"CHAT_OUTPUT_FAILED", "无法写入命令输出", 1})
		}
		return 0
	}
	if args[0] == "version" {
		if len(args) != 1 {
			return fail(logger, &Fault{"CHAT_USAGE", "version不接受额外参数", 2})
		}
		return output(logger, stdout, versionInfo{Stage: stage, GoVersion: runtime.Version(), SourceRevision: sourceRevision, SourceDirty: sourceDirty, ProtocolVersion: 1, GOOS: runtime.GOOS, GOARCH: runtime.GOARCH})
	}
	command := args[0]
	if command != "check-config" && command != "serve" && command != "worker" {
		return fail(logger, &Fault{"CHAT_USAGE", "未知命令，请运行chatd help", 2})
	}
	flags := flag.NewFlagSet(command, flag.ContinueOnError)
	flags.SetOutput(io.Discard) // flag errors otherwise echo paths and values.
	path := flags.String("config", "", "配置文件")
	probe := flags.Bool("probe", false, "只读探测依赖")
	if err := flags.Parse(args[1:]); err != nil || flags.NArg() != 0 || *path == "" {
		return fail(logger, &Fault{"CHAT_USAGE", "请仅使用--config指定JSON配置文件", 2})
	}
	if *probe && command != "check-config" {
		return fail(logger, &Fault{"CHAT_USAGE", "--probe仅供check-config使用", 2})
	}
	cfg, fault := LoadConfig(*path)
	if fault != nil {
		return fail(logger, fault)
	}
	level, _ := logLevel(cfg.LogLevel)
	logger = newLogger(stderr, level, command, cfg.Environment)
	if command == "check-config" && !*probe {
		return output(logger, stdout, struct {
			Status string `json:"status"`
			Stage  string `json:"stage"`
			Scope  string `json:"scope"`
		}{"ok", stage, "config_only"})
	}
	if ctx.Err() != nil {
		return 0
	}
	role := command
	if role == "check-config" {
		role = "serve"
	}
	deps, err := dependencies.Open(cfg.Dependencies, cfg.Environment, role, cfg.ProviderMode)
	if err != nil {
		return fail(logger, &Fault{"CHAT_DEPENDENCY_CONFIG_INVALID", "依赖凭证或初始化配置不可用", 2})
	}
	defer deps.Close()
	deps.ConfigureOperations(cfg.Operations)
	if err := deps.EnableRetention(cfg.Retention); err != nil {
		return fail(logger, &Fault{"CHAT_RETENTION_INVALID", "留存适配不可用", 2})
	}
	if err := deps.EnableIdentity(cfg.Auth); err != nil {
		return fail(logger, &Fault{"CHAT_CONFIG_AUTH_INVALID", "身份适配配置不可用", 2})
	}
	if err := deps.EnableConversations(cfg.Conversations); err != nil {
		return fail(logger, &Fault{"CHAT_CONFIG_CONVERSATIONS_INVALID", "会话投影配置不可用", 2})
	}
	if err := deps.EnableSafety(cfg.Safety); err != nil {
		return fail(logger, &Fault{"CHAT_CONFIG_SAFETY_INVALID", "文本审核适配配置不可用", 2})
	}
	if err := deps.EnableHistory(cfg.History); err != nil {
		return fail(logger, &Fault{"CHAT_CONFIG_HISTORY_INVALID", "历史读取配置不可用", 2})
	}
	if err := deps.EnableMessaging(cfg.Messages, cfg.Images, cfg.Reminders); err != nil {
		return fail(logger, &Fault{"CHAT_MESSAGING_INVALID", "消息组合依赖不可用", 2})
	}
	if err := deps.EnableAV(cfg.AV); err != nil {
		return fail(logger, &Fault{"CHAT_AV_INVALID", "音视频组合依赖不可用", 2})
	}
	if err := deps.EnableSocket(cfg.Socket); err != nil {
		return fail(logger, &Fault{"CHAT_CONFIG_SOCKET_INVALID", "WSS连接配置不可用", 2})
	}
	snapshot := deps.Refresh(ctx)
	if command == "check-config" {
		if code := output(logger, stdout, snapshot); code != 0 {
			return code
		}
		if !snapshot.InfrastructureReady {
			return 1
		}
		return 0
	}
	monitorCtx, stopMonitor := context.WithCancel(ctx)
	monitorDone := make(chan struct{})
	go func() { defer close(monitorDone); deps.Monitor(monitorCtx) }()
	defer func() { stopMonitor(); <-monitorDone }()
	if command == "worker" {
		if deps.Messages != nil {
			done := make(chan struct{})
			go func() { defer close(done); deps.RunAV(ctx) }()
			defer func() { <-done }()
		}
		if deps.Governance != nil {
			done := make(chan struct{})
			go func() { defer close(done); deps.RunMessaging(ctx, logger) }()
			defer func() { <-done }()
		}
		if deps.Retention != nil {
			done := make(chan struct{})
			go func() { defer close(done); deps.MaintainRetention(ctx, logger) }()
			defer func() { <-done }()
		}
		if deps.Moderation != nil {
			done := make(chan struct{})
			go func() { defer close(done); deps.Moderation.Maintain(ctx) }()
			defer func() { <-done }()
		}

		if deps.Conversations != nil {
			logger.Info("worker_started", "mode", "conversation_projection", "tasks_enabled", true)
			deps.RunProjection(ctx, logger)
			logger.Info("worker_stopped")
			return 0
		}
		logger.Info("worker_started", "mode", "idle", "tasks_enabled", false)
		<-ctx.Done()
		logger.Info("worker_stopped")
		return 0
	}
	if fault := serve(ctx, cfg, logger, deps); fault != nil {
		return fail(logger, fault)
	}
	return 0
}

func newLogger(w io.Writer, level slog.Level, process, environment string) *slog.Logger {
	return slog.New(slog.NewJSONHandler(w, &slog.HandlerOptions{Level: level})).With(
		"component", "chatd", "stage", stage, "process", process, "env", environment)
}

func requestID() string {
	var value [16]byte
	if _, err := rand.Read(value[:]); err != nil {
		return "unavailable"
	}
	return hex.EncodeToString(value[:])
}

func fail(logger *slog.Logger, fault *Fault) int {
	logger.Error(fault.Message, "code", fault.Code, "request_id", requestID())
	return fault.Exit
}

func output(logger *slog.Logger, w io.Writer, value any) int {
	if err := json.NewEncoder(w).Encode(value); err != nil {
		return fail(logger, &Fault{"CHAT_OUTPUT_FAILED", "无法写入命令输出", 1})
	}
	return 0
}
