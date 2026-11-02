package policy

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"sort"
	"strconv"
	"strings"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// Projection is a validated PHP fact, not an interpretation of order status.
type Projection struct {
	Mode                           string
	SourceVersion                  ports.ID
	Scope, Episode, State, Hash    string
	OrderID, AssignmentID, Version ports.ID
	StaffConfigVersion             ports.ID
	Members                        []ProjectedMember
}
type ProjectedMember struct {
	ActorID        ports.ID
	AccountVersion ports.ID
	Role           string
	Grant          ports.Grant
}

func DecodeEvent(body []byte, scope string, version ports.ID) (Projection, error) {
	var event struct {
		Schema   int             `json:"schema_version"`
		Version  ports.ID        `json:"policy_version"`
		Snapshot json.RawMessage `json:"snapshot"`
	}
	if len(body) > 65536 || !object(body, &event, "schema_version", "policy_version", "snapshot") || event.Schema != 1 || event.Version != version {
		return Projection{}, ErrDenied
	}
	return DecodeProjection(event.Snapshot, scope, version)
}

func DecodeProjection(body []byte, scope string, version ports.ID) (Projection, error) {
	var header struct {
		Schema int `json:"schema_version"`
	}
	if json.Unmarshal(body, &header) == nil && header.Schema == 3 {
		c, err := DecodeCollaborationProjection(body, scope, version)
		if err != nil {
			return Projection{}, err
		}
		p := Projection{Mode: "collaboration", SourceVersion: c.SourceVersion, Scope: c.Scope, Episode: c.Episode, State: c.State, Hash: c.Hash, OrderID: c.OrderID, Version: version, StaffConfigVersion: c.StaffConfigVersion}
		for _, m := range c.Members {
			p.Members = append(p.Members, ProjectedMember{ActorID: m.ActorID, AccountVersion: m.AccountVersion, Role: m.Grant.MemberRole, Grant: m.Grant})
		}
		return p, nil
	}
	var s snapshot
	if len(body) > 16384 || json.Unmarshal(body, &s) != nil || len(s.Members) == 0 {
		return Projection{}, ErrDenied
	}
	sum := sha256.Sum256(body)
	v := row{scope: scope, orderID: string(s.OrderID), assignmentID: string(s.AssignmentID), version: string(version), state: s.State, body: string(body), hash: hex.EncodeToString(sum[:])}
	p := Projection{Scope: scope, Episode: s.Episode, State: s.State, Hash: v.hash, OrderID: s.OrderID, AssignmentID: s.AssignmentID, Version: version, StaffConfigVersion: s.StaffConfigVersion}
	for _, m := range s.Members {
		g, err := evaluate(v, scope, version, m.ActorID, "read")
		if err != nil {
			return Projection{}, err
		}
		p.Members = append(p.Members, ProjectedMember{ActorID: m.ActorID, Role: m.Role, Grant: g})
	}
	return p, nil
}

// CurrentLocked always uses a current locking read, including in an older RR tx.
func (r *Reader) CurrentLocked(ctx context.Context, tx *sql.Tx, scope string) (Projection, error) {
	if tx == nil || len(scope) < 1 || len(scope) > 96 {
		return Projection{}, ErrDenied
	}
	// Order changes invalidate the group before updating its guard. Account
	// changes advance only users.chat_collaboration_version, avoiding a
	// users -> group / group -> users lock cycle.
	if strings.HasPrefix(scope, "order:") && strings.HasSuffix(scope, ":collaboration") {
		order := ports.ID(strings.TrimSuffix(strings.TrimPrefix(scope, "order:"), ":collaboration"))
		if !positive(order) {
			return Projection{}, ErrDenied
		}
		var epoch string
		if err := tx.QueryRowContext(ctx, "SELECT CAST(source_version AS CHAR) FROM chat_collaboration_groups WHERE order_id=? LOCK IN SHARE MODE", string(order)).Scan(&epoch); err != nil {
			if err == sql.ErrNoRows {
				return Projection{}, ErrDenied
			}
			return Projection{}, ports.ErrUnavailable
		}
	}
	var v row
	var body sql.NullString
	err := tx.QueryRowContext(ctx, `SELECT scope_key,CAST(order_id AS CHAR),COALESCE(CAST(assignment_id AS CHAR),''),CAST(policy_version AS CHAR),state,
		CASE WHEN OCTET_LENGTH(snapshot_json)<=32768 THEN snapshot_json ELSE NULL END,snapshot_hash
		FROM chat_policy_guards WHERE scope_key=? LOCK IN SHARE MODE`, scope).Scan(&v.scope, &v.orderID, &v.assignmentID, &v.version, &v.state, &body, &v.hash)
	if err == sql.ErrNoRows {
		return Projection{}, ErrDenied
	}
	if err != nil {
		return Projection{}, ports.ErrUnavailable
	}
	if !body.Valid {
		return Projection{}, ErrDenied
	}
	p, err := DecodeProjection([]byte(body.String), scope, ports.ID(v.version))
	if err != nil || p.Hash != v.hash || string(p.OrderID) != v.orderID || string(p.AssignmentID) != v.assignmentID || p.State != v.state {
		return Projection{}, ErrDenied
	}
	return p, nil
}

