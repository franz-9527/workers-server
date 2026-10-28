package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Audit history has its own scope and cursor namespace. It never invokes
// member bootstrap, read acknowledgements, presence or personal preferences.
func (g *GovernanceService) AuditHistory(ctx context.Context, p ports.Principal, q HistoryRequest) (HistoryPage, error) {
	if !validID(q.ConversationID) || q.Limit < 0 || q.Limit > 50 || q.TargetID != "" && !validID(q.TargetID) || q.TargetID != "" && q.Cursor != "" {
		return HistoryPage{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, g.base.timeout)
	defer cancel()
	tx, err := g.base.begin(ctx)
	if err != nil {
		return HistoryPage{}, err
	}
	defer tx.Rollback()
	if err = g.admin(ctx, tx, p, "chat:audit"); err != nil {
		return HistoryPage{}, err
	}
	var seq, change, floor uint64
	var version ports.ID
	if tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq,sync_floor_seq,CAST(policy_version AS CHAR) FROM chat_conversations WHERE id=? AND conversation_type='order' LOCK IN SHARE MODE`, string(q.ConversationID)).Scan(&seq, &change, &floor, &version) != nil {
		return HistoryPage{}, ErrResync
	}
	c := historyCursor{Schema: 1, Mode: "audit.history", Binding: bindingFor(g.environment, p), Conversation: q.ConversationID, Policy: version, Member: "audit", Seq: decimal(seq), Change: decimal(change), Position: decimal(seq), Issued: time.Now().Unix(), Expiry: time.Now().Add(cursorTTL).Unix()}
	if q.Cursor != "" {
		c, err = g.history.parse(q.Cursor, "audit.history")
		if err != nil || c.Binding != bindingFor(g.environment, p) || c.Conversation != q.ConversationID || number(c.Seq) > seq || number(c.Position) < floor {
			return HistoryPage{}, ErrResync
		}
	}
	limit := q.Limit
	if limit == 0 {
		limit = 30
	}
	out := HistoryPage{MemberRole: "audit", CanSend: false, ViewerID: p.ActorID, Messages: []MessageView{}, Changes: []ChangeView{}, PolicyVersion: version, SnapshotMaxSeq: c.Seq, SnapshotMaxChange: c.Change, ExpiresAt: c.Expiry}
	query := `SELECT ` + viewColumns + `,1 FROM chat_messages m WHERE m.conversation_id=? AND m.seq>? AND m.seq<=? AND m.seq<=? ORDER BY m.seq DESC LIMIT ?`
	pos := number(c.Position)
	if q.TargetID != "" {
		var target uint64
		if tx.QueryRowContext(ctx, `SELECT seq FROM chat_messages WHERE conversation_id=? AND id=? AND seq>? AND seq<=?`, string(q.ConversationID), string(q.TargetID), floor, seq).Scan(&target) != nil {
			return HistoryPage{}, ErrResync
		}
		pos = target + uint64(limit/2)
		if pos > seq {
			pos = seq
		}
	}
	rows, err := tx.QueryContext(ctx, query, string(q.ConversationID), floor, string(c.Seq), pos, limit+1)
	if err != nil {
		return out, ports.ErrUnavailable
	}
	for rows.Next() {
		v, e := scanCandidate(rows, false)
		if e != nil {
			rows.Close()
			return out, e
		}
		out.Messages = append(out.Messages, v.view)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return out, ports.ErrUnavailable
	}
	if len(out.Messages) > limit {
		out.HasMore = true
		out.Messages = out.Messages[:limit]
		c.Position = decimal(number(out.Messages[len(out.Messages)-1].Seq) - 1)
		out.HistoryCursor = g.history.sign(c)
	}
	for i, j := 0, len(out.Messages)-1; i < j; i, j = i+1, j-1 {
		out.Messages[i], out.Messages[j] = out.Messages[j], out.Messages[i]
	}
	views := make([]*MessageView, 0, len(out.Messages))
	for i := range out.Messages {
		views = append(views, &out.Messages[i])
	}
	if err = hydrateAV(ctx, tx, p, views); err != nil {
		return out, err
	}
	if err = hydrateSenders(ctx, tx, views); err != nil {
		return out, err
	}
	if err = hydrateImages(ctx, tx, views); err != nil {
		return out, err
	}
	if err = hydrateReferenceScope(ctx, tx, p, ports.Grant{}, q.ConversationID, views, true); err != nil {
		return out, err
	}
	if err = governanceLog(ctx, tx, p, "audit.history", q.ConversationID, q.TargetID, "", version, ""); err != nil {
		return out, err
	}
	if err = g.commit(tx, p); err != nil {
		return out, err
	}
	return out, nil
}
func (g *GovernanceService) AuditImage(ctx context.Context, p ports.Principal, cid, mid, item ports.ID, thumb bool) (string, error) {
	if g.media == nil || !validID(cid) || !validID(mid) || !validID(item) {
		return "", ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	if err = g.admin(ctx, tx, p, "chat:audit"); err != nil {
		return "", err
	}
	var id ports.ID
	if tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_conversations WHERE id=? AND conversation_type='order' LOCK IN SHARE MODE`, string(cid)).Scan(&id) != nil {
		return "", ErrResync
	}
	col := "u.main_key"
	if thumb {
		col = "u.thumb_key"
	}
	var key string
	var expiry sql.NullTime
	var version ports.ID
	if tx.QueryRowContext(ctx, `SELECT `+col+`,m.expires_at,CAST(m.state_version AS CHAR) FROM chat_messages m JOIN chat_image_items i ON i.message_id=m.id JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version WHERE m.conversation_id=? AND m.id=? AND i.item_id=? AND m.state='published' AND `+retainedTarget, string(cid), string(mid), string(item)).Scan(&key, &expiry, &version) != nil || key == "" {
		return "", ErrMedia
	}
	ttl := mediaReadTTL(p, expiry)
	if ttl <= 0 {
		return "", ErrMedia
	}
	url, err := g.media.storage.Sign(ctx, key, ttl)
	if err != nil {
		return "", ErrMedia
	}
	if err = governanceLog(ctx, tx, p, "audit.image", cid, mid, item, version, ""); err != nil {
		return "", err
	}
	if err = g.commit(tx, p); err != nil {
		return "", err
	}
	return url, nil
}
