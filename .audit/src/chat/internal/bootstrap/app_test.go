package bootstrap

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"strings"
	"sync"
	"testing"
	"time"
)

type capturedLogs struct {
	mu     sync.Mutex
	data   bytes.Buffer
	events chan map[string]any
}

func (c *capturedLogs) Write(p []byte) (int, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	n, err := c.data.Write(p)
	var event map[string]any
	if json.Unmarshal(p, &event) == nil {
		select {
		case c.events <- event:
		default:
		}
	}
	return n, err
}

func waitEvent(t *testing.T, logs *capturedLogs, message string) map[string]any {
	t.Helper()
	timer := time.NewTimer(3 * time.Second)
	defer timer.Stop()
	for {
		select {
		case event := <-logs.events:
			if event["msg"] == message {
				return event
			}
		case <-timer.C:
			t.Fatalf("did not observe %s", message)
		}
	}
}

func TestCLICommandsAndSafeErrors(t *testing.T) {
	path := configFile(t, encodedConfig(t, testConfig()))
	for _, command := range []string{"version", "check-config"} {
		var out, logs bytes.Buffer
		args := []string{command}
		if command == "check-config" {
			args = append(args, "--config", path)
		}
		if code := Run(context.Background(), args, &out, &logs); code != 0 {
			t.Fatalf("%s: exit=%d: %s", command, code, logs.String())
		}
		var value map[string]any
		if err := json.Unmarshal(out.Bytes(), &value); err != nil || value["stage"] != stage || logs.Len() != 0 {
			t.Fatalf("unexpected output: %s / %s", out.String(), logs.String())
		}
		if strings.Contains(out.String(), path) {
			t.Fatal("config path leaked")
		}
	}
	badConfig := configFile(t, `{"password":"secret-input","env":"development"}`)
	for _, args := range [][]string{
		nil, {"secret-input"}, {"version", "secret-input"}, {"serve"},
		{"worker", "--secret-input=secret-input"}, {"check-config", "--config", "secret-input"},
		{"serve", "--config", badConfig}, {"serve", "--config", path, "secret-input"},
	} {
		var out, logs bytes.Buffer
		if code := Run(context.Background(), args, &out, &logs); code != 2 {
			t.Fatalf("expected usage/config exit=2, got %d", code)
		}
		var value map[string]any
		if err := json.Unmarshal(logs.Bytes(), &value); err != nil || value["code"] == nil || value["request_id"] == nil {
			t.Fatalf("not a structured error: %s", logs.String())
		}
		if out.Len() != 0 || strings.Contains(logs.String(), "secret-input") || strings.Contains(logs.String(), badConfig) {
			t.Fatalf("sensitive input echoed: %s / %s", out.String(), logs.String())
		}
	}
}

type brokenWriter struct{}

func (brokenWriter) Write([]byte) (int, error) { return 0, errors.New("secret-input") }

func TestOutputFailureIsSafe(t *testing.T) {
	var logs bytes.Buffer
	if code := Run(context.Background(), []string{"version"}, brokenWriter{}, &logs); code != 1 || strings.Contains(logs.String(), "secret-input") {
		t.Fatalf("unsafe output failure: exit=%d %s", code, logs.String())
	}
}

func TestDependencyProbeIsExplicitAndSafe(t *testing.T) {
	cfg := testConfig()
	path := configFile(t, encodedConfig(t, cfg))
	var out, logs bytes.Buffer
	if code := Run(context.Background(), []string{"check-config", "--config", path, "--probe"}, &out, &logs); code != 1 {
		t.Fatalf("disabled dependencies exit=%d", code)
	}
	var health map[string]any
	if json.Unmarshal(out.Bytes(), &health) != nil || health["scope"] != "infrastructure_only" || health["infrastructure_ready"] != false || health["business_enabled"] != false {
		t.Fatal("probe falsely reported business ready")
	}
	cfg.Dependencies.MySQL.Enabled = true
	cfg.Dependencies.MySQL.Database = "bixuan_dev_s02"
	cfg.Dependencies.MySQL.Username = "bxchat_development_test"
	cfg.Dependencies.MySQL.PasswordEnv = "CHAT_S02_MISSING_PASSWORD"
	t.Setenv("CHAT_S02_MISSING_PASSWORD", "")
	path = configFile(t, encodedConfig(t, cfg))
	out.Reset()
	logs.Reset()
	if code := Run(context.Background(), []string{"check-config", "--config", path}, &out, &logs); code != 0 {
		t.Fatalf("offline validation unexpectedly probed credentials: %d", code)
	}
	out.Reset()
	logs.Reset()
	if code := Run(context.Background(), []string{"check-config", "--config", path, "--probe"}, &out, &logs); code != 2 {
		t.Fatalf("missing secret exit=%d", code)
	}
	if strings.Contains(logs.String(), "bixuan_dev_s02") || strings.Contains(logs.String(), "CHAT_S02_MISSING_PASSWORD") || out.Len() != 0 {
		t.Fatal("dependency error echoed configuration")
	}
}

func TestWorkerWaitsForCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	logs := &capturedLogs{events: make(chan map[string]any, 16)}
	path := configFile(t, encodedConfig(t, testConfig()))
	finished := make(chan int, 1)
	go func() { finished <- Run(ctx, []string{"worker", "--config", path}, io.Discard, logs) }()
	event := waitEvent(t, logs, "worker_started")
	if event["mode"] != "idle" || event["tasks_enabled"] != false {
		t.Fatalf("worker incorrectly claims task processing: %#v", event)
	}
	select {
	case <-finished:
		t.Fatal("worker exited before cancellation")
	default:
	}
	cancel()
	select {
	case code := <-finished:
		if code != 0 {
			t.Fatalf("exit=%d", code)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("worker did not stop")
	}
	waitEvent(t, logs, "worker_stopped")
}
