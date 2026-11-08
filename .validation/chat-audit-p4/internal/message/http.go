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
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// Handler is explicitly constructed by isolated fixtures in S06. Runtime
// admits messages only through the safety and signed release/staging composition.
type Handler struct {
	service  *Service
	identity ports.Identity
	gate     chan struct{}
}

func NewHandler(s *Service, identity ports.Identity, concurrency int) (*Handler, error) {
	if s == nil || identity == nil || concurrency < 1 || concurrency > 32 {
		return nil, ports.ErrInvalid
	}
	return &Handler{s, identity, make(chan struct{}, concurrency)}, nil
}
func validSurrogates(raw []byte) bool {
	for i := 1; i < len(raw)-1; i++ {
		if raw[i] != '\\' {
			continue
		}
		i++
		if i >= len(raw)-1 {
			return false
		}
		if raw[i] != 'u' {
			continue
		}
		if i+4 >= len(raw) {
			return false
		}
		n, e := strconv.ParseUint(string(raw[i+1:i+5]), 16, 16)
		if e != nil {
			return false
		}
		i += 4
		if n >= 0xdc00 && n <= 0xdfff {
			return false
		}
		if n >= 0xd800 && n <= 0xdbff {
			if i+6 >= len(raw) || raw[i+1] != '\\' || raw[i+2] != 'u' {
				return false
			}
			low, e := strconv.ParseUint(string(raw[i+3:i+7]), 16, 16)
			if e != nil || low < 0xdc00 || low > 0xdfff {
				return false
			}
			i += 6
		}
	}
	return true
}

