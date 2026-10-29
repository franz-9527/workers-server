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
	if !positive(cid) || !positive(actor) {
		return ports.Grant{}, policy.ErrDenied
	}
	var scope, kind string
	if tx.QueryRowContext(ctx, `SELECT scope_key,conversation_type FROM chat_conversations WHERE id=?`, string(cid)).Scan(&scope, &kind) != nil || kind != "order" {
		return ports.Grant{}, policy.ErrDenied
	}
	projection, grant, err := s.reader.RecipientLocked(ctx, tx, scope, actor)
	if err != nil {
		return ports.Grant{}, err
	}
	authority := &Authority{tx: tx, request: ports.PolicyRequest{Actor: ports.Principal{ActorID: actor, ExpiresAt: time.Now().Add(s.timeout)}, ConversationID: cid, Action: "read"}, projection: projection, grant: grant}
	return s.LockProjection(ctx, tx, authority)
}
