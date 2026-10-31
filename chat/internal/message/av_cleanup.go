package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// Cleanup holds the upload lock shared with publication and evidence changes.
// Failed deletes retain their ledger and retry on the next maintenance pass.
func (a *AVService) CleanOne(ctx context.Context) error {
	if e := a.sweepExpired(ctx); e != nil {
		return e
	}
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var id ports.ID
	var key, role string
	e = tx.QueryRowContext(ctx, `SELECT CAST(u.id AS CHAR),o.object_key,o.role FROM chat_av_objects o JOIN chat_av_uploads u ON u.id=o.upload_id WHERE u.legal_hold=0 AND NOT EXISTS(SELECT 1 FROM chat_av_jobs j WHERE j.upload_id=u.id AND j.lease_until>UTC_TIMESTAMP(6)) AND ((o.role IN ('check-0','check-1') AND (o.created_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY OR EXISTS(SELECT 1 FROM chat_av_jobs j WHERE j.upload_id=u.id AND j.phase IN ('published','failed')))) OR (o.created_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY AND u.expires_at<=UTC_TIMESTAMP(6) AND NOT EXISTS(SELECT 1 FROM chat_av_items i WHERE i.upload_id=u.id))) ORDER BY o.created_at LIMIT 1`).Scan(&id, &key, &role)
	if e != nil && e != sql.ErrNoRows {
		return ports.ErrUnavailable
	}
	if e == nil {
		var hold bool
		if tx.QueryRowContext(ctx, `SELECT legal_hold FROM chat_av_uploads WHERE id=? FOR UPDATE`, string(id)).Scan(&hold) != nil {
			return ports.ErrUnavailable
		}
		if hold {
			return nil
		}
		var eligible bool
		if tx.QueryRowContext(ctx, `SELECT legal_hold=0 AND NOT EXISTS(SELECT 1 FROM chat_av_jobs j WHERE j.upload_id=u.id AND j.lease_until>UTC_TIMESTAMP(6)) AND (? IN ('check-0','check-1') OR (expires_at<=UTC_TIMESTAMP(6) AND NOT EXISTS(SELECT 1 FROM chat_av_items i WHERE i.upload_id=u.id))) FROM chat_av_uploads u WHERE id=?`, role, string(id)).Scan(&eligible) != nil {
			return ports.ErrUnavailable
		}
		if !eligible {
			return nil
		}
		if e = a.storage.Delete(ctx, key); e != nil {
			return e
		}
		if _, e = tx.ExecContext(ctx, `DELETE FROM chat_av_objects WHERE object_key=?`, key); e != nil {
			return ports.ErrUnavailable
		}
	} else {
		var ordinal int
		e = tx.QueryRowContext(ctx, `SELECT CAST(u.id AS CHAR),p.object_key,p.ordinal FROM chat_av_parts p JOIN chat_av_uploads u ON u.id=p.upload_id WHERE p.state<>'cleaned' AND (p.grant_until IS NULL OR p.grant_until<UTC_TIMESTAMP(6)) AND (u.state IN ('prepared','published','canceled','cleaned') OR u.expires_at<=UTC_TIMESTAMP(6)) ORDER BY u.id,p.ordinal LIMIT 1`).Scan(&id, &key, &ordinal)
		if e == sql.ErrNoRows {
			return nil
		}
		if e != nil {
			return ports.ErrUnavailable
		}
		var state string
		if tx.QueryRowContext(ctx, `SELECT state FROM chat_av_uploads WHERE id=? FOR UPDATE`, string(id)).Scan(&state) != nil {
			return ports.ErrUnavailable
		}
		var eligible bool
		if tx.QueryRowContext(ctx, `SELECT (u.state IN ('prepared','published','canceled','cleaned') OR u.expires_at<=UTC_TIMESTAMP(6)) AND (p.grant_until IS NULL OR p.grant_until<UTC_TIMESTAMP(6)) FROM chat_av_parts p JOIN chat_av_uploads u ON u.id=p.upload_id WHERE u.id=? AND p.ordinal=?`, string(id), ordinal).Scan(&eligible) != nil {
			return ports.ErrUnavailable
		}
		if !eligible {
			return nil
		}
		if e = a.storage.Delete(ctx, key); e != nil {
			return e
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_parts SET state='cleaned' WHERE upload_id=? AND ordinal=?`, string(id), ordinal); e != nil {
			return ports.ErrUnavailable
		}
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) sweepExpired(ctx context.Context) error {
	var e error
	if _, e = a.base.db.ExecContext(ctx, `UPDATE chat_send_intents i JOIN chat_av_jobs j ON j.intent_id=i.id SET i.state='rejected',i.error_code='CHAT_ACTION_EXPIRED',i.lease_owner=NULL,i.lease_until=NULL,i.updated_at=UTC_TIMESTAMP(6) WHERE j.expires_at<=UTC_TIMESTAMP(6) AND i.state IN ('checking','review_pending','retryable')`); e != nil {
		return ports.ErrUnavailable
	}
	// Remove duplicate recognition text as soon as the uncommitted action expires.
	_, e = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET transcript=NULL,principal_json='{}',segments_json=NULL,phase=IF(phase='published',phase,'failed'),error_code=IF(phase='published',error_code,'CHAT_ACTION_EXPIRED') WHERE expires_at<=UTC_TIMESTAMP(6) AND (transcript IS NOT NULL OR principal_json<>'{}' OR segments_json IS NOT NULL) LIMIT 20`)
	if e != nil {
		return ports.ErrUnavailable
	}
	_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_asr_requests r JOIN chat_av_jobs j ON j.intent_id=r.intent_id SET r.text_result=NULL WHERE j.expires_at<=UTC_TIMESTAMP(6)`)
	_, _ = a.base.db.ExecContext(ctx, `DELETE FROM chat_av_raw_receipts WHERE created_at<UTC_TIMESTAMP(6)-INTERVAL 2 DAY ORDER BY created_at LIMIT 100`)
	return nil
}
func purgeAVReference(ctx context.Context, tx *sql.Tx, id ports.ID) error {
	if _, e := tx.ExecContext(ctx, `UPDATE chat_av_uploads u JOIN chat_av_items i ON i.upload_id=u.id SET u.transcript=NULL WHERE i.message_id=? AND u.legal_hold=0`, string(id)); e != nil {
		return ports.ErrUnavailable
	}
	if _, e := tx.ExecContext(ctx, `DELETE FROM chat_av_items WHERE message_id=?`, string(id)); e != nil {
		return ports.ErrUnavailable
	}
	return nil
}
