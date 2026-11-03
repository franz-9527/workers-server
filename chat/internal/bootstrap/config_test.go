package bootstrap

import (
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/franz-9527/workers/chat/internal/dependencies"
)

func testConfig() Config {
	c := Config{Environment: "development", ShutdownTimeout: "1s", LogLevel: "info", ProviderMode: "disabled", Dependencies: dependencies.Defaults()}
	c.HTTP.Listen = "127.0.0.1:0"
	c.HTTP.InternalListen = "127.0.0.1:0"
	return c
}

func configFile(t *testing.T, contents string) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), "chat.json")
	if err := os.WriteFile(p, []byte(contents), 0600); err != nil {
		t.Fatal(err)
	}
	return p
}

func encodedConfig(t *testing.T, cfg Config) string {
	t.Helper()
	data, err := json.Marshal(cfg)
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func TestConfigValidation(t *testing.T) {
	valid := encodedConfig(t, testConfig())
	tests := []struct{ name, data, code string }{
		{"valid", valid, ""},
		{"malformed", `{"env":"secret-input`, "CHAT_CONFIG_INVALID"},
		{"trailing_object", valid + `{}`, "CHAT_CONFIG_INVALID"},
		{"unknown_field", strings.Replace(valid, `"provider_mode"`, `"api_secret"`, 1), "CHAT_CONFIG_INVALID"},
		{"duplicate_field", strings.Replace(valid, `"env":`, `"env":"test","env":`, 1), "CHAT_CONFIG_INVALID"},
		{"case_alias", strings.Replace(valid, `"env":`, `"ENV":"test","env":`, 1), "CHAT_CONFIG_INVALID"},
		{"null_mode", strings.Replace(valid, `"provider_mode":"disabled"`, `"provider_mode":null`, 1), "CHAT_CONFIG_INVALID"},
		{"null_root", `null`, "CHAT_CONFIG_INVALID"},
		{"array", `[]`, "CHAT_CONFIG_INVALID"},
		{"oversized", strings.Repeat(" ", maxConfigBytes+1), "CHAT_CONFIG_INVALID"},
		{"empty", `{}`, "CHAT_CONFIG_ENV_INVALID"},
		{"unknown_env", strings.Replace(valid, "development", "secret-input", 1), "CHAT_CONFIG_ENV_INVALID"},
		{"public_bind", strings.ReplaceAll(valid, "127.0.0.1", "0.0.0.0"), "CHAT_CONFIG_LISTEN_INVALID"},
		{"hostname", strings.ReplaceAll(valid, "127.0.0.1", "localhost"), "CHAT_CONFIG_LISTEN_INVALID"},
		{"production_ephemeral", strings.Replace(valid, "development", "production", 1), "CHAT_CONFIG_LISTEN_INVALID"},
		{"conflicting_ports", strings.ReplaceAll(valid, ":0", ":19501"), "CHAT_CONFIG_LISTEN_CONFLICT"},
		{"negative_timeout", strings.Replace(valid, `"1s"`, `"-1s"`, 1), "CHAT_CONFIG_TIMEOUT_INVALID"},
		{"long_timeout", strings.Replace(valid, `"1s"`, `"31s"`, 1), "CHAT_CONFIG_TIMEOUT_INVALID"},
		{"invalid_log_level", strings.Replace(valid, `"info"`, `"secret-input"`, 1), "CHAT_CONFIG_LOG_INVALID"},
		{"fake_provider", strings.Replace(valid, `"disabled"`, `"fake"`, 1), ""},
		{"real_provider", strings.Replace(valid, `"disabled"`, `"real"`, 1), "CHAT_PROVIDER_NOT_IMPLEMENTED"},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			_, fault := LoadConfig(configFile(t, test.data))
			if test.code == "" {
				if fault != nil {
					t.Fatal(fault)
				}
				return
			}
			if fault == nil || fault.Code != test.code || strings.Contains(fault.Error(), "secret-input") {
				t.Fatalf("expected safe fault %s, got %#v", test.code, fault)
			}
		})
	}
}

func TestConfigDefaultsAndReadFailure(t *testing.T) {
	production := testConfig()
	production.Environment = "production"
	production.ProviderMode = "fake"
	production.HTTP.Listen = "127.0.0.1:9501"
	production.HTTP.InternalListen = "127.0.0.1:9502"
	if _, fault := LoadConfig(configFile(t, encodedConfig(t, production))); fault == nil || fault.Code != "CHAT_CONFIG_FAKE_FORBIDDEN" {
		t.Fatal("production fixture mode accepted")
	}
	cfg, fault := LoadConfig(configFile(t, `{"env":"test","http":{"listen":"127.0.0.1:19501","internal_listen":"127.0.0.1:19502"}}`))
	if fault != nil || cfg.ShutdownTimeout != "10s" || cfg.ProviderMode != "disabled" || cfg.LogLevel != "info" {
		t.Fatalf("unsafe defaults: %#v, %v", cfg, fault)
	}
	for _, path := range []string{t.TempDir(), filepath.Join(t.TempDir(), "secret-input.json")} {
		_, fault := LoadConfig(path)
		if fault == nil || strings.Contains(fault.Error(), path) {
			t.Fatalf("expected safe read fault, got %#v", fault)
		}
	}
}

