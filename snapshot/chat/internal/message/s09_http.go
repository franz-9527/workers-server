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

type s09Request struct {
 MessageID ports.ID `json:"message_id,omitempty"`
	ConversationID ports.ID `json:"conversation_id,omitempty"`
	Cursor         string   `json:"cursor,omitempty"`
	Limit          int      `json:"limit,omitempty"`
}

func decodeS09(raw []byte) (s09Request, error) {
	var q s09Request
	if !utf8.Valid(raw) {
		return q, ports.ErrInvalid
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	t, e := d.Token()
	if e != nil || t != json.Delim('{') {
		return q, ports.ErrInvalid
	}
	seen := map[string]bool{}
	for d.More() {
		v, e := d.Token()
		key, ok := v.(string)
		if e != nil || !ok || seen[key] {
			return q, ports.ErrInvalid
		}
		seen[key] = true
		var field json.RawMessage
		if d.Decode(&field) != nil {
			return q, ports.ErrInvalid
		}
		switch key {
		case "message_id":
   if json.Unmarshal(field,&q.MessageID)!=nil{return q,ports.ErrInvalid}
  case "conversation_id":
			if json.Unmarshal(field, &q.ConversationID) != nil {
				return q, ports.ErrInvalid
			}
		case "cursor":
			if len(field) < 2 || field[0] != '"' || !validSurrogates(field) || json.Unmarshal(field, &q.Cursor) != nil || len(q.Cursor) > 2048 {
				return q, ports.ErrInvalid
			}
		case "limit":
			if len(field) == 0 || field[0] < '0' || field[0] > '9' || json.Unmarshal(field, &q.Limit) != nil {
				return q, ports.ErrInvalid
			}
		default:
			return q, ports.ErrInvalid
		}
	}
	t, e = d.Token()
	if e != nil || t != json.Delim('}') {
		return q, ports.ErrInvalid
	}
	if _, e = d.Token(); e != io.EOF {
		return q, ports.ErrInvalid
	}
	return q, nil
}

func (h *HistoryHandler) serveS09(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	var nonce [16]byte
	_, _ = rand.Read(nonce[:])
	requestID := hex.EncodeToString(nonce[:])
	w.Header().Set("X-Request-ID", requestID)
	fail := func(status int, code string, retry bool) {
		w.WriteHeader(status)
		if r.Method != "HEAD" {
			_ = json.NewEncoder(w).Encode(map[string]any{"request_id": requestID, "code": code, "retryable": retry, "server_time": time.Now().UTC()})
		}
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
	media, _, e := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if e != nil || media != "application/json" {
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
	// Public HTTP WriteTimeout is 5s; terminate bounded digest scans first.
	ctx, cancel := context.WithTimeout(r.Context(), min(3*h.service.timeout, 4*time.Second))
	defer cancel()
	controller := http.NewResponseController(w)
	_ = controller.SetReadDeadline(time.Now().Add(min(3*h.service.timeout, 4*time.Second)))
	b, e := io.ReadAll(http.MaxBytesReader(w, r.Body, 4096))
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
	_ = controller.SetReadDeadline(time.Time{})
	var q s09Request
 var preference PreferenceRequest
 var decodeErr error
 isPreference:=r.URL.Path=="/chat/api/v1/conversations/preferences"||r.URL.Path=="/chat/api/v1/conversations/preference"
 if isPreference { preference,decodeErr=decodePreference(b,r.URL.Path=="/chat/api/v1/conversations/preference") } else {q,decodeErr=decodeS09(b)}
	if decodeErr != nil {
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
	if r.URL.Path!="/chat/api/v1/conversations/readers" && q.MessageID!=""{fail(400,"CHAT_INVALID_REQUEST",false);return}
 var out any
	switch r.URL.Path {
 case "/chat/api/v1/conversations/readers":
  if !validID(q.ConversationID)||!validID(q.MessageID)||q.Cursor!=""||q.Limit!=0{fail(400,"CHAT_INVALID_REQUEST",false);return}
  out,e=h.service.Readers(ctx,p,q.ConversationID,q.MessageID)
 case "/chat/api/v1/conversations/preferences":
  out,e=h.service.Preferences(ctx,p,preference.ConversationID)
 case "/chat/api/v1/conversations/preference":
  out,e=h.service.SetPreference(ctx,p,preference)
	case "/chat/api/v1/conversations/unread":
		if !validID(q.ConversationID) || q.Cursor != "" || q.Limit != 0 {
			fail(400, "CHAT_INVALID_REQUEST", false)
			return
		}
		out, e = h.service.Unread(ctx, p, q.ConversationID)
	case "/chat/api/v1/conversations/read":
		if !validID(q.ConversationID) || q.Cursor == "" || q.Limit != 0 {
			fail(400, "CHAT_INVALID_REQUEST", false)
			return
		}
		out, e = h.service.Acknowledge(ctx, p, q.ConversationID, q.Cursor)
	case "/chat/api/v1/conversations/digests":
		if q.ConversationID != "" || q.Limit < 1 || q.Limit > 20 {
			fail(400, "CHAT_INVALID_REQUEST", false)
			return
		}
		out, e = h.service.DigestsCursor(ctx, p, q.Cursor, q.Limit)
	default:
		fail(404, "CHAT_ROUTE_NOT_FOUND", false)
		return
	}
	if e != nil {
		switch {
		case errors.Is(e, ports.ErrInvalid):
			fail(400, "CHAT_INVALID_REQUEST", false)
		case errors.Is(e, ErrPreferenceConflict),errors.Is(e,ErrPinLimit):
   w.WriteHeader(409)
   _=json.NewEncoder(w).Encode(map[string]any{"request_id":requestID,"code":e.Error(),"retryable":false,"current":out,"server_time":time.Now().UTC()})
  case errors.Is(e, ErrResync):
			fail(409, ErrResync.Error(), false)
		case errors.Is(e, policy.ErrDenied), errors.Is(e, auth.ErrUnauthorized):
			fail(403, "CHAT_PERMISSION_DENIED", false)
		default:
			fail(503, ports.ErrUnavailable.Error(), true)
		}
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"request_id": requestID, "code": "OK", "data": out, "server_time": time.Now().UTC()})
}
