package message

import (
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"net/http"
	"time"
)

type ReminderInternalHandler struct {
	service *ReminderService
	auth    *auth.InternalAuth
	gate    chan struct{}
}

func NewReminderInternalHandler(s *ReminderService, a *auth.InternalAuth) (*ReminderInternalHandler, error) {
	if s == nil || a == nil {
		return nil, ports.ErrInvalid
	}
	return &ReminderInternalHandler{s, a, make(chan struct{}, 8)}, nil
}
func (h *ReminderInternalHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	fail := func(status int) { w.WriteHeader(status); w.Write([]byte("{\"code\":\"CHAT_REMINDER_UNAVAILABLE\"}")) }
	if r.Method != "POST" || r.URL.Path != "/chat/internal/v1/reminders/eligible" || r.URL.RawQuery != "" || r.URL.RawPath != "" {
		fail(404)
		return
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		fail(429)
		return
	}
	raw, err := io.ReadAll(io.LimitReader(r.Body, 1025))
	if err != nil || len(raw) > 1024 {
		fail(400)
		return
	}
	if err = h.auth.Verify(r.Context(), r, raw, time.Now()); err != nil {
		fail(401)
		return
	}
	var q struct {
		Operation string `json:"operation_id"`
	}
	if !strictImageJSON(raw, &q) || len(q.Operation) != 64 {
		fail(400)
		return
	}
	allowed, err := h.service.Eligible(r.Context(), q.Operation)
	if err != nil {
		fail(503)
		return
	}
	json.NewEncoder(w).Encode(map[string]bool{"eligible": allowed})
}
