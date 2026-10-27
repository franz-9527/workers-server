package dependencies

import (
	"bytes"
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"net/http"
	"net/url"
	"time"
)

type ReminderHTTP struct {
	transport     *HTTP
	signer        *auth.InternalAuth
	endpoint, kid string
}

func NewReminderHTTP(h *HTTP, signer *auth.InternalAuth, endpoint, kid string) (*ReminderHTTP, error) {
	u, err := url.Parse(endpoint)
	if err != nil || h == nil || signer == nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Path != "/chat-internal.php" {
		return nil, ports.ErrInvalid
	}
	return &ReminderHTTP{h, signer, endpoint, kid}, nil
}
func (h *ReminderHTTP) call(ctx context.Context, q map[string]string) (ports.Receipt, error) {
	raw, _ := json.Marshal(q)
	req, err := http.NewRequestWithContext(ctx, "POST", h.endpoint, bytes.NewReader(raw))
	if err != nil {
		return ports.Receipt{}, ports.ErrInvalid
	}
	req.Header.Set("Content-Type", "application/json")
	if err = h.signer.Sign(req, raw, h.kid, time.Now()); err != nil {
		return ports.Receipt{}, err
	}
	body, status, err := h.transport.Do(ctx, req)
	if err != nil || status != 200 {
		return ports.Receipt{}, ports.ErrUnavailable
	}
	var result struct {
		Operation  string `json:"operation_id"`
		State      string `json:"state"`
		Diagnostic string `json:"diagnostic"`
	}
	if json.Unmarshal(body, &result) != nil || result.Operation != q["operation_id"] {
		return ports.Receipt{}, ports.ErrUnavailable
	}
	switch result.State {
	case "accepted", "sent", "suppressed", "channel_limited", "provider_unknown":
	default:
		return ports.Receipt{}, ports.ErrUnavailable
	}
	if len(result.Diagnostic) > 64 {
		return ports.Receipt{}, ports.ErrUnavailable
	}
	return ports.Receipt{OperationID: result.Operation, State: result.State, Diagnostic: result.Diagnostic}, nil
}
func (h *ReminderHTTP) Submit(ctx context.Context, q ports.Reminder) (ports.Receipt, error) {
	if !q.IntentID.Valid() || !q.RecipientID.Valid() || !q.ConversationID.Valid() || !q.TargetSeq.Valid() || !q.ClusterVersion.Valid() || len(q.OperationID) != 64 {
		return ports.Receipt{}, ports.ErrInvalid
	}
	return h.call(ctx, map[string]string{"intent_id": string(q.IntentID), "recipient_id": string(q.RecipientID), "conversation_id": string(q.ConversationID), "target_seq": string(q.TargetSeq), "cluster_version": string(q.ClusterVersion), "operation_id": q.OperationID})
}
func (h *ReminderHTTP) Status(ctx context.Context, op string) (ports.Receipt, error) {
	if len(op) != 64 {
		return ports.Receipt{}, ports.ErrInvalid
	}
	return h.call(ctx, map[string]string{"operation_id": op})
}
