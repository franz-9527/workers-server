package calls

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"mime"
	"net/http"
	"strings"
	"time"
	"unicode/utf8"
)

type Handler struct {
	Service  *Service
	Identity ports.Identity
	gate     chan struct{}
}

func NewHandler(s *Service, i ports.Identity) *Handler {
	return &Handler{s, i, make(chan struct{}, 16)}
}
func Decode(action string, raw []byte) (Request, error) {
	var q Request
	if len(raw) > 4096 || !utf8.Valid(raw) {
		return q, ErrInvalid
	}
	fields := map[string][]string{
		"availability": {"conversation_id"}, "create": {"conversation_id", "peer_id", "media", "operation_id", "instance_id"},
		"inbox": {}, "status": {"call_id"}, "history": {"conversation_id", "before_id"},
		"prepare": {"call_id", "attempt_epoch", "instance_id"},
	}
	allowed, ok := fields[action]
	if !ok {
		if action != "accept" && action != "reject" && action != "end" && action != "ready" && action != "pulse" && action != "prepared" {
			return q, ErrInvalid
		}
		allowed = []string{"call_id", "attempt_epoch", "revision", "operation_id", "instance_id"}
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	token, e := d.Token()
	if e != nil || token != json.Delim('{') {
		return q, ErrInvalid
	}
	seen := map[string]bool{}
	for d.More() {
		token, e = d.Token()
		key, ok := token.(string)
		if e != nil || !ok || seen[key] {
			return q, ErrInvalid
		}
		found := false
		for _, k := range allowed {
			if k == key {
				found = true
				break
			}
		}
		if !found {
			return q, ErrInvalid
		}
		seen[key] = true
		var value string
		var encoded json.RawMessage
		if d.Decode(&encoded) != nil || len(encoded) < 2 || encoded[0] != '"' || json.Unmarshal(encoded, &value) != nil || strings.ContainsRune(value, 0) {
			return q, ErrInvalid
		}
	}
	if token, e = d.Token(); e != nil || token != json.Delim('}') {
		return q, ErrInvalid
	}
	if d.Decode(new(any)) != io.EOF || json.Unmarshal(raw, &q) != nil {
		return q, ErrInvalid
	}
	for _, k := range allowed {
		if k != "before_id" && !seen[k] {
			return q, ErrInvalid
		}
	}
	return q, nil
}
func (s *Service) Execute(ctx context.Context, p ports.Principal, action string, q Request) (any, error) {
 if p.Kind!="mini"||len(p.Scopes)!=1||p.Scopes[0]!="chat:use"{return nil,auth.ErrUnauthorized}
	switch action {
	case "create":
		return s.Create(ctx, p, q)
	case "availability":
		return s.Available(ctx, p, q)
	case "prepare":
		return s.Prepare(ctx, p, q)
	case "status":
		return s.Status(ctx, p, q)
	case "inbox":
		return s.Inbox(ctx, p)
	case "history":
		return s.History(ctx, p, q)
	default:
		return s.Action(ctx, p, action, q)
	}
}
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	fail := func(status int, code string) {
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": code, "retryable": status == 503, "server_time": time.Now().Unix()})
	}
	if r.Method != "POST" {
		w.Header().Set("Allow", "POST")
		fail(405, "CALL_METHOD_NOT_ALLOWED")
		return
	}
	if r.URL.RawQuery != "" || r.URL.RawPath != "" {
		fail(400, "CALL_REQUEST_INVALID")
		return
	}
	kind, _, e := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if e != nil || kind != "application/json" {
		fail(415, "CALL_CONTENT_TYPE_INVALID")
		return
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		fail(429, "CALL_BUSY")
		return
	}
	headers := r.Header.Values("Authorization")
	if len(headers) != 1 || !strings.HasPrefix(headers[0], "Bearer ") {
		fail(401, "CHAT_UNAUTHORIZED")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 4*time.Second)
	defer cancel()
	p, e := h.Identity.Authenticate(ctx, strings.TrimPrefix(headers[0], "Bearer "))
	if e != nil || p.Kind != "mini" || len(p.Scopes) != 1 || p.Scopes[0] != "chat:use" {
		fail(401, "CHAT_UNAUTHORIZED")
		return
	}
	raw, e := io.ReadAll(io.LimitReader(r.Body, 4097))
	if e != nil {
		fail(400, "CALL_REQUEST_INVALID")
		return
	}
	action := strings.TrimPrefix(r.URL.Path, "/chat/api/v1/calls/")
	q, e := Decode(action, raw)
	if e != nil {
		fail(400, "CALL_REQUEST_INVALID")
		return
	}
	result, e := h.Service.Execute(ctx, p, action, q)
	if e != nil {
		if errors.Is(e, policy.ErrDenied) || errors.Is(e, auth.ErrUnauthorized) {
			fail(403, "CHAT_PERMISSION_DENIED")
			return
		}
		if errors.Is(e, ErrConflict) || errors.Is(e, ErrExpired) || errors.Is(e, ErrBusy) {
			fail(409, e.Error())
			return
		}
		if errors.Is(e, ErrInvalid) {
			fail(400, "CALL_REQUEST_INVALID")
			return
		}
		code := e.Error()
		if !strings.HasPrefix(code, "CALL_") || len(code) > 64 {
			code = "CALL_UNAVAILABLE"
		}
		fail(503, code)
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"data": result})
}
