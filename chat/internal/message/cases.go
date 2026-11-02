package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

type Case struct {
	ID            ports.ID  `json:"case_id"`
	Kind          string    `json:"kind"`
	TargetType    string    `json:"target_type"`
	Target        ports.ID  `json:"target_id"`
	Item          ports.ID  `json:"item_id"`
	Conversation  ports.ID  `json:"conversation_id"`
	Reporter      ports.ID  `json:"reporter_id"`
	Subject       ports.ID  `json:"subject_id"`
	Version       ports.ID  `json:"version"`
	State         string    `json:"state"`
	Reason        string    `json:"reason"`
	Resolution    string    `json:"resolution"`
	Created       time.Time `json:"created_at"`
	Fingerprint   string    `json:"fingerprint"`
	TargetVersion ports.ID  `json:"target_version"`
}

const caseColumns = `CAST(id AS CHAR),kind,target_type,CAST(target_id AS CHAR),CAST(item_id AS CHAR),CAST(conversation_id AS CHAR),CAST(reporter_id AS CHAR),CAST(subject_id AS CHAR),CAST(version AS CHAR),state,reason,COALESCE(resolution,''),created_at,fingerprint,CAST(target_version AS CHAR)`

type CasePage struct {
	Items []Case   `json:"items"`
	After ports.ID `json:"next_after"`
	More  bool     `json:"has_more"`
}

