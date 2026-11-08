package message

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

func (g *GovernanceService) encodeAuditCursor(p ports.Principal, c auditCursor) (string, error) {
	a, err := g.history.digestAEAD()
	if err != nil {
		return "", ports.ErrUnavailable
	}
	raw, _ := json.Marshal(c)
	nonce := make([]byte, a.NonceSize())
	if _, err = rand.Read(nonce); err != nil {
		return "", ports.ErrUnavailable
	}
	return base64.RawURLEncoding.EncodeToString(append(nonce, a.Seal(nil, nonce, raw, []byte("audit:"+bindingFor(g.environment, p)))...)), nil
}
func (g *GovernanceService) decodeAuditCursor(token string, p ports.Principal, c *auditCursor) error {
	if len(token) > 1024 {
		return ErrResync
	}
	b, err := base64.RawURLEncoding.Strict().DecodeString(token)
	if err != nil {
		return ErrResync
	}
	a, err := g.history.digestAEAD()
	if err != nil {
		return ports.ErrUnavailable
	}
	if len(b) < a.NonceSize()+a.Overhead() {
		return ErrResync
	}
	raw, err := a.Open(nil, b[:a.NonceSize()], b[a.NonceSize():], []byte("audit:"+bindingFor(g.environment, p)))
	if err != nil || json.Unmarshal(raw, c) != nil || len(c.Snapshot) != 64 || !validID(c.After) || c.At.IsZero() {
		return ErrResync
	}
	return nil
}
func (g *GovernanceService) auditListPage(ctx context.Context, tx *sql.Tx, p ports.Principal, token string, limit int, c auditCursor, filter AuditFilter) (AuditList, error) {
	var live bool
	if tx.QueryRowContext(ctx, `SELECT expires_at>UTC_TIMESTAMP(6) FROM chat_audit_snapshots WHERE id=? AND binding=? LOCK IN SHARE MODE`, c.Snapshot, bindingFor(g.environment, p)).Scan(&live) != nil || !live {
		return AuditList{}, ErrResync
	}
	query := `SELECT CAST(conversation_id AS CHAR),CAST(order_id AS CHAR),activity_at,COALESCE((SELECT JSON_UNQUOTE(JSON_EXTRACT(g.snapshot_json,'$.order_context.public_no')) FROM chat_conversations c JOIN chat_policy_guards g ON g.scope_key=c.scope_key WHERE c.id=chat_audit_snapshot_items.conversation_id LIMIT 1),'') FROM chat_audit_snapshot_items WHERE snapshot_id=?`
	args := []any{c.Snapshot}
	if token != "" {
		query += ` AND (activity_at<? OR (activity_at=? AND conversation_id<?))`
		args = append(args, c.At, c.At, string(c.After))
	}
	query += ` ORDER BY activity_at DESC,conversation_id DESC LIMIT ?`
	args = append(args, limit+1)
	rows, err := tx.QueryContext(ctx, query, args...)
	if err != nil {
		return AuditList{}, ports.ErrUnavailable
	}
	out := AuditList{Items: []AuditConversation{}}
	for rows.Next() {
		var item AuditConversation
		if rows.Scan(&item.ID, &item.OrderID, &item.Activity, &item.OrderNo) != nil {
			rows.Close()
			return AuditList{}, ports.ErrUnavailable
		}
		out.Items = append(out.Items, item)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return AuditList{}, ports.ErrUnavailable
	}
	if len(out.Items) > limit {
		out.More = true
		out.Items = out.Items[:limit]
		last := out.Items[len(out.Items)-1]
		c.At = last.Activity
		c.After = last.ID
		out.Cursor, err = g.encodeAuditCursor(p, c)
		if err != nil {
			return AuditList{}, err
		}
	}
	for i := range out.Items {
		out.Items[i].MatchMessage = g.auditMatch(ctx, tx, filter, out.Items[i].ID)
	}
	release := c
	release.At = time.Now().UTC()
	release.After = "1"
	out.Snapshot, err = g.encodeAuditCursor(p, release)
	if err != nil {
		return AuditList{}, err
	}
	if err = governanceLog(ctx, tx, p, "audit.list", "", "", "", "", ""); err != nil {
		return AuditList{}, err
	}
	if err = g.commit(tx, p); err != nil {
		return AuditList{}, err
	}
	return out, nil
}

// Explicit bounded maintenance; snapshot expiration never reorders a live page.
func (g *GovernanceService) CleanupSnapshots(ctx context.Context) error {
	_, err := g.base.db.ExecContext(ctx, `DELETE FROM chat_audit_snapshots WHERE expires_at<=UTC_TIMESTAMP(6) ORDER BY expires_at LIMIT 10`)
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}
