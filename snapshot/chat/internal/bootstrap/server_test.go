package bootstrap

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestHealthAndClosedBusinessRoutes(t *testing.T) {
	for _, internal := range []bool{false, true} {
		for _, test := range []struct {
			method, path string
			status       int
		}{
			{"GET", "/livez", 200}, {"HEAD", "/livez", 200}, {"POST", "/livez", 405},
			{"GET", "/chat/api/v1/messages?token=secret-input", 404}, {"GET", "/chat/ws", 404},
			{"GET", "/debug/pprof/", 404}, {"GET", "/metrics", 404}, {"GET", "/livez/", 404},
		} {
			r := httptest.NewRequest(test.method, test.path, nil)
			w := httptest.NewRecorder()
			healthHandler(internal).ServeHTTP(w, r)
			if w.Code != test.status || w.Header().Get("Cache-Control") != "no-store" || strings.Contains(w.Body.String(), "secret-input") {
				t.Fatalf("%s %s: %d %s", test.method, test.path, w.Code, w.Body.String())
			}
			if test.method == "HEAD" && w.Body.Len() != 0 {
				t.Fatal("HEAD returned a body")
			}
			if test.status >= 400 {
				var body map[string]any
				if json.Unmarshal(w.Body.Bytes(), &body) != nil || body["request_id"] != w.Header().Get("X-Request-ID") {
					t.Fatalf("invalid error envelope: %s", w.Body.String())
				}
			}
		}
		w := httptest.NewRecorder()
		healthHandler(internal).ServeHTTP(w, httptest.NewRequest("GET", "/readyz", nil))
		expected := 404
		if internal {
			expected = 503
		}
		if w.Code != expected {
			t.Fatalf("skeleton must never report business-ready: %d", w.Code)
		}
	}
}

func TestServeLifecycleAndListenerCleanup(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	logs := &capturedLogs{events: make(chan map[string]any, 16)}
	path := configFile(t, encodedConfig(t, testConfig()))
	finished := make(chan int, 1)
	go func() { finished <- Run(ctx, []string{"serve", "--config", path}, io.Discard, logs) }()
	event := waitEvent(t, logs, "serve_started")
	client := &http.Client{Timeout: 2 * time.Second, Transport: &http.Transport{Proxy: nil}}
	defer client.CloseIdleConnections()
	for _, key := range []string{"public_address", "internal_address"} {
		address, ok := event[key].(string)
		if !ok || address == "" {
			t.Fatalf("missing bound address: %#v", event)
		}
		response, err := client.Get("http://" + address + "/livez")
		if err != nil {
			t.Fatal(err)
		}
		_, _ = io.Copy(io.Discard, response.Body)
		_ = response.Body.Close()
		if response.StatusCode != 200 {
			t.Fatal(response.Status)
		}
	}
	cancel()
	select {
	case code := <-finished:
		if code != 0 {
			t.Fatalf("exit=%d", code)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("serve did not stop both listeners")
	}
	for _, key := range []string{"public_address", "internal_address"} {
		listener, err := net.Listen("tcp", event[key].(string))
		if err != nil {
			t.Fatalf("listener was not released: %v", err)
		}
		_ = listener.Close()
	}
}

func TestSecondListenFailureReleasesFirst(t *testing.T) {
	occupied, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer occupied.Close()
	first, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	address := first.Addr().String()
	_ = first.Close()
	cfg := testConfig()
	cfg.HTTP.Listen = address
	cfg.HTTP.InternalListen = occupied.Addr().String()
	fault := serve(context.Background(), cfg, newLogger(io.Discard, slog.LevelInfo, "test", "test"))
	if fault == nil || fault.Code != "CHAT_LISTEN_FAILED" {
		t.Fatalf("expected bind failure, got %#v", fault)
	}
	rebound, err := net.Listen("tcp", address)
	if err != nil {
		t.Fatalf("partial startup leaked first listener: %v", err)
	}
	_ = rebound.Close()
}

func TestHTTPRuntimeLogDoesNotEchoRequestOrPanic(t *testing.T) {
	var logs bytes.Buffer
	w := httpLogWriter{newLogger(&logs, slog.LevelInfo, "serve", "test")}
	_, _ = w.Write([]byte("panic: secret-input /path?token=secret-input"))
	if strings.Contains(logs.String(), "secret-input") || !strings.Contains(logs.String(), "CHAT_HTTP_RUNTIME_ERROR") {
		t.Fatalf("unsafe runtime log: %s", logs.String())
	}
}
