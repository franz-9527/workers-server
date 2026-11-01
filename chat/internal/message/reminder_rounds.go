package message

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"errors"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

type reminderCluster struct {
	ID, CID, Actor ports.ID
	Hash           string
	Version        uint64
	Round          int
	Target         uint64
}

func (r *ReminderService) Prepare(ctx context.Context) error {
	rows, err := r.base.db.QueryContext(ctx, `SELECT CAST(id AS CHAR),CAST(conversation_id AS CHAR),CAST(recipient_id AS CHAR),member_hash FROM chat_reminder_clusters WHERE state='open' AND next_due<=UTC_TIMESTAMP(6) ORDER BY next_due,id LIMIT 50`)
	if err != nil {
		return ports.ErrUnavailable
	}
	candidates := []reminderCluster{}
	for rows.Next() {
		var c reminderCluster
		if rows.Scan(&c.ID, &c.CID, &c.Actor, &c.Hash) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		candidates = append(candidates, c)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	for _, c := range candidates {
		if err = r.prepareOne(ctx, c); err != nil {
			return err
		}
	}
	return nil
}
func (r *ReminderService) reserveBudget(ctx context.Context, tx *sql.Tx, actor ports.ID) (bool, error) {
	now := time.Now().UTC()
	for _, id := range []ports.ID{"0", actor} {
		if _, err := tx.ExecContext(ctx, `INSERT INTO chat_reminder_budgets(recipient_id,hour_start,hour_count,day_start,day_count) VALUES(?,UTC_TIMESTAMP(),0,UTC_DATE(),0) ON DUPLICATE KEY UPDATE recipient_id=recipient_id`, string(id)); err != nil {
			return false, ports.ErrUnavailable
		}
		var hourly, daily int
		var hour, day time.Time
		if tx.QueryRowContext(ctx, `SELECT hour_start,hour_count,day_start,day_count FROM chat_reminder_budgets WHERE recipient_id=? FOR UPDATE`, string(id)).Scan(&hour, &hourly, &day, &daily) != nil {
			return false, ports.ErrUnavailable
		}
		if now.Sub(hour) >= time.Hour {
			hour = now
			hourly = 0
		}
		if day.Format("2006-01-02") != now.Format("2006-01-02") {
			day = now
			daily = 0
		}
		h, d := r.config.RecipientHourly, r.config.RecipientDaily
		if id == "0" {
			h = r.config.PlatformHourly
			d = r.config.PlatformDaily
		}
		if hourly >= h || daily >= d {
			return false, nil
		}
		if _, err := tx.ExecContext(ctx, `UPDATE chat_reminder_budgets SET hour_start=?,hour_count=?,day_start=?,day_count=? WHERE recipient_id=?`, hour, hourly+1, day.Format("2006-01-02"), daily+1, string(id)); err != nil {
			return false, ports.ErrUnavailable
		}
	}
	return true, nil
}
func (r *ReminderService) prepareOne(ctx context.Context, c reminderCluster) error {
	tx, err := r.base.begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	w, err := r.window(ctx, tx, c.CID, c.Actor, ^uint64(0))
	if err != nil && !errors.Is(err, policy.ErrDenied) {
		return err
	}
	denied := errors.Is(err, policy.ErrDenied)
	var state string
	var due bool
	if tx.QueryRowContext(ctx, `SELECT round_version,rounds,target_seq,state,next_due<=UTC_TIMESTAMP(6) FROM chat_reminder_clusters WHERE id=? FOR UPDATE`, string(c.ID)).Scan(&c.Version, &c.Round, &c.Target, &state, &due) != nil {
		return ports.ErrUnavailable
	}
	if state != "open" || !due {
		return nil
	}
	if denied || w.First == 0 || w.Hash != c.Hash {
		if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET state='closed' WHERE id=?`, string(c.ID)); err != nil {
			return ports.ErrUnavailable
		}
		return tx.Commit()
	}
	if c.Round >= 3 {
		if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET state='exhausted' WHERE id=?`, string(c.ID)); err != nil {
			return ports.ErrUnavailable
		}
		return tx.Commit()
	}
	var exists int
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_reminder_intents WHERE cluster_id=? AND round_version=? AND round_no=?`, string(c.ID), c.Version, c.Round+1).Scan(&exists) != nil {
		return ports.ErrUnavailable
	}
	if exists > 0 {
		return nil
	} // One persisted attempt/outbox until a real outcome.
	// All reservations roll back together if either account/platform budget fails.
	if _, err = tx.ExecContext(ctx, "SAVEPOINT reminder_budget"); err != nil {
		return ports.ErrUnavailable
	}
	allowed, err := r.reserveBudget(ctx, tx, c.Actor)
	if err != nil {
		return err
	}
	if !allowed {
		if _, err = tx.ExecContext(ctx, "ROLLBACK TO SAVEPOINT reminder_budget"); err != nil {
			return ports.ErrUnavailable
		}
		if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)+INTERVAL 30 SECOND,blocked_reason='channel_budget' WHERE id=?`, string(c.ID)); err != nil {
			return ports.ErrUnavailable
		}
		return tx.Commit()
	}
	var b [32]byte
	if _, err = rand.Read(b[:]); err != nil {
		return ports.ErrUnavailable
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO chat_reminder_intents(operation_id,cluster_id,round_version,round_no,conversation_id,recipient_id,member_hash,target_seq,state,attempts,next_attempt_at,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,'pending',0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)`, hex.EncodeToString(b[:]), string(c.ID), c.Version, c.Round+1, string(c.CID), string(c.Actor), c.Hash, w.Target); err != nil {
		return ports.ErrUnavailable
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET blocked_reason=NULL WHERE id=?`, string(c.ID)); err != nil {
		return ports.ErrUnavailable
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (r *ReminderService) SubmitPending(ctx context.Context) error {
	rows, err := r.base.db.QueryContext(ctx, `SELECT CAST(id AS CHAR),operation_id,CAST(recipient_id AS CHAR),CAST(target_seq AS CHAR),CAST(round_version AS CHAR),CAST(conversation_id AS CHAR) FROM chat_reminder_intents WHERE state IN ('pending','retryable') AND attempts<8 AND next_attempt_at<=UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6) ORDER BY id LIMIT 20`)
	if err != nil {
		return ports.ErrUnavailable
	}
	work := []ports.Reminder{}
	for rows.Next() {
		var q ports.Reminder
		if rows.Scan(&q.IntentID, &q.OperationID, &q.RecipientID, &q.TargetSeq, &q.ClusterVersion, &q.ConversationID) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		work = append(work, q)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	for _, q := range work {
		result, err := r.base.db.ExecContext(ctx, `UPDATE chat_reminder_intents SET state='retryable',diagnostic='php_receipt_unconfirmed',attempts=attempts+1,next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 30 SECOND WHERE id=? AND state IN ('pending','retryable') AND next_attempt_at<=UTC_TIMESTAMP(6) AND attempts<8`, string(q.IntentID))
		if err != nil {
			return ports.ErrUnavailable
		}
		n, _ := result.RowsAffected()
		if n != 1 {
			continue
		}
		allowed, err := r.Eligible(ctx, q.OperationID)
		if err != nil {
			return err
		}
		if !allowed {
			if err = r.reconcileOne(ctx, q.OperationID, ports.Receipt{OperationID: q.OperationID, State: "suppressed", Diagnostic: "read_or_permission_changed"}); err != nil {
				return err
			}
			continue
		}
		receipt, err := r.notification.Submit(ctx, q)
		if err != nil {
			continue
		}
		if receipt.OperationID != q.OperationID {
			return ports.ErrUnavailable
		}
		switch receipt.State {
		case "accepted":
			_, err = r.base.db.ExecContext(ctx, `UPDATE chat_reminder_intents SET state='submitted' WHERE id=? AND state='retryable'`, string(q.IntentID))
		case "sent", "suppressed", "channel_limited", "provider_unknown":
			err = r.reconcileOne(ctx, q.OperationID, receipt)
		default:
			return ports.ErrUnavailable
		}
		if err != nil {
			return ports.ErrUnavailable
		}
	}
	return nil
}
func (r *ReminderService) Eligible(ctx context.Context, op string) (bool, error) {
	if len(op) != 64 {
		return false, ports.ErrInvalid
	}
	var cid, actor, cluster ports.ID
	var hash, state string
	var target, version uint64
	var live bool
	err := r.base.db.QueryRowContext(ctx, `SELECT CAST(conversation_id AS CHAR),CAST(recipient_id AS CHAR),CAST(cluster_id AS CHAR),member_hash,target_seq,round_version,state,expires_at>UTC_TIMESTAMP(6) FROM chat_reminder_intents WHERE operation_id=?`, op).Scan(&cid, &actor, &cluster, &hash, &target, &version, &state, &live)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, ports.ErrUnavailable
	}
	if !live || state == "suppressed" || state == "provider_unknown" || state == "channel_limited" {
		return false, nil
	}
	tx, err := r.base.begin(ctx)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	w, err := r.window(ctx, tx, cid, actor, target)
	if errors.Is(err, policy.ErrDenied) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	var current uint64
	if tx.QueryRowContext(ctx, `SELECT round_version FROM chat_reminder_clusters WHERE id=? LOCK IN SHARE MODE`, string(cluster)).Scan(&current) != nil {
		return false, ports.ErrUnavailable
	}
	valid := w.First > 0 && w.Hash == hash && current == version
	if tx.Commit() != nil {
		return false, ports.ErrUnavailable
	}
	return valid, nil
}
