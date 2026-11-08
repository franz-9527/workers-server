package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"strings"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func audioASRQueueMetadata(transcript string) string {
	source := "none"
	if strings.TrimSpace(transcript) != "" {
		source = "client_plugin"
	}
	raw, _ := json.Marshal(map[string]any{"schema_version": 2, "source": source, "server_asr": "queued"})
	return string(raw)
}

func backgroundASRDiagnostic(result ports.Transcription, status string) string {
	meta := map[string]any{
		"schema_version":      2,
		"server_asr":          status,
		"service":             result.ServiceID,
		"api":                 result.APIName,
		"engine":              result.Engine,
		"provider_code":       result.ProviderCode,
		"provider_message":    result.ProviderMessage,
		"provider_inner_code": result.ProviderInnerCode,
		"data_encoding":       result.DataEncoding,
		"url_host":            result.InputHost,
		"url_length":          result.InputURLLength,
		"url_encoded_length":  result.EncodedURLLength,
		"dispatched":          result.Dispatched,
	}
	if result.InputHost != "" {
		meta["source_preflight"] = "passed"
	}
	raw, _ := json.Marshal(meta)
	return string(raw)
}

func (a *AVService) reserveBackgroundASR(ctx context.Context, actor ports.ID) (bool, error) {
	tx, e := a.base.begin(ctx)
	if e != nil {
		return false, e
	}
	defer tx.Rollback()
	for _, id := range []ports.ID{"0", actor} {
		if _, e = tx.ExecContext(ctx, `INSERT INTO chat_av_budgets(actor_id,day_start,asr_reserved) VALUES(?,UTC_DATE(),0) ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(id)); e != nil {
			return false, ports.ErrUnavailable
		}
		var count int
		var current bool
		if tx.QueryRowContext(ctx, `SELECT asr_reserved,day_start=UTC_DATE() FROM chat_av_budgets WHERE actor_id=? FOR UPDATE`, string(id)).Scan(&count, &current) != nil {
			return false, ports.ErrUnavailable
		}
		if !current {
			count = 0
		}
		limit := 200
		if id == "0" {
			limit = a.dailyASR
		}
		if count >= limit {
			return false, nil
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_budgets SET day_start=UTC_DATE(),asr_reserved=? WHERE actor_id=?`, count+1, string(id)); e != nil {
			return false, ports.ErrUnavailable
		}
	}
	if tx.Commit() != nil {
		return false, ports.ErrUnavailable
	}
	return true, nil
}

func (a *AVService) finishBackgroundASR(ctx context.Context, v avJob, state, metadata string, result ports.Transcription, refund bool) error {
	source := "none"
	if strings.TrimSpace(v.Request.ClientTranscript) != "" {
		source = "client_plugin"
	}
	if state == "completed" && strings.TrimSpace(result.Text) != "" {
		source = "tencent_cloud"
	}
	var diagnostic any
	_ = json.Unmarshal([]byte(metadata), &diagnostic)
	uploadMeta, _ := json.Marshal(map[string]any{"schema_version": 2, "source": source, "server_asr": state, "diagnostic": diagnostic})
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	// Share the conversation-first lock order with recall and retention changes.
	var change uint64
	var policyVersion ports.ID
	if tx.QueryRowContext(ctx, `SELECT last_change_seq,CAST(policy_version AS CHAR) FROM chat_conversations WHERE id=? FOR UPDATE`, string(v.Request.ConversationID)).Scan(&change, &policyVersion) != nil {
		return ports.ErrUnavailable
	}
	r, e := tx.ExecContext(ctx, `UPDATE chat_av_asr_requests SET state=?,provider_request_id=NULLIF(?,''),text_result=NULLIF(?,''),source_proof=NULLIF(?,''),metadata_json=? WHERE intent_id=? AND ordinal=0 AND state='started'`, state, result.RequestID, result.Text, result.AuthorityProof, metadata, string(v.ID))
	if e != nil {
		return ports.ErrUnavailable
	}
	if n, err := r.RowsAffected(); err != nil || n != 1 {
		// A replay must not refund twice or publish another message change.
		return ports.ErrBusy
	}
	if refund {
		for _, id := range []ports.ID{"0", v.Principal.ActorID} {
			if _, e = tx.ExecContext(ctx, `UPDATE chat_av_budgets SET asr_reserved=GREATEST(asr_reserved,1)-1 WHERE actor_id=? AND day_start=UTC_DATE()`, string(id)); e != nil {
				return ports.ErrUnavailable
			}
		}
	}
	var event *ports.CommittedMessage
	if state == "completed" && strings.TrimSpace(result.Text) != "" {
		var mid ports.ID
		var messageState, previous string
		var version, seq uint64
		var retained bool
		e = tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR),m.state,m.state_version,m.seq,COALESCE(u.transcript,''),(m.retention_mode='permanent' AND m.expires_at IS NULL OR m.retention_mode='days' AND m.expires_at>UTC_TIMESTAMP(6)) FROM chat_messages m JOIN chat_av_items i ON i.message_id=m.id JOIN chat_av_uploads u ON u.id=i.upload_id AND u.object_version=i.object_version WHERE i.upload_id=? AND m.conversation_id=? FOR UPDATE`, string(v.Upload), string(v.Request.ConversationID)).Scan(&mid, &messageState, &version, &seq, &previous, &retained)
		if e != nil && e != sql.ErrNoRows {
			return ports.ErrUnavailable
		}
		text := strings.TrimSpace(result.Text)
		if e == nil && messageState == "published" && retained && previous != text {
			if change == ^uint64(0) || version == ^uint64(0) {
				return ports.ErrUnavailable
			}
			if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET transcript=? WHERE id=?`, text, string(v.Upload)); e != nil {
				return ports.ErrUnavailable
			}
			if _, e = tx.ExecContext(ctx, `UPDATE chat_messages SET state_version=state_version+1 WHERE id=?`, string(mid)); e != nil {
				return ports.ErrUnavailable
			}
			if _, e = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(?,?,?,?, 'message.transcript',UTC_TIMESTAMP(6))`, string(v.Request.ConversationID), change+1, string(mid), version+1); e != nil {
				return ports.ErrUnavailable
			}
			if _, e = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_change_seq=? WHERE id=?`, change+1, string(v.Request.ConversationID)); e != nil {
				return ports.ErrUnavailable
			}
			event = &ports.CommittedMessage{ConversationID: v.Request.ConversationID, Seq: decimal(seq), PolicyVersion: policyVersion}
		}
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET asr_metadata=? WHERE id=?`, string(uploadMeta), string(v.Upload)); e != nil {
		return ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET principal_json='{}' WHERE intent_id=? AND phase='published'`, string(v.ID)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	if event != nil && a.base.publisher != nil {
		if publisher, ok := a.base.publisher.(interface{ Changed(ports.CommittedMessage) }); ok {
			publisher.Changed(*event)
		} else {
			a.base.publisher.Committed(*event)
		}
	}
	return nil
}

