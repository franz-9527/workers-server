package calls

import (
	"context"
	"database/sql"
	"encoding/json"
	"strconv"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

const callSystemRule = "system.call.v1"

type callSystemEvent struct {
	Event           string   `json:"event"`
	CallID          ports.ID `json:"call_id"`
	Media           Media    `json:"media"`
	Outcome         string   `json:"outcome"`
	DurationSeconds int64    `json:"duration_seconds,omitempty"`
	CallerID        ports.ID `json:"caller_id"`
	CalleeID        ports.ID `json:"callee_id"`
}

func callMessageClientID(r record) string {
	stamp := strconv.FormatInt(r.Created, 10)
	if len(stamp) != 10 {
		stamp = "0000000000"
	}
	return stamp + "." + sha256String("call-message:"+string(r.CallID))[:32]
}

func (s *Service) callSystemEvent(ctx context.Context, tx *sql.Tx, r record) (callSystemEvent, ports.ID, error) {
	v := callSystemEvent{
		Event:    "call",
		CallID:   r.CallID,
		Media:    r.Media,
		CallerID: r.CallerID,
		CalleeID: r.CalleeID,
	}
	sender := r.CallerID
	switch r.State {
	case Rejected:
		v.Outcome, sender = "rejected", r.CalleeID
	case Cancelled:
		v.Outcome = "cancelled"
	case Missed:
		v.Outcome = "missed"
	case Failed:
		v.Outcome = "failed"
	case Ended:
		var activeAt, causeAt sql.NullTime
		var cause sql.NullString
		if err := tx.QueryRowContext(ctx, "SELECT MIN(created_at) FROM chat_call_events WHERE call_id=? AND event_type='active'", string(r.CallID)).Scan(&activeAt); err != nil {
			return v, "", err
		}
		if err := tx.QueryRowContext(ctx, "SELECT event_type,created_at FROM chat_call_events WHERE call_id=? AND event_type IN ('end','revoke','resource_stop','media_failure') ORDER BY id DESC LIMIT 1", string(r.CallID)).Scan(&cause, &causeAt); err != nil && err != sql.ErrNoRows {
			return v, "", err
		}
		if !activeAt.Valid {
			v.Outcome = "failed"
			break
		}
		v.Outcome = "ended"
		if cause.Valid && cause.String != "end" {
			v.Outcome = "interrupted"
		}
		until := time.Unix(r.LastEventAt, 0)
		if causeAt.Valid {
			until = causeAt.Time
		}
		if seconds := int64(until.Sub(activeAt.Time).Seconds()); seconds > 0 {
			v.DurationSeconds = seconds
		}
	default:
		return v, "", ErrConflict
	}
	return v, sender, nil
}

func (s *Service) ensureTerminalMessage(ctx context.Context, id ports.ID) error {
	var wake *ports.CommittedMessage
	err := s.tx(ctx, func(tx *sql.Tx) error {
		r, err := s.load(ctx, tx, id, false)
		if err != nil {
			return err
		}
		if !r.State.Terminal() || !r.PolicyVersion.Valid() {
			return ErrConflict
		}
		payload, sender, err := s.callSystemEvent(ctx, tx, r)
		if err != nil {
			return err
		}
		client := callMessageClientID(r)
		var seq, change uint64
		if err = tx.QueryRowContext(ctx, "SELECT last_seq,last_change_seq FROM chat_conversations WHERE id=? FOR UPDATE", string(r.ConversationID)).Scan(&seq, &change); err != nil || seq == ^uint64(0) || change == ^uint64(0) {
			return ports.ErrUnavailable
		}
		var existing string
		err = tx.QueryRowContext(ctx, "SELECT CAST(id AS CHAR) FROM chat_messages WHERE sender_id=? AND conversation_id=? AND client_message_id=? LIMIT 1", string(sender), string(r.ConversationID), client).Scan(&existing)
		if err == nil {
			return nil
		}
		if err != sql.ErrNoRows {
			return err
		}
		seq++
		change++
		raw, err := json.Marshal(payload)
		if err != nil {
			return ErrInvalid
		}
		result, err := tx.ExecContext(ctx, `INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,text_body,state,state_version,safety_rule_version,created_at) VALUES(?,?,?,?,?,'system_event',?,'published',1,?,UTC_TIMESTAMP(6))`, string(r.ConversationID), seq, string(sender), client, string(r.PolicyVersion), string(raw), callSystemRule)
		if err != nil {
			return err
		}
		mid, err := result.LastInsertId()
		if err != nil || mid < 1 {
			return ports.ErrUnavailable
		}
		if _, err = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(?,?,?,1,'message.accepted',UTC_TIMESTAMP(6))`, string(r.ConversationID), change, mid); err != nil {
			return ports.ErrUnavailable
		}
		if _, err = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_seq=?,last_change_seq=?,last_message_id=?,last_message_at=UTC_TIMESTAMP(6) WHERE id=?`, seq, change, mid, string(r.ConversationID)); err != nil {
			return ports.ErrUnavailable
		}
		wake = &ports.CommittedMessage{
			ConversationID: r.ConversationID,
			Seq:            ports.ID(strconv.FormatUint(seq, 10)),
			PolicyVersion:  r.PolicyVersion,
		}
		return nil
	})
	if err == nil && wake != nil && s.publisher != nil {
		s.publisher.Committed(*wake)
	}
	return err
}
