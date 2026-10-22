// Package conversation materializes PHP authority; it never interprets orders.
package conversation

import (
	"context"
	"database/sql"
	"errors"
	"strconv"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrGap = errors.New("CHAT_POLICY_VERSION_GAP")
var ErrLease = errors.New("CHAT_EVENT_LEASE_LOST")

type Config struct {
	Enabled bool `json:"enabled"`
}
type Store struct {
	db       *sql.DB
	reader   *policy.Reader
	identity *auth.MySQLSource
	timeout  time.Duration
}

func NewStore(db *sql.DB, environment string, timeout time.Duration) (*Store, error) {
	if db == nil {
		return nil, ports.ErrInvalid
	}
	r, err := policy.NewReader(environment, timeout)
	if err != nil {
		return nil, err
	}
	i, err := auth.NewMySQLSource(db)
	if err != nil {
		return nil, err
	}
	return &Store{db: db, reader: r, identity: i, timeout: timeout}, nil
}

// Authorize is for bounded metadata checks. A later send must NOT reuse its
// result: call AuthorizeLocked and commit the message using that same tx.
func (s *Store) Authorize(ctx context.Context, req ports.PolicyRequest) (ports.Grant, error) {
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, err := s.AuthorizeLocked(ctx, tx, req)
	if err != nil {
		return ports.Grant{}, err
	}
	if tx.Commit() != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	return g, nil
}

// Authority binds PHP guard/session locks to this exact transaction and request.
// Callers may lock a stable account restriction between authority and projection.
// Its fields are private so no caller can invent or reuse a grant on another tx.
type Authority struct {
	tx         *sql.Tx
	request    ports.PolicyRequest
	projection policy.Projection
	grant      ports.Grant
}

func (s *Store) AuthorizeLocked(ctx context.Context, tx *sql.Tx, req ports.PolicyRequest) (ports.Grant, error) {
	a, err := s.LockAuthority(ctx, tx, req)
	if err != nil {
		return ports.Grant{}, err
	}
	return s.LockProjection(ctx, tx, a)
}

// LockAuthority locks PHP guard, staff epochs, login and active account first.
func (s *Store) LockAuthority(ctx context.Context, tx *sql.Tx, req ports.PolicyRequest) (*Authority, error) {
	if tx == nil || !positive(req.ConversationID) || (req.Action != "read" && req.Action != "send") {
		return nil, policy.ErrDenied
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	var scope, kind string
	err := tx.QueryRowContext(ctx, `SELECT scope_key,conversation_type FROM chat_conversations WHERE id=?`, string(req.ConversationID)).Scan(&scope, &kind)
	if err == sql.ErrNoRows {
		return nil, policy.ErrDenied
	}
	if err != nil {
		return nil, ports.ErrUnavailable
	}
	if kind != "order" {
		return nil, policy.ErrDenied
	}
	p, err := s.reader.CurrentLocked(ctx, tx, scope)
	if err != nil {
		return nil, err
	}
	g, err := s.reader.AuthorizeLocked(ctx, tx, scope, p.Version, req.Actor, req.Action)
	if err != nil {
		return nil, err
	}
	if err = s.identity.ValidatePrincipalLocked(ctx, tx, req.Actor); err != nil {
		return nil, err
	}
	return &Authority{tx: tx, request: req, projection: p, grant: g}, nil
}

// LockProjection must follow any stable account restriction lock used by send.
func (s *Store) LockProjection(ctx context.Context, tx *sql.Tx, a *Authority) (ports.Grant, error) {
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	if a == nil || tx == nil || a.tx != tx {
		return ports.Grant{}, policy.ErrDenied
	}
	var version, hash, order, assignment, state string
	err := tx.QueryRowContext(ctx, `SELECT CAST(policy_version AS CHAR),snapshot_hash,CAST(order_id AS CHAR),CAST(assignment_id AS CHAR),state
		FROM chat_conversations WHERE id=? AND scope_key=? AND conversation_type='order' FOR UPDATE`, string(a.request.ConversationID), a.grant.ScopeKey).Scan(&version, &hash, &order, &assignment, &state)
	if err == sql.ErrNoRows {
		return ports.Grant{}, policy.ErrDenied
	}
	if err != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	if version != string(a.projection.Version) || hash != a.projection.Hash || order != string(a.projection.OrderID) || assignment != string(a.projection.AssignmentID) || state != a.projection.State {
		return ports.Grant{}, policy.ErrDenied
	}
	var key string
	var read, send bool
	err = tx.QueryRowContext(ctx, `SELECT member_key,can_read,can_send FROM chat_members WHERE conversation_id=? AND actor_id=? AND actor_kind='mini' LOCK IN SHARE MODE`, string(a.request.ConversationID), string(a.request.Actor.ActorID)).Scan(&key, &read, &send)
	if err == sql.ErrNoRows {
		return ports.Grant{}, policy.ErrDenied
	}
	if err != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	if key != a.grant.MemberKey || !read || (a.request.Action == "send" && !send) || !time.Now().Before(a.request.Actor.ExpiresAt) {
		return ports.Grant{}, policy.ErrDenied
	}
	return a.grant, nil
}

// FindOrder only finds an already-projected binding. It cannot create members.
func (s *Store) FindOrder(ctx context.Context, actor ports.Principal, order, assignment ports.ID) (ports.ID, error) {
	if !positive(order) || !positive(assignment) {
		return "", policy.ErrDenied
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return "", ports.ErrUnavailable
	}
	defer tx.Rollback()
	var id ports.ID
	err = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='order' AND order_id=? AND assignment_id=?`, string(order), string(assignment)).Scan(&id)
	if err == sql.ErrNoRows {
		return "", policy.ErrDenied
	}
	if err != nil {
		return "", ports.ErrUnavailable
	}
	if _, err = s.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: actor, ConversationID: id, Action: "read"}); err != nil {
		return "", err
	}
	if tx.Commit() != nil {
		return "", ports.ErrUnavailable
	}
	return id, nil
}

// VisibleVersionLocked is the S08 predicate contract. A message's policy_version
// is immutable, not reconstructed from processing time, current seq or episode.
func (s *Store) VisibleVersionLocked(ctx context.Context, tx *sql.Tx, req ports.PolicyRequest, version ports.ID) (bool, error) {
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	if !positive(version) {
		return false, policy.ErrDenied
	}
	req.Action = "read"
	g, err := s.AuthorizeLocked(ctx, tx, req)
	if err != nil {
		return false, err
	}
	if compare(version, g.Version) > 0 {
		return false, policy.ErrDenied
	}
	var start string
	// A plain EXISTS could reuse an older RR snapshot with an interval that has
	// since closed, resurrecting the returning member's unauthorized gap.
	err = tx.QueryRowContext(ctx, `SELECT CAST(start_version AS CHAR) FROM chat_member_intervals WHERE conversation_id=? AND actor_id=?
		AND start_version<=? AND (end_version IS NULL OR end_version>?) LIMIT 1 LOCK IN SHARE MODE`, string(req.ConversationID), string(req.Actor.ActorID), string(version), string(version)).Scan(&start)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, ports.ErrUnavailable
	}
	return true, nil
}

func positive(id ports.ID) bool { return id.Valid() && id != "0" }
func compare(a, b ports.ID) int {
	av, _ := strconv.ParseUint(string(a), 10, 64)
	bv, _ := strconv.ParseUint(string(b), 10, 64)
	if av < bv {
		return -1
	}
	if av > bv {
		return 1
	}
	return 0
}

// apply runs only inside a leased event transaction under the PHP guard lock.
// Intervals are [start_version,end_version); message seq is never a join bound.
func (s *Store) apply(ctx context.Context, tx *sql.Tx, p policy.Projection) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO chat_conversations(conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,created_at,updated_at)
		VALUES('order',?,?,?,0,'','','pending',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=id`, p.Scope, string(p.OrderID), string(p.AssignmentID))
	if err != nil {
		return ports.ErrUnavailable
	}
	var id, version ports.ID
	var hash, order, assignment string
	err = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),CAST(policy_version AS CHAR),snapshot_hash,CAST(order_id AS CHAR),CAST(assignment_id AS CHAR)
		FROM chat_conversations WHERE conversation_type='order' AND scope_key=? FOR UPDATE`, p.Scope).Scan(&id, &version, &hash, &order, &assignment)
	if err != nil {
		return ports.ErrUnavailable
	}
	if order != string(p.OrderID) || assignment != string(p.AssignmentID) {
		return policy.ErrDenied
	}
	if compare(p.Version, version) <= 0 {
		if p.Version == version && p.Hash != hash {
			return policy.ErrDenied
		}
		return nil
	}
	current, _ := strconv.ParseUint(string(version), 10, 64)
	incoming, _ := strconv.ParseUint(string(p.Version), 10, 64)
	if current == ^uint64(0) || incoming != current+1 {
		return ErrGap
	}
	rows, err := tx.QueryContext(ctx, `SELECT CAST(actor_id AS CHAR),member_key FROM chat_members WHERE conversation_id=? AND can_read=1 FOR UPDATE`, string(id))
	if err != nil {
		return ports.ErrUnavailable
	}
	old := map[ports.ID]string{}
	for rows.Next() {
		var actor ports.ID
		var key string
		if rows.Scan(&actor, &key) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		old[actor] = key
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return ports.ErrUnavailable
	}
	next := map[ports.ID]string{}
	for _, m := range p.Members {
		next[m.ActorID] = m.Grant.MemberKey
	}
	for actor, key := range old {
		if next[actor] != key {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_member_intervals SET end_version=? WHERE conversation_id=? AND actor_id=? AND end_version IS NULL`, string(p.Version), string(id), string(actor)); err != nil {
				return ports.ErrUnavailable
			}
		}
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_members SET can_read=0,can_send=0 WHERE conversation_id=?`, string(id)); err != nil {
		return ports.ErrUnavailable
	}
	for _, m := range p.Members {
		g := m.Grant
		if _, err = tx.ExecContext(ctx, `INSERT INTO chat_members(conversation_id,actor_id,actor_kind,role,member_key,can_read,can_send)
			VALUES(?,?,'mini',?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),member_key=VALUES(member_key),can_read=VALUES(can_read),can_send=VALUES(can_send)`, string(id), string(m.ActorID), m.Role, g.MemberKey, g.CanRead, g.CanSend); err != nil {
			return ports.ErrUnavailable
		}
		if old[m.ActorID] != g.MemberKey {
			if _, err = tx.ExecContext(ctx, `INSERT INTO chat_member_intervals(conversation_id,actor_id,member_key,start_version) VALUES(?,?,?,?)`, string(id), string(m.ActorID), g.MemberKey, string(p.Version)); err != nil {
				return ports.ErrUnavailable
			}
		}
	}
	_, err = tx.ExecContext(ctx, `UPDATE chat_conversations SET policy_version=?,snapshot_hash=?,episode_key=?,state=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, string(p.Version), p.Hash, p.Episode, p.State, string(id))
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}
