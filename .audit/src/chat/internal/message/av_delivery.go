package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"time"
)

func hydrateAV(ctx context.Context, tx *sql.Tx, p ports.Principal, views []*MessageView) error {
	marks := []string{}
	args := []any{string(p.ActorID)}
	byID := map[ports.ID][]*MessageView{}
	for _, v := range views {
		v.AV = nil
		if v.State == "published" && (v.Type == "audio" || v.Type == "video") {
			if len(byID[v.ID]) == 0 {
				marks = append(marks, "?")
				args = append(args, string(v.ID))
			}
			byID[v.ID] = append(byID[v.ID], v)
		}
	}
	if len(marks) == 0 {
		return nil
	}
	rows, e := tx.QueryContext(ctx, `SELECT CAST(i.message_id AS CHAR),CAST(i.upload_id AS CHAR),u.duration_ms,u.transcript,u.has_audio,COALESCE(u.frames_json,'[]'),EXISTS(SELECT 1 FROM chat_av_listens l WHERE l.actor_id=? AND l.message_id=i.message_id) FROM chat_av_items i JOIN chat_av_uploads u ON u.id=i.upload_id AND u.object_version=i.object_version WHERE i.message_id IN (`+strings.Join(marks, ",")+`)`, args...)
	if e != nil {
		return ports.ErrUnavailable
	}
	defer rows.Close()
	for rows.Next() {
		var id ports.ID
		var v AVView
		var transcript sql.NullString
		var frameJSON string
		if rows.Scan(&id, &v.AttachmentID, &v.DurationMS, &transcript, &v.HasAudio, &frameJSON, &v.Heard) != nil {
			return ports.ErrUnavailable
		}
		var frames []avFrameRecord
		if json.Unmarshal([]byte(frameJSON), &frames) != nil || len(frames) > 8 {
			return ports.ErrUnavailable
		}
		for i, frame := range frames {
			v.Frames = append(v.Frames, AVFrameView{Ordinal: i, AtMS: frame.AtMS})
		}
		if p.Kind != "mini" {
			v.Heard = false
		}
		if transcript.Valid {
			v.Transcript = &transcript.String
		}
		for _, parent := range byID[id] {
			copy := v
			parent.AV = &copy
		}
	}
	if rows.Err() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) URL(ctx context.Context, p ports.Principal, cid, mid ports.ID, poster bool, frame ...int) (MediaAccess, error) {
	if !validID(cid) || !validID(mid) {
		return MediaAccess{}, ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return MediaAccess{}, e
	}
	defer tx.Rollback()
	g, e := a.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"})
	if e != nil {
		return MediaAccess{}, e
	}
	column, hashColumn := "u.main_key", "u.main_sha256"
	if poster {
		column, hashColumn = "u.poster_key", "u.poster_sha256"
	}
	var key, hash, mode, mime, frameJSON string
	var expiry sql.NullTime
	var version ports.ID
	e = tx.QueryRowContext(ctx, `SELECT `+column+`,`+hashColumn+`,m.retention_mode,m.expires_at,CAST(m.state_version AS CHAR),u.mime_type,COALESCE(u.frames_json,'[]') FROM chat_messages m JOIN chat_av_items i ON i.message_id=m.id JOIN chat_av_uploads u ON u.id=i.upload_id AND u.object_version=i.object_version WHERE m.id=? AND m.conversation_id=? AND m.state='published' AND `+retainedTarget+` AND `+visibleColumn, string(mid), string(cid), string(g.Version), string(p.ActorID)).Scan(&key, &hash, &mode, &expiry, &version, &mime, &frameJSON)
	if e != nil || key == "" {
		return MediaAccess{}, ErrMedia
	}
	if len(frame) > 1 {
		return MediaAccess{}, ports.ErrInvalid
	}
	if len(frame) == 1 {
		var frames []avFrameRecord
		if frame[0] < 0 || frame[0] >= 8 || json.Unmarshal([]byte(frameJSON), &frames) != nil || frame[0] >= len(frames) {
			return MediaAccess{}, ErrMedia
		}
		key, hash = frames[frame[0]].Key, frames[frame[0]].Hash
		poster = true
	}
	var exists bool
	if tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM chat_av_objects WHERE object_key=? AND deleted_at IS NULL)`, key).Scan(&exists) != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	available := exists && (poster || !expiry.Valid || expiry.Time.After(time.Now()))
	accessExpiry := expiry
	if poster || !available {
		accessExpiry = sql.NullTime{}
	}
	ttl := mediaReadTTL(p, accessExpiry)
	if ttl < time.Second {
		return MediaAccess{}, ErrExpired
	}
	url := ""
	if available {
		url, e = a.storage.Sign(ctx, key, ttl)
		if e != nil {
			return MediaAccess{}, e
		}
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_objects SET last_access_at=UTC_TIMESTAMP(6) WHERE object_key=?`, key); e != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	if poster {
		mime = "image/jpeg"
	}
	out := MediaAccess{CloudAvailable: available, URL: url, ExpiresAt: time.Now().Add(ttl).Unix(), ObjectHash: hash, RetentionMode: mode, StateVersion: version, MIME: mime, ServerTime: time.Now().UTC()}
	if expiry.Valid {
		out.MessageExpiresAt = &expiry.Time
	}
	return out, nil
}
func (a *AVService) Listened(ctx context.Context, p ports.Principal, cid, mid ports.ID) error {
	if !validID(cid) || !validID(mid) {
		return ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	g, e := a.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"})
	if e != nil {
		return e
	}
	var id ports.ID
	if tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR) FROM chat_messages m JOIN chat_av_items i ON i.message_id=m.id WHERE m.id=? AND m.conversation_id=? AND m.message_type='audio' AND m.state='published' AND `+retainedTarget+` AND `+visibleColumn, string(mid), string(cid), string(g.Version), string(p.ActorID)).Scan(&id) != nil {
		return ErrMedia
	}
	if _, e = tx.ExecContext(ctx, `INSERT IGNORE INTO chat_av_listens(actor_id,message_id,first_listened_at) VALUES(?,?,UTC_TIMESTAMP(6))`, string(p.ActorID), string(mid)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) Status(ctx context.Context, p ports.Principal, cid ports.ID, client string, cancel bool) (AVStatus, error) {
	out := AVStatus{Uploaded: []int{}}
	if !validID(cid) || !validClient(client) {
		return out, ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return out, e
	}
	defer tx.Rollback()
	if _, e = a.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"}); e != nil {
		return out, e
	}
	i, e := a.base.lookup(ctx, tx, p, cid, client)
	if e != nil {
		return out, e
	}
	if i == nil && cancel {
		_, e = tx.ExecContext(ctx, `INSERT INTO chat_send_intents(sender_id,conversation_id,client_message_id,fingerprint,state,attempts,action_expires_at,retain_until,error_code,created_at,updated_at) VALUES(?,?,?,'','canceled',0,?,?,'CHAT_ACTION_CANCELED',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, string(p.ActorID), string(cid), client, expires(client), expires(client).Add(MinimumRetention))
		if e != nil || tx.Commit() != nil {
			return out, ports.ErrUnavailable
		}
		out.Result = Result{ClientMessageID: client, State: "canceled", Code: "CHAT_ACTION_CANCELED"}
		return out, nil
	}
	if i == nil {
		return out, ErrUnknown
	}
	out.Result = i.result
	out.IntentID = i.id
	if err := tx.QueryRowContext(ctx, `SELECT CAST(review_version AS CHAR) FROM chat_av_jobs WHERE intent_id=?`, string(i.id)).Scan(&out.ReviewVersion); err != nil && !(err == sql.ErrNoRows && out.State == "canceled") {
		return out, ErrMedia
	}
	e = tx.QueryRowContext(ctx, `SELECT j.phase,CAST(u.id AS CHAR),u.object_version,u.part_count,j.expires_at FROM chat_av_jobs j JOIN chat_av_uploads u ON u.id=j.upload_id WHERE j.intent_id=? FOR UPDATE`, string(i.id)).Scan(&out.Phase, &out.AttachmentID, &out.ObjectVersion, &out.PartCount, &out.ExpiresAt)
	if e == sql.ErrNoRows && out.State == "canceled" {
		if tx.Commit() != nil {
			return out, ports.ErrUnavailable
		}
		return out, nil
	}
	if e != nil {
		return out, ErrMedia
	}
	if cancel && out.State != "accepted" && out.State != "rejected" && out.State != "canceled" {
		out.State = "canceled"
		out.Code = "CHAT_ACTION_CANCELED"
	}
	if !time.Now().Before(out.ExpiresAt) && out.State != "accepted" && out.State != "canceled" && out.State != "rejected" {
		out.State = "rejected"
		out.Code = ErrExpired.Error()
	}
	if out.State != i.result.State {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state=?,error_code=?,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, out.State, out.Code, string(i.id)); e != nil {
			return out, ports.ErrUnavailable
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='failed',lease_owner=NULL WHERE intent_id=?`, string(i.id)); e != nil {
			return out, ports.ErrUnavailable
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET state='canceled',expires_at=LEAST(expires_at,UTC_TIMESTAMP(6)) WHERE id=?`, string(out.AttachmentID)); e != nil {
			return out, ports.ErrUnavailable
		}
	}
	rows, e := tx.QueryContext(ctx, `SELECT ordinal FROM chat_av_parts WHERE upload_id=? AND state='present' ORDER BY ordinal`, string(out.AttachmentID))
	if e != nil {
		return out, ports.ErrUnavailable
	}
	for rows.Next() {
		var n int
		if rows.Scan(&n) != nil {
			rows.Close()
			return out, ports.ErrUnavailable
		}
		out.Uploaded = append(out.Uploaded, n)
	}
	e = rows.Err()
	rows.Close()
	if e != nil || tx.Commit() != nil {
		return out, ports.ErrUnavailable
	}
	return out, nil
}
func (a *AVService) Pending(ctx context.Context, p ports.Principal, cid ports.ID) ([]AVStatus, error) {
	if _, e := a.base.conversations.Authorize(ctx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"}); e != nil {
		return nil, e
	}
	rows, e := a.base.db.QueryContext(ctx, `SELECT i.client_message_id FROM chat_send_intents i JOIN chat_av_jobs j ON j.intent_id=i.id WHERE i.sender_id=? AND i.conversation_id=? AND i.state IN ('checking','review_pending') AND j.expires_at>UTC_TIMESTAMP(6) ORDER BY i.id DESC LIMIT 20`, string(p.ActorID), string(cid))
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	clients := []string{}
	for rows.Next() {
		var client string
		if rows.Scan(&client) != nil {
			rows.Close()
			return nil, ports.ErrUnavailable
		}
		clients = append(clients, client)
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	out := []AVStatus{}
	for _, client := range clients {
		v, e := a.Status(ctx, p, cid, client, false)
		if e != nil {
			return nil, e
		}
		out = append(out, v)
	}
	return out, nil
}
