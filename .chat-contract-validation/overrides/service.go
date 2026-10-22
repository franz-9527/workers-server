// Package message owns the transport-independent, fail-closed text send use case.
// HTTP and future WSS must call Send; neither transport may write messages.
package message

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/conversation"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

var (
	ErrConflict   = errors.New("CHAT_CLIENT_MESSAGE_CONFLICT")
	ErrExpired    = errors.New("CHAT_ACTION_EXPIRED")
	ErrUnknown    = errors.New("CHAT_SEND_RESULT_UNKNOWN")
	ErrRestricted = errors.New("CHAT_SEND_RESTRICTED")
	ErrBlocked    = errors.New("CHAT_CONTENT_BLOCKED")
	ErrCheck      = errors.New("CHAT_CHECK_UNAVAILABLE")
)

const ActionWindow = 24 * time.Hour
const MinimumRetention = 30 * 24 * time.Hour
const leaseDuration = 10 * time.Second

var clientPattern = regexp.MustCompile(`^([1-9][0-9]{9})\.([0-9a-f]{32})$`)
var rulePattern = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,64}$`)

type Config struct {
	Enabled bool `json:"enabled"`
}
type Request struct {
	ConversationID  ports.ID `json:"conversation_id"`
	ClientMessageID string   `json:"client_message_id"`
	MessageType     string   `json:"message_type"`
	Text            string   `json:"text"`
}

// Result deliberately never returns stored text, including after recall/block.
// State is checking/retryable/rejected/accepted; absence is never proof of failure.
type Result struct {
	ClientMessageID string   `json:"client_message_id"`
	State           string   `json:"state"`
	Code            string   `json:"code,omitempty"`
	MessageID       ports.ID `json:"message_id,omitempty"`
	Seq             ports.ID `json:"seq,omitempty"`
	PolicyVersion   ports.ID `json:"policy_version,omitempty"`
	StateVersion    ports.ID `json:"state_version,omitempty"`
	MessageState    string   `json:"message_state,omitempty"`
}
type Service struct {
	db            *sql.DB
	conversations *conversation.Store
	safety        ports.Safety
	timeout       time.Duration
}

func NewService(db *sql.DB, c *conversation.Store, safety ports.Safety, timeout time.Duration) (*Service, error) {
	if db == nil || c == nil || safety == nil || timeout < 100*time.Millisecond || timeout > 3*time.Second {
		return nil, ports.ErrInvalid
	}
	return &Service{db, c, safety, timeout}, nil
}
func validID(id ports.ID) bool   { return id.Valid() && id != "0" }
func validClient(id string) bool { return clientPattern.MatchString(id) }
func expires(id string) time.Time {
	seconds, _ := strconv.ParseInt(strings.SplitN(id, ".", 2)[0], 10, 64)
	return time.Unix(seconds, 0).UTC().Add(ActionWindow)
}
func active(id string, now time.Time) bool {
	end := expires(id)
	return now.Before(end) && !now.Add(30*time.Second).Before(end.Add(-ActionWindow))
}
func fingerprint(r Request) (string, error) {
	if !validID(r.ConversationID) || !validClient(r.ClientMessageID) || r.MessageType != "text" || !utf8.ValidString(r.Text) || len(r.Text) > 4000 || strings.TrimSpace(r.Text) == "" || strings.ContainsRune(r.Text, 0) {
		return "", ports.ErrInvalid
	}
	// Versioned structural encoding: preserves every UTF-8 byte, whitespace and
	// line break. Future attachments/replies require a new explicit schema.
	data, _ := json.Marshal(struct {
		Schema int    `json:"schema"`
		Type   string `json:"type"`
		Text   string `json:"text"`
	}{1, r.MessageType, r.Text})
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:]), nil
}
func (s *Service) begin(ctx context.Context) (*sql.Tx, error) {
	tx, e := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	return tx, nil
}
func (s *Service) authorize(ctx context.Context, tx *sql.Tx, p ports.Principal, id ports.ID) (ports.Grant, error) {
	a, e := s.conversations.LockAuthority(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "send"})
	if e != nil {
		return ports.Grant{}, e
	}
	// Never lock a possibly absent restriction via SELECT alone. Even an
	// unrestricted actor owns a permanent row; all future bans use this same row.
	_, e = tx.ExecContext(ctx, `INSERT INTO chat_restrictions(actor_id,permanent,version,updated_at) VALUES(?,0,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(p.ActorID))
	if e != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	var restricted bool
	e = tx.QueryRowContext(ctx, `SELECT permanent=1 OR (blocked_until IS NOT NULL AND blocked_until>UTC_TIMESTAMP(6)) FROM chat_restrictions WHERE actor_id=? FOR UPDATE`, string(p.ActorID)).Scan(&restricted)
	if e != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	if restricted {
		return ports.Grant{}, ErrRestricted
	}
	return s.conversations.LockProjection(ctx, tx, a)
}

