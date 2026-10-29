package message

import (
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
)

type governanceAction struct {
 Order ports.ID `json:"order_id,omitempty"`
 Query string `json:"query,omitempty"`
 Kind string `json:"kind,omitempty"`
 From string `json:"from,omitempty"`
 To string `json:"to,omitempty"`
 Replace string `json:"replace_cursor,omitempty"`
	Conversation ports.ID `json:"conversation_id,omitempty"`
	Message      ports.ID `json:"message_id,omitempty"`
	Item         ports.ID `json:"item_id,omitempty"`
	Case         ports.ID `json:"case_id,omitempty"`
	Target       ports.ID `json:"target_id,omitempty"`
	Actor        ports.ID `json:"actor_id,omitempty"`
	Expected     ports.ID `json:"expected_version,omitempty"`
	Cursor       string   `json:"cursor,omitempty"`
	After        ports.ID `json:"after,omitempty"`
	Limit        int      `json:"limit,omitempty"`
	Seconds      int      `json:"seconds,omitempty"`
	Reason       string   `json:"reason,omitempty"`
	Action       string   `json:"action,omitempty"`
	TargetType   string   `json:"target_type,omitempty"`
	Thumbnail    bool     `json:"thumbnail,omitempty"`
}
type GovernanceHandler struct {
	service  *GovernanceService
	identity ports.Identity
	gate     chan struct{}
}

func NewGovernanceHandler(g *GovernanceService, id ports.Identity, concurrency int) (*GovernanceHandler, error) {
	if g == nil || id == nil || concurrency < 1 || concurrency > 16 {
		return nil, ports.ErrInvalid
	}
	return &GovernanceHandler{g, id, make(chan struct{}, concurrency)}, nil
}
func (h *GovernanceHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	fail := func(status int, code string) {
		w.WriteHeader(status)
		json.NewEncoder(w).Encode(map[string]any{"code": code, "message": "操作未完成，请刷新状态后重试", "retryable": status >= 500})
	}
	routes := map[string]string{
		"audit/search": "conversation_id order_id actor_id query kind from to cursor replace_cursor limit",
  "audit/av":   "conversation_id message_id",
		"audit/list": "cursor limit", "audit/history": "conversation_id cursor message_id limit", "audit/url": "conversation_id message_id item_id thumbnail",
		"governance/reminders": "after", "governance/status": "actor_id", "governance/restrict": "actor_id expected_version action seconds reason", "governance/report": "conversation_id message_id item_id reason",
		"governance/appeal": "target_type target_id expected_version reason", "governance/cases": "after", "governance/queue": "after",
		"governance/evidence": "case_id expected_version item_id reason", "governance/decide": "case_id expected_version action reason",
	}
	route := strings.TrimPrefix(r.URL.Path, "/chat/api/v1/")
	fields, ok := routes[route]
	if !ok || r.URL.Path != "/chat/api/v1/"+route {
		fail(404, "CHAT_ROUTE_NOT_FOUND")
		return
	}
	if r.Method != "POST" {
		w.Header().Set("Allow", "POST")
		fail(405, "CHAT_METHOD_NOT_ALLOWED")
		return
	}
	content, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil || content != "application/json" || r.URL.RawQuery != "" || r.URL.RawPath != "" {
		fail(400, "CHAT_INVALID_REQUEST")
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
	p, err := h.identity.Authenticate(r.Context(), strings.TrimPrefix(headers[0], "Bearer "))
	if err != nil {
		fail(401, "CHAT_UNAUTHORIZED")
		return
	}
	raw, err := io.ReadAll(io.LimitReader(r.Body, 8193))
	if err != nil || len(raw) > 8192 {
		fail(400, "CHAT_INVALID_REQUEST")
		return
	}
	var q governanceAction
	var keys map[string]json.RawMessage
	if !strictImageJSON(raw, &q) || json.Unmarshal(raw, &keys) != nil {
		fail(400, "CHAT_INVALID_REQUEST")
		return
	}
	allowed := map[string]bool{}
	for _, v := range strings.Fields(fields) {
		allowed[v] = true
	}
	for k, v := range keys {
		if !allowed[k] || string(v) == "null" {
			fail(400, "CHAT_INVALID_REQUEST")
			return
		}
	}
	var data any
	switch route {
	case "audit/search":
  data, err = h.service.AuditSearch(r.Context(),p,AuditFilter{Conversation:q.Conversation,Order:q.Order,Actor:q.Actor,Query:q.Query,Kind:q.Kind,From:q.From,To:q.To},q.Cursor,q.Replace,q.Limit)
 case "audit/list":
		data, err = h.service.AuditList(r.Context(), p, q.Cursor, q.Limit)
	case "audit/history":
		data, err = h.service.AuditHistory(r.Context(), p, HistoryRequest{ConversationID: q.Conversation, Cursor: q.Cursor, TargetID: q.Message, Limit: q.Limit})
	case "audit/av":
		data, err = h.service.AuditAV(r.Context(), p, q.Conversation, q.Message)
	case "audit/url":
		var url string
		url, err = h.service.AuditImage(r.Context(), p, q.Conversation, q.Message, q.Item, q.Thumbnail)
		data = map[string]any{"url": url, "expires_in": 60}
	case "governance/reminders":
		data, err = h.service.ReminderSummaries(r.Context(), p, q.After)
	case "governance/status":
		if q.Actor == "" {
			q.Actor = p.ActorID
		}
		data, err = h.service.Restriction(r.Context(), p, q.Actor)
	case "governance/restrict":
		data, err = h.service.Restrict(r.Context(), p, RestrictionRequest{Actor: q.Actor, Expected: q.Expected, Action: q.Action, Seconds: q.Seconds, Reason: q.Reason})
	case "governance/report":
		var id ports.ID
		id, err = h.service.Report(r.Context(), p, q.Conversation, q.Message, q.Item, q.Reason)
		data = map[string]any{"case_id": id}
	case "governance/appeal":
		var id ports.ID
		id, err = h.service.Appeal(r.Context(), p, q.TargetType, q.Target, q.Expected, q.Reason)
		data = map[string]any{"case_id": id}
	case "governance/cases", "governance/queue":
		data, err = h.service.Cases(r.Context(), p, q.After, route == "governance/queue")
	case "governance/evidence":
		data, err = h.service.Evidence(r.Context(), p, q.Case, q.Expected, q.Item, q.Reason)
	case "governance/decide":
		data, err = h.service.Decide(r.Context(), p, CaseDecision{ID: q.Case, Expected: q.Expected, Action: q.Action, Reason: q.Reason})
	}
	if err != nil {
		status := 503
		switch {
		case errors.Is(err, auth.ErrUnauthorized):
			status = 401
		case errors.Is(err, policy.ErrDenied):
			status = 403
		case errors.Is(err, ports.ErrInvalid):
			status = 400
		case errors.Is(err, ports.ErrBusy):
			status = 429
		case errors.Is(err, ErrResync), errors.Is(err, ErrMediaVersion), errors.Is(err, ErrPreferenceConflict), errors.Is(err, ErrMedia):
			status = 409
		}
		fail(status, err.Error())
		return
	}
	json.NewEncoder(w).Encode(map[string]any{"data": data, "server_time": time.Now().UTC()})
}