func TestRealIdentityConfiguration(t *testing.T) {
	c := testConfig()
	c.Auth.Enabled = true
	c.Auth.PublicKeys = map[string]string{"chat-v1": base64.StdEncoding.EncodeToString(make([]byte, 32))}
	if c.validate() == nil {
		t.Fatal("identity requires SQL")
	}
	c.Dependencies.MySQL.Enabled = true
	c.Dependencies.MySQL.Database = "bixuan_dev_identity"
	c.Dependencies.MySQL.Username = "bxchat_development_identity"
	if f := c.validate(); f != nil {
		t.Fatal(f)
	}
	c.ProviderMode = "fake"
	if c.validate() == nil {
		t.Fatal("mixed real/fake authority")
	}
	c.ProviderMode = "disabled"
	c.Auth.PublicKeys["chat-v1"] = "bad-key"
	if c.validate() == nil {
		t.Fatal("bad public key")
	}
}

func TestConversationProjectionConfiguration(t *testing.T) {
	c := testConfig()
	c.Conversations.Enabled = true
	if c.validate() == nil {
		t.Fatal("projection must not mix disabled identity or test providers")
	}
	c.Auth.Enabled = true
	c.Auth.PublicKeys = map[string]string{"chat-v1": base64.StdEncoding.EncodeToString(make([]byte, 32))}
	c.Dependencies.MySQL.Enabled = true
	c.Dependencies.MySQL.Database = "bixuan_dev_projection"
	c.Dependencies.MySQL.Username = "bxchat_development_projection"
	if err := c.validate(); err != nil {
		t.Fatal(err)
	}
	c.ProviderMode = "fake"
	if c.validate() == nil {
		t.Fatal("fake projection accepted")
	}
}

func TestS06CannotEnableSending(t *testing.T) {
	c := testConfig()
	c.Messages.Enabled = true
	_, fault := LoadConfig(configFile(t, encodedConfig(t, c)))
	if fault == nil || fault.Code != "CHAT_MESSAGES_NOT_READY" {
		t.Fatal("S06 send gate was bypassed", fault)
	}
}

func TestS07SafetyCannotUseFakeOrUnboundIdentity(t *testing.T) {
	c := testConfig()
	c.Safety.Enabled = true
	c.Safety.AppID = "wx_s07_fixture"
	c.Safety.CredentialFile = "/tmp/fixture-token.json"
	_, fault := LoadConfig(configFile(t, encodedConfig(t, c)))
	if fault == nil || fault.Code != "CHAT_CONFIG_SAFETY_INVALID" {
		t.Fatal("unbound safety enabled", fault)
	}
	c.Safety.Enabled = false
	_, fault = LoadConfig(configFile(t, encodedConfig(t, c)))
	if fault == nil || fault.Code != "CHAT_CONFIG_SAFETY_INVALID" {
		t.Fatal("disabled safety retained credential settings", fault)
	}
}

func TestS08HistoryRequiresBoundIdentityAndPrivateCursorKey(t *testing.T) {
	c := testConfig()
	c.History.Enabled = true
	c.History.CursorKeyEnv = "CHAT_S08_FIXTURE_CURSOR"
	t.Setenv(c.History.CursorKeyEnv, strings.Repeat("ab", 32))
	if c.validate() == nil {
		t.Fatal("unbound history enabled")
	}
	c.Auth.Enabled = true
	c.Auth.PublicKeys = map[string]string{"chat-v1": base64.StdEncoding.EncodeToString(make([]byte, 32))}
	c.Conversations.Enabled = true
	c.Dependencies.MySQL.Enabled = true
	c.Dependencies.MySQL.Database = "bixuan_dev_history"
	c.Dependencies.MySQL.Username = "bxchat_development_history"
	if e := c.validate(); e != nil {
		t.Fatal(e)
	}
	t.Setenv(c.History.CursorKeyEnv, "malformed")
	if c.validate() == nil {
		t.Fatal("weak cursor key accepted")
	}
	c.History.Enabled = false
	if c.validate() == nil {
		t.Fatal("disabled config retained key")
	}
}

func TestImageReleaseGateRemainsClosed(t *testing.T){
 c:=testConfig();c.Images.Enabled=true
 if fault:=c.validate();fault==nil||fault.Code!="CHAT_IMAGES_NOT_READY"{t.Fatal("image deployment gate opened",fault)}
}
