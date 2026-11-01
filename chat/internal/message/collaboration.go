package message

import (
	"context"
	"database/sql"
	"errors"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"time"
)

type SenderIdentity struct {
	RoleSource     string   `json:"role_source"`
	DisplayRole    string   `json:"display_role"`
	DisplayVersion ports.ID `json:"display_role_version"`
}

func stampSender(ctx context.Context, tx *sql.Tx, mid int64, g ports.Grant) error {
	if g.Mode != "collaboration" {
		return nil
	}
	if _, err := tx.ExecContext(ctx, `UPDATE chat_messages SET sender_role_source=?,sender_display_role=?,sender_display_role_version=? WHERE id=?`, g.RoleSource, g.DisplayRole, string(g.DisplayRoleVersion), mid); err != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func hydrateSenders(ctx context.Context, tx *sql.Tx, views []*MessageView) error {
	if len(views) == 0 {
		return nil
	}
	if len(views) > 200 {
		return ports.ErrInvalid
	}
	ids := []any{}
	slots := []string{}
	by := map[ports.ID][]*MessageView{}
	for _, v := range views {
		if v == nil {
			continue
		}
		ids = append(ids, string(v.ID))
		slots = append(slots, "?")
		by[v.ID] = append(by[v.ID], v)
	}
	if len(ids) == 0 {
		return nil
	}
	rows, err := tx.QueryContext(ctx, `SELECT CAST(id AS CHAR),sender_role_source,sender_display_role,CAST(sender_display_role_version AS CHAR) FROM chat_messages WHERE id IN (`+strings.Join(slots, ",")+`)`, ids...)
	if err != nil {
		return ports.ErrUnavailable
	}
	defer rows.Close()
	for rows.Next() {
		var id ports.ID
		var source, role, version sql.NullString
		if rows.Scan(&id, &source, &role, &version) != nil {
			return ports.ErrUnavailable
		}
		if source.Valid && role.Valid && version.Valid {
			for _, v := range by[id] {
				v.Sender = &SenderIdentity{source.String, role.String, ports.ID(version.String)}
			}
		}
	}
	if rows.Err() != nil {
		return ports.ErrUnavailable
	}
	return nil
}

type ReaderCounts struct {
	MessageID     ports.ID `json:"message_id"`
	PolicyVersion ports.ID `json:"policy_version"`
	Read          int      `json:"read_count"`
	Unread        int      `json:"unread_count"`
}

func (s *HistoryService) Readers(ctx context.Context, p ports.Principal, cid, mid ports.ID) (ReaderCounts, error) {
	out := ReaderCounts{MessageID: mid}
	if !validID(mid) {
		return out, ports.ErrInvalid
	}
	r, err := s.open(ctx, p, HistoryRequest{ConversationID: cid}, "bootstrap")
	if err != nil {
		return out, err
	}
	defer r.tx.Rollback()
	if r.grant.Mode != "collaboration" {
		return out, ports.ErrInvalid
	}
	var seq uint64
	var version ports.ID
	var sender ports.ID
	err = r.tx.QueryRowContext(ctx, `SELECT seq,CAST(policy_version AS CHAR),CAST(sender_id AS CHAR) FROM chat_messages m WHERE m.id=? AND m.conversation_id=? AND m.state='published'
 AND m.seq>? AND m.seq<=? AND `+visibleColumn, string(mid), string(cid), r.floorSeq, number(r.cursor.Seq), string(r.grant.Version), string(p.ActorID)).Scan(&seq, &version, &sender)
	if err == sql.ErrNoRows {
		return out, ErrResync
	}
	if err != nil {
		return out, ports.ErrUnavailable
	}
	rows, err := r.tx.QueryContext(ctx, `SELECT CAST(m.actor_id AS CHAR),COALESCE(r.read_seq,0)>=? FROM chat_members m LEFT JOIN chat_member_reads r ON r.conversation_id=m.conversation_id AND r.actor_id=m.actor_id AND r.member_key_hash=SHA2(m.member_key,256)
 WHERE m.conversation_id=? AND m.actor_kind='mini' AND m.can_read=1 AND m.actor_id<>? AND EXISTS(SELECT 1 FROM chat_member_intervals i WHERE i.conversation_id=m.conversation_id AND i.actor_id=m.actor_id AND i.start_version<=? AND (i.end_version IS NULL OR i.end_version>?)) LIMIT 65`, seq, string(cid), string(sender), string(version), string(version))
	if err != nil {
		return out, ports.ErrUnavailable
	}

	type candidate struct {
		actor ports.ID
		read  bool
	}
	candidates := []candidate{}
	for rows.Next() {
		var c candidate
		if rows.Scan(&c.actor, &c.read) != nil {
			rows.Close()
			return out, ports.ErrUnavailable
		}
		candidates = append(candidates, c)
	}
	err = rows.Err()
	rows.Close()
	if err != nil || len(candidates) > 64 {
		return out, ports.ErrUnavailable
	}
	for _, c := range candidates {
		// Internal recipient facts do not issue a login, presence or read receipt.
		if _, err = s.conversations.RecipientLocked(ctx, r.tx, cid, c.actor); err != nil {
			if errors.Is(err, policy.ErrDenied) {
				continue
			}
			return out, err
		}
		if c.read {
			out.Read++
		} else {
			out.Unread++
		}
	}
	out.PolicyVersion = r.grant.Version
	if !time.Now().Before(p.ExpiresAt) {
		return out, policy.ErrDenied
	}
	if r.tx.Commit() != nil {
		return out, ports.ErrUnavailable
	}
	return out, nil
}
