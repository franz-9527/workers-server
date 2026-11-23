package message

import (
	"context"
	"database/sql"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type supportPreview struct {
	ID           ports.ID `json:"message_id"`
	Type         string   `json:"message_type"`
	State        string   `json:"state"`
	StateVersion ports.ID `json:"state_version"`
	Text         *string  `json:"text,omitempty"`
	ImageCount   int      `json:"image_count,omitempty"`
}

type supportRow struct {
	ID                ports.ID        `json:"conversation_id"`
	OwnerID           ports.ID        `json:"owner_id"`
	Suffix            string          `json:"owner_suffix"`
	OwnerName         string          `json:"owner_name"`
	OwnerRole         string          `json:"owner_role"`
	Last              ports.ID        `json:"last_message_id"`
	LastUserMessage   *supportPreview `json:"last_user_message,omitempty"`
	LastUserMessageAt *time.Time      `json:"last_user_message_at,omitempty"`
	Version           ports.ID        `json:"work_version"`
	UserSeq           ports.ID        `json:"last_user_seq"`
	Handled           bool            `json:"handled"`
	Pinned            bool            `json:"pinned"`
	Unread            uint64          `json:"unread_count"`
	Capped            bool            `json:"unread_count_capped"`
	timeKey           string
}

type supportInboxStageError struct{ code string }

func (e supportInboxStageError) Error() string              { return e.code }
func (e supportInboxStageError) Unwrap() error              { return ports.ErrUnavailable }
func (e supportInboxStageError) PublicCode() (string, bool) { return e.code, true }

func supportInboxUnavailable(code string) error { return supportInboxStageError{code: code} }

// Keep the unread visibility predicate equivalent to messageVisibility for
// Support, but express it with one-level joins so MySQL 5.7 never has to
// resolve nested correlated aliases from the outer inbox query.
const supportUnreadJoins = `
	LEFT JOIN chat_messages unread_msg ON unread_msg.conversation_id=c.id
		AND unread_msg.sender_id<>m.actor_id AND unread_msg.state='published'
		AND unread_msg.seq>COALESCE(r.read_seq,0) AND unread_msg.policy_version<=c.policy_version
	LEFT JOIN chat_member_intervals unread_interval ON unread_interval.conversation_id=unread_msg.conversation_id
		AND unread_interval.actor_id=m.actor_id AND unread_interval.member_key=m.member_key
		AND unread_interval.start_version<=unread_msg.policy_version AND unread_interval.end_version IS NULL`

const supportUnreadVisible = `(unread_msg.id IS NOT NULL AND
	(unread_msg.seq<=COALESCE(handoff.through_seq,0) OR unread_interval.actor_id IS NOT NULL))`

const supportUnreadExists = `EXISTS(SELECT 1 FROM chat_messages unread_filter
	LEFT JOIN chat_member_intervals unread_filter_interval
		ON unread_filter_interval.conversation_id=unread_filter.conversation_id
		AND unread_filter_interval.actor_id=m.actor_id AND unread_filter_interval.member_key=m.member_key
		AND unread_filter_interval.start_version<=unread_filter.policy_version AND unread_filter_interval.end_version IS NULL
	WHERE unread_filter.conversation_id=c.id AND unread_filter.sender_id<>m.actor_id AND unread_filter.state='published'
		AND unread_filter.seq>COALESCE(r.read_seq,0) AND unread_filter.policy_version<=c.policy_version
		AND (unread_filter.seq<=COALESCE(handoff.through_seq,0) OR unread_filter_interval.actor_id IS NOT NULL))`

func (s *HistoryService) supportInbox(ctx context.Context, p ports.Principal, cursor, filter string, limit int) (any, error) {
	if _, mock := ports.SandboxSource(p.SourceID); mock || p.Environment != s.environment || p.Kind != "mini" || len(p.Scopes) != 1 || p.Scopes[0] != "chat:use" {
		return nil, policy.ErrDenied
	}
	if filter == "" {
		filter = "all"
	}
	if limit < 1 || limit > 20 || (filter != "all" && filter != "pending" && filter != "unread" && filter != "hidden" && filter != "archived") {
		return nil, ports.ErrInvalid
	}
	if err := s.conversations.ValidatePrincipal(ctx, p); err != nil {
		return nil, err
	}
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelRepeatableRead})
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	defer tx.Rollback()

	generation, revision, err := policy.SupportStaffBinding(ctx, tx, p.ActorID)
	if err != nil {
		return nil, err
	}

	joins := ` FROM chat_members m JOIN chat_conversations c ON c.id=m.conversation_id AND c.conversation_type='support'
		JOIN chat_support_work w ON w.conversation_id=c.id
		JOIN chat_support_guards g ON g.scope_key=c.scope_key AND g.snapshot_hash=c.snapshot_hash AND g.policy_version=c.policy_version
		JOIN users u ON u.id=g.owner_id
		LEFT JOIN chat_profile_worker_display worker_display ON worker_display.user_id=u.id
		LEFT JOIN chat_profile_authorized_display authorized_display ON authorized_display.user_id=u.id
		JOIN chat_messages feedback ON feedback.conversation_id=c.id AND feedback.seq=w.last_user_seq
		LEFT JOIN chat_member_reads r ON r.conversation_id=c.id AND r.actor_id=m.actor_id AND r.member_key_hash=SHA2(m.member_key,256)
		LEFT JOIN chat_user_preferences pref ON pref.conversation_id=c.id AND pref.actor_id=m.actor_id AND pref.member_key_hash=SHA2(m.member_key,256)
		LEFT JOIN chat_scene_preferences opt ON opt.conversation_id=c.id AND opt.actor_id=m.actor_id AND opt.member_key_hash=SHA2(m.member_key,256)
		LEFT JOIN chat_support_history_access handoff ON handoff.scope_key=c.scope_key AND handoff.actor_id=m.actor_id
		  AND m.member_key=CONCAT(c.scope_key,':staff:',m.actor_id,':',?,':',handoff.assignment_version)`
	where := ` WHERE m.actor_id=? AND m.actor_kind='mini' AND m.role='support_staff' AND m.can_read=1
		AND m.member_key=CONCAT(c.scope_key,':staff:',m.actor_id,':',?,':',SUBSTRING_INDEX(m.member_key,':',-1))`
	if s.environment != "test" {
		where += " AND c.scope_key NOT LIKE 'support:platform_feedback_test:%'"
	}
	base := joins + where
	args := []any{string(generation), string(p.ActorID), string(generation)}
	var pending, attention int
	var upper ports.ID
	err = tx.QueryRowContext(ctx, "SELECT COALESCE(SUM(w.handled=0),0),CAST(COALESCE(MAX(c.last_message_id),0) AS CHAR)"+base, args...).Scan(&pending, &upper)
	if err != nil {
		return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_SUMMARY_UNAVAILABLE")
	}
	// attention_count is a global exact count, but it must not make the inbox
	// depend on a derived GROUP BY/HAVING query. Count the disjoint sets
	// separately so MySQL 5.7 can use the same bounded membership snapshot as
	// the list: later conversations, plus unread conversations not already later.
	var laterAttention, unreadAttention int
	attentionWarning := ""
	err = tx.QueryRowContext(ctx, "SELECT COUNT(*)"+base+" AND COALESCE(pref.later,0)=1", args...).Scan(&laterAttention)
	if err != nil {
		laterAttention = 0
		attentionWarning = "CHAT_SUPPORT_INBOX_ATTENTION_LATER_UNAVAILABLE"
	}
	err = tx.QueryRowContext(ctx, "SELECT COUNT(*)"+base+" AND COALESCE(pref.later,0)=0 AND "+supportUnreadExists, args...).Scan(&unreadAttention)
	if err != nil {
		unreadAttention = 0
		if attentionWarning == "" {
			attentionWarning = "CHAT_SUPPORT_INBOX_ATTENTION_UNREAD_UNAVAILABLE"
		}
	}
	attention = laterAttention + unreadAttention
	mode := "feedback.inbox." + filter
	prefRevision, err := accountRevision(ctx, tx, p.ActorID)
	if err != nil {
		return nil, err
	}
	if filter == "pending" {
		base += " AND w.handled=0"
	}
	if filter == "unread" {
		base += " AND " + supportUnreadExists
	}
	switch filter {
	case "hidden":
		base += " AND opt.hidden=1"
	case "archived":
		base += " AND opt.archived=1"
	default:
		base += " AND COALESCE(opt.hidden,0)=0 AND COALESCE(opt.archived,0)=0"
	}
	now := time.Now()
	pageCursor := historyCursor{Schema: 1, Mode: mode, Binding: bindingFor(s.environment, p), Policy: revision, Seq: upper, Change: prefRevision, Position: "0", Issued: now.Unix(), Expiry: now.Add(cursorTTL).Unix()}
	if cursor != "" {
		prior, err := s.parse(cursor, mode)
		if err != nil || prior.Binding != pageCursor.Binding || prior.Policy != revision || prior.Seq != upper || prior.Change != prefRevision {
			return nil, ErrResync
		}
		base += " AND (COALESCE(pref.pinned,0)<? OR (COALESCE(pref.pinned,0)=? AND (c.last_message_at<? OR (c.last_message_at=? AND c.id<?))))"
		args = append(args, string(prior.Position), string(prior.Position), prior.Member, prior.Member, string(prior.Conversation))
		pageCursor.Issued, pageCursor.Expiry = prior.Issued, prior.Expiry
	}
	rows, err := tx.QueryContext(ctx, `SELECT CAST(c.id AS CHAR),CAST(g.owner_id AS CHAR),RIGHT(u.public_id,6),
		CASE WHEN worker_display.user_id IS NOT NULL THEN
			CASE WHEN BINARY worker_display.real_name_hash=BINARY authorized_display.real_name_hash AND authorized_display.verified_name<>'' THEN authorized_display.verified_name
				ELSE COALESCE(NULLIF(TRIM(worker_display.worker_nickname),''),CONCAT('师傅',RIGHT(u.public_id,6))) END
		ELSE CASE WHEN u.mobile_masked LIKE '%*%' THEN CONCAT('用户',u.mobile_masked)
			WHEN CHAR_LENGTH(u.mobile_masked)=11 THEN CONCAT('用户',LEFT(u.mobile_masked,3),'****',RIGHT(u.mobile_masked,4))
			ELSE CONCAT('用户',RIGHT(u.public_id,6)) END END,CASE WHEN worker_display.user_id IS NOT NULL THEN 'worker' ELSE 'user' END,
		CAST(c.last_message_id AS CHAR),CAST(w.work_version AS CHAR),
		CAST(w.last_user_seq AS CHAR),w.handled,COALESCE(pref.pinned,0),CAST(feedback.id AS CHAR),feedback.message_type,feedback.state,
		CAST(feedback.state_version AS CHAR),CASE WHEN feedback.state='published' AND feedback.message_type='text' THEN LEFT(feedback.text_body,160) ELSE NULL END,
		feedback.created_at,(SELECT COUNT(*) FROM chat_image_items ci WHERE ci.message_id=feedback.id),
		DATE_FORMAT(c.last_message_at,'%Y-%m-%d %H:%i:%s.%f')`+base+" ORDER BY COALESCE(pref.pinned,0) DESC,c.last_message_at DESC,c.id DESC LIMIT ?", append(args, limit+1)...)
	if err != nil {
		return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_LIST_UNAVAILABLE")
	}
	items := []supportRow{}
	for rows.Next() {
		var item supportRow
		var latestID, latestStateVersion ports.ID
		var latestType, latestState string
		var latestText sql.NullString
		var latestAt time.Time
		var latestImageCount int
		if err = rows.Scan(&item.ID, &item.OwnerID, &item.Suffix, &item.OwnerName, &item.OwnerRole, &item.Last, &item.Version, &item.UserSeq, &item.Handled, &item.Pinned,
			&latestID, &latestType, &latestState, &latestStateVersion, &latestText, &latestAt, &latestImageCount, &item.timeKey); err != nil {
			rows.Close()
			return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_SCAN_UNAVAILABLE")
		}
		latestAt = latestAt.UTC()
		item.LastUserMessageAt = &latestAt
		preview := &supportPreview{ID: latestID, Type: latestType, State: latestState, StateVersion: latestStateVersion, ImageCount: latestImageCount}
		if latestState == "published" && latestType == "text" && latestText.Valid {
			value := latestText.String
			preview.Text = &value
		}
		item.LastUserMessage = preview
		items = append(items, item)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_SCAN_UNAVAILABLE")
	}
	next := ""
	if len(items) > limit {
		items = items[:limit]
		last := items[limit-1]
		pageCursor.Member, pageCursor.Conversation = last.timeKey, last.ID
		if last.Pinned {
			pageCursor.Position = "1"
		}
		next = s.sign(pageCursor)
	}
	// Count unread in one bounded query for this page. This preserves the same
	// Support generation/member-key and handover visibility rules without the
	// old per-row authorization N+1 or a nested correlated scalar subquery.
	if len(items) > 0 {
		slots := strings.TrimSuffix(strings.Repeat("?,", len(items)), ",")
		unreadArgs := []any{string(generation), string(p.ActorID), string(generation)}
		for _, item := range items {
			unreadArgs = append(unreadArgs, string(item.ID))
		}
		unreadWhere := ` WHERE m.actor_id=? AND m.actor_kind='mini' AND m.role='support_staff' AND m.can_read=1
			AND m.member_key=CONCAT(c.scope_key,':staff:',m.actor_id,':',?,':',SUBSTRING_INDEX(m.member_key,':',-1))
			AND c.id IN (` + slots + `)`
		if s.environment != "test" {
			unreadWhere += " AND c.scope_key NOT LIKE 'support:platform_feedback_test:%'"
		}
		unreadSQL := `SELECT CAST(c.id AS CHAR),
			COUNT(DISTINCT CASE WHEN ` + supportUnreadVisible + ` THEN unread_msg.id END)
			FROM chat_members m
			JOIN chat_conversations c ON c.id=m.conversation_id AND c.conversation_type='support'
			LEFT JOIN chat_member_reads r ON r.conversation_id=c.id AND r.actor_id=m.actor_id AND r.member_key_hash=SHA2(m.member_key,256)
			LEFT JOIN chat_support_history_access handoff ON handoff.scope_key=c.scope_key AND handoff.actor_id=m.actor_id
				AND m.member_key=CONCAT(c.scope_key,':staff:',m.actor_id,':',?,':',handoff.assignment_version)` +
			supportUnreadJoins + unreadWhere + ` GROUP BY c.id`
		unreadRows, unreadErr := tx.QueryContext(ctx, unreadSQL, unreadArgs...)
		if unreadErr != nil {
			return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_UNREAD_UNAVAILABLE")
		}
		counts := make(map[ports.ID]uint64, len(items))
		for unreadRows.Next() {
			var id ports.ID
			var count uint64
			if unreadRows.Scan(&id, &count) != nil {
				unreadRows.Close()
				return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_UNREAD_UNAVAILABLE")
			}
			counts[id] = count
		}
		if unreadRows.Err() != nil {
			unreadRows.Close()
			return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_UNREAD_UNAVAILABLE")
		}
		unreadRows.Close()
		for idx := range items {
			items[idx].Unread = counts[items[idx].ID]
			if items[idx].Unread > unreadCountLimit {
				items[idx].Unread = unreadCountLimit
				items[idx].Capped = true
			}
		}
	}
	if !time.Now().Before(p.ExpiresAt) {
		return nil, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return nil, supportInboxUnavailable("CHAT_SUPPORT_INBOX_COMMIT_UNAVAILABLE")
	}
	if err = s.conversations.ValidatePrincipal(ctx, p); err != nil {
		return nil, err
	}
	return map[string]any{
		"items": items, "pending_count": pending, "attention_count": attention,
		"attention_count_complete": attentionWarning == "", "attention_warning": attentionWarning,
		"next_cursor": next,
	}, nil
}

