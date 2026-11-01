package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
	"unicode/utf8"
)

type AVReview struct {
	ConversationID ports.ID  `json:"conversation_id"`
	SenderID       ports.ID  `json:"sender_id"`
	IntentID       ports.ID  `json:"intent_id"`
	Version        ports.ID  `json:"expected_version"`
	Hash           string    `json:"manifest_hash"`
	Kind           string    `json:"message_type"`
	DurationMS     int       `json:"duration_ms"`
	Transcript     string    `json:"transcript"`
	HasAudio       bool      `json:"has_audio"`
	State          string    `json:"review_state"`
	ExpiresAt      time.Time `json:"expires_at"`
}

func (a *AVService) admin(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	if p.Environment != a.environment {
		return auth.ErrUnauthorized
	}
	source, e := auth.NewMySQLSource(a.base.db)
	if e != nil {
		return e
	}
	return source.ValidateAdminLocked(ctx, tx, p, "chat:media")
}
func avAudit(ctx context.Context, tx *sql.Tx, p ports.Principal, id, version ports.ID, action, reason string) error {
	_, e := tx.ExecContext(ctx, `INSERT INTO chat_governance_audit(actor_kind,actor_id,session_binding,action,target_id,target_version,reason,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))`, p.Kind, string(p.ActorID), p.SessionID, "av."+action, string(id), string(version), reason)
	if e != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) Reviews(ctx context.Context, p ports.Principal, after ports.ID) ([]AVReview, error) {
	if after == "" {
		after = "0"
	}
	if !after.Valid() {
		return nil, ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return nil, e
	}
	defer tx.Rollback()
	if e = a.admin(ctx, tx, p); e != nil {
		return nil, e
	}
	rows, e := tx.QueryContext(ctx, `SELECT CAST(i.conversation_id AS CHAR),CAST(i.sender_id AS CHAR),CAST(j.intent_id AS CHAR),CAST(j.review_version AS CHAR),j.manifest_hash,u.kind,u.duration_ms,COALESCE(j.transcript,''),u.has_audio,j.review_state,j.expires_at FROM chat_av_jobs j JOIN chat_av_uploads u ON u.id=j.upload_id JOIN chat_send_intents i ON i.id=j.intent_id WHERE j.intent_id>? AND j.phase='review_pending' AND j.review_state IN ('pending','claimed') AND i.state='review_pending' AND j.expires_at>UTC_TIMESTAMP(6) ORDER BY j.intent_id LIMIT 20`, string(after))
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	out := []AVReview{}
	for rows.Next() {
		var v AVReview
		if rows.Scan(&v.ConversationID, &v.SenderID, &v.IntentID, &v.Version, &v.Hash, &v.Kind, &v.DurationMS, &v.Transcript, &v.HasAudio, &v.State, &v.ExpiresAt) != nil {
			rows.Close()
			return nil, ports.ErrUnavailable
		}
		out = append(out, v)
	}
	e = rows.Err()
	rows.Close()
	if e != nil || tx.Commit() != nil {
		return nil, ports.ErrUnavailable
	}
	return out, nil
}
func (a *AVService) Review(ctx context.Context, p ports.Principal, id, expected ports.ID, action, reason string, general, group bool) (AVReview, error) {
	var out AVReview
	if !validID(id) || !validID(expected) || (action != "claim" && action != "approve" && action != "reject") || utf8.RuneCountInString(reason) < 2 || utf8.RuneCountInString(reason) > 200 {
		return out, ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return out, e
	}
	defer tx.Rollback()
	if e = a.admin(ctx, tx, p); e != nil {
		return out, e
	}
	var version ports.ID
	var state, hash string
	var reviewer sql.NullString
	var lease bool
	e = tx.QueryRowContext(ctx, `SELECT CAST(j.review_version AS CHAR),j.review_state,j.manifest_hash,CAST(j.reviewer_id AS CHAR),COALESCE(j.review_lease_until>UTC_TIMESTAMP(6),0) FROM chat_send_intents i JOIN chat_av_jobs j ON j.intent_id=i.id WHERE i.id=? AND i.state='review_pending' AND j.phase='review_pending' AND j.expires_at>UTC_TIMESTAMP(6) AND j.rule_version=? AND j.configuration_hash=? FOR UPDATE`, string(id), AVRule, a.configuration).Scan(&version, &state, &hash, &reviewer, &lease)
	if e != nil || version != expected {
		return out, ErrMediaVersion
	}
	if action == "claim" {
		if state != "pending" && !(state == "claimed" && (!lease || reviewer.String == string(p.ActorID))) {
			return out, ErrMediaVersion
		}
		_, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET review_state='claimed',reviewer_id=?,review_lease_until=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE,review_version=review_version+1 WHERE intent_id=?`, string(p.ActorID), string(id))
		state = "claimed"
	} else {
		if state != "claimed" || !lease || reviewer.String != string(p.ActorID) {
			return out, ErrMediaVersion
		}
		if action == "approve" {
			var viewed int
			if !general || !group || tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_governance_audit WHERE action='av.view' AND actor_id=? AND target_id=? AND target_version=? AND session_binding=?`, string(p.ActorID), string(id), string(expected), p.SessionID).Scan(&viewed) != nil || viewed == 0 {
				return out, ErrMedia
			}
			state = "approved"
			_, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET review_state='approved',general_confirmed=1,group_confirmed=1,review_version=review_version+1,review_lease_until=NULL,phase='ready',next_attempt_at=UTC_TIMESTAMP(6) WHERE intent_id=?`, string(id))
		} else {
			state = "rejected"
			_, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET review_state='rejected',review_version=review_version+1,review_lease_until=NULL,phase='failed',error_code=? WHERE intent_id=?`, ErrBlocked.Error(), string(id))
			if e == nil {
				_, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='rejected',error_code=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, ErrBlocked.Error(), string(id))
			}
		}
	}
	if e != nil {
		return out, ports.ErrUnavailable
	}
	if e = avAudit(ctx, tx, p, id, expected, action, reason); e != nil {
		return out, e
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return out, ports.ErrUnavailable
	}
	return AVReview{IntentID: id, Version: decimal(number(version) + 1), Hash: hash, State: state}, nil
}
func (a *AVService) ReviewURL(ctx context.Context, p ports.Principal, id, version ports.ID) (MediaAccess, error) {
	if !validID(id) || !validID(version) {
		return MediaAccess{}, ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return MediaAccess{}, e
	}
	defer tx.Rollback()
	if e = a.admin(ctx, tx, p); e != nil {
		return MediaAccess{}, e
	}
	var key, hash, mime string
	var expiry time.Time
	e = tx.QueryRowContext(ctx, `SELECT u.main_key,u.main_sha256,u.mime_type,j.expires_at FROM chat_send_intents i JOIN chat_av_jobs j ON j.intent_id=i.id JOIN chat_av_uploads u ON u.id=j.upload_id WHERE i.id=? AND i.state='review_pending' AND j.phase='review_pending' AND j.review_state='claimed' AND j.reviewer_id=? AND j.review_version=? AND j.review_lease_until>UTC_TIMESTAMP(6) AND j.expires_at>UTC_TIMESTAMP(6) AND j.rule_version=? AND j.configuration_hash=? FOR UPDATE`, string(id), string(p.ActorID), string(version), AVRule, a.configuration).Scan(&key, &hash, &mime, &expiry)
	if e != nil {
		return MediaAccess{}, ErrMediaVersion
	}
	ttl := min(60*time.Second, time.Until(expiry), time.Until(p.ExpiresAt))
	if ttl < time.Second {
		return MediaAccess{}, ErrExpired
	}
	url, e := a.storage.Sign(ctx, key, ttl)
	if e != nil {
		return MediaAccess{}, e
	}
	if e = avAudit(ctx, tx, p, id, version, "view", "已领取预审媒体临时访问"); e != nil {
		return MediaAccess{}, e
	}
	if tx.Commit() != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	return MediaAccess{CloudAvailable: true, URL: url, ExpiresAt: time.Now().Add(ttl).Unix(), MIME: mime, ObjectHash: hash, StateVersion: version, ServerTime: time.Now().UTC()}, nil
}
