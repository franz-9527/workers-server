package policy

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strconv"
	"strings"
)

// Only PHP-created testing rooms supply these facts. No order guard, payment,
// ordinary login or production policy is inferred or modified.
func (r *Reader) sandboxLocked(ctx context.Context, tx *sql.Tx, scope string) (Projection, error) {
	if r.environment != "test" || !strings.HasPrefix(scope, "sandbox:") {
		return Projection{}, ErrDenied
	}
	id := ports.ID(strings.TrimPrefix(scope, "sandbox:"))
	n, err := strconv.ParseUint(string(id), 10, 64)
	if !positive(id) || err != nil || n >= 100000000000000000 {
		return Projection{}, ErrDenied
	}
	var title string
	var revision ports.ID
	err = tx.QueryRowContext(ctx, "SELECT title,CAST(revision AS CHAR) FROM chat_sandbox_rooms WHERE id=? LOCK IN SHARE MODE", string(id)).Scan(&title, &revision)
	if err == sql.ErrNoRows {
		return Projection{}, ErrDenied
	}
	if err != nil {
		return Projection{}, ports.ErrUnavailable
	}
	rows, err := tx.QueryContext(ctx, "SELECT actor_key,wire_id,display_role FROM chat_sandbox_members WHERE room_id=? ORDER BY actor_key LOCK IN SHARE MODE", string(id))
	if err != nil {
		return Projection{}, ports.ErrUnavailable
	}
	type entry struct{ key, wire, role string }
	members := []entry{}
	for rows.Next() {
		var m entry
		if rows.Scan(&m.key, &m.wire, &m.role) != nil {
			rows.Close()
			return Projection{}, ports.ErrUnavailable
		}
		members = append(members, m)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return Projection{}, ports.ErrUnavailable
	}
	if len(members) < 2 || len(members) > 20 {
		return Projection{}, ErrDenied
	}
	binding := ports.ID(strconv.FormatUint(ports.SandboxBindingBase+n, 10))
	p := Projection{Mode: "sandbox", Scope: scope, Episode: scope + ":v1", State: "open", OrderID: binding, AssignmentID: binding, Version: revision}
	fingerprint := scope + "\n" + title
	mode := "direct"
	if len(members) > 2 {
		mode = "collaboration"
	}
	for _, m := range members {
		actor := ports.ID(m.wire)
		if !positive(actor) {
			return Projection{}, ErrDenied
		}
		var active int
		if uid, ok := ports.SandboxMiniAdminUser(m.key, actor); ok && m.role == "admin" {
			err = tx.QueryRowContext(ctx, "SELECT 1 FROM users WHERE id=? AND status='active' LOCK IN SHARE MODE", string(uid)).Scan(&active)
		} else if strings.HasPrefix(m.key, "admin:") {
			uid := ports.ID(strings.TrimPrefix(m.key, "admin:"))
			if !positive(uid) || m.role != "admin" {
				return Projection{}, ErrDenied
			}
			err = tx.QueryRowContext(ctx, "SELECT 1 FROM admin_users WHERE id=? AND status IN ('active','1') AND must_change_password=0 LOCK IN SHARE MODE", string(uid)).Scan(&active)
		} else if strings.HasPrefix(m.key, "user:") {
			if m.key != "user:"+m.wire || (m.role != "customer" && m.role != "worker") {
				return Projection{}, ErrDenied
			}
			err = tx.QueryRowContext(ctx, "SELECT 1 FROM users WHERE id=? AND status='active' LOCK IN SHARE MODE", m.wire).Scan(&active)
		} else {
			return Projection{}, ErrDenied
		}
		if err == sql.ErrNoRows {
			return Projection{}, ErrDenied
		}
		if err != nil {
			return Projection{}, ports.ErrUnavailable
		}
		role, display, source := m.role, m.role, "invite"
		if role == "admin" {
			role = "platform_staff"
			display = "platform"
			source = "platform_staff"
		} else if role == "worker" {
			source = "assigned_worker"
		} else {
			display = "user"
		}
		g := ports.Grant{Mode: mode, MemberRole: role, RoleSource: source, DisplayRole: display, DisplayRoleVersion: "1", CanRead: true, CanSend: true, ScopeKey: scope, Version: revision, MemberKey: scope + ":mini:" + m.wire,
			OrderContext: &ports.OrderContext{PublicNo: "", Title: "【测试】" + title, Workflow: "construction", Simulation: true, SandboxRoomID: id}}
		p.Members = append(p.Members, ProjectedMember{ActorID: actor, Role: role, Grant: g})
		fingerprint += "\n" + m.key + ":" + m.wire + ":" + m.role
	}
	digest := sha256.Sum256([]byte(fingerprint))
	p.Hash = hex.EncodeToString(digest[:])
	return p, nil
}
