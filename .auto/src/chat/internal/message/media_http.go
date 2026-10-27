package message

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"mime"
	"net/http"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// Reject duplicate keys at EVERY depth; DisallowUnknownFields alone accepts
// duplicate nested keys, which cannot define an immutable ordered manifest.
func uniqueImageJSON(d *json.Decoder, depth int) bool {
	if depth > 12 {
		return false
	}
	t, e := d.Token()
	if e != nil {
		return false
	}
	switch t {
	case json.Delim('{'):
		seen := map[string]bool{}
		for d.More() {
			v, e := d.Token()
			key, ok := v.(string)
			if e != nil || !ok || seen[key] {
				return false
			}
			seen[key] = true
			if !uniqueImageJSON(d, depth+1) {
				return false
			}
		}
		v, e := d.Token()
		return e == nil && v == json.Delim('}')
	case json.Delim('['):
		n := 0
		for d.More() {
			n++
			if n > 50 || !uniqueImageJSON(d, depth+1) {
				return false
			}
		}
		v, e := d.Token()
		return e == nil && v == json.Delim(']')
	}
	return true
}
func strictImageJSON(raw []byte, target any) bool {
	if !utf8.Valid(raw) || len(raw) == 0 || raw[0] != '{' || !validSurrogates(raw) {
		return false
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	if !uniqueImageJSON(d, 0) {
		return false
	}
	if _, e := d.Token(); e != io.EOF {
		return false
	}
	d = json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	return d.Decode(target) == nil
}
func decodeMediaSend(raw []byte) (Request, error) {
	var q Request
	if !strictImageJSON(raw, &q) {
		return q, ports.ErrInvalid
	}
	var fields map[string]json.RawMessage
	json.Unmarshal(raw, &fields)
	var items []map[string]json.RawMessage
	if json.Unmarshal(fields["items"], &items) != nil {
		return q, ports.ErrInvalid
	}
	for _, item := range items {
		value := item["ordinal"]
		if len(item) != 3 || len(value) == 0 || value[0] < '0' || value[0] > '9' {
			return q, ports.ErrInvalid
		}
	}
	var e error
	if q.MessageType == "audio" || q.MessageType == "video" {
		_, e = avFingerprint(q)
	} else {
		_, e = mediaFingerprint(q)
	}
	return q, e
}

type imageAction struct {
	ConversationID ports.ID `json:"conversation_id,omitempty"`
	UploadID       string   `json:"upload_id,omitempty"`
	Size           int64    `json:"size_bytes,omitempty"`
	AttachmentID   ports.ID `json:"attachment_id,omitempty"`
	ClientID       string   `json:"client_message_id,omitempty"`
	MessageID      ports.ID `json:"message_id,omitempty"`
	ItemID         ports.ID `json:"item_id,omitempty"`
	Thumbnail      bool     `json:"thumbnail,omitempty"`
	IntentID       ports.ID `json:"intent_id,omitempty"`
	Expected       ports.ID `json:"expected_version,omitempty"`
	Action         string   `json:"action,omitempty"`
	Reason         string   `json:"reason,omitempty"`
	General        bool     `json:"general_safety_confirmed,omitempty"`
	Group          bool     `json:"group_confirmed,omitempty"`
	After          ports.ID `json:"after,omitempty"`
}
type MediaHandler struct {
	service  *MediaService
	identity ports.Identity
	gate     chan struct{}
}

func NewMediaHandler(m *MediaService, identity ports.Identity, concurrency int) (*MediaHandler, error) {
	if m == nil || identity == nil || concurrency < 1 || concurrency > 16 {
		return nil, ports.ErrInvalid
	}
	return &MediaHandler{m, identity, make(chan struct{}, concurrency)}, nil
}
func (h *MediaHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	fail := func(status int, code string) {
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(map[string]any{"code": code, "message": "图片操作未完成，请刷新状态后重试", "retryable": status >= 500, "server_time": time.Now().UTC()})
	}
	route := strings.TrimPrefix(r.URL.Path, "/chat/api/v1/media/")
	fields := map[string]string{
		"upload": "conversation_id upload_id size_bytes", "uploaded": "conversation_id attachment_id",
		"status": "conversation_id client_message_id", "cancel": "conversation_id client_message_id", "pending": "conversation_id",
		"report": "conversation_id message_id item_id reason", "url": "conversation_id message_id item_id thumbnail", "review/list": "after",
		"review/decide": "intent_id expected_version action reason general_safety_confirmed group_confirmed", "review/url": "intent_id expected_version item_id",
	}
	allowed, ok := fields[route]
	if !ok || r.URL.Path != "/chat/api/v1/media/"+route {
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
	var q imageAction
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
		result, err = h.service.Upload(ctx, p, q.ConversationID, q.UploadID, q.Size)
	case "uploaded":
		result, err = h.service.Uploaded(ctx, p, q.ConversationID, q.AttachmentID)
	case "status", "cancel":
		result, err = h.service.Status(ctx, p, q.ConversationID, q.ClientID, route == "cancel")
	case "pending":
		result, err = h.service.Pending(ctx, p, q.ConversationID)
	case "report":
		var id ports.ID
		id, err = h.service.Report(ctx, p, q.ConversationID, q.MessageID, q.ItemID, q.Reason)
		result = map[string]any{"report_id": id, "state": "pending"}
	case "url":
		result, err = h.service.URLInfo(ctx, p, q.ConversationID, q.MessageID, q.ItemID, q.Thumbnail)
	case "review/list":
		result, err = h.service.Reviews(ctx, p, q.After)
	case "review/decide":
		result, err = h.service.Review(ctx, p, q.IntentID, q.Expected, q.Action, q.Reason, q.General, q.Group)
	case "review/url":
		var url string
		url, err = h.service.ReviewURL(ctx, p, q.IntentID, q.Expected, q.ItemID)
		result = map[string]any{"url": url, "expires_at": time.Now().Add(60 * time.Second).Unix()}
	}
	if err != nil {
		status := 409
		code := err.Error()
		if err == ports.ErrInvalid {
			status = 400
		} else if err == auth.ErrUnauthorized {
			status = 403
		} else if err == ports.ErrUnavailable || err == ErrCheck {
			status = 503
		}
		fail(status, code)
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "data": result, "server_time": time.Now().UTC()})
}