// Every platform participant must fence ALL staff epochs. Otherwise other peers
// could send under an obsolete policy version during a staff member's absence,
// making that gap indistinguishable from the member's old authorized history.
func (r *Reader) CheckEpochsLocked(ctx context.Context, tx *sql.Tx, p Projection, actor ports.ID, action string) error {
	if p.Mode == "collaboration" {
		var current string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(source_version AS CHAR) FROM chat_collaboration_groups WHERE order_id=? LOCK IN SHARE MODE`, string(p.OrderID)).Scan(&current); err != nil {
			if err == sql.ErrNoRows {
				return ErrDenied
			}
			return ports.ErrUnavailable
		}
		if current != string(p.SourceVersion) {
			return ErrDenied
		}
		// Current locking reads fence every member's account, including phone
		// corrections. The immutable snapshot records each PHP account epoch.
		members := append([]ProjectedMember(nil), p.Members...)
		sort.Slice(members, func(i, j int) bool {
			a, _ := strconv.ParseUint(string(members[i].ActorID), 10, 64)
			b, _ := strconv.ParseUint(string(members[j].ActorID), 10, 64)
			return a < b
		})
		for _, member := range members {
			var version, status string
			err := tx.QueryRowContext(ctx, "SELECT CAST(chat_collaboration_version AS CHAR),status FROM users WHERE id=? LOCK IN SHARE MODE", string(member.ActorID)).Scan(&version, &status)
			if err == sql.ErrNoRows {
				return ErrDenied
			}
			if err != nil {
				return ports.ErrUnavailable
			}
			if status != "active" || version != string(member.AccountVersion) {
				return ErrDenied
			}
		}
	}
	// A schema-2 snapshot may have no staff; its config epoch still fences joins.
	if p.StaffConfigVersion == "" {
		return nil
	}
	versions := map[ports.ID]ports.ID{"0": p.StaffConfigVersion}
	isStaff := false
	for _, m := range p.Members {
		if m.Role == "platform_staff" && (action == "send" || m.ActorID == actor) {
			versions[m.ActorID] = m.Grant.StaffUserVersion
			if m.ActorID == actor {
				isStaff = true
			}
		}
	}
	// Unaffected peers keep their authorized history, including closed scopes
	// whose historical staff roster intentionally never refreshes.
	if action == "read" && !isStaff {
		return nil
	}
	ids := make([]ports.ID, 0, len(versions))
	for id := range versions {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool {
		a, _ := strconv.ParseUint(string(ids[i]), 10, 64)
		b, _ := strconv.ParseUint(string(ids[j]), 10, 64)
		return a < b
	})
	for _, id := range ids {
		var current string
		err := tx.QueryRowContext(ctx, `SELECT CAST(authority_version AS CHAR) FROM chat_staff_authority_versions WHERE user_id=? LOCK IN SHARE MODE`, string(id)).Scan(&current)
		if err == sql.ErrNoRows {
			return ErrDenied
		}
		if err != nil {
			return ports.ErrUnavailable
		}
		if current != string(versions[id]) {
			return ErrDenied
		}
	}
	return nil
}
