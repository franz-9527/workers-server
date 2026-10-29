package message

import (
	"context"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
	"strings"
	"time"
)

func (a *AVService) Publish(ctx context.Context, id ports.ID) (Result, error) {
	v, e := a.job(ctx, id)
	p, q := v.Principal, v.Request
	if e != nil {
		if e == policy.ErrDenied {
			a.rejectAV(ctx, id, e.Error())
		}
		return Result{}, e
	}
	fp, e := avFingerprint(q)
	if e != nil {
		return Result{}, e
	}
	// Immutable delivery bytes are checked again before the final business transaction.
	for _, object := range []struct{ key, hash string }{{v.Main, v.MainHash}, {v.Poster, v.PosterHash}} {
		if object.key == "" {
			continue
		}
		b, err := a.storage.Read(ctx, object.key, MaxVideoBytes)
		if err != nil || mediaHash(b) != object.hash || a.storage.Keep(ctx, object.key) != nil {
			return Result{}, ErrMedia
		}
	}
	checked := ports.SafetyResult{Decision: "passed", RuleVersion: v.TextRule, AuthorityProof: v.TextProof}
	if strings.TrimSpace(v.Transcript) == "" {
		checked.Decision = "media_identity"
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	g, e := a.base.authorize(ctx, tx, p, q.ConversationID, checked)
	if e != nil {
		tx.Rollback()
		if e == ErrRestricted || e == policy.ErrDenied {
			a.rejectAV(ctx, id, e.Error())
		}
		return Result{}, e
	}
	i, e := a.base.lookup(ctx, tx, p, q.ConversationID, q.ClientMessageID)
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
	var hash, review, rule, phase, configuration, raw string
	var general, anti, live bool
	e = tx.QueryRowContext(ctx, `SELECT manifest_hash,review_state,rule_version,phase,configuration_hash,COALESCE(raw_decision,''),general_confirmed,group_confirmed,expires_at>UTC_TIMESTAMP(6) FROM chat_av_jobs WHERE intent_id=? FOR UPDATE`, string(id)).Scan(&hash, &review, &rule, &phase, &configuration, &raw, &general, &anti, &live)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if !live || !active(q.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	approved := review == "approved" && general && anti
	if hash != fp || rule != AVRule || configuration != a.configuration || phase != "ready" || q.MessageType == "video" && !approved || q.MessageType == "audio" && (raw != "pass" && !(raw == "review" && approved)) {
		return Result{}, ErrMedia
	}
	if v.TextProof != v.Proof || !isMediaHash(v.Proof) {
		return Result{}, ErrMedia
	}
	if q.MessageType == "audio" {
		var actual string
		if tx.QueryRowContext(ctx, `SELECT decision FROM chat_av_raw_receipts WHERE trace_id=? AND app_id=? LOCK IN SHARE MODE`, v.RawTrace, a.inspector.MediaAppID()).Scan(&actual) != nil || actual != raw {
			return Result{}, ErrMedia
		}
	}
	if _, e = validateReferences(ctx, tx, p, q, g); e != nil {
		tx.Rollback()
		a.rejectAV(ctx, id, e.Error())
		return Result{}, e
	}
	var uploadVersion, state string
	if tx.QueryRowContext(ctx, `SELECT object_version,state FROM chat_av_uploads WHERE id=? AND actor_id=? AND conversation_id=? LOCK IN SHARE MODE`, string(v.Upload), string(p.ActorID), string(q.ConversationID)).Scan(&uploadVersion, &state) != nil || uploadVersion != v.Version || state != "prepared" {
		return Result{}, ErrMedia
	}
	var seq, change uint64
	if tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq FROM chat_conversations WHERE id=?`, string(q.ConversationID)).Scan(&seq, &change) != nil || seq == ^uint64(0) || change == ^uint64(0) {
		return Result{}, ports.ErrUnavailable
	}
	seq++
	change++
	res, e := tx.ExecContext(ctx, `INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,state,state_version,safety_rule_version,created_at,reply_to_message_id,reply_to_item_id) VALUES(?,?,?,?,?,?,'published',1,?,UTC_TIMESTAMP(6),?,?)`, string(q.ConversationID), seq, string(p.ActorID), q.ClientMessageID, string(g.Version), q.MessageType, AVRule, nullableID(q.ReplyTo), nullableID(q.ReplyItem))
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	mid, e := res.LastInsertId()
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if e = stampSender(ctx, tx, mid, g); e != nil {
		return Result{}, e
	}
	if e = storagepolicy.Stamp(ctx, tx, mid, q.MessageType); e != nil {
		return Result{}, e
	}
	if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_items(message_id,upload_id,object_version) VALUES(?,?,?)`, mid, string(v.Upload), v.Version); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET state='published',transcript=? WHERE id=?`, v.Transcript, string(v.Upload)); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='published',transcript=NULL,principal_json='{}',lease_owner=NULL,lease_until=NULL WHERE intent_id=?`, string(id)); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_asr_requests SET text_result=NULL WHERE intent_id=?`, string(id)); e != nil {
		return Result{}, ports.ErrUnavailable
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
	if tx.QueryRowContext(ctx, `SELECT expires_at>UTC_TIMESTAMP(6) FROM chat_av_jobs WHERE intent_id=?`, string(id)).Scan(&liveCommit) != nil || !liveCommit || !time.Now().Before(p.ExpiresAt) {
		return Result{}, ErrExpired
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	if a.base.publisher != nil {
		a.base.publisher.Committed(ports.CommittedMessage{ConversationID: q.ConversationID, Seq: decimal(seq), PolicyVersion: g.Version})
	}
	return Result{ClientMessageID: q.ClientMessageID, State: "accepted", MessageID: ports.ID(fmt.Sprint(mid)), Seq: decimal(seq), PolicyVersion: g.Version, StateVersion: "1", MessageState: "published"}, nil
}

func (a *AVService) rejectAV(ctx context.Context, id ports.ID, code string) {
	_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_send_intents SET state='rejected',error_code=?,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state IN ('checking','review_pending')`, code, string(id))
}
