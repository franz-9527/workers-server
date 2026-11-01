package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"regexp"
	"strconv"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// Optional real-safety finalizer: after PHP authority/current login, before
// stable account restriction/conversation locks. It must never do network I/O.
type finalSafety interface {
	ValidateTextLocked(context.Context, *sql.Tx, ports.Principal, ports.SafetyResult) error
}

var riskCodePattern = regexp.MustCompile(`^[a-z][a-z0-9_]{1,47}$`)
var riskTracePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{0,128}$`)

func terminal(state string) bool {
	return state == "accepted" || state == "rejected" || state == "review_pending"
}
func validFinding(r ports.SafetyResult) bool {
	f := r.Finding
	return f != nil && (f.Provider == "local.anti_diversion" || f.Provider == "wechat.msg_sec_check.v2" || f.Provider == "wechat.media_check_async.v2") && riskCodePattern.MatchString(f.Code) && riskTracePattern.MatchString(f.TraceID) && len(f.ContextEvidence) <= 40<<10 && (f.ContextEvidence == "" || json.Valid([]byte(f.ContextEvidence))) &&
		((r.Decision == "blocked" && f.Level == 2 && f.Confirmed) || (r.Decision == "review" && f.Level == 1 && !f.Confirmed))
}

// Risk evidence and the author's terminal result commit atomically. A late
// inspector/expired owner cannot create risk, increment counts or publish.
func (s *Service) reject(ctx context.Context, p ports.Principal, r Request, fp string, id ports.ID, owner string, checked ports.SafetyResult) (Result, error) {
	if !validFinding(checked) || !time.Now().Before(p.ExpiresAt) {
		return Result{}, ErrCheck
	}
	tx, e := s.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	// Rejections also use the canonical authority -> identity -> restriction ->
	// conversation -> intent order. An inspection completed after revocation
	// must not create a confirmed violation under the old authority.
	if _, e = s.authorize(ctx, tx, p, r.ConversationID, checked); e != nil {
		return Result{}, e
	}
	var intent string
	e = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_send_intents WHERE id=? AND sender_id=? AND conversation_id=? AND fingerprint=? AND state='checking' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) FOR UPDATE`, string(id), string(p.ActorID), string(r.ConversationID), fp, owner).Scan(&intent)
	if e == sql.ErrNoRows {
		return Result{}, ports.ErrBusy
	}
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	f := checked.Finding
	state, code := "rejected", ErrBlocked.Error()
	if checked.Decision == "review" {
		state, code = "review_pending", "CHAT_REVIEW_PENDING"
	}
	res, e := tx.ExecContext(ctx, `INSERT INTO chat_risk_events(intent_id,sender_id,conversation_id,content_fingerprint,rule_version,provider,reason_code,provider_trace,risk_level,confirmed,disposition,review_state,text_evidence,context_evidence,retain_until,created_at)
 SELECT id,sender_id,conversation_id,fingerprint,?,?,?,?,?,?,?,'open',?,?,retain_until,UTC_TIMESTAMP(6) FROM chat_send_intents WHERE id=?`, checked.RuleVersion, f.Provider, f.Code, f.TraceID, f.Level, f.Confirmed, state, r.Text, f.ContextEvidence, intent)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	n, e := res.LastInsertId()
	if e != nil || n <= 0 {
		return Result{}, ports.ErrUnavailable
	}
	risk := ports.ID(strconv.FormatInt(n, 10))
	if _, e = createCase(ctx, tx, "review", "risk", risk, "0", r.ConversationID, p.ActorID, p.ActorID, "1", fp, "内容安全待复核"); e != nil {
		return Result{}, e
	}
	res, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state=?,error_code=?,risk_event_id=?,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state='checking' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6)`, state, code, string(risk), intent, owner)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	changed, e := res.RowsAffected()
	if e != nil || changed != 1 {
		return Result{}, ports.ErrBusy
	}
	if !time.Now().Before(p.ExpiresAt) {
		return Result{}, ErrCheck
	}
	if !active(r.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	return Result{ClientMessageID: r.ClientMessageID, State: state, Code: code, RiskEventID: risk}, nil
}
