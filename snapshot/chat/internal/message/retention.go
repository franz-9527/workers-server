package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Minimal idempotency, authority versions, restriction rows and message tombstones
// are not deleted by this worker. Their disaster-recovery window is independent.
type RetentionConfig struct {
	Enabled      bool `json:"enabled"`
	BodyDays     int  `json:"body_days"`
	EvidenceDays int  `json:"evidence_days"`
	ChangeDays   int  `json:"change_days"`
	Batch        int  `json:"batch"`
}

func DefaultRetentionConfig() RetentionConfig { return RetentionConfig{false, 365, 30, 90, 20} }
func (c RetentionConfig) Validate() error {
	if !c.Enabled {
		return nil
	}
	if c.BodyDays < 90 || c.BodyDays > 3650 || c.EvidenceDays < 30 || c.EvidenceDays > c.BodyDays || c.ChangeDays < 1 || c.ChangeDays > 365 || c.Batch < 1 || c.Batch > 50 {
		return ports.ErrInvalid
	}
	return nil
}

type Retention struct {
	db          *sql.DB
	config      RetentionConfig
	environment string
}
type RetentionResult struct {
	Next          ports.ID `json:"next_after"`
	Conversations int      `json:"conversations"`
	Messages      int      `json:"messages"`
	Changes       int      `json:"changes"`
}

