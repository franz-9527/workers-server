package message

import (
	"context"
	"database/sql"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

const orderCardPrefix = "【平台订单】\n"
const directRealTypes = "'text','image','image_album','audio','video'"
const directStrangerMessageLimit = 3
const directDailyNewContactDefault = 5
const directDailyNewContactMax = 20

type directContactState struct {
	Established    bool
	Initiator      bool
	NewContact     bool
	Sent           int
	Remaining      int
	DailyLimit     int
	DailyRemaining int
}

func directDailyNewContactLimit(ctx context.Context, tx *sql.Tx) (int, error) {
	var raw string
	err := tx.QueryRowContext(ctx, `SELECT COALESCE((SELECT setting_value FROM system_settings
		WHERE setting_key='chat.direct.daily_new_contact_limit' LIMIT 1),'5')`).Scan(&raw)
	if err != nil {
		return 0, ports.ErrUnavailable
	}
	limit, err := strconv.Atoi(strings.TrimSpace(raw))
	if err != nil || limit < 1 || limit > directDailyNewContactMax {
		return 0, ports.ErrUnavailable
	}
	return limit, nil
}

func directContactStateLocked(ctx context.Context, tx *sql.Tx, cid, actor ports.ID, serializeSend bool) (directContactState, error) {
	var scope string
	if err := tx.QueryRowContext(ctx, `SELECT scope_key FROM chat_conversations WHERE id=? AND conversation_type='direct'`, string(cid)).Scan(&scope); err != nil {
		return directContactState{}, ports.ErrUnavailable
	}
	// Sending is serialized by actor before the relationship state is read.
	// This closes both same-conversation "fourth message" races and cross-conversation
	// daily-new-contact races without trusting a client-side counter.
	if serializeSend {
		if _, err := tx.ExecContext(ctx, `INSERT INTO chat_direct_contact_accounts(actor_id,updated_at) VALUES(?,UTC_TIMESTAMP(6))
			ON DUPLICATE KEY UPDATE updated_at=updated_at`, string(actor)); err != nil {
			return directContactState{}, ports.ErrUnavailable
		}
		var locked string
		if err := tx.QueryRowContext(ctx, `SELECT CAST(actor_id AS CHAR) FROM chat_direct_contact_accounts
			WHERE actor_id=? FOR UPDATE`, string(actor)).Scan(&locked); err != nil || locked != string(actor) {
			return directContactState{}, ports.ErrUnavailable
		}
	}
	state := directContactState{NewContact: true, Remaining: directStrangerMessageLimit}
	var initiator string
	var count int
	var established sql.NullTime
	lock := " LOCK IN SHARE MODE"
	if serializeSend {
		lock = " FOR UPDATE"
	}
	err := tx.QueryRowContext(ctx, `SELECT CAST(initiator_id AS CHAR),initiator_message_count,established_at
		FROM chat_direct_contacts WHERE scope_key=?`+lock, scope).Scan(&initiator, &count, &established)
	if err != nil && err != sql.ErrNoRows {
		return directContactState{}, ports.ErrUnavailable
	}
	if err == nil {
		state.NewContact = false
		state.Initiator = initiator == string(actor)
		state.Established = established.Valid
		if state.Initiator {
			state.Sent = count
			state.Remaining = directStrangerMessageLimit - count
			if state.Remaining < 0 {
				state.Remaining = 0
			}
		}
		if state.Established || !state.Initiator {
			return state, nil
		}
	} else {
		state.Initiator = true
	}
	if !state.NewContact {
		return state, nil
	}
	state.DailyLimit, err = directDailyNewContactLimit(ctx, tx)
	if err != nil {
		return directContactState{}, err
	}
	var used int
	err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_direct_contacts
		WHERE initiator_id=? AND first_contact_at>=DATE(UTC_TIMESTAMP(6)+INTERVAL 8 HOUR)-INTERVAL 8 HOUR`, string(actor)).Scan(&used)
	if err != nil {
		return directContactState{}, ports.ErrUnavailable
	}
	state.DailyRemaining = state.DailyLimit - used
	if state.DailyRemaining < 0 {
		state.DailyRemaining = 0
	}
	return state, nil
}

func enforceDirectSend(ctx context.Context, tx *sql.Tx, grant ports.Grant, cid, actor ports.ID) error {
	if grant.ConversationType != ports.DirectScene {
		return nil
	}
	state, err := directContactStateLocked(ctx, tx, cid, actor, true)
	if err != nil {
		return err
	}
	if state.Established || !state.Initiator {
		return nil
	}
	if state.Sent >= directStrangerMessageLimit {
		return ErrDirectStrangerLimit
	}
	if state.NewContact && state.DailyRemaining <= 0 {
		return ErrDirectDailyLimit
	}
	return nil
}

func recordDirectContact(ctx context.Context, tx *sql.Tx, grant ports.Grant, actor ports.ID) error {
	if grant.ConversationType != ports.DirectScene {
		return nil
	}
	_, err := tx.ExecContext(ctx, `INSERT INTO chat_direct_contacts(
		scope_key,initiator_id,initiator_message_count,first_contact_at,established_at,updated_at
	) VALUES(?,?,1,UTC_TIMESTAMP(6),NULL,UTC_TIMESTAMP(6))
	ON DUPLICATE KEY UPDATE
		initiator_message_count=IF(established_at IS NULL AND initiator_id=VALUES(initiator_id),
			LEAST(3,initiator_message_count+1),initiator_message_count),
		established_at=IF(established_at IS NULL AND initiator_id<>VALUES(initiator_id),VALUES(first_contact_at),established_at),
		updated_at=VALUES(updated_at)`, grant.ScopeKey, string(actor))
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}

func directEstablished(ctx context.Context, tx *sql.Tx, grant ports.Grant) (bool, error) {
	if grant.ConversationType != ports.DirectScene {
		return false, policy.ErrDenied
	}
	var established bool
	err := tx.QueryRowContext(ctx, `SELECT established_at IS NOT NULL FROM chat_direct_contacts WHERE scope_key=?`, grant.ScopeKey).Scan(&established)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, ports.ErrUnavailable
	}
	return established, nil
}

func validateDirectOrderCard(ctx context.Context, tx *sql.Tx, g ports.Grant, cid ports.ID, text string) error {
	if g.ConversationType != ports.DirectScene || !strings.HasPrefix(text, orderCardPrefix) {
		return nil
	}
	established, err := directEstablished(ctx, tx, g)
	if err != nil {
		return err
	}
	if !established {
		return ErrRestricted
	}
	var card struct {
		PublicNo string `json:"publicNo"`
		Title    string `json:"title"`
	}
	if !strictImageJSON([]byte(strings.TrimPrefix(text, orderCardPrefix)), &card) || len(card.PublicNo) > 32 ||
		len(card.PublicNo) == 0 || utf8.RuneCountInString(card.Title) > 120 {
		return ports.ErrInvalid
	}
	pair, ok := ports.ParseScope(g.ScopeKey)
	if !ok || pair.Scene != ports.DirectScene {
		return policy.ErrDenied
	}
	var number string
	err = tx.QueryRowContext(ctx, `SELECT o.public_no FROM orders o JOIN assignments a ON a.id=o.current_assignment_id
		JOIN chat_profile_worker_display w ON w.worker_id=a.worker_id
		WHERE o.public_no=? AND o.is_demo=0 AND ((o.user_id=? AND w.user_id=?) OR (o.user_id=? AND w.user_id=?))
		LOCK IN SHARE MODE`, card.PublicNo, string(pair.First), string(pair.Second), string(pair.Second), string(pair.First)).Scan(&number)
	if err == sql.ErrNoRows {
		return policy.ErrDenied
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}

func (s *HistoryService) directReport(ctx context.Context, p ports.Principal, cid, mid ports.ID, reason string) (any, error) {
	if !validID(cid) || !validID(mid) || (reason != "abuse" && reason != "spam" && reason != "unsafe") {
		return nil, ports.ErrInvalid
	}
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, err := s.conversations.AuthorizeLocked(policy.WithDirect(ctx), tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"})
	if err != nil {
		return nil, err
	}
	var target, version ports.ID
	var mime, proof string
	err = tx.QueryRowContext(ctx, `SELECT CAST(m.sender_id AS CHAR),CAST(m.state_version AS CHAR),m.message_type,SHA2(COALESCE(m.text_body,''),256)
		FROM chat_messages m WHERE m.id=? AND m.conversation_id=? AND m.sender_id<>? AND m.state='published' AND `+visibleColumn,
		string(mid), string(cid), string(p.ActorID), string(g.Version), string(p.ActorID)).Scan(&target, &version, &mime, &proof)
	if err == sql.ErrNoRows {
		return nil, policy.ErrDenied
	}
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO chat_direct_report_limits(actor_id) VALUES(?) ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(p.ActorID))
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	var lockedReporter string
	if err = tx.QueryRowContext(ctx, `SELECT CAST(actor_id AS CHAR) FROM chat_direct_report_limits WHERE actor_id=? FOR UPDATE`, string(p.ActorID)).Scan(&lockedReporter); err != nil || lockedReporter != string(p.ActorID) {
		return nil, ports.ErrUnavailable
	}
	var existing bool
	err = tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM chat_direct_reports WHERE reporter_id=? AND conversation_id=? AND message_id=?)`,
		string(p.ActorID), string(cid), string(mid)).Scan(&existing)
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	if !existing {
		var count int
		if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_direct_reports WHERE reporter_id=? AND created_at>=UTC_TIMESTAMP(6)-INTERVAL 1 DAY`, string(p.ActorID)).Scan(&count) != nil {
			return nil, ports.ErrUnavailable
		}
		if count >= 5 {
			return nil, ports.ErrBusy
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO chat_direct_reports(conversation_id,message_id,reporter_id,subject_id,state_version,message_type,proof_hash,reason,created_at)
			VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))`, string(cid), string(mid), string(p.ActorID), string(target), string(version), mime, proof, reason)
		if err != nil {
			return nil, ports.ErrUnavailable
		}
	}
	if !time.Now().Before(p.ExpiresAt) {
		return nil, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return nil, ports.ErrUnavailable
	}
	return map[string]bool{"received": true}, nil
}