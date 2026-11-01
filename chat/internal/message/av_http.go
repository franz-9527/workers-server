package message

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"mime"
	"net/http"
	"strings"
	"time"
)

type avAction struct {
	Frame *int `json:"frame_ordinal,omitempty"`
	imageAction
	Source  string `json:"source,omitempty"`
	MD5     string `json:"source_md5,omitempty"`
	Ordinal int    `json:"ordinal,omitempty"`
	Confirm bool   `json:"confirm,omitempty"`
}
type AVHandler struct {
	service  *AVService
	identity ports.Identity
	gate     chan struct{}
}

func NewAVHandler(m *AVService, identity ports.Identity, concurrency int) (*AVHandler, error) {
	if m == nil || identity == nil || concurrency < 1 || concurrency > 16 {
		return nil, ports.ErrInvalid
	}
	return &AVHandler{m, identity, make(chan struct{}, concurrency)}, nil
}
func (h *AVHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	fail := func(status int, code string) {
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": code, "message": "音视频操作未完成，请刷新状态后重试", "retryable": status >= 500, "server_time": time.Now().UTC()})
	}
	route := strings.TrimPrefix(r.URL.Path, "/chat/api/v1/av/")
	fields := map[string]string{
		"upload":   "conversation_id upload_id source size_bytes source_md5",
		"part":     "conversation_id attachment_id ordinal confirm",
		"complete": "conversation_id attachment_id",
		"status":   "conversation_id client_message_id", "cancel": "conversation_id client_message_id", "pending": "conversation_id",
		"url": "conversation_id message_id thumbnail frame_ordinal", "listened": "conversation_id message_id",
		"review/list": "after", "review/decide": "intent_id expected_version action reason general_safety_confirmed group_confirmed",
		"review/url": "intent_id expected_version",
	}
	allowed, ok := fields[route]
	if !ok || r.URL.Path != "/chat/api/v1/av/"+route {
		fail(404, "CHAT_ROUTE_NOT_FOUND")
		return
	}
	if r.Method != "POST" {
		w.Header().Set("Allow", "POST")
		fail(405, "CHAT_METHOD_NOT_ALLOWED")
		return
	}
	if r.URL.RawQuery != "" || r.URL.RawPath != "" {
		fail(400, "CHAT_INVALID_REQUEST")
		return
	}
	content, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil || content != "application/json" {
		fail(415, "CHAT_CONTENT_TYPE_INVALID")
		return
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		fail(429, ports.ErrBusy.Error())
		return
	}
	headers := r.Header.Values("Authorization")
	if len(headers) != 1 || !strings.HasPrefix(headers[0], "Bearer ") || strings.ContainsAny(headers[0], "\r\n\t") {
		fail(401, "CHAT_UNAUTHORIZED")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
	defer cancel()
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 16<<10))
	if err != nil {
		fail(413, "CHAT_REQUEST_TOO_LARGE")
		return
	}
	var q avAction
	if !strictImageJSON(body, &q) {
		fail(400, "CHAT_INVALID_REQUEST")
		return
	}
	var keys map[string]json.RawMessage
	json.Unmarshal(body, &keys)
	for key := range keys {
		if !strings.Contains(" "+allowed+" ", " "+key+" ") {
			fail(400, "CHAT_INVALID_REQUEST")
			return
		}
	}
	p, err := h.identity.Authenticate(ctx, strings.TrimPrefix(headers[0], "Bearer "))
	if err != nil {
		fail(401, "CHAT_UNAUTHORIZED")
		return
	}
	var result any
	switch route {
	case "upload":
		result, err = h.service.Upload(ctx, p, q.ConversationID, q.UploadID, q.Source, q.Size, q.MD5)
	case "part":
		result, err = h.service.Part(ctx, p, q.ConversationID, q.AttachmentID, q.Ordinal, q.Confirm)
	case "complete":
		err = h.service.Complete(ctx, p, q.ConversationID, q.AttachmentID)
		result = map[string]bool{"submitted": err == nil}
	case "status", "cancel":
		result, err = h.service.Status(ctx, p, q.ConversationID, q.ClientID, route == "cancel")
	case "pending":
		result, err = h.service.Pending(ctx, p, q.ConversationID)
	case "url":
		if q.Frame == nil {
			result, err = h.service.URL(ctx, p, q.ConversationID, q.MessageID, q.Thumbnail)
		} else {
			result, err = h.service.URL(ctx, p, q.ConversationID, q.MessageID, q.Thumbnail, *q.Frame)
		}
	case "listened":
		err = h.service.Listened(ctx, p, q.ConversationID, q.MessageID)
		result = map[string]bool{"heard": err == nil}
	case "review/list":
		result, err = h.service.Reviews(ctx, p, q.After)
	case "review/decide":
		result, err = h.service.Review(ctx, p, q.IntentID, q.Expected, q.Action, q.Reason, q.General, q.Group)
	case "review/url":
		result, err = h.service.ReviewURL(ctx, p, q.IntentID, q.Expected)
	}
	if err != nil {
		status := 409
		code := err.Error()
		if err == ports.ErrInvalid {
			status = 400
		} else if err == auth.ErrUnauthorized {
			status = 403
		} else if err == ports.ErrBusy {
			status = 429
		} else if err == ports.ErrUnavailable || err == ErrCheck {
			status = 503
		}
		fail(status, code)
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "data": result, "server_time": time.Now().UTC()})
}
