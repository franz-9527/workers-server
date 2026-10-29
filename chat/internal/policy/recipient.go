package policy

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// Internal notification eligibility uses current PHP business authority and
// account status. It does not fabricate a logged-in user or online presence.
func (r *Reader) RecipientLocked(ctx context.Context, tx *sql.Tx, scope string, actor ports.ID) (Projection, ports.Grant, error) {
	p, err := r.CurrentLocked(ctx, tx, scope)
	if err != nil {
		return p, ports.Grant{}, err
	}
	if err = r.CheckEpochsLocked(ctx, tx, p, actor, "read"); err != nil {
		return p, ports.Grant{}, err
	}
	var active bool
	err = tx.QueryRowContext(ctx, `SELECT status='active' FROM users WHERE id=? LOCK IN SHARE MODE`, string(actor)).Scan(&active)
	if err == sql.ErrNoRows {
		return p, ports.Grant{}, ErrDenied
	}
	if err != nil {
		return p, ports.Grant{}, ports.ErrUnavailable
	}
	if !active {
		return p, ports.Grant{}, ErrDenied
	}
	for _, m := range p.Members {
		if m.ActorID == actor && m.Grant.CanRead {
			return p, m.Grant, nil
		}
	}
	return p, ports.Grant{}, ErrDenied
}
