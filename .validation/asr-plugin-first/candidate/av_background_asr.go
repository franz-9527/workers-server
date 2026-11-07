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
		source = "wechat_si"
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

func (a *AVService) refundBackgroundASR(ctx context.Context, actor ports.ID) {
	for _, id := range []ports.ID{"0", actor} {
		_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_budgets SET asr_reserved=GREATEST(asr_reserved,1)-1 WHERE actor_id=? AND day_start=UTC_DATE()`, string(id))
	}
}

func (a *AVService) finishBackgroundASR(ctx context.Context, v avJob, state, metadata string, result ports.Transcription, refund bool) error {
	if refund {
		a.refundBackgroundASR(ctx, v.Principal.ActorID)
	}
	source := "none"
	if strings.TrimSpace(v.Request.ClientTranscript) != "" {
		source = "wechat_si"
	}
	var diagnostic any
	_ = json.Unmarshal([]byte(metadata), &diagnostic)
	uploadMeta, _ := json.Marshal(map[string]any{"schema_version": 2, "source": source, "server_asr": state, "diagnostic": diagnostic})
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_asr_requests SET state=?,provider_request_id=NULLIF(?,''),text_result=NULLIF(?,''),source_proof=NULLIF(?,''),metadata_json=? WHERE intent_id=? AND ordinal=0 AND state='started'`, state, result.RequestID, result.Text, result.AuthorityProof, metadata, string(v.ID)); e != nil {
		return ports.ErrUnavailable
	}
	if state == "completed" && strings.TrimSpace(v.Request.ClientTranscript) == "" && strings.TrimSpace(result.Text) != "" {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET transcript=?,asr_metadata=? WHERE id=?`, strings.TrimSpace(result.Text), string(uploadMeta), string(v.Upload)); e != nil {
			return ports.ErrUnavailable
		}
	} else {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET asr_metadata=? WHERE id=?`, string(uploadMeta), string(v.Upload)); e != nil {
			return ports.ErrUnavailable
		}
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET principal_json='{}' WHERE intent_id=? AND phase='published'`, string(v.ID)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
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
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_asr_requests SET state='started' WHERE intent_id=? AND ordinal=0 AND state='queued'`, string(id)); e != nil || tx.Commit() != nil {
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