func decode(body []byte, result bool) (Request, error) {
	var r Request
	if !result {
		var peek map[string]json.RawMessage
		if json.Unmarshal(body, &peek) == nil {
			var kind string
			json.Unmarshal(peek["message_type"], &kind)
			if kind == "image" || kind == "image_album" || kind == "audio" || kind == "video" {
				return decodeMediaSend(body)
			}
		}
	}
	if !utf8.Valid(body) {
		return r, ports.ErrInvalid
	}
	d := json.NewDecoder(bytes.NewReader(body))
	token, e := d.Token()
	if e != nil || token != json.Delim('{') {
		return r, ports.ErrInvalid
	}
	seen := map[string]bool{}
	for d.More() {
		token, e = d.Token()
		key, ok := token.(string)
		if e != nil || !ok || seen[key] {
			return r, ports.ErrInvalid
		}
		seen[key] = true
		var value string
		var raw json.RawMessage
		if d.Decode(&raw) != nil || len(raw) < 2 || raw[0] != '"' || json.Unmarshal(raw, &value) != nil || !validSurrogates(raw) {
			return r, ports.ErrInvalid
		}
		switch key {
		case "conversation_id":
			r.ConversationID = ports.ID(value)
		case "client_message_id":
			r.ClientMessageID = value
		case "message_type":
			if result {
				return r, ports.ErrInvalid
			}
			r.MessageType = value
		case "reply_to_message_id", "edited_from_recalled_message_id", "reply_to_item_id":
			if result || !validID(ports.ID(value)) {
				return r, ports.ErrInvalid
			}
			if key == "reply_to_item_id" {
				r.ReplyItem = ports.ID(value)
			} else if key == "reply_to_message_id" {
				r.ReplyTo = ports.ID(value)
			} else {
				r.EditedFrom = ports.ID(value)
			}
		case "text":
			if result {
				return r, ports.ErrInvalid
			}
			r.Text = value
		default:
			return r, ports.ErrInvalid
		}
	}
	if token, e = d.Token(); e != nil || token != json.Delim('}') {
		return r, ports.ErrInvalid
	}
	if _, e = d.Token(); e != io.EOF {
		return r, ports.ErrInvalid
	}
	if !validID(r.ConversationID) || !validClient(r.ClientMessageID) {
		return r, ports.ErrInvalid
	}
	if !result {
		_, e = fingerprint(r)
		if e != nil {
			return r, e
		}
	}
	return r, nil
}
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	var nonce [16]byte
	_, _ = rand.Read(nonce[:])
	id := hex.EncodeToString(nonce[:])
	w.Header().Set("X-Request-ID", id)
	fail := func(status int, code, message string, retry bool) {
		w.WriteHeader(status)
		if r.Method != "HEAD" {
			_ = json.NewEncoder(w).Encode(map[string]any{"request_id": id, "code": code, "message": message, "retryable": retry, "server_time": time.Now().UTC()})
		}
	}
	recall := r.URL.Path == "/chat/api/v1/messages/recall"
	result := r.URL.Path == "/chat/api/v1/messages/result"
	if !recall && !result && r.URL.Path != "/chat/api/v1/messages/send" {
		fail(404, "CHAT_ROUTE_NOT_FOUND", "接口未开放", false)
		return
	}
	if r.Method != "POST" {
		w.Header().Set("Allow", "POST")
		fail(405, "CHAT_METHOD_NOT_ALLOWED", "请求方法不支持", false)
		return
	}
	if r.URL.RawQuery != "" || r.URL.RawPath != "" {
		fail(400, "CHAT_INVALID_REQUEST", "请求参数不正确", false)
		return
	}
	content, _, e := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if e != nil || content != "application/json" {
		fail(415, "CHAT_CONTENT_TYPE_INVALID", "请使用JSON请求", false)
		return
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		fail(429, ports.ErrBusy.Error(), "请求较多，请稍后重试", true)
		return
	}
	headers := r.Header.Values("Authorization")
	if len(headers) != 1 || !strings.HasPrefix(headers[0], "Bearer ") || strings.ContainsAny(headers[0], "\r\n\t") {
		fail(401, "CHAT_UNAUTHORIZED", "登录状态已失效", false)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), h.service.timeout)
	defer cancel()
	controller := http.NewResponseController(w)
	_ = controller.SetReadDeadline(time.Now().Add(h.service.timeout))
	body, e := io.ReadAll(http.MaxBytesReader(w, r.Body, 32<<10))
	if e != nil {
		w.Header().Set("Connection", "close")
		var large *http.MaxBytesError
		if errors.As(e, &large) {
			fail(413, "CHAT_REQUEST_TOO_LARGE", "请求内容过大", false)
		} else {
			fail(408, "CHAT_REQUEST_TIMEOUT", "请求未及时完成", true)
		}
		return
	}
	_ = controller.SetReadDeadline(time.Time{})
	var req Request
	var target HistoryRequest
	if recall {
		target, e = decodeRecall(body)
	} else {
		req, e = decode(body, result)
	}
	if e != nil {
		fail(400, "CHAT_INVALID_REQUEST", "消息格式不正确", false)
		return
	}
	p, e := h.identity.Authenticate(ctx, strings.TrimPrefix(headers[0], "Bearer "))
	if e != nil {
		if errors.Is(e, auth.ErrUnauthorized) {
			fail(401, "CHAT_UNAUTHORIZED", "登录状态已失效", false)
		} else {
			fail(503, ports.ErrUnavailable.Error(), "身份服务暂不可用", true)
		}
		return
	}
	var data any
	if recall {
		data, e = h.service.Recall(ctx, p, target.ConversationID, target.TargetID)
	} else if result {
		data, e = h.service.Query(ctx, p, req.ConversationID, req.ClientMessageID)
	} else {
		data, e = h.service.Send(ctx, p, req)
	}
	if e != nil {
		switch {
		case errors.Is(e, ports.ErrInvalid):
			fail(400, "CHAT_INVALID_REQUEST", "消息格式不正确", false)
		case errors.Is(e, ErrReference), errors.Is(e, ErrReeditLimit), errors.Is(e, ErrRecall):
			fail(409, e.Error(), "消息来源或操作已不可用，请刷新后核对", false)
		case errors.Is(e, ErrRecallWindow):
			fail(410, e.Error(), "已超过两分钟撤回窗口", false)
		case errors.Is(e, ErrConflict):
			fail(409, e.Error(), "同一消息ID的内容不能更改", false)
		case errors.Is(e, ErrExpired):
			fail(410, e.Error(), "发送动作已过有效期，请先核对原结果", false)
		case errors.Is(e, ErrUnknown):
			fail(409, e.Error(), "暂未确认发送结果，请沿用原ID核验", true)
		case errors.Is(e, ErrRestricted):
			fail(403, e.Error(), "当前账号暂不能发送聊天消息", false)
		case errors.Is(e, policy.ErrDenied), errors.Is(e, auth.ErrUnauthorized):
			fail(403, "CHAT_PERMISSION_DENIED", "当前无法访问或发送此会话", false)
		case errors.Is(e, ports.ErrBusy):
			fail(429, e.Error(), "消息正在处理，请用原ID查询", true)
		case errors.Is(e, ErrCheck):
			fail(503, e.Error(), "内容检查暂不可用，请沿用原ID重试", true)
		default:
			fail(503, ports.ErrUnavailable.Error(), "结果暂未确认，请用原ID查询", true)
		}
		return
	}
	status := 200
	if outcome, ok := data.(Result); ok && (outcome.State == "checking" || outcome.State == "retryable" || outcome.State == "review_pending") {
		status = 202
	}
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "request_id": id, "data": data, "server_time": time.Now().UTC()})
}
