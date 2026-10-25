package message

import (
	"context"
	"database/sql"
	"strconv"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

const unreadCountLimit = 1000

type UnreadWindow struct {
 Mode string `json:"conversation_mode,omitempty"`
	CountCapped       bool                `json:"unread_count_capped"`
	ReadSeq           ports.ID            `json:"read_seq"`
	SnapshotSeq       ports.ID            `json:"snapshot_max_seq"`
	SnapshotChange    ports.ID            `json:"snapshot_max_change_seq"`
	PolicyVersion     ports.ID            `json:"policy_version"`
	Count             uint64              `json:"unread_count"`
	FirstMessage      ports.ID            `json:"first_unread_message_id,omitempty"`
	FirstSeq          ports.ID            `json:"first_unread_seq,omitempty"`
	LastMessageAt     *time.Time          `json:"last_message_at,omitempty"`
	LastMessage       *MessageView        `json:"last_message,omitempty"`
	OrderContext      *ports.OrderContext `json:"order_context,omitempty"`
	MemberRole        string              `json:"member_role"`
	CanSend           bool                `json:"can_send"`
	ConversationState string              `json:"conversation_state"`
	Preferences       *Preferences        `json:"preferences,omitempty"`
}

// unreadLocked counts actual currently eligible incoming published messages,
// never the gap between sequence numbers. Call only under AuthorizeLocked.
func unreadLocked(ctx context.Context, tx *sql.Tx, p ports.Principal, id ports.ID, grant ports.Grant, read, upper, change uint64) (UnreadWindow, error) {
	if read > upper {
		return UnreadWindow{}, ports.ErrInvalid
	}
	result := UnreadWindow{Mode:grant.Mode,ReadSeq: decimal(read), SnapshotSeq: decimal(upper), SnapshotChange: decimal(change), PolicyVersion: grant.Version, OrderContext: grant.OrderContext, MemberRole: grant.MemberRole, CanSend: grant.CanSend}
	// Activity belongs to the last message this member may see, including their
	// own messages and recall placeholders. Read acknowledgements never move it.
	var preview MessageView
	var text sql.NullString
	err := tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR),CAST(m.seq AS CHAR),CAST(m.sender_id AS CHAR),CAST(m.policy_version AS CHAR),m.message_type,m.state,CAST(m.state_version AS CHAR),CASE WHEN m.state='published' AND m.message_type='text' THEN LEFT(m.text_body,160) ELSE NULL END,m.created_at,(SELECT COUNT(*) FROM chat_image_items ci WHERE ci.message_id=m.id),m.retention_mode,m.expires_at FROM chat_messages m WHERE m.conversation_id=? AND m.seq<=? AND m.policy_version<=? AND EXISTS (
 SELECT 1 FROM chat_member_intervals i WHERE i.conversation_id=m.conversation_id AND i.actor_id=?
 AND i.start_version<=m.policy_version AND (i.end_version IS NULL OR i.end_version>m.policy_version)) ORDER BY m.seq DESC LIMIT 1`, string(id), upper, string(grant.Version), string(p.ActorID)).Scan(&preview.ID, &preview.Seq, &preview.SenderID, &preview.PolicyVersion, &preview.Type, &preview.State, &preview.StateVersion, &text, &preview.CreatedAt, &preview.ImageCount, &preview.RetentionMode, &preview.ExpiresAt)
	if err != nil && err != sql.ErrNoRows {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	if err == nil {
		preview.CreatedAt = preview.CreatedAt.UTC()
		result.LastMessageAt = &preview.CreatedAt
		if preview.State == "published" && preview.Type == "text" && text.Valid {
			value := text.String
			preview.Text = &value
		}
		if preview.State != "published" && preview.State != "recalled" && preview.State != "blocked" && preview.State != "expired" {
			preview.State = "unavailable"
			preview.Text = nil
		}
		result.LastMessage = &preview
	}
	prefs, err := preferenceLocked(ctx, tx, p, id, grant)
	if err != nil {
		return UnreadWindow{}, err
	}
	prefs.AccountRevision, err = accountRevision(ctx, tx, p.ActorID)
	if err != nil {
		return UnreadWindow{}, err
	}
	result.Preferences = &prefs
	if tx.QueryRowContext(ctx, `SELECT state FROM chat_conversations WHERE id=?`, string(id)).Scan(&result.ConversationState) != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	predicate := `m.conversation_id=? AND m.seq>? AND m.seq<=? AND m.state='published' AND m.sender_id<>? AND m.policy_version<=? AND EXISTS (
 SELECT 1 FROM chat_member_intervals i WHERE i.conversation_id=m.conversation_id AND i.actor_id=?
 AND i.start_version<=m.policy_version AND (i.end_version IS NULL OR i.end_version>m.policy_version))`
	args := []any{string(id), read, upper, string(p.ActorID), string(grant.Version), string(p.ActorID)}
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM (SELECT m.id FROM chat_messages m FORCE INDEX (idx_chat_unread) WHERE `+predicate+` LIMIT 1001) unread_candidates`, args...).Scan(&result.Count); err != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	if result.Count > unreadCountLimit {
		result.Count = unreadCountLimit
		result.CountCapped = true
	}
	var first, seq string
	e := tx.QueryRowContext(ctx, `SELECT CAST(m.id AS CHAR),CAST(m.seq AS CHAR) FROM chat_messages m FORCE INDEX (idx_chat_unread) WHERE `+predicate+` ORDER BY m.seq ASC LIMIT 1`, args...).Scan(&first, &seq)
	if e != nil && e != sql.ErrNoRows {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	if e == nil {
		result.FirstMessage = ports.ID(first)
		result.FirstSeq = ports.ID(seq)
	}
	return result, nil
}

