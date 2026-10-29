package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrReference = errors.New("CHAT_REFERENCE_UNAVAILABLE")
var ErrReeditLimit = errors.New("CHAT_REEDIT_LIMIT")
var ErrRecall = errors.New("CHAT_RECALL_NOT_ALLOWED")
var ErrRecallWindow = errors.New("CHAT_RECALL_WINDOW_EXPIRED")

const maxReeditDepth = 8

type ReferenceView struct {
	Type     string   `json:"message_type,omitempty"`
	ItemID   ports.ID `json:"item_id,omitempty"`
	Ordinal  int      `json:"ordinal,omitempty"`
	Count    int      `json:"count,omitempty"`
	ID       ports.ID `json:"message_id,omitempty"`
	SenderID ports.ID `json:"sender_id,omitempty"`
	State    string   `json:"state"`
	Version  ports.ID `json:"state_version,omitempty"`
	Text     *string  `json:"text,omitempty"`
}

func nullableID(id ports.ID) any {
	if id == "" {
		return nil
	}
	return string(id)
}

const retainedTarget = `m.seq>(SELECT sync_floor_seq FROM chat_conversations WHERE id=m.conversation_id) AND m.seq<=(SELECT last_seq FROM chat_conversations WHERE id=m.conversation_id)`

// Called before inspection and again inside the final publication lock. No
// client snapshot or remembered authorization can authorize a source.
func validateReferences(ctx context.Context, tx *sql.Tx, p ports.Principal, q Request, g ports.Grant) (int, error) {
	depth := 0
	for _, ref := range []struct {
		id     ports.ID
		edited bool
	}{{q.ReplyTo, false}, {q.EditedFrom, true}} {
		if ref.id == "" {
			continue
		}
		var state, kind, sender string
		var previous int
		err := tx.QueryRowContext(ctx, `SELECT m.state,m.message_type,CAST(m.sender_id AS CHAR),m.reedit_depth FROM chat_messages m WHERE m.conversation_id=? AND m.id=? AND `+retainedTarget+` AND `+visibleColumn, string(q.ConversationID), string(ref.id), string(g.Version), string(p.ActorID)).Scan(&state, &kind, &sender, &previous)
		if err == sql.ErrNoRows {
			return 0, ErrReference
		}
		if err != nil {
			return 0, ports.ErrUnavailable
		}
		if ref.edited {
			if state != "recalled" || kind != "text" || sender != string(p.ActorID) {
				return 0, ErrReference
			}
			if previous >= maxReeditDepth {
				return 0, ErrReeditLimit
			}
			depth = previous + 1
		} else if state != "published" {
			return 0, ErrReference
		} else if q.ReplyItem != "" {
			if kind != "image_album" && kind != "image" {
				return 0, ErrReference
			}
			var count int
			if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_image_items WHERE message_id=? AND item_id=?`, string(ref.id), string(q.ReplyItem)).Scan(&count) != nil || count != 1 {
				return 0, ErrReference
			}
		}
	}
	return depth, nil
}

// Hydrates at most 100 distinct targets for one bounded page. No nested
// references and no recalled/blocked text are selected, even for the author.
func hydrateReferences(ctx context.Context, tx *sql.Tx, p ports.Principal, g ports.Grant, id ports.ID, views []*MessageView) error {
	return hydrateReferenceScope(ctx, tx, p, g, id, views, false)
}
func hydrateReferenceScope(ctx context.Context, tx *sql.Tx, p ports.Principal, g ports.Grant, id ports.ID, views []*MessageView, audit bool) error {
	ids := map[ports.ID]bool{}
	for _, v := range views {
		if v.State == "published" {
			if v.ReplyTo != "" {
				ids[v.ReplyTo] = true
			}
			if v.EditedFrom != "" {
				ids[v.EditedFrom] = true
			}
		}
	}
	if len(ids) == 0 {
		return nil
	}
	if len(ids) > 100 {
		return ports.ErrInvalid
	}
	marks := make([]string, 0, len(ids))
	args := []any{string(g.Version), string(p.ActorID), string(id)}
	visibility := visibleColumn
	if audit {
		visibility = "1"
		args = []any{string(id)}
	}
	for target := range ids {
		marks = append(marks, "?")
		args = append(args, string(target))
	}
	rows, err := tx.QueryContext(ctx, `SELECT CAST(m.id AS CHAR),CAST(m.seq AS CHAR),CAST(m.sender_id AS CHAR),m.state,CAST(m.state_version AS CHAR),m.message_type,(SELECT COUNT(*) FROM chat_image_items ci WHERE ci.message_id=m.id),CASE WHEN m.state='published' AND m.message_type='text' THEN LEFT(m.text_body,160) ELSE NULL END,`+visibility+` FROM chat_messages m WHERE m.conversation_id=? AND `+retainedTarget+` AND m.id IN (`+strings.Join(marks, ",")+`)`, args...)
	if err != nil {
		return ports.ErrUnavailable
	}
	type target struct {
		ref     ReferenceView
		seq     ports.ID
		visible bool
	}
	targets := map[ports.ID]target{}
	for rows.Next() {
		var t target
		var text sql.NullString
		if rows.Scan(&t.ref.ID, &t.seq, &t.ref.SenderID, &t.ref.State, &t.ref.Version, &t.ref.Type, &t.ref.Count, &text, &t.visible) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		if t.ref.State == "published" && (t.ref.Type == "image" || t.ref.Type == "image_album") {
			value := "[图片]"
			if t.ref.Type == "image_album" {
				value = "[图片合集]"
			}
			t.ref.Text = &value
		}
		if t.ref.State == "published" && text.Valid {
			s := text.String
			t.ref.Text = &s
		}
		targets[t.ref.ID] = t
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	itemOrdinals := map[ports.ID]map[ports.ID]int{}
	itemArgs := []any{}
	for id := range ids {
		itemArgs = append(itemArgs, string(id))
	}
	itemRows, itemErr := tx.QueryContext(ctx, `SELECT CAST(message_id AS CHAR),CAST(item_id AS CHAR),ordinal FROM chat_image_items WHERE message_id IN (`+strings.Join(marks, ",")+`)`, itemArgs...)
	if itemErr != nil {
		return ports.ErrUnavailable
	}
	for itemRows.Next() {
		var mid, item ports.ID
		var ordinal int
		if itemRows.Scan(&mid, &item, &ordinal) != nil {
			itemRows.Close()
			return ports.ErrUnavailable
		}
		if itemOrdinals[mid] == nil {
			itemOrdinals[mid] = map[ports.ID]int{}
		}
		itemOrdinals[mid][item] = ordinal
	}
	itemErr = itemRows.Err()
	itemRows.Close()
	if itemErr != nil {
		return ports.ErrUnavailable
	}
	for _, v := range views {
		if v.State != "published" {
			v.Reply = nil
			v.EditedSource = nil
			continue
		}
		for _, slot := range []struct {
			id     ports.ID
			out    **ReferenceView
			edited bool
		}{{v.ReplyTo, &v.Reply, false}, {v.EditedFrom, &v.EditedSource, true}} {
			if slot.id == "" {
				continue
			}
			ref := ReferenceView{State: "unavailable"}
			t, ok := targets[slot.id]
			if ok && t.visible && number(t.seq) < number(v.Seq) {
				ref = t.ref
				if ref.State != "published" {
					ref.Text = nil
				}
				if ref.State != "published" && ref.State != "recalled" {
					ref.State = "unavailable"
					ref.Text = nil
				}
				if !slot.edited && v.ReplyItem != "" && ref.State == "published" {
					if ordinal, ok := itemOrdinals[slot.id][v.ReplyItem]; ok {
						ref.ItemID = v.ReplyItem
						ref.Ordinal = ordinal
					}
				}
				if slot.edited {
					ref.Text = nil
				}
			}
			*slot.out = &ref
		}
	}
	return nil
}
func decodeRecall(raw []byte) (HistoryRequest, error) {
	q, e := decodeHistory(raw)
	if e != nil {
		return q, e
	}
	var fields map[string]json.RawMessage
	if json.Unmarshal(raw, &fields) != nil || len(fields) != 2 || fields["conversation_id"] == nil || fields["message_id"] == nil || !validID(q.TargetID) {
		return q, ports.ErrInvalid
	}
	return q, nil
}

// Recall uses current READ authority and the conversation commit lock. It
// never takes the send restriction gate or advances last_seq/read_seq.
func (s *Service) Recall(ctx context.Context, p ports.Principal, id, target ports.ID) (MessageView, error) {
	if !validID(id) || !validID(target) {
		return MessageView{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, e := s.begin(ctx)
	if e != nil {
		return MessageView{}, e
	}
	defer tx.Rollback()
	g, e := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"})
	if e != nil {
		return MessageView{}, e
	}
	var v MessageView
	var allowed bool
	e = tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR),CAST(m.seq AS CHAR),CAST(m.sender_id AS CHAR),CAST(m.policy_version AS CHAR),m.message_type,m.state,CAST(m.state_version AS CHAR),m.created_at,TIMESTAMPDIFF(MICROSECOND,m.created_at,UTC_TIMESTAMP(6)) BETWEEN 0 AND 120000000 FROM chat_messages m WHERE m.conversation_id=? AND m.id=? AND `+retainedTarget+` AND `+visibleColumn, string(id), string(target), string(g.Version), string(p.ActorID)).Scan(&v.ID, &v.Seq, &v.SenderID, &v.PolicyVersion, &v.Type, &v.State, &v.StateVersion, &v.CreatedAt, &allowed)
	if e == sql.ErrNoRows {
		return MessageView{}, ErrRecall
	}
	if e != nil {
		return MessageView{}, ports.ErrUnavailable
	}
	if v.SenderID != p.ActorID || (v.Type != "text" && v.Type != "image" && v.Type != "image_album" && v.Type != "audio" && v.Type != "video") || (v.State != "published" && v.State != "recalled") {
		return MessageView{}, ErrRecall
	}
	changed := v.State == "published"
	if changed {
		if !allowed {
			return MessageView{}, ErrRecallWindow
		}
		var change uint64
		if tx.QueryRowContext(ctx, `SELECT last_change_seq FROM chat_conversations WHERE id=?`, string(id)).Scan(&change) != nil || change == ^uint64(0) || number(v.StateVersion) == ^uint64(0) {
			return MessageView{}, ports.ErrUnavailable
		}
		version := number(v.StateVersion) + 1
		if _, e = tx.ExecContext(ctx, `UPDATE chat_messages SET state='recalled',state_version=?,recalled_at=UTC_TIMESTAMP(6) WHERE id=? AND conversation_id=?`, version, string(target), string(id)); e != nil {
			return MessageView{}, ports.ErrUnavailable
		}
		if _, e = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(?,?,?,?,'message.recalled',UTC_TIMESTAMP(6))`, string(id), change+1, string(target), version); e != nil {
			return MessageView{}, ports.ErrUnavailable
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_change_seq=? WHERE id=?`, change+1, string(id)); e != nil {
			return MessageView{}, ports.ErrUnavailable
		}
		v.State = "recalled"
		v.StateVersion = decimal(version)
	}
	if !time.Now().Before(p.ExpiresAt) {
		return MessageView{}, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return MessageView{}, ports.ErrUnavailable
	}
	if changed && s.publisher != nil {
		event := ports.CommittedMessage{ConversationID: id, Seq: v.Seq, PolicyVersion: g.Version}
		if publisher, ok := s.publisher.(interface{ Changed(ports.CommittedMessage) }); ok {
			publisher.Changed(event)
		} else {
			s.publisher.Committed(event)
		}
	}
	return v, nil
}
