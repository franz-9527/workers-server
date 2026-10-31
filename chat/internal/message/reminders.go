package message

import (
	"context"
	"database/sql"
	"errors"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Publication persists only this wakeup; the worker may restart at any time.
func reminderEvent(ctx context.Context, tx *sql.Tx, cid ports.ID, seq uint64) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO chat_reminder_events(conversation_id,seq,created_at) VALUES(?,?,UTC_TIMESTAMP(6))`, string(cid), seq)
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}

type ReminderConfig struct {
	FirstDelaySeconds                                              int
	RecipientHourly, RecipientDaily, PlatformHourly, PlatformDaily int
}

func DefaultReminderConfig() ReminderConfig { return ReminderConfig{10, 3, 10, 1000, 10000} }
func (c ReminderConfig) Validate() error {
	if c.FirstDelaySeconds < 1 || c.FirstDelaySeconds > 30 || c.RecipientHourly < 1 || c.RecipientHourly > 100 || c.RecipientDaily < c.RecipientHourly || c.RecipientDaily > 1000 || c.PlatformHourly < c.RecipientHourly || c.PlatformHourly > 100000 || c.PlatformDaily < c.PlatformHourly || c.PlatformDaily > 1000000 {
		return ports.ErrInvalid
	}
	return nil
}

type ReminderService struct {
	base         *Service
	notification ports.Notification
	config       ReminderConfig
}
type reminderReceipts interface {
	Status(context.Context, string) (ports.Receipt, error)
}

func NewReminderService(base *Service, n ports.Notification) (*ReminderService, error) {
	if base == nil || n == nil {
		return nil, ports.ErrInvalid
	}
	return &ReminderService{base, n, DefaultReminderConfig()}, nil
}
func (r *ReminderService) WithConfig(c ReminderConfig) (*ReminderService, error) {
	if c.Validate() != nil {
		return nil, ports.ErrInvalid
	}
	copy := *r
	copy.config = c
	return &copy, nil
}

type reminderWindow struct {
	Grant         ports.Grant
	Hash          string
	First, Target uint64
	Read          uint64
	At            time.Time
}

func (r *ReminderService) window(ctx context.Context, tx *sql.Tx, cid, actor ports.ID, target uint64) (reminderWindow, error) {
	grant, err := r.base.conversations.RecipientLocked(ctx, tx, cid, actor)
	if err != nil {
		return reminderWindow{}, err
	}
	out := reminderWindow{Grant: grant, Hash: memberHash(grant.MemberKey)}
	if target == ^uint64(0) {
		if tx.QueryRowContext(ctx, `SELECT last_seq FROM chat_conversations WHERE id=?`, string(cid)).Scan(&target) != nil {
			return out, ports.ErrUnavailable
		}
	}
	var hash string
	err = tx.QueryRowContext(ctx, `SELECT member_key_hash,read_seq FROM chat_member_reads WHERE conversation_id=? AND actor_id=? LOCK IN SHARE MODE`, string(cid), string(actor)).Scan(&hash, &out.Read)
	if err != nil && err != sql.ErrNoRows {
		return out, ports.ErrUnavailable
	}
	if hash != out.Hash {
		out.Read = 0
	}
	predicate := `m.conversation_id=? AND m.sender_id<>? AND m.state='published' AND m.seq>? AND m.seq<=? AND ` + retainedTarget + ` AND ` + visibleColumn
	args := []any{string(cid), string(actor), out.Read, target, string(grant.Version), string(actor)}
	err = tx.QueryRowContext(ctx, `SELECT m.seq,m.created_at FROM chat_messages m WHERE `+predicate+` ORDER BY m.seq LIMIT 1`, args...).Scan(&out.First, &out.At)
	if err == sql.ErrNoRows {
		return out, nil
	}
	if err != nil {
		return out, ports.ErrUnavailable
	}
	if tx.QueryRowContext(ctx, `SELECT m.seq FROM chat_messages m WHERE `+predicate+` ORDER BY m.seq DESC LIMIT 1`, args...).Scan(&out.Target) != nil {
		return out, ports.ErrUnavailable
	}
	return out, nil
}

// DrainEvents is bounded and replay-safe. First due comes from the first
// still-unread message, never from the time this process happens to wake up.
func (r *ReminderService) DrainEvents(ctx context.Context) error {
	rows, err := r.base.db.QueryContext(ctx, `SELECT CAST(id AS CHAR),CAST(conversation_id AS CHAR),seq FROM chat_reminder_events WHERE processed_at IS NULL ORDER BY id LIMIT 50`)
	if err != nil {
		return ports.ErrUnavailable
	}
	type event struct {
		id, cid ports.ID
		seq     uint64
	}
	events := []event{}
	for rows.Next() {
		var v event
		if rows.Scan(&v.id, &v.cid, &v.seq) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		events = append(events, v)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	for _, event := range events {
		actors, e := r.base.conversations.MemberCandidates(ctx, event.cid)
		if e != nil {
			return e
		}
		for _, actor := range actors {
			tx, e := r.base.begin(ctx)
			if e != nil {
				return e
			}
			w, e := r.window(ctx, tx, event.cid, actor, event.seq)
			if e != nil {
				tx.Rollback()
				if errors.Is(e, policy.ErrDenied) {
					continue
				}
				return e
			}
			if w.First > 0 {
				_, e = tx.ExecContext(ctx, `INSERT INTO chat_reminder_clusters(conversation_id,recipient_id,member_hash,round_version,first_seq,target_seq,first_due_at,next_due,rounds,state,created_at) VALUES(?,?,?,1,?,?,TIMESTAMPADD(SECOND,?,?),TIMESTAMPADD(SECOND,?,?),0,'open',UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE round_version=IF(state='closed' OR ? >= target_seq,round_version+1,round_version),first_seq=IF(state='closed' OR ? >= target_seq,VALUES(first_seq),LEAST(first_seq,VALUES(first_seq))),first_due_at=IF(state='closed' OR ? >= target_seq,VALUES(first_due_at),first_due_at),first_notified_at=IF(state='closed' OR ? >= target_seq,NULL,first_notified_at),next_due=IF(state='closed' OR ? >= target_seq,VALUES(next_due),next_due),rounds=IF(state='closed' OR ? >= target_seq,0,rounds),target_seq=GREATEST(target_seq,VALUES(target_seq)),state=IF(state='exhausted' AND rounds>=3,'exhausted','open')`, string(event.cid), string(actor), w.Hash, w.First, w.Target, r.config.FirstDelaySeconds, w.At, r.config.FirstDelaySeconds, w.At, w.Read, w.Read, w.Read, w.Read, w.Read, w.Read)
			}
			if e != nil {
				tx.Rollback()
				return ports.ErrUnavailable
			}
			if tx.Commit() != nil {
				return ports.ErrUnavailable
			}
		}
		if _, err = r.base.db.ExecContext(ctx, `UPDATE chat_reminder_events SET processed_at=UTC_TIMESTAMP(6) WHERE id=?`, string(event.id)); err != nil {
			return ports.ErrUnavailable
		}
	}
	return nil
}
