package message

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"mime"
	"net/http"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type HistoryHandler struct {
	service  *HistoryService
	identity ports.Identity
	gate     chan struct{}
}

func NewHistoryHandler(s *HistoryService, identity ports.Identity, concurrency int) (*HistoryHandler, error) {
	if s == nil || identity == nil || concurrency < 1 || concurrency > 32 {
		return nil, ports.ErrInvalid
	}
	return &HistoryHandler{s, identity, make(chan struct{}, concurrency)}, nil
}
func historyMode(path string) string {
	switch path {
	case "/chat/api/v1/conversations/bootstrap":
		return "bootstrap"
	case "/chat/api/v1/messages/history":
		return "history"
	case "/chat/api/v1/messages/sync":
		return "sync"
	case "/chat/api/v1/messages/changes":
		return "changes"
	case "/chat/api/v1/messages/locate":
		return "locate"
	}
	return ""
}
func decodeHistory(body []byte) (HistoryRequest, error) {
	var q HistoryRequest
	if !utf8.Valid(body) {
		return q, ports.ErrInvalid
	}
	d := json.NewDecoder(bytes.NewReader(body))
	t, e := d.Token()
	if e != nil || t != json.Delim('{') {
		return q, ports.ErrInvalid
	}
	seen := map[string]bool{}
	for d.More() {
		t, e = d.Token()
		k, ok := t.(string)
		if e != nil || !ok || seen[k] {
			return q, ports.ErrInvalid
		}
		seen[k] = true
		var raw json.RawMessage
		if d.Decode(&raw) != nil {
			return q, ports.ErrInvalid
		}
		if k == "limit" {
			if len(raw) == 0 || raw[0] < '0' || raw[0] > '9' || json.Unmarshal(raw, &q.Limit) != nil || q.Limit < 1 || q.Limit > 50 {
				return q, ports.ErrInvalid
			}
			continue
		}
		var v string
		if len(raw) < 2 || raw[0] != '"' || !validSurrogates(raw) || json.Unmarshal(raw, &v) != nil {
			return q, ports.ErrInvalid
		}
		switch k {
		case "conversation_id":
			q.ConversationID = ports.ID(v)
		case "cursor":
			q.Cursor = v
		case "message_id":
			q.TargetID = ports.ID(v)
		default:
			return q, ports.ErrInvalid
		}
	}
	t, e = d.Token()
	if e != nil || t != json.Delim('}') {
		return q, ports.ErrInvalid
	}
	if _, e = d.Token(); e != io.EOF || !validID(q.ConversationID) {
		return q, ports.ErrInvalid
	}
	return q, nil
}
func (h *HistoryHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	var nonce [16]byte
	rand.Read(nonce[:])
	id := hex.EncodeToString(nonce[:])
	w.Header().Set("X-Request-ID", id)
	fail := func(status int, code string, retry bool) {
		w.WriteHeader(status)
		if r.Method != "HEAD" {
			json.NewEncoder(w).Encode(map[string]any{"request_id": id, "code": code, "retryable": retry, "server_time": time.Now().UTC()})
		}
	}
	mode := historyMode(r.URL.Path)
	if r.URL.Path == "/chat/api/v1/conversations/readers" || r.URL.Path == "/chat/api/v1/conversations/unread" || r.URL.Path == "/chat/api/v1/conversations/read" || r.URL.Path == "/chat/api/v1/conversations/digests" || r.URL.Path == "/chat/api/v1/conversations/preferences" || r.URL.Path == "/chat/api/v1/conversations/preference" {
		h.serveS09(w, r)
		return
	}
	if mode == "" {
		fail(404, "CHAT_ROUTE_NOT_FOUND", false)
		return
	}
	if r.Method != "POST" {
		w.Header().Set("Allow", "POST")
		fail(405, "CHAT_METHOD_NOT_ALLOWED", false)
		return
	}
	if r.URL.RawQuery != "" || r.URL.RawPath != "" {
		fail(400, "CHAT_INVALID_REQUEST", false)
		return
	}
	content, _, e := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if e != nil || content != "application/json" {
		fail(415, "CHAT_CONTENT_TYPE_INVALID", false)
		return
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		fail(429, ports.ErrBusy.Error(), true)
		return
	}
	headers := r.Header.Values("Authorization")
	if len(headers) != 1 || !strings.HasPrefix(headers[0], "Bearer ") || strings.ContainsAny(headers[0], "\r\n\t") {
		fail(401, "CHAT_UNAUTHORIZED", false)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), h.service.timeout)
	defer cancel()
	controller := http.NewResponseController(w)
	controller.SetReadDeadline(time.Now().Add(h.service.timeout))
	body, e := io.ReadAll(http.MaxBytesReader(w, r.Body, 4096))
	if e != nil {
		w.Header().Set("Connection", "close")
		var large *http.MaxBytesError
		if errors.As(e, &large) {
			fail(413, "CHAT_REQUEST_TOO_LARGE", false)
		} else {
			fail(408, "CHAT_REQUEST_TIMEOUT", true)
		}
		return
	}
	controller.SetReadDeadline(time.Time{})
	q, e := decodeHistory(body)
	if e != nil {
		fail(400, "CHAT_INVALID_REQUEST", false)
		return
	}
	p, e := h.identity.Authenticate(ctx, strings.TrimPrefix(headers[0], "Bearer "))
	if e != nil {
		if errors.Is(e, auth.ErrUnauthorized) {
			fail(401, "CHAT_UNAUTHORIZED", false)
		} else {
			fail(503, ports.ErrUnavailable.Error(), true)
		}
		return
	}
	page, e := h.service.Read(ctx, p, q, mode)
	if e != nil {
		switch {
		case errors.Is(e, ports.ErrInvalid):
			fail(400, "CHAT_INVALID_REQUEST", false)
		case errors.Is(e, ErrResync):
			fail(409, ErrResync.Error(), false)
		case errors.Is(e, policy.ErrDenied), errors.Is(e, auth.ErrUnauthorized):
			fail(403, "CHAT_PERMISSION_DENIED", false)
		default:
			fail(503, ports.ErrUnavailable.Error(), true)
		}
		return
	}
	json.NewEncoder(w).Encode(map[string]any{"request_id": id, "code": "OK", "data": page, "server_time": time.Now().UTC()})
}
