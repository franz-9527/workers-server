package bootstrap

import (
	"context"
	"encoding/json"
	"errors"
	"log"
	"log/slog"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/dependencies"
)

func serve(ctx context.Context, cfg Config, logger *slog.Logger, runtimes ...*dependencies.Runtime) *Fault {
	var deps *dependencies.Runtime
	if len(runtimes) > 0 {
		deps = runtimes[0]
	}
	var listeners []net.Listener
	defer func() {
		for _, listener := range listeners {
			_ = listener.Close()
		}
	}()
	for _, address := range []string{cfg.HTTP.Listen, cfg.HTTP.InternalListen} {
		listener, err := (&net.ListenConfig{}).Listen(ctx, "tcp", address)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return &Fault{"CHAT_LISTEN_FAILED", "监听启动失败，请检查端口及权限", 1}
		}
		listeners = append(listeners, listener)
	}
	servers := []*http.Server{newHTTPServer(false, logger, deps), newHTTPServer(true, logger, deps)}
	finished := make(chan error, len(servers))
	for i, server := range servers {
		listener := listeners[i]
		go func() { finished <- server.Serve(listener) }()
	}
	logger.Info("serve_started", "public_address", listeners[0].Addr().String(),
		"internal_address", listeners[1].Addr().String(), "business_enabled", cfg.Messages.Enabled)
	remaining := len(servers)
	var fault *Fault
	select {
	case <-ctx.Done():
	case err := <-finished:
		remaining--
		if !errors.Is(err, http.ErrServerClosed) {
			fault = &Fault{"CHAT_HTTP_FAILED", "HTTP服务异常停止", 1}
		}
	}
	logger.Info("serve_stopping")
	if deps != nil {
		deps.Stop()
	}
	timeout, _ := time.ParseDuration(cfg.ShutdownTimeout)
	shutdown, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	if deps != nil && deps.Socket != nil {
		if err := deps.Socket.Shutdown(shutdown); err != nil {
			logger.Warn("socket_drain_timeout", "code", "CHAT_SHUTDOWN_FORCED")
			fault = &Fault{"CHAT_SHUTDOWN_FORCED", "WSS连接未在排空期限内关闭", 1}
		}
	}
	for _, server := range servers {
		if err := server.Shutdown(shutdown); err != nil {
			_ = server.Close()
			logger.Warn("http_drain_timeout", "code", "CHAT_SHUTDOWN_FORCED")
			if fault == nil {
				fault = &Fault{"CHAT_SHUTDOWN_FORCED", "HTTP请求未在排空期限内完成", 1}
			}
		}
	}
	for range remaining {
		<-finished
	}
	logger.Info("serve_stopped")
	return fault
}

func newHTTPServer(internal bool, logger *slog.Logger, deps *dependencies.Runtime) *http.Server {
	return &http.Server{
		Handler:           healthHandler(internal, deps),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      5 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    16 << 10,
		ErrorLog:          log.New(httpLogWriter{logger}, "", 0),
	}
}

