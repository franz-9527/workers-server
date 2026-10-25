package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
)

// Run is sequential: one image/helper at a time, including retries. Deployers
// must run one worker until a global resource limiter is introduced.
func (m *MediaService) Run(ctx context.Context) {
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	for {
		if ctx.Err() != nil {
			return
		}
		_ = m.ProcessOne(ctx)
		_ = m.Advance(ctx)
		_ = m.CleanOne(ctx)
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}
func (m *MediaService) ProcessOne(ctx context.Context) error {
	tx, e := m.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var id ports.ID
	var key, version string
	var size int64
	e = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),source_key,object_version,declared_bytes FROM chat_image_uploads WHERE (state='queued' OR state='checking' AND lease_until<UTC_TIMESTAMP(6)) AND attempts<8 AND expires_at>UTC_TIMESTAMP(6) ORDER BY id LIMIT 1 FOR UPDATE`).Scan(&id, &key, &version, &size)
	if e == sql.ErrNoRows {
		return nil
	}
	if e != nil {
		return ports.ErrUnavailable
	}
	owner := randomMedia()
	if owner == "" {
		return ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_image_uploads SET state='checking',lease_owner=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 90 SECOND,attempts=attempts+1 WHERE id=?`, owner, string(id)); e != nil {
		return ports.ErrUnavailable
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	work, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	raw, e := m.storage.Read(work, key, MaxImageBytes)
	var out ProcessedImage
	if e == nil && int64(len(raw)) != size {
		e = ErrMedia
	}
	if e == nil {
		out, e = m.processor.Process(work, raw)
	}
	if e != nil {
		return m.imageFailure(ctx, id, owner)
	}
	if len(out.Main) == 0 || len(out.Thumb) == 0 || len(out.Main) > MaxImageBytes || len(out.Thumb) > 1<<20 || out.Width < 1 || out.Height < 1 || int64(out.Width)*int64(out.Height) > MaxImagePixels ||
		(out.MIME != "image/jpeg" && out.MIME != "image/png" && out.MIME != "image/webp") ||
		(out.QRStatus != "completed" && out.QRStatus != "unavailable") || (out.QRDecision != "clear" && out.QRDecision != "review" && out.QRDecision != "blocked") ||
		(out.OCRStatus != "not_run" && out.OCRStatus != "unavailable") || !rulePattern.MatchString(out.QRVersion) || !rulePattern.MatchString(out.Method) {
		return m.imageFailure(ctx, id, owner)
	}
	mainHash, thumbHash := mediaHash(out.Main), mediaHash(out.Thumb)
	prefix := key[:len(key)-len("source")]
	mainKey, thumbKey := prefix+mainHash+"/main", prefix+thumbHash+"/thumb"
	for _, v := range []struct{ key, hash string }{{mainKey, mainHash}, {thumbKey, thumbHash}} {
		if _, e = m.base.db.ExecContext(ctx, `INSERT INTO chat_image_objects(object_key,upload_id,sha256,size_bytes,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE object_key=object_key`, v.key, string(id), v.hash, map[string]int{mainKey: len(out.Main), thumbKey: len(out.Thumb)}[v.key]); e != nil {
			return ports.ErrUnavailable
		}
	}
	if e = m.storage.Put(work, mainKey, out.Main, out.MIME); e == nil {
		e = m.storage.Put(work, thumbKey, out.Thumb, "image/jpeg")
	}
	if e != nil {
		return m.imageFailure(ctx, id, owner)
	}
	state := "processed"
	if out.QRDecision == "blocked" {
		state = "blocked"
	}
	res, e := m.base.db.ExecContext(ctx, `UPDATE chat_image_uploads SET state=?,source_sha256=?,main_key=?,thumb_key=?,main_sha256=?,thumb_sha256=?,mime_type=?,width_px=?,height_px=?,method=?,qr_status=?,qr_version=?,anti_diversion_result=?,optional_ocr_status=?,rule_version=?,lease_owner=NULL,lease_until=NULL,error_code=NULL WHERE id=? AND state='checking' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, state, mediaHash(raw), mainKey, thumbKey, mainHash, thumbHash, out.MIME, out.Width, out.Height, out.Method, out.QRStatus, out.QRVersion, out.QRDecision, out.OCRStatus, ImageRule, string(id), owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := res.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	// Source is temporary; only tested delivery artifacts are available to readers.
	if m.storage.Delete(ctx, key) == nil {
		_, _ = m.base.db.ExecContext(ctx, `UPDATE chat_image_uploads SET source_cleaned=1 WHERE id=?`, string(id))
	}
	return nil
}
func (m *MediaService) imageFailure(ctx context.Context, id ports.ID, owner string) error {
	_, e := m.base.db.ExecContext(ctx, `UPDATE chat_image_uploads SET state='check_unavailable',error_code='CHAT_CHECK_UNAVAILABLE',lease_owner=NULL,lease_until=NULL WHERE id=? AND state='checking' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6)`, string(id), owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	return ErrCheck
}

// Advance never creates messages. Manual mode requires full-image general
// safety AND ordered group anti-diversion review, even when QR found nothing.
func (m *MediaService) Advance(ctx context.Context) error {
	rows, e := m.base.db.QueryContext(ctx, `SELECT CAST(b.intent_id AS CHAR) FROM chat_image_bundles b JOIN chat_send_intents i ON i.id=b.intent_id WHERE i.state IN ('checking','review_pending','retryable') ORDER BY b.intent_id LIMIT 20`)
	if e != nil {
		return ports.ErrUnavailable
	}
	ids := []ports.ID{}
	for rows.Next() {
		var id ports.ID
		if rows.Scan(&id) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		ids = append(ids, id)
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return ports.ErrUnavailable
	}
	for _, id := range ids {
		if err := m.advanceOne(ctx, id); err != nil && err != ErrMedia && err != ErrCheck && err != policy.ErrDenied && err != ErrRestricted && err != ErrExpired {
			return err
		}
	}
	return nil
}
func (m *MediaService) advanceOne(ctx context.Context, id ports.ID) error {
	tx, e := m.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var raw, review, rule, state string
	var live bool
	if tx.QueryRowContext(ctx, `SELECT state FROM chat_send_intents WHERE id=? FOR UPDATE`, string(id)).Scan(&state) != nil {
		return ports.ErrUnavailable
	}
	e = tx.QueryRowContext(ctx, `SELECT b.manifest_json,b.review_state,b.rule_version,i.state,b.expires_at>UTC_TIMESTAMP(6) FROM chat_image_bundles b JOIN chat_send_intents i ON i.id=b.intent_id WHERE b.intent_id=? FOR UPDATE`, string(id)).Scan(&raw, &review, &rule, &state, &live)
	if e != nil {
		return ports.ErrUnavailable
	}
	if state == "accepted" || state == "rejected" || state == "canceled" {
		return nil
	}
	var q Request
	if json.Unmarshal([]byte(raw), &q) != nil {
		return ports.ErrUnavailable
	}
	next, code := "checking", ""
	if review == "rejected" {
		next, code = "rejected", ErrBlocked.Error()
	} else if !live {
		next, code = "rejected", ErrExpired.Error()
	} else if rule != ImageRule {
		next, code = "rejected", ErrMediaVersion.Error()
	} else {
		ready := true
		for _, v := range q.Items {
			var st, rv string
			if tx.QueryRowContext(ctx, `SELECT state,COALESCE(rule_version,'') FROM chat_image_uploads WHERE id=? AND object_version=?`, string(v.AttachmentID), v.ObjectVersion).Scan(&st, &rv) != nil {
				return ErrMedia
			}
			if st == "blocked" {
				next, code = "rejected", ErrBlocked.Error()
				break
			}
			if st == "check_unavailable" {
				next, code = "retryable", ErrCheck.Error()
				ready = false
			}
			if st != "processed" || rv != ImageRule {
				ready = false
			}
		}
		if ready {
			next = "review_pending"
			if review == "waiting" {
				if _, e = tx.ExecContext(ctx, `UPDATE chat_image_bundles SET review_state='pending',review_version=review_version+1 WHERE intent_id=?`, string(id)); e != nil {
					return ports.ErrUnavailable
				}
			}
		}
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state=?,error_code=NULLIF(?,''),updated_at=UTC_TIMESTAMP(6) WHERE id=?`, next, code, string(id)); e != nil {
		return ports.ErrUnavailable
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	if live && review == "approved" {
		_, e = m.Publish(ctx, id)
		return e
	}
	return nil
}
func (m *MediaService) workerPrincipal(ctx context.Context, id ports.ID) (ports.Principal, Request, error) {
	var raw, manifest string
	var p ports.Principal
	var q Request
	if m.base.db.QueryRowContext(ctx, `SELECT principal_json,manifest_json FROM chat_image_bundles WHERE intent_id=?`, string(id)).Scan(&raw, &manifest) != nil || json.Unmarshal([]byte(raw), &p) != nil || json.Unmarshal([]byte(manifest), &q) != nil {
		return p, q, ErrMedia
	}
	if p.Kind != "mini" || p.Environment != m.environment {
		return p, q, policy.ErrDenied
	}
	var expiry int64
	if err := m.base.db.QueryRowContext(ctx, `SELECT UNIX_TIMESTAMP(expires_at) FROM login_sessions WHERE id=? AND user_id=? AND status='active'`, string(p.SourceID), string(p.ActorID)).Scan(&expiry); err != nil {
		if err == sql.ErrNoRows {
			return p, q, policy.ErrDenied
		}
		return p, q, ports.ErrUnavailable
	}
	// Refresh only the short-lived access deadline of the already-confirmed
	// action; LockAuthority still validates original source/session generation.
	p.ExpiresAt = time.Unix(expiry, 0)
	return p, q, nil
}
func (m *MediaService) Publish(ctx context.Context, id ports.ID) (Result, error) {
	p, q, e := m.workerPrincipal(ctx, id)
	if e != nil {
		if e == policy.ErrDenied {
			m.rejectMedia(ctx, id, e.Error())
		}
		return Result{}, e
	}
	fp, e := mediaFingerprint(q)
	if e != nil {
		return Result{}, e
	}
	// Verify exact delivered bytes before taking business locks; immutable keys
	// prohibit changes between this verification and commit.
	for _, v := range q.Items {
		var main, thumb, mh, th string
		if m.base.db.QueryRowContext(ctx, `SELECT main_key,thumb_key,main_sha256,thumb_sha256 FROM chat_image_uploads WHERE id=? AND object_version=? AND state='processed' AND rule_version=?`, string(v.AttachmentID), v.ObjectVersion, ImageRule).Scan(&main, &thumb, &mh, &th) != nil {
			return Result{}, ErrMedia
		}
		for _, object := range []struct{ key, hash string }{{main, mh}, {thumb, th}} {
			b, err := m.storage.Read(ctx, object.key, MaxImageBytes)
			if err != nil || mediaHash(b) != object.hash {
				return Result{}, ErrMedia
			}
			if keeper, ok := m.storage.(ObjectKeeper); ok {
				if err = keeper.Keep(ctx, object.key); err != nil {
					return Result{}, ErrMedia
				}
			} else if m.environment != "test" {
				return Result{}, ErrMedia
			}
		}
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	g, e := m.base.authorize(ctx, tx, p, q.ConversationID)
	if e != nil {
		tx.Rollback()
		if e == ErrRestricted || e == policy.ErrDenied {
			m.rejectMedia(ctx, id, e.Error())
		}
		return Result{}, e
	}
	i, e := m.base.lookup(ctx, tx, p, q.ConversationID, q.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if i == nil || i.id != id || i.fingerprint != fp {
		return Result{}, ErrConflict
	}
	if i.result.State == "accepted" {
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return i.result, nil
	}
	if i.result.State == "rejected" || i.result.State == "canceled" {
		return i.result, nil
	}
	var hash, review, rule, general, anti string
	var live bool
	e = tx.QueryRowContext(ctx, `SELECT manifest_hash,review_state,rule_version,COALESCE(general_safety_result,''),COALESCE(anti_diversion_result,''),submitted_at IS NOT NULL AND expires_at>UTC_TIMESTAMP(6) FROM chat_image_bundles WHERE intent_id=? FOR UPDATE`, string(id)).Scan(&hash, &review, &rule, &general, &anti, &live)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if !live || !active(q.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	if hash != fp || rule != ImageRule || review != "approved" || general != "passed" || anti != "passed" {
		return Result{}, ErrMedia
	}
	if _, e = validateReferences(ctx, tx, p, q, g); e != nil {
		tx.Rollback()
		m.rejectMedia(ctx, id, e.Error())
		return Result{}, e
	}
	for _, v := range q.Items {
		var version, state, rule string
		var live bool
		if tx.QueryRowContext(ctx, `SELECT object_version,state,COALESCE(rule_version,''),expires_at>UTC_TIMESTAMP(6) FROM chat_image_uploads WHERE id=? AND actor_id=? AND conversation_id=? LOCK IN SHARE MODE`, string(v.AttachmentID), string(p.ActorID), string(q.ConversationID)).Scan(&version, &state, &rule, &live) != nil || !live || version != v.ObjectVersion || state != "processed" || rule != ImageRule {
			return Result{}, ErrMedia
		}
	}
	var seq, change uint64
	if tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq FROM chat_conversations WHERE id=?`, string(q.ConversationID)).Scan(&seq, &change) != nil || seq == ^uint64(0) || change == ^uint64(0) {
		return Result{}, ports.ErrUnavailable
	}
	seq++
	change++
	res, e := tx.ExecContext(ctx, `INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,state,state_version,safety_rule_version,created_at,reply_to_message_id,reply_to_item_id) VALUES(?,?,?,?,?,?,'published',1,?,UTC_TIMESTAMP(6),?,?)`, string(q.ConversationID), seq, string(p.ActorID), q.ClientMessageID, string(g.Version), q.MessageType, ImageRule, nullableID(q.ReplyTo), nullableID(q.ReplyItem))
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	mid, e := res.LastInsertId()
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if e=stampSender(ctx,tx,mid,g);e!=nil{return Result{},e}
	if e = storagepolicy.Stamp(ctx, tx, mid, q.MessageType); e != nil {
		return Result{}, e
	}
	for _, v := range q.Items {
		if _, e = tx.ExecContext(ctx, `INSERT INTO chat_image_items(message_id,item_id,ordinal,object_version) VALUES(?,?,?,?)`, mid, string(v.AttachmentID), v.Ordinal, v.ObjectVersion); e != nil {
			return Result{}, ports.ErrUnavailable
		}
	}
	if _, e = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(?,?,?,1,'message.accepted',UTC_TIMESTAMP(6))`, string(q.ConversationID), change, mid); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_seq=?,last_change_seq=?,last_message_id=?,last_message_at=UTC_TIMESTAMP(6) WHERE id=?`, seq, change, mid, string(q.ConversationID)); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='accepted',message_id=?,error_code=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, mid, string(id)); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if e = reminderEvent(ctx, tx, q.ConversationID, seq); e != nil {
		return Result{}, e
	}
	var liveCommit bool
	if tx.QueryRowContext(ctx, `SELECT expires_at>UTC_TIMESTAMP(6) FROM chat_image_bundles WHERE intent_id=?`, string(id)).Scan(&liveCommit) != nil || !liveCommit || !time.Now().Before(p.ExpiresAt) {
		return Result{}, ErrExpired
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	if m.base.publisher != nil {
		m.base.publisher.Committed(ports.CommittedMessage{ConversationID: q.ConversationID, Seq: decimal(seq), PolicyVersion: g.Version})
	}
	return Result{ClientMessageID: q.ClientMessageID, State: "accepted", MessageID: ports.ID(fmt.Sprint(mid)), Seq: decimal(seq), PolicyVersion: g.Version, StateVersion: "1", MessageState: "published"}, nil
}

func (m *MediaService) rejectMedia(ctx context.Context, id ports.ID, code string) {
	_, _ = m.base.db.ExecContext(ctx, `UPDATE chat_send_intents SET state='rejected',error_code=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state IN ('checking','retryable','review_pending')`, code, string(id))
}
