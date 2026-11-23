package policy

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type directAccess struct{}

func WithDirect(ctx context.Context) context.Context {
	return context.WithValue(ctx, directAccess{}, true)
}

func DirectOnly(ctx context.Context) bool { return ctx.Value(directAccess{}) == true }

func DecodeDirect(body []byte, scope string, version ports.ID) (Projection, error) {
	const schema = 5
	var wire struct {
		Schema      int      `json:"schema_version"`
		Scope       string   `json:"scope_key"`
		First       ports.ID `json:"first"`
		Second      ports.ID `json:"second"`
		Requester   ports.ID `json:"requester"`
		Application string   `json:"application"`
		Blocked     bool     `json:"blocked"`
	}
	binding, ok := ports.ParseScope(scope)
	if !ok || binding.Scene != ports.DirectScene || !positive(version) || len(body) > 1024 ||
		!object(body, &wire, "schema_version", "scope_key", "first", "second", "requester", "application", "blocked") ||
		wire.Schema != schema || wire.Scope != scope || wire.First != binding.First || wire.Second != binding.Second ||
		(wire.Requester != wire.First && wire.Requester != wire.Second) ||
		(wire.Application != "pending" && wire.Application != "accepted" && wire.Application != "rejected") {
		return Projection{}, ErrDenied
	}
	// application is retained only for backward-compatible snapshot decoding.
	// Direct no longer requires request/accept/reject; only an explicit block makes it readonly.
	state := "open"
	if wire.Blocked {
		state = "readonly"
	}
	sum := sha256.Sum256(body)
	projection := Projection{ConversationType: ports.DirectScene, Mode: "private", Scope: scope, Episode: scope, State: state, Version: version, Hash: hex.EncodeToString(sum[:])}
	for _, actor := range []ports.ID{wire.First, wire.Second} {
		grant := ports.Grant{
			ConversationType: ports.DirectScene, Mode: "private", ScopeKey: scope, Version: version, MemberRole: "direct_member",
			MemberKey: scope + ":" + string(actor), CanRead: true,
			CanSend:        state == "open",
			DirectAccepted: !wire.Blocked,
		}
		projection.Members = append(projection.Members, ProjectedMember{ActorID: actor, Role: grant.MemberRole, Grant: grant})
	}
	return projection, nil
}

func DirectSnapshot(ctx context.Context, tx *sql.Tx, scope string) (Projection, error) {
	var body, hash string
	var version ports.ID
	err := tx.QueryRowContext(ctx, `SELECT snapshot_json,snapshot_hash,CAST(policy_version AS CHAR) FROM chat_direct_guards
		WHERE scope_key=? LOCK IN SHARE MODE`, scope).Scan(&body, &hash, &version)
	if err == sql.ErrNoRows {
		return Projection{}, ErrDenied
	}
	if err != nil {
		return Projection{}, ports.ErrUnavailable
	}
	projection, err := DecodeDirect([]byte(body), scope, version)
	if err != nil || projection.Hash != hash {
		return Projection{}, ErrDenied
	}
	return projection, nil
}

func (r *Reader) DirectAuthority(ctx context.Context, tx *sql.Tx, scope string, actor ports.Principal, action string) (Projection, ports.Grant, error) {
	if _, sandbox := ports.SandboxSource(actor.SourceID); sandbox || !r.actor(actor, time.Now()) {
		return Projection{}, ports.Grant{}, ErrDenied
	}
	var enabled bool
	err := tx.QueryRowContext(ctx, `SELECT enabled FROM chat_direct_release WHERE id=1 LOCK IN SHARE MODE`).Scan(&enabled)
	if err != nil {
		return Projection{}, ports.Grant{}, ports.ErrUnavailable
	}
	if !enabled {
		return Projection{}, ports.Grant{}, ErrDenied
	}
	projection, err := DirectSnapshot(ctx, tx, scope)
	if err != nil {
		return Projection{}, ports.Grant{}, err
	}
	for _, member := range projection.Members {
		if member.ActorID == actor.ActorID && (action == "read" || member.Grant.CanSend) {
			if err = activeMiniIdentity(ctx, tx, actor.ActorID); err != nil {
				return Projection{}, ports.Grant{}, err
			}
			if action == "send" {
				for _, peer := range projection.Members {
					if peer.ActorID == actor.ActorID {
						continue
					}
					var active string
					err = tx.QueryRowContext(ctx, `SELECT status FROM users WHERE id=? LOCK IN SHARE MODE`, string(peer.ActorID)).Scan(&active)
					if err == sql.ErrNoRows || err == nil && active != "active" {
						return Projection{}, ports.Grant{}, ErrDenied
					}
					if err != nil {
						return Projection{}, ports.Grant{}, ports.ErrUnavailable
					}
					if err = activeMiniIdentity(ctx, tx, peer.ActorID); err != nil {
						return Projection{}, ports.Grant{}, err
					}
				}
			}
			return projection, member.Grant, nil
		}
	}
	return Projection{}, ports.Grant{}, ErrDenied
}

func activeMiniIdentity(ctx context.Context, tx *sql.Tx, actor ports.ID) error {
	rows, err := tx.QueryContext(ctx, `SELECT CAST(w.id AS CHAR) FROM wechat_identities w
		JOIN system_settings cfg ON cfg.setting_key='wechat.miniapp_appid' AND BINARY w.app_id=BINARY cfg.setting_value
		WHERE w.user_id=? AND w.status='active' LIMIT 2 LOCK IN SHARE MODE`, string(actor))
	if err != nil {
		return ports.ErrUnavailable
	}
	defer rows.Close()
	count := 0
	for rows.Next() {
		count++
	}
	if rows.Err() != nil {
		return ports.ErrUnavailable
	}
	if count != 1 {
		return ErrDenied
	}
	return nil
}

// Direct reminders require a currently enabled, unblocked relationship.
func DirectReminderRecipient(ctx context.Context, tx *sql.Tx, scope string, actor ports.ID) (Projection, ports.Grant, error) {
	var enabled bool
	err := tx.QueryRowContext(ctx, `SELECT enabled FROM chat_direct_release WHERE id=1 LOCK IN SHARE MODE`).Scan(&enabled)
	if err != nil {
		return Projection{}, ports.Grant{}, ports.ErrUnavailable
	}
	if !enabled {
		return Projection{}, ports.Grant{}, ErrDenied
	}
	p, err := DirectSnapshot(ctx, tx, scope)
	if err != nil {
		return Projection{}, ports.Grant{}, err
	}
	var grant ports.Grant
	for _, member := range p.Members {
		if !member.Grant.DirectAccepted {
			return Projection{}, ports.Grant{}, ErrDenied
		}
		if err = activeReminderIdentity(ctx, tx, member.ActorID); err != nil {
			return Projection{}, ports.Grant{}, err
		}
		if member.ActorID == actor {
			grant = member.Grant
		}
	}
	if !grant.CanRead {
		return Projection{}, ports.Grant{}, ErrDenied
	}
	return p, grant, nil
}

func activeReminderIdentity(ctx context.Context, tx *sql.Tx, actor ports.ID) error {
	var state string
	err := tx.QueryRowContext(ctx, `SELECT status FROM users WHERE id=? LOCK IN SHARE MODE`, string(actor)).Scan(&state)
	if err == sql.ErrNoRows || err == nil && state != "active" {
		return ErrDenied
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	return activeMiniIdentity(ctx, tx, actor)
}