func healthHandler(internal bool, runtimes ...*dependencies.Runtime) http.Handler {
	var deps *dependencies.Runtime
	if len(runtimes) > 0 {
		deps = runtimes[0]
	}
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		if internal && deps != nil && r.URL.Path == "/metrics" {
			deps.Metrics(w, r)
			return
		}
		if !internal && deps != nil && (r.URL.Path == "/chat/ws" || len(r.URL.Path) >= 13 && r.URL.Path[:13] == "/chat/api/v1/") && !deps.OperationsReady() {
			writeError(w, r, 503, "CHAT_RECOVERY_BLOCKED", "安全恢复尚未核验", true)
			return
		}
		if deps != nil {
			if internal && deps.CallsInternal!=nil && r.URL.Path=="/chat/internal/v1/calls/stop-confirmed" {if !deps.OperationsReady(){writeError(w,r,503,"CHAT_RECOVERY_BLOCKED","安全恢复尚未核验",true);return};deps.CallsInternal.ServeHTTP(w,r);return}
   if internal && deps.ReminderHTTP != nil && r.URL.Path == "/chat/internal/v1/reminders/eligible" {
				if !deps.OperationsReady() {
					writeError(w, r, 503, "CHAT_RECOVERY_BLOCKED", "安全恢复尚未核验", true)
					return
				}
				deps.ReminderHTTP.ServeHTTP(w, r)
				return
			}
			if !internal && deps.CallsHTTP != nil && strings.HasPrefix(r.URL.Path,"/chat/api/v1/calls/") {deps.CallsHTTP.ServeHTTP(w,r);return}
   if !internal && deps.MessageHTTP != nil && (r.URL.Path == "/chat/api/v1/messages/send" || r.URL.Path == "/chat/api/v1/messages/result" || r.URL.Path == "/chat/api/v1/messages/recall") {
				deps.MessageHTTP.ServeHTTP(w, r)
				return
			}
			if !internal && deps.AVCallback != nil && r.URL.Path == "/chat/api/v1/av/callback" {
				deps.AVCallback.ServeHTTP(w, r)
				return
			}
			if !internal && deps.AVHTTP != nil && strings.HasPrefix(r.URL.Path, "/chat/api/v1/av/") {
				deps.AVHTTP.ServeHTTP(w, r)
				return
			}
			if !internal && deps.MediaHTTP != nil && strings.HasPrefix(r.URL.Path, "/chat/api/v1/media/") {
				deps.MediaHTTP.ServeHTTP(w, r)
				return
			}
			if !internal && deps.GovernanceHTTP != nil && (strings.HasPrefix(r.URL.Path, "/chat/api/v1/audit/") || strings.HasPrefix(r.URL.Path, "/chat/api/v1/governance/")) {
				deps.GovernanceHTTP.ServeHTTP(w, r)
				return
			}
		}
		if !internal && deps != nil && deps.Socket != nil && r.URL.Path == "/chat/ws" {
			deps.Socket.ServeHTTP(w, r)
			return
		}
		if !internal && deps != nil && deps.IdentityHTTP != nil && (r.URL.Path == "/chat/api/v1/auth/session" || r.URL.Path == "/chat/api/v1/auth/socket-ticket") {
			deps.IdentityHTTP.ServeHTTP(w, r)
			return
		}
		if !internal && deps != nil && deps.HistoryHTTP != nil && (r.URL.Path == "/chat/api/v1/conversations/bootstrap" || r.URL.Path == "/chat/api/v1/messages/history" || r.URL.Path == "/chat/api/v1/messages/sync" || r.URL.Path == "/chat/api/v1/messages/changes" || r.URL.Path == "/chat/api/v1/messages/locate" || r.URL.Path == "/chat/api/v1/conversations/readers" || r.URL.Path == "/chat/api/v1/conversations/unread" || r.URL.Path == "/chat/api/v1/conversations/read" || r.URL.Path == "/chat/api/v1/conversations/digests" || r.URL.Path == "/chat/api/v1/conversations/preferences" || r.URL.Path == "/chat/api/v1/conversations/preference") {
			deps.HistoryHTTP.ServeHTTP(w, r)
			return
		}
		if r.URL.Path != "/livez" && !(internal && r.URL.Path == "/readyz") {
			writeError(w, r, http.StatusNotFound, "CHAT_ROUTE_NOT_FOUND", "接口未开放", false)
			return
		}
		if r.Method != http.MethodGet && r.Method != http.MethodHead {
			w.Header().Set("Allow", "GET, HEAD")
			writeError(w, r, http.StatusMethodNotAllowed, "CHAT_METHOD_NOT_ALLOWED", "请求方法不支持", false)
			return
		}
		if r.URL.Path == "/readyz" {
			if deps == nil {
				writeError(w, r, http.StatusServiceUnavailable, "CHAT_NOT_READY", "基础依赖未接入", false)
				return
			}
			snapshot := deps.Snapshot()
			status := http.StatusOK
			if !snapshot.InfrastructureReady || !deps.OperationsReady() {
				status = http.StatusServiceUnavailable
			}
			w.WriteHeader(status)
			if r.Method != http.MethodHead {
				_ = json.NewEncoder(w).Encode(snapshot)
			}
			return
		}
		if r.Method != http.MethodHead {
			enabled := deps != nil && deps.Messages != nil && deps.OperationsReady()
			_ = json.NewEncoder(w).Encode(map[string]any{"status": "alive", "stage": stage, "business_enabled": enabled})
		}
	})
}

func writeError(w http.ResponseWriter, r *http.Request, status int, code, message string, retryable bool) {
	id := requestID()
	w.Header().Set("X-Request-ID", id)
	w.WriteHeader(status)
	if r.Method != http.MethodHead {
		_ = json.NewEncoder(w).Encode(struct {
			RequestID  string `json:"request_id"`
			Code       string `json:"code"`
			Message    string `json:"message"`
			Retryable  bool   `json:"retryable"`
			ServerTime string `json:"server_time"`
		}{id, code, message, retryable, time.Now().UTC().Format(time.RFC3339Nano)})
	}
}

type httpLogWriter struct{ logger *slog.Logger }

func (w httpLogWriter) Write(p []byte) (int, error) {
	// net/http's original diagnostics can contain attacker-controlled request
	// targets or panic values. Never forward that raw data into ordinary logs.
	w.logger.Error("http_runtime_error", "code", "CHAT_HTTP_RUNTIME_ERROR")
	return len(p), nil
}
