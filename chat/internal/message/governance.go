package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
	"unicode/utf8"
)

type GovernanceService struct {
	base        *Service
	history     *HistoryService
	media       *MediaService
	source      *auth.MySQLSource
	environment string
}

func NewGovernanceService(base *Service, h *HistoryService, m *MediaService, env string) (*GovernanceService, error) {
	if base == nil || h == nil || base.db != h.db || env != h.environment {
		return nil, ports.ErrInvalid
	}
	source, err := auth.NewMySQLSource(base.db)
	if err != nil {
		return nil, err
	}
	return &GovernanceService{base, h, m, source, env}, nil
}
func (g *GovernanceService) admin(ctx context.Context, tx *sql.Tx, p ports.Principal, scope string) error {
	if p.Environment != g.environment {
		return auth.ErrUnauthorized
	}
	return g.source.ValidateAdminLocked(ctx, tx, p, scope)
}
func reasonValid(s string) bool {
	return utf8.ValidString(s) && utf8.RuneCountInString(s) >= 2 && utf8.RuneCountInString(s) <= 200
}
func governanceLog(ctx context.Context, tx *sql.Tx, p ports.Principal, action string, cid, target, item ports.ID, version ports.ID, reason string) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO chat_governance_audit(actor_kind,actor_id,session_binding,action,conversation_id,target_id,item_id,target_version,reason,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))`, p.Kind, string(p.ActorID), bindingFor(p.Environment, p), action, nullableID(cid), nullableID(target), nullableID(item), nullableID(version), reason)
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (g *GovernanceService) commit(tx *sql.Tx, p ports.Principal) error {
	if !time.Now().Before(p.ExpiresAt) {
		return auth.ErrUnauthorized
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}

type AuditConversation struct {
	OrderNo      string    `json:"order_no,omitempty"`
	MatchMessage ports.ID  `json:"match_message_id,omitempty"`
	ID           ports.ID  `json:"conversation_id"`
	OrderID      ports.ID  `json:"order_id"`
	Activity     time.Time `json:"activity_at"`
}
type AuditList struct {
	Snapshot string              `json:"snapshot_cursor,omitempty"`
	Items    []AuditConversation `json:"items"`
	Cursor   string              `json:"next_cursor,omitempty"`
	More     bool                `json:"has_more"`
}
type auditCursor struct {
	Filter   string    `json:"filter,omitempty"`
	Snapshot string    `json:"snapshot"`
	At       time.Time `json:"at"`
	After    ports.ID  `json:"after"`
}

// Materialize only IDs/activity in one DB statement, never message history.
// Subsequent message activity cannot move a row across snapshot pages.
func (g *GovernanceService) AuditList(ctx context.Context, p ports.Principal, token string, limit int) (AuditList, error) {
	return g.AuditSearch(ctx, p, AuditFilter{}, token, "", limit)
}

type ReminderSummary struct {
	ID           ports.ID  `json:"cluster_id"`
	Conversation ports.ID  `json:"conversation_id"`
	Recipient    ports.ID  `json:"recipient_id"`
	FirstDue     time.Time `json:"first_due_at"`
	Target       ports.ID  `json:"target_seq"`
	LastNotified ports.ID  `json:"last_notified_seq"`
	Rounds       int       `json:"successful_rounds"`
	State        string    `json:"state"`
	Diagnostic   string    `json:"diagnostic"`
}

func (g *GovernanceService) ReminderSummaries(ctx context.Context, p ports.Principal, after ports.ID) ([]ReminderSummary, error) {
	if after == "" {
		after = "0"
	}
	if !after.Valid() {
		return nil, ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if err = g.admin(ctx, tx, p, "chat:audit"); err != nil {
		return nil, err
	}
	rows, err := tx.QueryContext(ctx, `SELECT CAST(c.id AS CHAR),CAST(c.conversation_id AS CHAR),CAST(c.recipient_id AS CHAR),c.first_due_at,CAST(c.target_seq AS CHAR),CAST(c.last_notified_seq AS CHAR),c.rounds,c.state,COALESCE(c.blocked_reason,(SELECT i.diagnostic FROM chat_reminder_intents i WHERE i.cluster_id=c.id AND i.round_version=c.round_version ORDER BY i.id DESC LIMIT 1),'') FROM chat_reminder_clusters c WHERE c.id>? AND c.first_due_at<=UTC_TIMESTAMP(6) ORDER BY c.id LIMIT 20`, string(after))
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	out := []ReminderSummary{}
	for rows.Next() {
		var v ReminderSummary
		if rows.Scan(&v.ID, &v.Conversation, &v.Recipient, &v.FirstDue, &v.Target, &v.LastNotified, &v.Rounds, &v.State, &v.Diagnostic) != nil {
			rows.Close()
			return nil, ports.ErrUnavailable
		}
		out = append(out, v)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	if err = governanceLog(ctx, tx, p, "reminders.summary", "", "", "", "", ""); err != nil {
		return nil, err
	}
	if err = g.commit(tx, p); err != nil {
		return nil, err
	}
	return out, nil
}
