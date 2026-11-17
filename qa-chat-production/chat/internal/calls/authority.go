package calls

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/conversation"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

type Authority interface {
	Pair(context.Context, *sql.Tx, ports.Principal, ports.ID, ports.ID, bool) (ports.Grant, ports.Grant, error)
	Validate(context.Context, ports.Principal) error
}
type OrderAuthority struct {
	store    *conversation.Store
	reader   *policy.Reader
	identity *auth.MySQLSource
}

func NewOrderAuthority(db *sql.DB, store *conversation.Store, env string) (*OrderAuthority, error) {
	r, e := policy.NewReader(env, 3*time.Second)
	if e != nil {
		return nil, e
	}
	i, e := auth.NewMySQLSource(db)
	if e != nil {
		return nil, e
	}
	return &OrderAuthority{store, r, i}, nil
}
func (g *OrderAuthority) Validate(ctx context.Context, p ports.Principal) error {
	return g.store.ValidatePrincipal(ctx, p)
}
func (g *OrderAuthority) Pair(ctx context.Context, tx *sql.Tx, p ports.Principal, cid, peer ports.ID, send bool) (ports.Grant, ports.Grant, error) {
	action := "read"
	if send {
		action = "send"
	}
	a, e := g.store.LockAuthority(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: action})
	if e != nil {
		return ports.Grant{}, ports.Grant{}, e
	}
	var scope string
	if e = tx.QueryRowContext(ctx, "SELECT scope_key FROM chat_conversations WHERE id=?", string(cid)).Scan(&scope); e != nil {
		return ports.Grant{}, ports.Grant{}, e
	}
	_, other, e := g.reader.RecipientLocked(ctx, tx, scope, peer)
	if e != nil {
		return ports.Grant{}, ports.Grant{}, e
	}
	if !other.CanRead || (send && !other.CanSend) {
		return ports.Grant{}, ports.Grant{}, policy.ErrDenied
	}
	ids := []ports.ID{p.ActorID, peer}
	if lessID(ids[1], ids[0]) {
		ids[0], ids[1] = ids[1], ids[0]
	}
	if send {
		for _, id := range ids {
			if _, e = tx.ExecContext(ctx, "INSERT INTO chat_restrictions(actor_id,permanent,version,updated_at) VALUES(?,0,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=actor_id", string(id)); e != nil {
				return ports.Grant{}, ports.Grant{}, e
			}
			var blocked bool
			if e = tx.QueryRowContext(ctx, "SELECT permanent=1 OR (blocked_until IS NOT NULL AND blocked_until>UTC_TIMESTAMP(6)) FROM chat_restrictions WHERE actor_id=? FOR UPDATE", string(id)).Scan(&blocked); e != nil {
				return ports.Grant{}, ports.Grant{}, e
			}
			if blocked {
				return ports.Grant{}, ports.Grant{}, policy.ErrDenied
			}
		}
	}
	own, e := g.store.LockProjection(ctx, tx, a)
	if e != nil {
		return own, other, e
	}
	var key string
	var readable, writable bool
	e = tx.QueryRowContext(ctx, "SELECT member_key,can_read,can_send FROM chat_members WHERE conversation_id=? AND actor_id=? LOCK IN SHARE MODE", string(cid), string(peer)).Scan(&key, &readable, &writable)
	if e != nil || key != other.MemberKey || !readable || (send && !writable) {
		return own, other, policy.ErrDenied
	}
	return own, other, nil
}
func lessID(a, b ports.ID) bool { return len(a) < len(b) || (len(a) == len(b) && a < b) }
