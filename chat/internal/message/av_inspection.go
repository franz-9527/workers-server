package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strconv"
	"strings"
	"time"
)

func (a *AVService) recognize(ctx context.Context, v avJob) error {
	if v.Ordinal >= len(v.Segments) {
		return a.advance(ctx, v, "text_ready")
	}
	segment := v.Segments[v.Ordinal]
	url, e := a.storage.InspectionURL(ctx, segment.Key)
	if e != nil {
		return e
	}
	key := mediaHash([]byte(string(v.ID) + "\n" + v.Hash + "\n" + strconv.Itoa(v.Ordinal) + "\n" + segment.Hash + "\n" + a.configuration))
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	// All actors share row zero; deterministic order prevents cross-account budget races.
	for _, actor := range []ports.ID{"0", v.Principal.ActorID} {
		if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_budgets(actor_id,day_start,asr_reserved) VALUES(?,UTC_DATE(),0) ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(actor)); e != nil {
			return ports.ErrUnavailable
		}
		var count int
		var current bool
		if tx.QueryRowContext(ctx, `SELECT asr_reserved,day_start=UTC_DATE() FROM chat_av_budgets WHERE actor_id=? FOR UPDATE`, string(actor)).Scan(&count, &current) != nil {
			return ports.ErrUnavailable
		}
		if !current {
			count = 0
		}
		limit := 200
		if actor == "0" {
			limit = a.dailyASR
		}
		if count >= limit {
			return ports.ErrBusy
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_budgets SET day_start=UTC_DATE(),asr_reserved=? WHERE actor_id=?`, count+1, string(actor)); e != nil {
			return ports.ErrUnavailable
		}
	}
	if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_asr_requests(intent_id,ordinal,request_key,state,created_at) VALUES(?,?,?,'started',UTC_TIMESTAMP(6))`, string(v.ID), v.Ordinal, key); e != nil {
		return ErrAVUnknown
	}
	r, e := tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='asr_started' WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	result, e := a.inspector.Recognize(ctx, ports.AudioInspectionRequest{Actor: v.Principal, ConversationID: v.Request.ConversationID, IntentID: v.ID, ObjectHash: segment.Hash, URL: url, Segment: v.Ordinal, UserAudioKey: key})
	if e != nil {
		if result.Known && result.Retryable {
			tx, e = a.base.begin(ctx)
			if e != nil {
				return e
			}
			defer tx.Rollback()
			if _, e = tx.ExecContext(ctx, `DELETE FROM chat_av_asr_requests WHERE intent_id=? AND ordinal=? AND state='started'`, string(v.ID), v.Ordinal); e != nil {
				return ports.ErrUnavailable
			}
			if !result.Dispatched {
				for _, actor := range []ports.ID{"0", v.Principal.ActorID} {
					if _, e = tx.ExecContext(ctx, `UPDATE chat_av_budgets SET asr_reserved=GREATEST(asr_reserved,1)-1 WHERE actor_id=? AND day_start=UTC_DATE()`, string(actor)); e != nil {
						return ports.ErrUnavailable
					}
				}
			}
			if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='asr_ready',next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 30 SECOND,lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND lease_owner=?`, string(v.ID), v.Owner); e != nil || tx.Commit() != nil {
				return ports.ErrUnavailable
			}
			return ErrCheck
		}
		if result.Known {
			a.fail(ctx, v.ID, v.Owner, ErrCheck.Error())
			return ErrCheck
		}
		return a.unknown(ctx, v)
	}
	if !result.Known || !isMediaHash(result.AuthorityProof) || v.Proof != "" && v.Proof != result.AuthorityProof || result.RequestID == "" {
		return a.unknown(ctx, v)
	}
	transcript := strings.TrimSpace(v.Transcript + " " + result.Text)
	if len([]rune(transcript)) > 2000 || len(transcript) > 4000 {
		a.fail(ctx, v.ID, v.Owner, "CHAT_AUDIO_TRANSCRIPT_TOO_LONG")
		return ErrCheck
	}
	meta, _ := json.Marshal(map[string]string{"service": result.ServiceID, "api": result.APIName, "engine": result.Engine, "request_id": result.RequestID})
	tx, e = a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	r, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET transcript=?,source_proof=?,asr_ordinal=asr_ordinal+1,phase='asr_ready',lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND phase='asr_started' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, transcript, result.AuthorityProof, string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ = r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_asr_requests SET state='completed',provider_request_id=?,text_result=?,source_proof=?,metadata_json=? WHERE intent_id=? AND ordinal=? AND state='started'`, result.RequestID, result.Text, result.AuthorityProof, string(meta), string(v.ID), v.Ordinal); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) unknown(ctx context.Context, v avJob) error {
	_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='unknown',error_code=?,lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND lease_owner=?`, ErrAVUnknown.Error(), string(v.ID), v.Owner)
	_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_send_intents SET error_code=? WHERE id=? AND state='checking'`, ErrAVUnknown.Error(), string(v.ID))
	return ErrAVUnknown
}
func (a *AVService) submitRaw(ctx context.Context, v avJob) error {
	url, e := a.storage.InspectionURL(ctx, v.Main)
	if e != nil {
		return e
	}
	if e = a.advanceStarted(ctx, v, "raw_started"); e != nil {
		return e
	}
	key := mediaHash([]byte(string(v.ID) + "\n" + v.MainHash + "\nraw"))
	result, e := a.inspector.SubmitAudio(ctx, ports.AudioInspectionRequest{Actor: v.Principal, ConversationID: v.Request.ConversationID, IntentID: v.ID, ObjectHash: v.MainHash, URL: url, Segment: 0, UserAudioKey: key})
	if e != nil {
		if result.Known && result.Retryable {
			return a.advance(ctx, v, "raw_ready")
		}
		if result.Known {
			a.fail(ctx, v.ID, v.Owner, ErrCheck.Error())
			return ErrCheck
		}
		return a.unknown(ctx, v)
	}
	if !result.Known || result.TraceID == "" || result.AuthorityProof != v.Proof {
		return a.unknown(ctx, v)
	}
	_, e = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET raw_trace_id=?,phase='raw_wait',lease_owner=NULL,lease_until=NULL,next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 5 SECOND WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, result.TraceID, string(v.ID), v.Owner)
	return e
}
func (a *AVService) advanceStarted(ctx context.Context, v avJob, phase string) error {
	r, e := a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET phase=? WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, phase, string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	return nil
}
func (a *AVService) ReceiveAudio(ctx context.Context, v ports.AudioReceipt) error {
	if v.AppID != a.inspector.MediaAppID() || !riskTracePattern.MatchString(v.TraceID) || v.TraceID == "" || !isMediaHash(v.Fingerprint) || (v.Decision != "pass" && v.Decision != "risky" && v.Decision != "review" && v.Decision != "error") {
		return ports.ErrInvalid
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var existing string
	e = tx.QueryRowContext(ctx, `SELECT fingerprint FROM chat_av_raw_receipts WHERE trace_id=? FOR UPDATE`, v.TraceID).Scan(&existing)
	if e == nil {
		if existing != v.Fingerprint {
			_, _ = tx.ExecContext(ctx, `UPDATE chat_av_raw_receipts SET decision='error',code='conflicting_receipt' WHERE trace_id=?`, v.TraceID)
		}
		return tx.Commit()
	}
	if e != sql.ErrNoRows {
		return ports.ErrUnavailable
	}
	var pending int
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_av_jobs WHERE phase IN ('raw_started','raw_wait') AND expires_at>UTC_TIMESTAMP(6)`).Scan(&pending) != nil {
		return ports.ErrUnavailable
	}
	if pending == 0 {
		return nil
	}
	if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_raw_receipts(trace_id,app_id,decision,code,fingerprint,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))`, v.TraceID, v.AppID, v.Decision, v.Code, v.Fingerprint); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) rawResult(ctx context.Context, v avJob) error {
	var decision string
	e := a.base.db.QueryRowContext(ctx, `SELECT decision FROM chat_av_raw_receipts WHERE trace_id=? AND app_id=?`, v.RawTrace, a.inspector.MediaAppID()).Scan(&decision)
	if e == sql.ErrNoRows {
		_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET lease_owner=NULL,lease_until=NULL,next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 15 SECOND WHERE intent_id=? AND lease_owner=?`, string(v.ID), v.Owner)
		return nil
	}
	if e != nil {
		return ports.ErrUnavailable
	}
	if _, e = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET raw_decision=? WHERE intent_id=? AND lease_owner=?`, decision, string(v.ID), v.Owner); e != nil {
		return ports.ErrUnavailable
	}
	switch decision {
	case "pass":
		return a.advance(ctx, v, "ready")
	case "review":
		return a.reviewPending(ctx, v)
	case "risky":
		q := v.Request
		q.Text = v.Transcript
		checked := ports.SafetyResult{Decision: "blocked", RuleVersion: v.TextRule, AuthorityProof: v.Proof, Finding: &ports.SafetyFinding{Provider: "wechat.media_check_async.v2", Code: "official_risky", TraceID: v.RawTrace, Level: 2, Confirmed: true}}
		_, e = a.base.reject(ctx, v.Principal, q, v.Hash, v.ID, v.Owner, checked)
		if e == nil {
			_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_uploads SET legal_hold=1 WHERE id=?`, string(v.Upload))
			_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='failed',lease_owner=NULL,lease_until=NULL WHERE intent_id=?`, string(v.ID))
		}
		return e
	default:
		a.fail(ctx, v.ID, v.Owner, ErrCheck.Error())
		return ErrCheck
	}
}

var _ = time.Second
