package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

type Restriction struct {
	Actor          ports.ID   `json:"actor_id"`
	Version        ports.ID   `json:"version"`
	Permanent      bool       `json:"permanent"`
	Until          *time.Time `json:"blocked_until,omitempty"`
	Blocked        bool       `json:"blocked"`
	ConfirmedRisks uint64     `json:"confirmed_risks"`
}
type RestrictionRequest struct {
	Actor    ports.ID `json:"actor_id"`
	Expected ports.ID `json:"expected_version"`
	Action   string   `json:"action"`
	Seconds  int      `json:"seconds,omitempty"`
	Reason   string   `json:"reason"`
}

func restrictionLocked(ctx context.Context, tx *sql.Tx, id ports.ID) (Restriction, error) {
	if _, err := tx.ExecContext(ctx, `INSERT INTO chat_restrictions(actor_id,version,updated_at) VALUES(?,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(id)); err != nil {
		return Restriction{}, ports.ErrUnavailable
	}
	out := Restriction{Actor: id}
	var until sql.NullTime
	if tx.QueryRowContext(ctx, `SELECT CAST(version AS CHAR),permanent,blocked_until,(permanent=1 OR blocked_until>UTC_TIMESTAMP(6)) IS TRUE FROM chat_restrictions WHERE actor_id=? FOR UPDATE`, string(id)).Scan(&out.Version, &out.Permanent, &until, &out.Blocked) != nil {
		return out, ports.ErrUnavailable
	}
	if until.Valid {
		v := until.Time
		out.Until = &v
	}
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_risk_events WHERE sender_id=? AND confirmed=1 AND review_state<>'corrected'`, string(id)).Scan(&out.ConfirmedRisks) != nil {
		return out, ports.ErrUnavailable
	}
	return out, nil
}
func (g *GovernanceService) Restriction(ctx context.Context, p ports.Principal, actor ports.ID) (Restriction, error) {
	if !validID(actor) {
		return Restriction{}, ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return Restriction{}, err
	}
	defer tx.Rollback()
	if p.Kind == "mini" && p.ActorID == actor && p.Environment == g.environment {
		err = g.source.ValidatePrincipalLocked(ctx, tx, p)
	} else {
		err = g.admin(ctx, tx, p, "chat:moderate")
	}
	if err != nil {
		return Restriction{}, err
	}
	out, err := restrictionLocked(ctx, tx, actor)
	if err != nil {
		return out, err
	}
	if err = g.commit(tx, p); err != nil {
		return out, err
	}
	return out, nil
}
func (g *GovernanceService) Restrict(ctx context.Context, p ports.Principal, q RestrictionRequest) (Restriction, error) {
	if !validID(q.Actor) || !q.Expected.Valid() || !reasonValid(q.Reason) || q.Action != "unban" && q.Action != "permanent" && q.Action != "timed" || q.Action == "timed" && (q.Seconds < 60 || q.Seconds > 30*86400) || q.Action != "timed" && q.Seconds != 0 {
		return Restriction{}, ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return Restriction{}, err
	}
	defer tx.Rollback()
	if err = g.admin(ctx, tx, p, "chat:moderate"); err != nil {
		return Restriction{}, err
	}
	var actor ports.ID
	if tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM users WHERE id=? LOCK IN SHARE MODE`, string(q.Actor)).Scan(&actor) != nil {
		return Restriction{}, auth.ErrUnauthorized
	}
	out, err := restrictionLocked(ctx, tx, q.Actor)
	if err != nil {
		return out, err
	}
	if out.Version != q.Expected {
		return out, ErrPreferenceConflict
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_restrictions SET permanent=?,blocked_until=IF(?='timed',TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),NULL),version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE actor_id=?`, q.Action == "permanent", q.Action, q.Seconds, string(q.Actor)); err != nil {
		return out, ports.ErrUnavailable
	}
	if err = governanceLog(ctx, tx, p, "restriction."+q.Action, "", q.Actor, "", decimal(number(out.Version)+1), q.Reason); err != nil {
		return out, err
	}
	out, err = restrictionLocked(ctx, tx, q.Actor)
	if err != nil {
		return out, err
	}
	if err = g.commit(tx, p); err != nil {
		return out, err
	}
	return out, nil
}

// Ban expiry uses the permanent stable row, exactly as send and manual unban.
func (g *GovernanceService) ExpireRestrictions(ctx context.Context) error {
	tx, err := g.base.begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	rows, err := tx.QueryContext(ctx, `SELECT CAST(actor_id AS CHAR) FROM chat_restrictions WHERE permanent=0 AND blocked_until<=UTC_TIMESTAMP(6) ORDER BY actor_id LIMIT 50 FOR UPDATE`)
	if err != nil {
		return ports.ErrUnavailable
	}
	ids := []ports.ID{}
	for rows.Next() {
		var id ports.ID
		if rows.Scan(&id) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		ids = append(ids, id)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	for _, id := range ids {
		if _, err = tx.ExecContext(ctx, `INSERT INTO chat_governance_audit(actor_kind,actor_id,session_binding,action,target_id,target_version,reason,created_at) SELECT 'system',0,REPEAT('0',64),'restriction.expire',actor_id,version+1,'限时禁聊到期',UTC_TIMESTAMP(6) FROM chat_restrictions WHERE actor_id=?`, string(id)); err != nil {
			return ports.ErrUnavailable
		}
		if _, err = tx.ExecContext(ctx, `UPDATE chat_restrictions SET blocked_until=NULL,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE actor_id=?`, string(id)); err != nil {
			return ports.ErrUnavailable
		}
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