func (a *AVService) ProcessBackgroundASROne(ctx context.Context) error {
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var id ports.ID
	var key string
	e = tx.QueryRowContext(ctx, `SELECT CAST(r.intent_id AS CHAR),r.request_key FROM chat_av_asr_requests r JOIN chat_av_jobs j ON j.intent_id=r.intent_id JOIN chat_av_uploads u ON u.id=j.upload_id WHERE r.state='queued' AND r.ordinal=0 AND j.phase='published' AND u.kind='audio' ORDER BY r.created_at,r.intent_id LIMIT 1 FOR UPDATE`).Scan(&id, &key)
	if e == sql.ErrNoRows {
		return nil
	}
	if e != nil || len(key) != 32 {
		return ports.ErrUnavailable
	}
	initial := ports.Transcription{}
	if provider, ok := a.inspector.(interface{ ASRMetadata() ports.Transcription }); ok {
		initial = provider.ASRMetadata()
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_asr_requests SET state='started',metadata_json=?,created_at=UTC_TIMESTAMP(6) WHERE intent_id=? AND ordinal=0 AND state='queued'`, backgroundASRDiagnostic(initial, "started"), string(id)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	v, e := a.job(ctx, id)
	if e != nil {
		_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_asr_requests SET state='unknown',metadata_json=? WHERE intent_id=? AND ordinal=0 AND state='started'`, `{"schema_version":2,"server_asr":"identity_unavailable"}`, string(id))
		_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET principal_json='{}' WHERE intent_id=? AND phase='published'`, string(id))
		return nil
	}
	if len(v.Segments) == 0 || v.Segments[0].Key == "" || !isMediaHash(v.Segments[0].Hash) {
		return a.finishBackgroundASR(ctx, v, "failed", `{"schema_version":2,"server_asr":"segment_unavailable"}`, ports.Transcription{}, false)
	}
	reserved, e := a.reserveBackgroundASR(ctx, v.Principal.ActorID)
	if e != nil {
		return e
	}
	if !reserved {
		return a.finishBackgroundASR(ctx, v, "failed", `{"schema_version":2,"server_asr":"budget_skipped"}`, ports.Transcription{}, false)
	}
	url, e := a.storage.InspectionURL(ctx, v.Segments[0].Key)
	if e != nil {
		return a.finishBackgroundASR(ctx, v, "failed", `{"schema_version":2,"server_asr":"source_url_unavailable"}`, ports.Transcription{}, true)
	}
	result, callErr := a.inspector.Recognize(ctx, ports.AudioInspectionRequest{
		Actor: v.Principal, ConversationID: v.Request.ConversationID, IntentID: v.ID,
		ObjectHash: v.Segments[0].Hash, URL: url, Segment: 0, UserAudioKey: key,
	})
	if callErr != nil {
		state := "failed"
		if !result.Known {
			state = "unknown"
		}
		return a.finishBackgroundASR(ctx, v, state, backgroundASRDiagnostic(result, state), result, !result.Dispatched)
	}
	if !result.Known || result.RequestID == "" || !isMediaHash(result.AuthorityProof) || len([]rune(strings.TrimSpace(result.Text))) > 1000 {
		return a.finishBackgroundASR(ctx, v, "unknown", backgroundASRDiagnostic(result, "invalid_result"), result, !result.Dispatched)
	}
	return a.finishBackgroundASR(ctx, v, "completed", backgroundASRDiagnostic(result, "completed"), result, false)
}
