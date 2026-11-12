package auth

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type Handler struct {
	service *Service
	gate    chan struct{}
}

func NewHandler(service *Service, concurrency int) (*Handler, error) {
	if service == nil || concurrency < 1 || concurrency > 32 {
		return nil, ports.ErrInvalid
	}
	return &Handler{service, make(chan struct{}, concurrency)}, nil
}
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	id, e := randomID()
	if e != nil {
		id = "unavailable"
	}
	w.Header().Set("X-Request-ID", id)
	fail := func(status int, code, message string, retryable bool) {
		w.WriteHeader(status)
		if r.Method != "HEAD" {
			_ = json.NewEncoder(w).Encode(map[string]any{"request_id": id, "code": code, "message": message, "retryable": retryable, "server_time": time.Now().UTC().Format(time.RFC3339Nano)})
		}
	}
	expected := "GET"
	if r.URL.Path == "/chat/api/v1/auth/socket-ticket" {
		expected = "POST"
	} else if r.URL.Path != "/chat/api/v1/auth/session" {
		fail(404, "CHAT_ROUTE_NOT_FOUND", "接口未开放", false)
		return
	}
	if r.Method != expected {
		w.Header().Set("Allow", expected)
		fail(405, "CHAT_METHOD_NOT_ALLOWED", "请求方法不支持", false)
		return
	}
	if r.URL.RawQuery != "" {
		fail(400, "CHAT_INVALID_REQUEST", "请求参数不正确", false)
		return
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		fail(429, "CHAT_RESOURCE_BUSY", "请求较多，请稍后重试", true)
		return
	}
	headers := r.Header.Values("Authorization")
	if len(headers) != 1 || !strings.HasPrefix(headers[0], "Bearer ") || strings.ContainsAny(headers[0], "\r\n\t") {
		fail(401, "CHAT_UNAUTHORIZED", "登录状态已失效，请重新进入", false)
		return
	}
	credential := strings.TrimPrefix(headers[0], "Bearer ")
	controller := http.NewResponseController(w)
	_ = controller.SetReadDeadline(time.Now().Add(h.service.timeout))
	body, e := io.ReadAll(http.MaxBytesReader(w, r.Body, 256))
	if e != nil {
		// Leave an expired read deadline in place and close the connection;
		// net/http must not drain a stalled request body after the handler returns.
		w.Header().Set("Connection", "close")
		var oversized *http.MaxBytesError
		if errors.As(e, &oversized) {
			fail(413, "CHAT_REQUEST_TOO_LARGE", "请求内容过大", false)
		} else {
			fail(408, "CHAT_REQUEST_TIMEOUT", "请求未及时完成，请重试", true)
		}
		return
	}
	_ = controller.SetReadDeadline(time.Time{})
	if len(strings.TrimSpace(string(body))) > 0 && (expected == "GET" || strings.TrimSpace(string(body)) != "{}") {
		fail(400, "CHAT_INVALID_REQUEST", "请求参数不正确", false)
		return
	}
	var data any
	if expected == "GET" {
		var c Claims
		c, e = h.service.Check(r.Context(), credential)
		if e == nil {
			data = map[string]any{"actor_id": c.ActorID, "kind": c.Kind, "env": c.Environment, "scopes": c.Scopes, "expires_at": time.Unix(c.ExpiresAt, 0).UTC(), "business_enabled": false}
		}
	} else {
		var ticket string
		var expiry time.Time
		ticket, expiry, e = h.service.IssueTicket(r.Context(), credential)
		if e == nil {
			data = map[string]any{"ticket": ticket, "expires_at": expiry, "business_enabled": false}
		}
	}
	if e != nil {
		switch e {
		case ErrUnauthorized:
			fail(401, "CHAT_UNAUTHORIZED", "登录状态已失效，请重新进入", false)
		case ports.ErrBusy:
			fail(429, "CHAT_RESOURCE_BUSY", "请求较多，请稍后重试", true)
		default:
			fail(503, "CHAT_DEPENDENCY_UNAVAILABLE", "聊天身份暂不可用，请稍后重试", true)
		}
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "request_id": id, "data": data, "server_time": time.Now().UTC()})
}
