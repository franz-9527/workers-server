package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// One bounded pass. S22 owns wider archive/backup policy; this pass expires
// governance evidence holds only after every linked case's retention ends.
func (g *GovernanceService) ExpireEvidence(ctx context.Context) error {
	return expireGovernanceEvidence(ctx, g.base.db, g.environment)
}
func expireGovernanceEvidence(ctx context.Context, db *sql.DB, environment string) error {
	// Risk TTL may be later than its original review case (action cutoff +30d).
	// Revisit due risk evidence independently, even after that case was expired.
	if _, err := db.ExecContext(ctx, `UPDATE chat_risk_events SET text_evidence='',context_evidence='' WHERE retain_until<=UTC_TIMESTAMP(6) AND (text_evidence<>'' OR context_evidence<>'') AND NOT EXISTS(SELECT 1 FROM chat_cases c WHERE c.target_type='risk' AND c.target_id=chat_risk_events.id AND c.retain_until>UTC_TIMESTAMP(6)) ORDER BY retain_until,id LIMIT 30`); err != nil {
		return ports.ErrUnavailable
	}
	rows, err := db.QueryContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_cases WHERE retain_until<=UTC_TIMESTAMP(6) AND state<>'expired' ORDER BY retain_until,id LIMIT 30`)
	if err != nil {
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
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	for _, id := range ids {
		tx, err := db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
		if err != nil {
			return err
		}
		// Expired cases cannot be opened through caseLock; lock in the same order.
		var subject, cid ports.ID
		if tx.QueryRowContext(ctx, `SELECT CAST(subject_id AS CHAR),CAST(conversation_id AS CHAR) FROM chat_cases WHERE id=?`, string(id)).Scan(&subject, &cid) != nil {
			tx.Rollback()
			return ports.ErrUnavailable
		}
		if _, err = restrictionLocked(ctx, tx, subject); err != nil {
			tx.Rollback()
			return err
		}
		if cid != "0" {
			var n int
			if tx.QueryRowContext(ctx, `SELECT 1 FROM chat_conversations WHERE id=? FOR UPDATE`, string(cid)).Scan(&n) != nil {
				tx.Rollback()
				return ports.ErrUnavailable
			}
		}
		c, err := scanCase(tx.QueryRowContext(ctx, `SELECT `+caseColumns+` FROM chat_cases WHERE id=? AND retain_until<=UTC_TIMESTAMP(6) FOR UPDATE`, string(id)))
		if err != nil {
			tx.Rollback()
			return err
		}
		if c.State == "expired" {
			tx.Rollback()
			continue
		}
		if _, err = tx.ExecContext(ctx, `UPDATE chat_cases SET resolution=CONCAT('expired:',state),reason='',state='expired',version=version+1 WHERE id=?`, string(id)); err != nil {
			tx.Rollback()
			return ports.ErrUnavailable
		}
		if c.TargetType == "risk" {
			_, err = tx.ExecContext(ctx, `UPDATE chat_risk_events SET text_evidence='',context_evidence='' WHERE id=? AND retain_until<=UTC_TIMESTAMP(6) AND NOT EXISTS(SELECT 1 FROM chat_cases c WHERE c.target_type='risk' AND c.target_id=chat_risk_events.id AND c.retain_until>UTC_TIMESTAMP(6))`, string(c.Target))
		}
		if err == nil && (c.TargetType == "message" || c.TargetType == "image_intent") {
			items, e := caseImageItems(ctx, tx, c)
			if e != nil {
				tx.Rollback()
				return e
			}
			for _, item := range items {
				_, err = tx.ExecContext(ctx, `UPDATE chat_image_uploads u SET legal_hold=0 WHERE u.id=? AND NOT EXISTS(SELECT 1 FROM chat_cases c JOIN chat_image_items i ON c.target_type='message' AND c.target_id=i.message_id WHERE i.item_id=u.id AND c.retain_until>UTC_TIMESTAMP(6)) AND NOT EXISTS(SELECT 1 FROM chat_cases c WHERE c.target_type='image_intent' AND c.subject_id=u.actor_id AND c.conversation_id=u.conversation_id AND c.retain_until>UTC_TIMESTAMP(6))`, string(item.ItemID))
				if err != nil {
					break
				}
			}
		}
		if err == nil {
			err = releaseAVHolds(ctx, tx)
		}
		if err != nil {
			tx.Rollback()
			return ports.ErrUnavailable
		}
		sys := ports.Principal{Kind: "system", ActorID: "0", Environment: environment}
		if err = governanceLog(ctx, tx, sys, "evidence.expire", c.Conversation, c.ID, c.Item, decimal(number(c.Version)+1), "治理证据保留期结束"); err != nil {
			tx.Rollback()
			return err
		}
		if tx.Commit() != nil {
			return ports.ErrUnavailable
		}
	}
	tx, e := db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if e != nil {
		return ports.ErrUnavailable
	}
	defer tx.Rollback()
	if e = releaseAVHolds(ctx, tx); e != nil {
		return e
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (g *GovernanceService) Maintain(ctx context.Context, reminders *ReminderService) error {
	if err := g.ExpireRestrictions(ctx); err != nil {
		return err
	}
	if err := g.CleanupSnapshots(ctx); err != nil {
		return err
	}
	if err := g.ExpireEvidence(ctx); err != nil {
		return err
	}
	if reminders != nil {
		if err := reminders.Reconcile(ctx); err != nil {
			return err
		}
		if err := reminders.DrainEvents(ctx); err != nil {
			return err
		}
		if err := reminders.Prepare(ctx); err != nil {
			return err
		}
		return reminders.SubmitPending(ctx)
	}
	return nil
}
func (g *GovernanceService) Run(ctx context.Context, reminders *ReminderService) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		pass, cancel := context.WithTimeout(ctx, 10*time.Second)
		_ = g.Maintain(pass, reminders)
		cancel()
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

func (r *ReminderService) Reconcile(ctx context.Context) error {
	reader, ok := r.notification.(reminderReceipts)
	if !ok {
		return nil
	}
	rows, err := r.base.db.QueryContext(ctx, `SELECT operation_id FROM chat_reminder_intents WHERE (state IN ('submitted','provider_unknown') OR (state='retryable' AND attempts>=8)) AND (next_receipt_at IS NULL OR next_receipt_at<=UTC_TIMESTAMP(6)) ORDER BY next_receipt_at,id LIMIT 20`)
	if err != nil {
		return ports.ErrUnavailable
	}
	ops := []string{}
	for rows.Next() {
		var op string
		if rows.Scan(&op) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		ops = append(ops, op)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	for _, op := range ops {
		if _, err = r.base.db.ExecContext(ctx, `UPDATE chat_reminder_intents SET next_receipt_at=UTC_TIMESTAMP(6)+INTERVAL 30 SECOND WHERE operation_id=?`, op); err != nil {
			return ports.ErrUnavailable
		}
		receipt, e := reader.Status(ctx, op)
		if e != nil {
			continue
		}
		if receipt.OperationID != op {
			return ports.ErrUnavailable
		}
		if receipt.State != "accepted" {
			if e = r.reconcileOne(ctx, op, receipt); e != nil {
				return e
			}
		} else {
			if _, e = r.base.db.ExecContext(ctx, `UPDATE chat_reminder_intents SET state='submitted' WHERE operation_id=? AND state='retryable'`, op); e != nil {
				return ports.ErrUnavailable
			}
		}
	}
	return nil
}
func (r *ReminderService) reconcileOne(ctx context.Context, op string, receipt ports.Receipt) error {
	if receipt.State != "sent" && receipt.State != "suppressed" && receipt.State != "channel_limited" && receipt.State != "provider_unknown" {
		return ports.ErrInvalid
	}
	var c reminderCluster
	if r.base.db.QueryRowContext(ctx, `SELECT CAST(cluster_id AS CHAR),CAST(conversation_id AS CHAR),CAST(recipient_id AS CHAR),member_hash FROM chat_reminder_intents WHERE operation_id=?`, op).Scan(&c.ID, &c.CID, &c.Actor, &c.Hash) != nil {
		return ports.ErrUnavailable
	}
	tx, err := r.base.begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	// Current PHP guard/account -> conversation -> cluster -> intent -> budget.
	w, windowErr := r.window(ctx, tx, c.CID, c.Actor, ^uint64(0))
	err = windowErr
	if err != nil && err != policy.ErrDenied {
		return err
	}
	var currentVersion uint64
	if tx.QueryRowContext(ctx, `SELECT round_version,rounds FROM chat_reminder_clusters WHERE id=? FOR UPDATE`, string(c.ID)).Scan(&currentVersion, &c.Round) != nil {
		return ports.ErrUnavailable
	}
	var version, target uint64
	var round int
	var state string
	var created time.Time
	var released bool
	if tx.QueryRowContext(ctx, `SELECT round_version,round_no,target_seq,state,created_at,budget_released FROM chat_reminder_intents WHERE operation_id=? FOR UPDATE`, op).Scan(&version, &round, &target, &state, &created, &released) != nil {
		return ports.ErrUnavailable
	}
	if state == "sent" || state == "suppressed" || state == "channel_limited" {
		return nil
	}
	if receipt.State == "suppressed" || receipt.State == "channel_limited" {
		if !released {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_budgets SET hour_count=GREATEST(0,CAST(hour_count AS SIGNED)-IF(hour_start<=?,1,0)),day_count=GREATEST(0,CAST(day_count AS SIGNED)-IF(day_start=DATE(?),1,0)) WHERE recipient_id IN (0,?)`, created, created, string(c.Actor)); err != nil {
				return ports.ErrUnavailable
			}
			released = true
		}
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_intents SET state=?,diagnostic=?,budget_released=? WHERE operation_id=?`, receipt.State, receipt.Diagnostic, released, op); err != nil {
		return ports.ErrUnavailable
	}
	if version == currentVersion {
		if receipt.State == "sent" && round == c.Round+1 {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET rounds=?,last_notified_seq=GREATEST(last_notified_seq,?),first_notified_at=COALESCE(first_notified_at,UTC_TIMESTAMP(6)),next_due=TIMESTAMPADD(MINUTE,?,COALESCE(first_notified_at,UTC_TIMESTAMP(6))),state=IF(? >= 3,'exhausted','open'),blocked_reason=NULL WHERE id=?`, round, target, 5+(round-1)*10, round, string(c.ID)); err != nil {
				return ports.ErrUnavailable
			}
		} else if receipt.State == "channel_limited" || receipt.State == "provider_unknown" {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET state=?,blocked_reason=? WHERE id=?`, receipt.State, receipt.Diagnostic, string(c.ID)); err != nil {
				return ports.ErrUnavailable
			}
		} else if receipt.State == "suppressed" {
			if windowErr == nil && w.First > 0 && w.Read >= target {
				if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET round_version=round_version+1,first_seq=?,target_seq=?,first_due_at=TIMESTAMPADD(SECOND,?,?),next_due=TIMESTAMPADD(SECOND,?,?),first_notified_at=NULL,rounds=0,state='open',blocked_reason=NULL WHERE id=?`, w.First, w.Target, r.config.FirstDelaySeconds, w.At, r.config.FirstDelaySeconds, w.At, string(c.ID)); err != nil {
					return ports.ErrUnavailable
				}
			} else {
				if _, err = tx.ExecContext(ctx, `UPDATE chat_reminder_clusters SET state=IF(target_seq<=?,'closed',state),blocked_reason=? WHERE id=?`, target, receipt.Diagnostic, string(c.ID)); err != nil {
					return ports.ErrUnavailable
				}
			}
		}
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