func scanCase(row interface{ Scan(...any) error }) (Case, error) {
	var c Case
	err := row.Scan(&c.ID, &c.Kind, &c.TargetType, &c.Target, &c.Item, &c.Conversation, &c.Reporter, &c.Subject, &c.Version, &c.State, &c.Reason, &c.Resolution, &c.Created, &c.Fingerprint, &c.TargetVersion)
	if err != nil {
		return c, ports.ErrUnavailable
	}
	return c, nil
}
func createCase(ctx context.Context, tx *sql.Tx, kind, targetType string, target, item, cid, reporter, subject, version ports.ID, fp, reason string) (ports.ID, error) {
	var id ports.ID
	err := tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_cases WHERE kind=? AND target_type=? AND target_id=? AND item_id=? AND reporter_id=? AND (kind<>'appeal' OR target_version=?)`, kind, targetType, string(target), string(item), string(reporter), string(version)).Scan(&id)
	if err == nil {
		return id, nil
	}
	if err != sql.ErrNoRows {
		return "", ports.ErrUnavailable
	}
	var n int
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_cases WHERE reporter_id=? AND kind<>'review' AND created_at>UTC_TIMESTAMP()-INTERVAL 1 DAY`, string(reporter)).Scan(&n) != nil {
		return "", ports.ErrUnavailable
	}
	if n >= 20 && kind != "review" {
		return "", ports.ErrBusy
	}
	r, err := tx.ExecContext(ctx, `INSERT INTO chat_cases(kind,target_type,target_id,item_id,conversation_id,reporter_id,subject_id,target_version,fingerprint,reason,state,version,retain_until,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',1,UTC_TIMESTAMP(6)+INTERVAL 30 DAY,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`, kind, targetType, string(target), string(item), string(cid), string(reporter), string(subject), string(version), fp, reason)
	if err != nil {
		return "", ports.ErrUnavailable
	}
	n64, err := r.LastInsertId()
	if err != nil {
		return "", ports.ErrUnavailable
	}
	return decimal(uint64(n64)), nil
}
func (g *GovernanceService) Report(ctx context.Context, p ports.Principal, cid, mid, item ports.ID, reason string) (ports.ID, error) {
	if !validID(cid) || !validID(mid) || item != "" && !validID(item) || !reasonValid(reason) {
		return "", ports.ErrInvalid
	}
	if item == "" {
		item = "0"
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	authority, err := g.base.conversations.LockAuthority(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"})
	if err != nil {
		return "", err
	}
	if _, err = restrictionLocked(ctx, tx, p.ActorID); err != nil {
		return "", err
	}
	grant, err := g.base.conversations.LockProjection(ctx, tx, authority)
	if err != nil {
		return "", err
	}
	var subject, version ports.ID
	var fp string
	if tx.QueryRowContext(ctx, `SELECT CAST(m.sender_id AS CHAR),CAST(m.state_version AS CHAR),SHA2(CONCAT(m.id,':',m.client_message_id,':',m.safety_rule_version),256) FROM chat_messages m WHERE m.conversation_id=? AND m.id=? AND `+retainedTarget+` AND `+visibleColumn, string(cid), string(mid), string(grant.Version), string(p.ActorID)).Scan(&subject, &version, &fp) != nil {
		return "", ErrResync
	}
	if item != "0" {
		var n int
		if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_image_items WHERE message_id=? AND item_id=?`, string(mid), string(item)).Scan(&n) != nil || n != 1 {
			return "", ErrMedia
		}
	}
	id, err := createCase(ctx, tx, "report", "message", mid, item, cid, p.ActorID, subject, version, fp, reason)
	if err != nil {
		return "", err
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_image_uploads u JOIN chat_image_items i ON i.item_id=u.id SET u.legal_hold=1 WHERE i.message_id=?`, string(mid)); err != nil {
		return "", ports.ErrUnavailable
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_av_uploads u JOIN chat_av_items i ON i.upload_id=u.id SET u.legal_hold=1 WHERE i.message_id=?`, string(mid)); err != nil {
		return "", ports.ErrUnavailable
	}
	if err = governanceLog(ctx, tx, p, "case.report", cid, id, item, version, reason); err != nil {
		return "", err
	}
	if err = g.commit(tx, p); err != nil {
		return "", err
	}
	return id, nil
}
func (g *GovernanceService) Appeal(ctx context.Context, p ports.Principal, targetType string, target, expected ports.ID, reason string) (ports.ID, error) {
	if !validID(target) || !expected.Valid() || !reasonValid(reason) || targetType != "risk" && targetType != "image_intent" && targetType != "media_intent" && targetType != "restriction" || p.Environment != g.environment {
		return "", ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	if err = g.source.ValidatePrincipalLocked(ctx, tx, p); err != nil {
		return "", err
	}
	status, err := restrictionLocked(ctx, tx, p.ActorID)
	if err != nil {
		return "", err
	}
	cid, subject, version, fp := ports.ID("0"), p.ActorID, ports.ID("1"), ""
	switch targetType {
	case "risk":
		if tx.QueryRowContext(ctx, `SELECT CAST(conversation_id AS CHAR),content_fingerprint FROM chat_risk_events WHERE id=? AND sender_id=? AND retain_until>UTC_TIMESTAMP(6) LOCK IN SHARE MODE`, string(target), string(p.ActorID)).Scan(&cid, &fp) != nil {
			return "", ErrResync
		}
	case "image_intent":
		if tx.QueryRowContext(ctx, `SELECT CAST(i.conversation_id AS CHAR),b.manifest_hash,CAST(b.review_version AS CHAR) FROM chat_send_intents i JOIN chat_image_bundles b ON b.intent_id=i.id WHERE i.id=? AND i.sender_id=? AND i.state='rejected' AND i.retain_until>UTC_TIMESTAMP(6) LOCK IN SHARE MODE`, string(target), string(p.ActorID)).Scan(&cid, &fp, &version) != nil {
			return "", ErrResync
		}
	case "media_intent":
		if tx.QueryRowContext(ctx, `SELECT CAST(i.conversation_id AS CHAR),j.manifest_hash,CAST(j.review_version AS CHAR) FROM chat_send_intents i JOIN chat_av_jobs j ON j.intent_id=i.id WHERE i.id=? AND i.sender_id=? AND i.state='rejected' AND i.retain_until>UTC_TIMESTAMP(6) LOCK IN SHARE MODE`, string(target), string(p.ActorID)).Scan(&cid, &fp, &version) != nil {
			return "", ErrResync
		}
	case "restriction":
		if target != p.ActorID || status.Version != expected || !status.Blocked {
			return "", ErrResync
		}
		version = status.Version
		fp = memberHash(string(p.ActorID) + ":" + string(version))
	}
	if targetType != "restriction" && version != expected {
		return "", ErrResync
	}
	id, err := createCase(ctx, tx, "appeal", targetType, target, "0", cid, p.ActorID, subject, version, fp, reason)
	if err != nil {
		return "", err
	}
	if targetType == "risk" {
		if _, err = tx.ExecContext(ctx, `UPDATE chat_risk_events SET retain_until=GREATEST(retain_until,UTC_TIMESTAMP(6)+INTERVAL 30 DAY) WHERE id=?`, string(target)); err != nil {
			return "", ports.ErrUnavailable
		}
	}
	if targetType == "image_intent" {
		items, e := caseImageItems(ctx, tx, Case{TargetType: targetType, Target: target, Fingerprint: fp})
		if e != nil {
			return "", e
		}
		for _, v := range items {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_image_uploads SET legal_hold=1 WHERE id=? AND actor_id=? AND conversation_id=?`, string(v.ItemID), string(p.ActorID), string(cid)); err != nil {
				return "", ports.ErrUnavailable
			}
		}
	}
	if targetType == "media_intent" {
		if _, err = tx.ExecContext(ctx, `UPDATE chat_av_uploads u JOIN chat_av_jobs j ON j.upload_id=u.id SET u.legal_hold=1 WHERE j.intent_id=? AND u.actor_id=?`, string(target), string(p.ActorID)); err != nil {
			return "", ports.ErrUnavailable
		}
	}
	if err = governanceLog(ctx, tx, p, "case.appeal", cid, id, "", version, reason); err != nil {
		return "", err
	}
	if err = g.commit(tx, p); err != nil {
		return "", err
	}
	return id, nil
}
func (g *GovernanceService) Cases(ctx context.Context, p ports.Principal, after ports.ID, admin bool) (CasePage, error) {
	if after == "" {
		after = "0"
	}
	if !after.Valid() {
		return CasePage{}, ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return CasePage{}, err
	}
	defer tx.Rollback()
	if admin {
		err = g.admin(ctx, tx, p, "chat:moderate")
	} else if p.Environment == g.environment {
		err = g.source.ValidatePrincipalLocked(ctx, tx, p)
	} else {
		err = ports.ErrInvalid
	}
	if err != nil {
		return CasePage{}, err
	}
	query := `SELECT ` + caseColumns + ` FROM chat_cases WHERE id>?`
	args := []any{string(after)}
	if !admin {
		query += ` AND reporter_id=?`
		args = append(args, string(p.ActorID))
	}
	query += ` ORDER BY id LIMIT 21`
	rows, err := tx.QueryContext(ctx, query, args...)
	if err != nil {
		return CasePage{}, ports.ErrUnavailable
	}
	out := CasePage{Items: []Case{}, After: after}
	for rows.Next() {
		c, e := scanCase(rows)
		if e != nil {
			rows.Close()
			return out, e
		}
		if !admin {
			c.Fingerprint = ""
			c.Subject = "0"
		}
		out.Items = append(out.Items, c)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return out, ports.ErrUnavailable
	}
	if len(out.Items) > 20 {
		out.More = true
		out.Items = out.Items[:20]
	}
	if len(out.Items) > 0 {
		out.After = out.Items[len(out.Items)-1].ID
	}
	if admin {
		if err = governanceLog(ctx, tx, p, "case.list", "", "", "", "", ""); err != nil {
			return out, err
		}
	}
	if err = g.commit(tx, p); err != nil {
		return out, err
	}
	return out, nil
}
