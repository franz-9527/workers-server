package message

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
	"unicode/utf8"
)

// Report binds either the whole image message (item=0) or a stable item.
// It creates one pending governance case, never blocks content by report count.
func (m *MediaService) Report(ctx context.Context, p ports.Principal, cid, mid, item ports.ID, reason string) (ports.ID, error) {
	if !validID(cid) || !validID(mid) || item != "" && !validID(item) || !utf8.ValidString(reason) || utf8.RuneCountInString(reason) < 2 || utf8.RuneCountInString(reason) > 200 {
		return "", ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return "", e
	}
	defer tx.Rollback()
	authority, e := m.base.conversations.LockAuthority(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"})
	if e != nil {
		return "", e
	}
	if _, e = restrictionLocked(ctx, tx, p.ActorID); e != nil {
		return "", e
	}
	g, e := m.base.conversations.LockProjection(ctx, tx, authority)
	if e != nil {
		return "", e
	}
	var found ports.ID
	if tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR) FROM chat_messages m WHERE m.id=? AND m.conversation_id=? AND m.message_type IN ('image','image_album') AND `+retainedTarget+` AND `+visibleColumn, string(mid), string(cid), string(g.Version), string(p.ActorID)).Scan(&found) != nil {
		return "", ErrMedia
	}
	var count int
	if item != "" {
		if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_image_items WHERE message_id=? AND item_id=?`, string(mid), string(item)).Scan(&count) != nil || count != 1 {
			return "", ErrMedia
		}
	}
	if item == "" {
		item = "0"
	}
	var existing ports.ID
	err := tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_image_reports WHERE reporter_id=? AND message_id=? AND item_id=?`, string(p.ActorID), string(mid), string(item)).Scan(&existing)
	if err == nil {
		if tx.Commit() != nil {
			return "", ports.ErrUnavailable
		}
		return existing, nil
	}
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_image_reports WHERE reporter_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 DAY`, string(p.ActorID)).Scan(&count) != nil {
		return "", ports.ErrUnavailable
	}
	if count >= 20 {
		return "", ports.ErrBusy
	}
	res, e := tx.ExecContext(ctx, `INSERT INTO chat_image_reports(reporter_id,conversation_id,message_id,item_id,reason,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))`, string(p.ActorID), string(cid), string(mid), string(item), reason)
	if e != nil {
		return "", ports.ErrUnavailable
	}
	id, e := res.LastInsertId()
	if e != nil {
		return "", ports.ErrUnavailable
	}
	// A report protects all versions in this ordered message pending governance.
	if _, e = tx.ExecContext(ctx, `UPDATE chat_image_uploads u JOIN chat_image_items i ON i.item_id=u.id SET u.legal_hold=1 WHERE i.message_id=?`, string(mid)); e != nil {
		return "", ports.ErrUnavailable
	}
	var subject, version ports.ID
	var fp string
	if tx.QueryRowContext(ctx, `SELECT CAST(sender_id AS CHAR),CAST(state_version AS CHAR),SHA2(CONCAT(id,':',client_message_id,':',safety_rule_version),256) FROM chat_messages WHERE id=?`, string(mid)).Scan(&subject, &version, &fp) != nil {
		return "", ports.ErrUnavailable
	}
	if _, e = createCase(ctx, tx, "report", "message", mid, item, cid, p.ActorID, subject, version, fp, reason); e != nil {
		return "", e
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return "", ports.ErrUnavailable
	}
	return decimal(uint64(id)), nil
}
