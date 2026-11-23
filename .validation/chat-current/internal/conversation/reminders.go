package conversation

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Server-side only: not an impersonation API and never grants send permission.
func (s *Store) RecipientLocked(ctx context.Context, tx *sql.Tx, cid, actor ports.ID) (ports.Grant, error) {
	return s.recipientLocked(ctx, tx, cid, actor, false)
}

func (s *Store) ReminderRecipientLocked(ctx context.Context, tx *sql.Tx, cid, actor ports.ID) (ports.Grant, error) {
	return s.recipientLocked(ctx, tx, cid, actor, true)
}

func (s *Store) recipientLocked(ctx context.Context, tx *sql.Tx, cid, actor ports.ID, reminder bool) (ports.Grant, error) {
	if !positive(cid) || !positive(actor) {
		return ports.Grant{}, policy.ErrDenied
	}
	var scope, kind string
	err := tx.QueryRowContext(ctx, `SELECT scope_key,conversation_type FROM chat_conversations WHERE id=?`, string(cid)).Scan(&scope, &kind)
	if err == sql.ErrNoRows {
		return ports.Grant{}, policy.ErrDenied
	}
	if err != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	binding, ok := ports.ParseScope(scope)
	if !ok || binding.Scene != kind || reminder && binding.Layout == "sandbox" {
		return ports.Grant{}, policy.ErrDenied
	}
	var projection policy.Projection
	var grant ports.Grant
	switch {
	case kind == ports.OrderScene:
		projection, grant, err = s.reader.RecipientLocked(ctx, tx, scope, actor)
	case reminder && kind == ports.DirectScene:
		projection, grant, err = policy.DirectReminderRecipient(ctx, tx, scope, actor)
	case reminder && kind == ports.SupportScene:
		projection, grant, err = policy.SupportReminderRecipient(ctx, tx, scope, actor)
	default:
		return ports.Grant{}, policy.ErrDenied
	}
	if err != nil {
		return ports.Grant{}, err
	}
	grant.ConversationType = projection.ConversationType
	if reminder {
		grant.CanSend = false
	}
	authority := &Authority{tx: tx, request: ports.PolicyRequest{Actor: ports.Principal{ActorID: actor, ExpiresAt: time.Now().Add(s.timeout)}, ConversationID: cid, Action: "read"}, projection: projection, grant: grant}
	return s.LockProjection(ctx, tx, authority)
}
