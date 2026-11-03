package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

type AVEvidence struct {
	ItemID     ports.ID `json:"item_id"`
	Kind       string   `json:"message_type"`
	DurationMS int      `json:"duration_ms"`
	Transcript *string  `json:"transcript,omitempty"`
}

func caseAV(ctx context.Context, tx *sql.Tx, c Case) (*AVEvidence, string, error) {
	var out AVEvidence
	var key string
	var transcript sql.NullString
	query := `SELECT CAST(u.id AS CHAR),u.kind,u.duration_ms,COALESCE(u.transcript,j.transcript),COALESCE(u.main_key,'') FROM chat_av_uploads u JOIN chat_av_jobs j ON j.upload_id=u.id `
	var args []any
	switch c.TargetType {
	case "message":
		query += `JOIN chat_av_items ai ON ai.upload_id=u.id AND ai.object_version=u.object_version WHERE ai.message_id=? AND u.legal_hold=1`
		args = []any{string(c.Target)}
	case "media_intent":
		query += `WHERE j.intent_id=? AND j.manifest_hash=? AND u.legal_hold=1`
		args = []any{string(c.Target), c.Fingerprint}
	case "risk":
		query += `JOIN chat_risk_events r ON r.intent_id=j.intent_id WHERE r.id=? AND r.content_fingerprint=? AND r.retain_until>UTC_TIMESTAMP(6) AND u.legal_hold=1`
		args = []any{string(c.Target), c.Fingerprint}
	default:
		return nil, "", nil
	}
	e := tx.QueryRowContext(ctx, query, args...).Scan(&out.ItemID, &out.Kind, &out.DurationMS, &transcript, &key)
	if e == sql.ErrNoRows {
		return nil, "", nil
	}
	if e != nil {
		return nil, "", ports.ErrUnavailable
	}
	if transcript.Valid {
		out.Transcript = &transcript.String
	}
	return &out, key, nil
}
func (g *GovernanceService) avEvidenceURL(ctx context.Context, tx *sql.Tx, p ports.Principal, c Case, item ports.ID) (*AVEvidence, string, error) {
	out, key, e := caseAV(ctx, tx, c)
	if e != nil {
		return nil, "", e
	}
	if out == nil {
		return nil, "", nil
	}
	if item == "" {
		return out, "", nil
	}
	if item != out.ItemID || g.base.av == nil || key == "" {
		return nil, "", ErrMedia
	}
	var expires time.Time
	if tx.QueryRowContext(ctx, `SELECT retain_until FROM chat_cases WHERE id=?`, string(c.ID)).Scan(&expires) != nil {
		return nil, "", ErrMedia
	}
	ttl := min(60*time.Second, time.Until(expires), time.Until(p.ExpiresAt))
	if ttl < time.Second {
		return nil, "", ErrExpired
	}
	url, e := g.base.av.storage.Sign(ctx, key, ttl)
	return out, url, e
}
func releaseAVHolds(ctx context.Context, tx *sql.Tx) error {
	_, e := tx.ExecContext(ctx, `UPDATE chat_av_uploads u SET legal_hold=0 WHERE u.legal_hold=1 AND NOT EXISTS(SELECT 1 FROM chat_cases c JOIN chat_av_items i ON c.target_type='message' AND c.target_id=i.message_id WHERE i.upload_id=u.id AND c.retain_until>UTC_TIMESTAMP(6)) AND NOT EXISTS(SELECT 1 FROM chat_cases c JOIN chat_av_jobs j ON c.target_type='media_intent' AND c.target_id=j.intent_id WHERE j.upload_id=u.id AND c.retain_until>UTC_TIMESTAMP(6)) AND NOT EXISTS(SELECT 1 FROM chat_risk_events r JOIN chat_av_jobs j ON j.intent_id=r.intent_id WHERE j.upload_id=u.id AND (r.retain_until>UTC_TIMESTAMP(6) OR EXISTS(SELECT 1 FROM chat_cases c WHERE c.target_type='risk' AND c.target_id=r.id AND c.retain_until>UTC_TIMESTAMP(6))))`)
	if e != nil {
		return ports.ErrUnavailable
	}
	return nil
}

func (g *GovernanceService) AuditAV(ctx context.Context, p ports.Principal, cid, mid ports.ID) (MediaAccess, error) {
	if g.base.av == nil || !validID(cid) || !validID(mid) {
		return MediaAccess{}, ports.ErrInvalid
	}
	tx, e := g.base.begin(ctx)
	if e != nil {
		return MediaAccess{}, e
	}
	defer tx.Rollback()
	if e = g.admin(ctx, tx, p, "chat:audit"); e != nil {
		return MediaAccess{}, e
	}
	var found ports.ID
	if tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_conversations WHERE id=? AND conversation_type='order' LOCK IN SHARE MODE`, string(cid)).Scan(&found) != nil {
		return MediaAccess{}, ErrResync
	}
	var key, hash, mode, mime string
	var expiry sql.NullTime
	var version ports.ID
	e = tx.QueryRowContext(ctx, `SELECT u.main_key,u.main_sha256,m.retention_mode,m.expires_at,CAST(m.state_version AS CHAR),u.mime_type FROM chat_messages m JOIN chat_av_items ai ON ai.message_id=m.id JOIN chat_av_uploads u ON u.id=ai.upload_id AND u.object_version=ai.object_version WHERE m.id=? AND m.conversation_id=? AND m.state='published' AND `+retainedTarget, string(mid), string(cid)).Scan(&key, &hash, &mode, &expiry, &version, &mime)
	if e != nil || key == "" {
		return MediaAccess{}, ErrMedia
	}
	var exists bool
	if tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM chat_av_objects WHERE object_key=? AND deleted_at IS NULL)`, key).Scan(&exists) != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	available := exists && (!expiry.Valid || expiry.Time.After(time.Now()))
	accessExpiry := expiry
	if !available {
		accessExpiry = sql.NullTime{}
	}
	ttl := mediaReadTTL(p, accessExpiry)
	if ttl < time.Second {
		return MediaAccess{}, ErrExpired
	}
	url := ""
	if available {
		url, e = g.base.av.storage.Sign(ctx, key, ttl)
		if e != nil {
			return MediaAccess{}, e
		}
	}
	if e = governanceLog(ctx, tx, p, "audit.av", cid, mid, "", version, ""); e != nil {
		return MediaAccess{}, e
	}
	if e = g.commit(tx, p); e != nil {
		return MediaAccess{}, e
	}
	out := MediaAccess{CloudAvailable: available, URL: url, ExpiresAt: time.Now().Add(ttl).Unix(), MIME: mime, ObjectHash: hash, StateVersion: version, RetentionMode: mode, ServerTime: time.Now().UTC()}
	if expiry.Valid {
		out.MessageExpiresAt = &expiry.Time
	}
	return out, nil
}