func (s *HistoryService) handleFeedback(ctx context.Context, p ports.Principal, id, version, seq ports.ID) (any, error) {
	if !validID(id) || !validID(version) || !validID(seq) {
		return nil, ports.ErrInvalid
	}
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	defer tx.Rollback()
	grant, err := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"})
	if err != nil {
		return nil, err
	}
	if grant.MemberRole != "support_staff" {
		return nil, policy.ErrDenied
	}
	result, err := tx.ExecContext(ctx, `UPDATE chat_support_work SET handled=1,handled_by=?,work_version=work_version+1,updated_at=UTC_TIMESTAMP(6) WHERE conversation_id=? AND work_version=? AND last_user_seq=? AND handled=0`, string(p.ActorID), string(id), string(version), string(seq))
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	count, err := result.RowsAffected()
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	if count == 0 {
		var replay bool
		err = tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM chat_support_work w JOIN chat_support_work_audit a ON a.conversation_id=w.conversation_id
			WHERE w.conversation_id=? AND w.handled=1 AND w.handled_by=a.actor_id AND w.work_version=a.prior_version+1
			AND a.actor_id=? AND a.prior_version=? AND a.observed_seq=?)`, string(id), string(p.ActorID), string(version), string(seq)).Scan(&replay)
		if err != nil {
			return nil, ports.ErrUnavailable
		}
		if !replay {
			return nil, ErrResync
		}
	} else {
		_, err = tx.ExecContext(ctx, `INSERT INTO chat_support_work_audit(conversation_id,actor_id,prior_version,observed_seq,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))`, string(id), string(p.ActorID), string(version), string(seq))
		if err != nil {
			return nil, ports.ErrUnavailable
		}
	}
	if !time.Now().Before(p.ExpiresAt) {
		return nil, policy.ErrDenied
	}
	if err = tx.Commit(); err != nil {
		return nil, ports.ErrUnavailable
	}
	return map[string]bool{"handled": true}, nil
}
