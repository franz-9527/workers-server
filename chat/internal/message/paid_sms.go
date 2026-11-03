package message

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"net/http"
	"strconv"
	"time"
)

// Only the authenticated PHP service can query other members' read watermarks.
// No body, phone number or read acknowledgement crosses this boundary.
type SMSCandidate struct {
	RecipientID   ports.ID `json:"recipient_id"`
	MessageID     ports.ID `json:"message_id"`
	PolicyVersion ports.ID `json:"policy_version"`
	Wave          string   `json:"wave"`
}

func (s *Service) PaidSMSCandidates(ctx context.Context, payer, cid, recipient, message ports.ID) ([]SMSCandidate, error) {
	out := []SMSCandidate{}
	if !validID(payer) || !validID(cid) || (recipient != "" && !validID(recipient)) || (message != "" && !validID(message)) {
		return nil, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	actors, e := s.conversations.MemberCandidates(ctx, cid)
	if e != nil {
		return nil, e
	}
	if len(actors) > 64 {
		return nil, ports.ErrInvalid
	}
	// Each pair owns a short transaction, following the existing PHP guard then projection order.
	for _, actor := range actors {
		if actor == payer || (recipient != "" && actor != recipient) {
			continue
		}
		tx, e := s.begin(ctx)
		if e != nil {
			return nil, e
		}
		sender, e := s.conversations.RecipientLocked(ctx, tx, cid, payer)
		if e != nil {
			tx.Rollback()
			return nil, e
		}
		if !sender.CanSend {
			tx.Rollback()
			return out, nil
		}
		var blocked bool
		e = tx.QueryRowContext(ctx, `SELECT permanent=1 OR (blocked_until IS NOT NULL AND blocked_until>UTC_TIMESTAMP(6)) FROM chat_restrictions WHERE actor_id=? LOCK IN SHARE MODE`, string(payer)).Scan(&blocked)
		if e != nil && e != sql.ErrNoRows {
			tx.Rollback()
			return nil, ports.ErrUnavailable
		}
		if blocked {
			tx.Rollback()
			return out, nil
		}
		grant, e := s.conversations.RecipientLocked(ctx, tx, cid, actor)
		if errors.Is(e, policy.ErrDenied) {
			tx.Rollback()
			continue
		}
		if e != nil {
			tx.Rollback()
			return nil, e
		}
		if grant.MemberRole == "platform_staff" {
			tx.Rollback()
			continue
		}
		var read uint64
		var hash string
		e = tx.QueryRowContext(ctx, `SELECT member_key_hash,read_seq FROM chat_member_reads WHERE conversation_id=? AND actor_id=? LOCK IN SHARE MODE`, string(cid), string(actor)).Scan(&hash, &read)
		if e != nil && e != sql.ErrNoRows {
			tx.Rollback()
			return nil, ports.ErrUnavailable
		}
		if hash != memberHash(grant.MemberKey) {
			read = 0
		}
		predicate := `m.conversation_id=? AND m.sender_id=? AND m.seq>? AND m.state='published' AND m.created_at<=UTC_TIMESTAMP(6)-INTERVAL 5 MINUTE AND (m.expires_at IS NULL OR m.expires_at>UTC_TIMESTAMP(6)) AND ` + visibleColumn
		args := []any{string(cid), string(payer), read, string(grant.Version), string(actor)}
		if message != "" {
			predicate += " AND m.id=?"
			args = append(args, string(message))
		}
		var id ports.ID
		e = tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR) FROM chat_messages m WHERE `+predicate+` ORDER BY m.seq LIMIT 1`, args...).Scan(&id)
		if e != nil && e != sql.ErrNoRows {
			tx.Rollback()
			return nil, ports.ErrUnavailable
		}
		if e == nil {
			sum := sha256.Sum256([]byte(string(cid) + ":" + string(payer) + ":" + string(actor) + ":" + sender.MemberKey + ":" + grant.MemberKey + ":" + strconv.FormatUint(read, 10)))
			out = append(out, SMSCandidate{actor, id, grant.Version, hex.EncodeToString(sum[:])})
		}
		if tx.Commit() != nil {
			return nil, ports.ErrUnavailable
		}
	}
	return out, nil
}

type PaidSMSInternalHandler struct {
	base *Service
	auth *auth.InternalAuth
	gate chan struct{}
}

func NewPaidSMSInternalHandler(s *Service, a *auth.InternalAuth) (*PaidSMSInternalHandler, error) {
	if s == nil || a == nil {
		return nil, ports.ErrInvalid
	}
	return &PaidSMSInternalHandler{s, a, make(chan struct{}, 4)}, nil
}
func (h *PaidSMSInternalHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	fail := func(code int) { w.WriteHeader(code); _, _ = w.Write([]byte(`{"code":"CHAT_SMS_UNAVAILABLE"}`)) }
	if r.Method != "POST" || r.URL.RequestURI() != "/chat/internal/v1/sms/eligible" {
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
	raw, e := io.ReadAll(io.LimitReader(r.Body, 1025))
	if e != nil || len(raw) > 1024 {
		fail(400)
		return
	}
	if h.auth.Verify(r.Context(), r, raw, time.Now()) != nil {
		fail(401)
		return
	}
	var q struct {
		Payer        ports.ID `json:"payer_id"`
		Conversation ports.ID `json:"conversation_id"`
		Recipient    ports.ID `json:"recipient_id,omitempty"`
		Message      ports.ID `json:"message_id,omitempty"`
	}
	if !strictImageJSON(raw, &q) {
		fail(400)
		return
	}
	rows, e := h.base.PaidSMSCandidates(r.Context(), q.Payer, q.Conversation, q.Recipient, q.Message)
	if e != nil {
		fail(503)
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"items": rows})
}