// unreadSince is for trusted server-side preloaded watermarks and tests; HTTP
// consumers must use Unread, which reads the watermark inside its own lock.
func (s *HistoryService) unreadSince(ctx context.Context, p ports.Principal, id ports.ID, readSeq ports.ID) (UnreadWindow, error) {
	if !validID(id) || !readSeq.Valid() || p.Environment != s.environment {
		return UnreadWindow{}, ports.ErrInvalid
	}
	read, _ := strconv.ParseUint(string(readSeq), 10, 64)
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, e := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if e != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, e := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"})
	if e != nil {
		return UnreadWindow{}, e
	}
	var upper, change uint64
	if tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq FROM chat_conversations WHERE id=?`, string(id)).Scan(&upper, &change) != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	out, e := unreadLocked(ctx, tx, p, id, g, read, upper, change)
	if e != nil {
		return UnreadWindow{}, e
	}
	if !time.Now().Before(p.ExpiresAt) {
		return UnreadWindow{}, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	return out, nil
}

// Unread obtains authorization, committed max sequence, persisted watermark,
// publication states and membership ranges in one transaction. No read
// acknowledgement is created by opening an unread/digest endpoint.
func (s *HistoryService) Unread(ctx context.Context, p ports.Principal, id ports.ID) (UnreadWindow, error) {
	if !validID(id) || !p.ActorID.Valid() || p.Environment != s.environment {
		return UnreadWindow{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, e := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if e != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, e := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"})
	if e != nil {
		return UnreadWindow{}, e
	}
	var upper, change, read uint64
	if tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq FROM chat_conversations WHERE id=?`, string(id)).Scan(&upper, &change) != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	var hash string
	e = tx.QueryRowContext(ctx, `SELECT member_key_hash,read_seq FROM chat_member_reads WHERE conversation_id=? AND actor_id=?`, string(id), string(p.ActorID)).Scan(&hash, &read)
	if e != nil && e != sql.ErrNoRows {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	if e == sql.ErrNoRows || hash != memberHash(g.MemberKey) {
		read = 0
	}
	out, e := unreadLocked(ctx, tx, p, id, g, read, upper, change)
	if e != nil {
		return UnreadWindow{}, e
	}
	if !time.Now().Before(p.ExpiresAt) {
		return UnreadWindow{}, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return UnreadWindow{}, ports.ErrUnavailable
	}
	return out, nil
}