type intent struct {
	id                 ports.ID
	fingerprint, owner string
	result             Result
	leased             bool
	attempts           int
}

func (s *Service) lookup(ctx context.Context, tx *sql.Tx, p ports.Principal, conversation ports.ID, client string) (*intent, error) {
	var i intent
	i.result.ClientMessageID = client
	var message sql.NullString
	e := tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),fingerprint,state,COALESCE(error_code,''),CAST(message_id AS CHAR),COALESCE(lease_owner,''),COALESCE(lease_until>UTC_TIMESTAMP(6),0),attempts
 FROM chat_send_intents WHERE sender_id=? AND conversation_id=? AND client_message_id=? FOR UPDATE`, string(p.ActorID), string(conversation), client).Scan(&i.id, &i.fingerprint, &i.result.State, &i.result.Code, &message, &i.owner, &i.leased, &i.attempts)
	if e == sql.ErrNoRows {
		return nil, nil
	}
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	if i.result.State == "accepted" {
		if !message.Valid {
			return nil, ports.ErrUnavailable
		}
		i.result.MessageID = ports.ID(message.String)
		e = tx.QueryRowContext(ctx, `SELECT CAST(seq AS CHAR),CAST(policy_version AS CHAR),CAST(state_version AS CHAR),state FROM chat_messages WHERE id=? AND conversation_id=? AND sender_id=? LOCK IN SHARE MODE`, message.String, string(conversation), string(p.ActorID)).Scan(&i.result.Seq, &i.result.PolicyVersion, &i.result.StateVersion, &i.result.MessageState)
		if e != nil {
			return nil, ports.ErrUnavailable
		}
		// Current readable membership alone must not expose an absence-period send.
		var start string
		e = tx.QueryRowContext(ctx, `SELECT CAST(start_version AS CHAR) FROM chat_member_intervals WHERE conversation_id=? AND actor_id=? AND start_version<=? AND (end_version IS NULL OR end_version>?) LIMIT 1 LOCK IN SHARE MODE`, string(conversation), string(p.ActorID), string(i.result.PolicyVersion), string(i.result.PolicyVersion)).Scan(&start)
		if e == sql.ErrNoRows {
			return nil, policy.ErrDenied
		}
		if e != nil {
			return nil, ports.ErrUnavailable
		}
	}
	if !time.Now().Before(p.ExpiresAt) {
		return nil, policy.ErrDenied
	}
	return &i, nil
}

// Query uses read authority, never send authority or the ban gate. An accepted
// action remains confirmable by its legitimate author after a send ban/closure.
func (s *Service) Query(ctx context.Context, p ports.Principal, id ports.ID, client string) (Result, error) {
	if !validID(id) || !validClient(client) {
		return Result{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, e := s.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	if _, e = s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"}); e != nil {
		return Result{}, e
	}
	i, e := s.lookup(ctx, tx, p, id, client)
	if e != nil {
		return Result{}, e
	}
	if i == nil {
		if !active(client, time.Now()) {
			return Result{}, ErrExpired
		}
		return Result{}, ErrUnknown
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	if i.result.State != "accepted" && i.result.State != "rejected" && !active(client, time.Now()) {
		i.result.State = "rejected"
		i.result.Code = ErrExpired.Error()
	}
	return i.result, nil
}

func (s *Service) Send(ctx context.Context, p ports.Principal, r Request) (Result, error) {
	fp, e := fingerprint(r)
	if e != nil {
		return Result{}, e
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	// Read-only result lookup precedes send restrictions, expiry and safety. It
	// never commits a new message, even if the original acknowledgement was lost.
	tx, e := s.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	if _, e = s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: r.ConversationID, Action: "read"}); e != nil {
		tx.Rollback()
		return Result{}, e
	}
	previous, e := s.lookup(ctx, tx, p, r.ConversationID, r.ClientMessageID)
	if e != nil {
		tx.Rollback()
		return Result{}, e
	}
	if previous != nil && previous.fingerprint != fp {
		tx.Rollback()
		return Result{}, ErrConflict
	}
	if previous != nil && (previous.result.State == "accepted" || previous.result.State == "rejected") {
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return previous.result, nil
	}
	tx.Rollback()
	if !active(r.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	tx, e = s.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	if _, e = s.authorize(ctx, tx, p, r.ConversationID); e != nil {
		return Result{}, e
	}
	// Re-read under canonical guard -> restriction -> conversation -> intent order.
	current, e := s.lookup(ctx, tx, p, r.ConversationID, r.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if current != nil && current.fingerprint != fp {
		return Result{}, ErrConflict
	}
	if current != nil && (current.result.State == "accepted" || current.result.State == "rejected") {
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return current.result, nil
	}
	if current != nil && (current.leased || current.attempts >= 8) {
		return current.result, nil
	}
	var pending int
	if e = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_send_intents WHERE sender_id=? AND state='checking' AND lease_until>UTC_TIMESTAMP(6)`, string(p.ActorID)).Scan(&pending); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if pending >= 4 {
		return Result{}, ports.ErrBusy
	}
	var token [16]byte
	if _, e = rand.Read(token[:]); e != nil {
		return Result{}, ports.ErrUnavailable
	}
	owner := hex.EncodeToString(token[:])
	if current == nil {
		res, err := tx.ExecContext(ctx, `INSERT INTO chat_send_intents(sender_id,conversation_id,client_message_id,fingerprint,state,lease_owner,lease_until,attempts,action_expires_at,retain_until,created_at,updated_at) VALUES(?,?,?,?,'checking',?,UTC_TIMESTAMP(6)+INTERVAL 10 SECOND,1,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, string(p.ActorID), string(r.ConversationID), r.ClientMessageID, fp, owner, expires(r.ClientMessageID), expires(r.ClientMessageID).Add(MinimumRetention))
		if err != nil {
			return Result{}, ports.ErrUnavailable
		}
		n, err := res.LastInsertId()
		if err != nil || n <= 0 {
			return Result{}, ports.ErrUnavailable
		}
		current = &intent{id: ports.ID(strconv.FormatInt(n, 10))}
	} else {
		_, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='checking',error_code=NULL,lease_owner=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 10 SECOND,attempts=attempts+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, owner, string(current.id))
		if e != nil {
			return Result{}, ports.ErrUnavailable
		}
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	// No DB lease/transaction is held over network inspection. Exact text and
	// fingerprint are immutable on this stack and supplied together to safety.
	checked, checkErr := s.safety.CheckText(ctx, ports.TextRequest{Actor: p, ConversationID: r.ConversationID, IntentID: current.id, Text: r.Text, Fingerprint: fp})
	if checkErr != nil || !rulePattern.MatchString(checked.RuleVersion) || (checked.Decision != "passed" && checked.Decision != "blocked") {
		s.settle(ctx, current.id, owner, "retryable", ErrCheck.Error())
		return Result{}, ErrCheck
	}
	if checked.Decision == "blocked" {
		if !s.settle(ctx, current.id, owner, "rejected", ErrBlocked.Error()) {
			return Result{}, ports.ErrUnavailable
		}
		return Result{ClientMessageID: r.ClientMessageID, State: "rejected", Code: ErrBlocked.Error()}, nil
	}
	return s.publish(ctx, p, r, fp, current.id, owner, checked.RuleVersion)
}

func (s *Service) settle(ctx context.Context, id ports.ID, owner, state, code string) bool {
	// Intent-only CAS never acquires an earlier lock and cannot publish anything.
	res, e := s.db.ExecContext(ctx, `UPDATE chat_send_intents SET state=?,error_code=?,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state='checking' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6)`, state, code, string(id), owner)
	if e != nil {
		return false
	}
	n, e := res.RowsAffected()
	return e == nil && n == 1
}
func (s *Service) publish(ctx context.Context, p ports.Principal, r Request, fp string, id ports.ID, owner, rule string) (Result, error) {
	tx, e := s.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	grant, e := s.authorize(ctx, tx, p, r.ConversationID)
	if e != nil {
		tx.Rollback()
		if e == ErrRestricted || errors.Is(e, policy.ErrDenied) {
			s.settle(ctx, id, owner, "rejected", e.Error())
		}
		return Result{}, e
	}
	i, e := s.lookup(ctx, tx, p, r.ConversationID, r.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if i == nil || i.id != id || i.owner != owner || !i.leased || i.result.State != "checking" || i.fingerprint != fp {
		return Result{}, ports.ErrBusy
	}
	if !active(r.ClientMessageID, time.Now()) {
		tx.Rollback()
		s.settle(ctx, id, owner, "rejected", ErrExpired.Error())
		return Result{}, ErrExpired
	}
	var seq, change uint64
	e = tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq FROM chat_conversations WHERE id=? FOR UPDATE`, string(r.ConversationID)).Scan(&seq, &change)
	if e != nil || seq == ^uint64(0) || change == ^uint64(0) {
		return Result{}, ports.ErrUnavailable
	}
	seq++
	change++
	res, e := tx.ExecContext(ctx, `INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,text_body,state,state_version,safety_rule_version,created_at) VALUES(?,?,?,?,?,'text',?,'published',1,?,UTC_TIMESTAMP(6))`, string(r.ConversationID), seq, string(p.ActorID), r.ClientMessageID, string(grant.Version), r.Text, rule)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	messageID, e := res.LastInsertId()
	if e != nil || messageID <= 0 {
		return Result{}, ports.ErrUnavailable
	}
	_, e = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(?,?,?,1,'message.accepted',UTC_TIMESTAMP(6))`, string(r.ConversationID), change, messageID)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	_, e = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_seq=?,last_change_seq=?,last_message_id=?,last_message_at=UTC_TIMESTAMP(6) WHERE id=?`, seq, change, messageID, string(r.ConversationID))
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	res, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='accepted',message_id=?,error_code=NULL,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state='checking' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6)`, messageID, string(id), owner)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	n, e := res.RowsAffected()
	if e != nil || n != 1 {
		return Result{}, ports.ErrBusy
	}
	// Credential validity is rechecked after any lock wait immediately at commit.
	if !time.Now().Before(p.ExpiresAt) {
		return Result{}, policy.ErrDenied
	}
	if !active(r.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	} // UNKNOWN: query same ID.
	return Result{ClientMessageID: r.ClientMessageID, State: "accepted", MessageID: ports.ID(fmt.Sprint(messageID)), Seq: ports.ID(fmt.Sprint(seq)), PolicyVersion: grant.Version, StateVersion: "1", MessageState: "published"}, nil
}
