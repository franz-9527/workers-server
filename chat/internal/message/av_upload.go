package message

import (
	"context"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
	"strconv"
	"time"
)

func validFileMD5(v string) bool {
	b, e := hex.DecodeString(v)
	return e == nil && len(b) == 16 && v == hex.EncodeToString(b)
}
func (a *AVService) Upload(ctx context.Context, p ports.Principal, cid ports.ID, client, source string, size int64, digest string) (AVUpload, error) {
	limit, _ := avLimits(source)
	kind := avKind(source)
	if !validID(cid) || !validClient(client) || !active(client, time.Now()) || !validFileMD5(digest) || limit == 0 || size < 1 || size > limit || !a.enabled(kind) {
		return AVUpload{}, ErrAVLimit
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return AVUpload{}, e
	}
	defer tx.Rollback()
	if _, e = a.base.authorize(ctx, tx, p, cid); e != nil {
		return AVUpload{}, e
	}
	var u AVUpload
	var declared int64
	var oldSource, oldMD5 string
	e = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),object_version,state,part_count,expires_at,declared_bytes,source,source_md5 FROM chat_av_uploads WHERE actor_id=? AND conversation_id=? AND upload_id=? FOR UPDATE`, string(p.ActorID), string(cid), client).Scan(&u.AttachmentID, &u.ObjectVersion, &u.State, &u.PartCount, &u.ExpiresAt, &declared, &oldSource, &oldMD5)
	if e == sql.ErrNoRows {
		var pending, count int
		var bytes int64
		if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_av_uploads WHERE actor_id=? AND state IN ('uploading','uploaded','preparing') AND expires_at>UTC_TIMESTAMP(6)`, string(p.ActorID)).Scan(&pending) != nil || pending >= 4 {
			return AVUpload{}, ports.ErrBusy
		}
		settings, err := storagepolicy.Load(ctx, tx)
		if err != nil {
			return AVUpload{}, ports.ErrUnavailable
		}
		if tx.QueryRowContext(ctx, `SELECT COUNT(*),COALESCE(SUM(declared_bytes),0) FROM chat_av_uploads u WHERE actor_id=? AND kind=? AND created_at>UTC_TIMESTAMP(6)-INTERVAL 1 DAY AND (state<>'canceled' OR EXISTS(SELECT 1 FROM chat_av_parts p WHERE p.upload_id=u.id AND p.grant_until IS NOT NULL))`, string(p.ActorID), kind).Scan(&count, &bytes) != nil {
			return AVUpload{}, ports.ErrUnavailable
		}
		if kind == "video" && (count >= settings.Policy.Quota.VideoCount || bytes+size > settings.Policy.Quota.VideoBytes) || kind == "audio" && (count >= 200 || bytes+size > 200<<20) {
			return AVUpload{}, ports.ErrBusy
		}
		u.ObjectVersion = randomMedia()
		if u.ObjectVersion == "" {
			return AVUpload{}, ports.ErrUnavailable
		}
		u.State = "uploading"
		u.PartCount = avParts(size)
		u.ExpiresAt = minTime(time.Now().UTC().Add(MediaTTL), expires(client))
		result, err := tx.ExecContext(ctx, `INSERT INTO chat_av_uploads(actor_id,conversation_id,upload_id,object_version,kind,source,declared_bytes,source_md5,part_count,state,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,'uploading',?,UTC_TIMESTAMP(6))`, string(p.ActorID), string(cid), client, u.ObjectVersion, kind, source, size, digest, u.PartCount, u.ExpiresAt)
		if err != nil {
			return AVUpload{}, ports.ErrUnavailable
		}
		n, err := result.LastInsertId()
		if err != nil || n <= 0 {
			return AVUpload{}, ports.ErrUnavailable
		}
		u.AttachmentID = ports.ID(fmt.Sprint(n))
		for i := 1; i <= u.PartCount; i++ {
			key := "chatav/" + a.environment + "/" + string(p.ActorID) + "/" + u.ObjectVersion + "/part-" + strconv.Itoa(i)
			if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_parts(upload_id,ordinal,object_key,size_bytes,state,created_at) VALUES(?,?,?,?,'allocated',UTC_TIMESTAMP(6))`, string(u.AttachmentID), i, key, avPartSize(size, i)); e != nil {
				return AVUpload{}, ports.ErrUnavailable
			}
		}
	} else if e != nil {
		return AVUpload{}, ports.ErrUnavailable
	} else if declared != size || oldSource != source || oldMD5 != digest {
		return AVUpload{}, ErrConflict
	} else if !time.Now().Before(u.ExpiresAt) || u.State == "canceled" {
		return AVUpload{}, ErrExpired
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return AVUpload{}, ports.ErrUnavailable
	}
	u.PartBytes = AVPartBytes
	return u, nil
}
func minTime(a, b time.Time) time.Time {
	if a.Before(b) {
		return a
	}
	return b
}
func (a *AVService) Send(ctx context.Context, p ports.Principal, q Request) (Result, error) {
	fp, e := avFingerprint(q)
	if e != nil {
		return Result{}, e
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	if _, e = a.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: q.ConversationID, Action: "read"}); e != nil {
		return Result{}, e
	}
	previous, e := a.base.lookup(ctx, tx, p, q.ConversationID, q.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if previous != nil {
		if previous.result.State != "canceled" && previous.fingerprint != fp {
			return Result{}, ErrConflict
		}
		if !active(q.ClientMessageID, time.Now()) && !terminal(previous.result.State) {
			return Result{}, ErrExpired
		}
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return previous.result, nil
	}
	tx.Rollback()
	if !a.enabled(q.MessageType) || !active(q.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	tx, e = a.base.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	g, e := a.base.authorize(ctx, tx, p, q.ConversationID)
	if e != nil {
		return Result{}, e
	}
	previous, e = a.base.lookup(ctx, tx, p, q.ConversationID, q.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if previous != nil {
		if previous.result.State != "canceled" && previous.fingerprint != fp {
			return Result{}, ErrConflict
		}
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return previous.result, nil
	}
	item := q.Items[0]
	var kind, version, state, uploadNonce string
	var live bool
	if tx.QueryRowContext(ctx, `SELECT kind,object_version,state,upload_id,expires_at>UTC_TIMESTAMP(6) FROM chat_av_uploads WHERE id=? AND actor_id=? AND conversation_id=? FOR UPDATE`, string(item.AttachmentID), string(p.ActorID), string(q.ConversationID)).Scan(&kind, &version, &state, &uploadNonce, &live) != nil {
		return Result{}, ErrMedia
	}
	if !live || kind != q.MessageType || version != item.ObjectVersion || state != "uploading" || uploadNonce != q.ClientMessageID {
		return Result{}, ErrMediaVersion
	}
	if _, e = validateReferences(ctx, tx, p, q, g); e != nil {
		return Result{}, e
	}
	principal, _ := json.Marshal(p)
	manifest, _ := json.Marshal(q)
	deadline := expires(q.ClientMessageID)
	result, e := tx.ExecContext(ctx, `INSERT INTO chat_send_intents(sender_id,conversation_id,client_message_id,fingerprint,state,attempts,action_expires_at,retain_until,created_at,updated_at) VALUES(?,?,?,?,'checking',0,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, string(p.ActorID), string(q.ConversationID), q.ClientMessageID, fp, deadline, deadline.Add(MinimumRetention))
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	n, e := result.LastInsertId()
	if e != nil || n <= 0 {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_jobs(intent_id,upload_id,principal_json,manifest_json,manifest_hash,rule_version,configuration_hash,phase,next_attempt_at,expires_at,submitted_at) VALUES(?,?,?,?,?,?,?,'uploading',UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6))`, n, string(item.AttachmentID), string(principal), string(manifest), fp, AVRule, a.configuration, deadline); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET expires_at=? WHERE id=?`, deadline, string(item.AttachmentID)); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if !active(q.ClientMessageID, time.Now()) || !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	return Result{ClientMessageID: q.ClientMessageID, State: "checking"}, nil
}
func (a *AVService) Part(ctx context.Context, p ports.Principal, cid, upload ports.ID, ordinal int, confirm bool) (AVPartGrant, error) {
	if !validID(cid) || !validID(upload) || ordinal < 1 || ordinal > 10 {
		return AVPartGrant{}, ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return AVPartGrant{}, e
	}
	defer tx.Rollback()
	if _, e = a.base.authorize(ctx, tx, p, cid); e != nil {
		return AVPartGrant{}, e
	}
	var key, version, state string
	var size int64
	var deadline time.Time
	e = tx.QueryRowContext(ctx, `SELECT v.object_key,v.size_bytes,u.object_version,v.state,u.expires_at FROM chat_av_parts v JOIN chat_av_uploads u ON u.id=v.upload_id JOIN chat_av_jobs j ON j.upload_id=u.id JOIN chat_send_intents i ON i.id=j.intent_id WHERE u.id=? AND u.actor_id=? AND u.conversation_id=? AND v.ordinal=? AND u.state='uploading' AND u.expires_at>UTC_TIMESTAMP(6) AND j.phase='uploading' AND j.expires_at>UTC_TIMESTAMP(6) AND i.state='checking' FOR UPDATE`, string(upload), string(p.ActorID), string(cid), ordinal).Scan(&key, &size, &version, &state, &deadline)
	if e != nil {
		return AVPartGrant{}, ErrMediaVersion
	}
	if state == "present" {
		if tx.Commit() != nil {
			return AVPartGrant{}, ports.ErrUnavailable
		}
		return AVPartGrant{UploadGrant: UploadGrant{AttachmentID: upload, ObjectVersion: version, State: "present"}, Ordinal: ordinal, Size: size}, nil
	}
	if !confirm {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_parts SET state='issued',grant_until=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE WHERE upload_id=? AND ordinal=?`, string(upload), ordinal); e != nil {
			return AVPartGrant{}, ports.ErrUnavailable
		}
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return AVPartGrant{}, ports.ErrUnavailable
	}
	if !confirm && state == "issued" {
		actual, err := a.storage.StatAV(ctx, key)
		if err == nil && actual == size {
			return a.Part(ctx, p, cid, upload, ordinal, true)
		}
		if err == nil {
			return AVPartGrant{}, ErrMedia
		}
	}
	if confirm {
		actual, err := a.storage.StatAV(ctx, key)
		if err != nil || actual != size {
			return AVPartGrant{}, ErrMedia
		}
		tx, e = a.base.begin(ctx)
		if e != nil {
			return AVPartGrant{}, e
		}
		defer tx.Rollback()
		if _, e = a.base.authorize(ctx, tx, p, cid); e != nil {
			return AVPartGrant{}, e
		}
		result, err := tx.ExecContext(ctx, `UPDATE chat_av_parts v JOIN chat_av_uploads u ON u.id=v.upload_id JOIN chat_av_jobs j ON j.upload_id=u.id JOIN chat_send_intents i ON i.id=j.intent_id SET v.state='present' WHERE u.id=? AND v.ordinal=? AND u.actor_id=? AND u.conversation_id=? AND u.state='uploading' AND j.phase='uploading' AND j.expires_at>UTC_TIMESTAMP(6) AND i.state='checking'`, string(upload), ordinal, string(p.ActorID), string(cid))
		if err != nil {
			return AVPartGrant{}, ports.ErrUnavailable
		}
		n, _ := result.RowsAffected()
		if n != 1 {
			return AVPartGrant{}, ErrMediaVersion
		}
		if tx.Commit() != nil {
			return AVPartGrant{}, ports.ErrUnavailable
		}
		return AVPartGrant{UploadGrant: UploadGrant{AttachmentID: upload, ObjectVersion: version, State: "present"}, Ordinal: ordinal, Size: size}, nil
	}
	token, host, e := a.storage.GrantAVPart(ctx, key, size)
	if e != nil {
		return AVPartGrant{}, e
	}
	return AVPartGrant{UploadGrant: UploadGrant{AttachmentID: upload, ObjectVersion: version, Key: key, Token: token, Host: host, ExpiresAt: time.Now().Add(5 * time.Minute).Unix(), State: "issued"}, Ordinal: ordinal, Size: size}, nil
}
func (a *AVService) Complete(ctx context.Context, p ports.Principal, cid, upload ports.ID) error {
	if !validID(cid) || !validID(upload) {
		return ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	if _, e = a.base.authorize(ctx, tx, p, cid); e != nil {
		return e
	}
	var state string
	var count int
	if tx.QueryRowContext(ctx, `SELECT state,part_count FROM chat_av_uploads WHERE id=? AND actor_id=? AND conversation_id=? AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE`, string(upload), string(p.ActorID), string(cid)).Scan(&state, &count) != nil {
		return ErrMedia
	}
	if state == "uploaded" || state == "prepared" {
		if tx.Commit() != nil {
			return ports.ErrUnavailable
		}
		return nil
	}
	var ready int
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_av_parts WHERE upload_id=? AND state='present'`, string(upload)).Scan(&ready) != nil {
		return ports.ErrUnavailable
	}
	if ready != count || state != "uploading" {
		return ErrMedia
	}
	result, e := tx.ExecContext(ctx, `UPDATE chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id SET j.phase='prepare',j.next_attempt_at=UTC_TIMESTAMP(6) WHERE j.upload_id=? AND j.phase='uploading' AND j.expires_at>UTC_TIMESTAMP(6) AND i.state='checking'`, string(upload))
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := result.RowsAffected()
	if n != 1 {
		return ErrMedia
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET state='uploaded' WHERE id=?`, string(upload)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