func NewRetention(db *sql.DB, environment string, c RetentionConfig) (*Retention, error) {
	if db == nil || c.Validate() != nil || !c.Enabled || (environment != "test" && environment != "development" && environment != "production") {
		return nil, ports.ErrInvalid
	}
	return &Retention{db, c, environment}, nil
}
func (r *Retention) Pass(ctx context.Context, after ports.ID) (RetentionResult, error) {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	out := RetentionResult{Next: "0"}
	if err := expireGovernanceEvidence(ctx, r.db, r.environment); err != nil {
		return out, err
	}
	if after != "" && after != "0" && !validID(after) {
		return out, ports.ErrInvalid
	}
	if after == "" {
		after = "0"
	}
	rows, err := r.db.QueryContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_conversations WHERE id>? ORDER BY id LIMIT ?`, string(after), r.config.Batch)
	if err != nil {
		return out, ports.ErrUnavailable
	}
	ids := []ports.ID{}
	for rows.Next() {
		var id ports.ID
		if rows.Scan(&id) != nil {
			rows.Close()
			return out, ports.ErrUnavailable
		}
		ids = append(ids, id)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return out, ports.ErrUnavailable
	}
	for _, id := range ids {
		n, c, err := r.one(ctx, id)
		if err != nil {
			return out, err
		}
		out.Conversations++
		out.Messages += n
		out.Changes += c
		out.Next = id
	}
	if len(ids) < r.config.Batch {
		out.Next = "0"
	}
	return out, nil
}
func (r *Retention) one(ctx context.Context, id ports.ID) (int, int, error) {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	tx, err := r.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return 0, 0, ports.ErrUnavailable
	}
	defer tx.Rollback()
	var floor, change uint64
	if tx.QueryRowContext(ctx, `SELECT sync_floor_seq,last_change_seq FROM chat_conversations WHERE id=? FOR UPDATE`, string(id)).Scan(&floor, &change) != nil {
		return 0, 0, ports.ErrUnavailable
	}
	// Published messages and stable media references never expire with cloud bytes.
	// Per-type retention removes cloud objects in the media maintenance worker.
	var holds int
	if tx.QueryRowContext(ctx, `SELECT (SELECT COUNT(*) FROM chat_cases WHERE conversation_id=? AND retain_until>UTC_TIMESTAMP(6))+(SELECT COUNT(*) FROM chat_image_uploads WHERE conversation_id=? AND legal_hold=1)+(SELECT COUNT(*) FROM chat_av_uploads WHERE conversation_id=? AND legal_hold=1)`, string(id), string(id), string(id)).Scan(&holds) != nil {
		return 0, 0, ports.ErrUnavailable
	}
	n := 0
	var rows *sql.Rows
	var e error
	if holds == 0 {
		result, err := tx.ExecContext(ctx, `UPDATE chat_messages SET text_body=NULL WHERE conversation_id=? AND state='published' AND message_type='text' AND text_body IS NOT NULL AND retention_mode='days' AND expires_at<=UTC_TIMESTAMP(6)`, string(id))
		if err != nil {
			return 0, 0, ports.ErrUnavailable
		}
		affected, _ := result.RowsAffected()
		n = int(affected)
	}
	if holds == 0 {
		rows, e = tx.QueryContext(ctx, `SELECT CAST(m.id AS CHAR) FROM chat_messages m WHERE m.conversation_id=? AND m.state IN ('recalled','blocked','expired') AND m.created_at<TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6)) AND NOT EXISTS(SELECT 1 FROM chat_message_changes c WHERE c.message_id=m.id AND c.created_at>=TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6))) AND (m.text_body IS NOT NULL OR EXISTS(SELECT 1 FROM chat_image_items i WHERE i.message_id=m.id) OR EXISTS(SELECT 1 FROM chat_av_items i WHERE i.message_id=m.id)) ORDER BY m.seq LIMIT 50`, string(id), r.config.EvidenceDays, r.config.EvidenceDays)
		if e != nil {
			return 0, 0, ports.ErrUnavailable
		}
		evidence := []ports.ID{}
		for rows.Next() {
			var mid ports.ID
			if rows.Scan(&mid) != nil {
				rows.Close()
				return 0, 0, ports.ErrUnavailable
			}
			evidence = append(evidence, mid)
		}
		e = rows.Err()
		rows.Close()
		if e != nil {
			return 0, 0, ports.ErrUnavailable
		}
		for _, mid := range evidence {
			if e = purgeAVReference(ctx, tx, mid); e != nil {
				return 0, 0, e
			}
			if _, e = tx.ExecContext(ctx, `UPDATE chat_messages SET text_body=NULL WHERE id=?`, string(mid)); e != nil {
				return 0, 0, ports.ErrUnavailable
			}
			if _, e = tx.ExecContext(ctx, `DELETE FROM chat_image_items WHERE message_id=?`, string(mid)); e != nil {
				return 0, 0, ports.ErrUnavailable
			}
		}
	}
	// Change retention always advances a contiguous prefix while holding the same
	// conversation lock as publish/recall. A cursor below the new floor must resync.
	rows, err = tx.QueryContext(ctx, `SELECT change_seq,created_at<TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6)) FROM chat_message_changes WHERE conversation_id=? ORDER BY change_seq LIMIT 100`, r.config.ChangeDays, string(id))
	if err != nil {
		return 0, 0, ports.ErrUnavailable
	}
	changeFloor := uint64(0)
	count := 0
	for rows.Next() {
		var v uint64
		var old bool
		if rows.Scan(&v, &old) != nil {
			rows.Close()
			return 0, 0, ports.ErrUnavailable
		}
		if !old {
			break
		}
		changeFloor = v
		count++
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return 0, 0, ports.ErrUnavailable
	}
	if count > 0 {
		if _, err = tx.ExecContext(ctx, `DELETE FROM chat_message_changes WHERE conversation_id=? AND change_seq<=?`, string(id), changeFloor); err != nil {
			return 0, 0, ports.ErrUnavailable
		}
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_conversations SET sync_floor_seq=GREATEST(sync_floor_seq,?),change_floor_seq=GREATEST(change_floor_seq,?),last_change_seq=? WHERE id=?`, floor, changeFloor, change, string(id)); err != nil {
		return 0, 0, ports.ErrUnavailable
	}
	if tx.Commit() != nil {
		return 0, 0, ports.ErrUnavailable
	}
	return n, count, nil
}
