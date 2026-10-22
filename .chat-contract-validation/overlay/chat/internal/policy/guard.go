// Package policy reads PHP-owned authorization facts. It never interprets order states.
package policy

import (
	"bytes"
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrDenied = errors.New("CHAT_POLICY_DENIED")
var hexVersion = regexp.MustCompile(`^[a-f0-9]{64}$`)

type member struct {
	ActorID ports.ID `json:"actor_id"`
	Kind    string   `json:"kind"`
	Role    string   `json:"role"`
	CanRead bool     `json:"can_read"`
	CanSend bool     `json:"can_send"`
}

func (m *member) UnmarshalJSON(data []byte) error {
	type plain member
	if !object(data, (*plain)(m), "actor_id", "kind", "role", "can_read", "can_send") {
		return ErrDenied
	}
	return nil
}

type snapshot struct {
	Schema       int      `json:"schema_version"`
	Scope        string   `json:"scope_key"`
	OrderID      ports.ID `json:"order_id"`
	AssignmentID ports.ID `json:"assignment_id"`
	Episode      string   `json:"episode_key"`
	State        string   `json:"state"`
	CanInvite    bool     `json:"can_invite"`
	Members      []member `json:"members"`
}

type row struct{ scope, orderID, assignmentID, version, state, body, hash string }

// Reader is deliberately not ports.Policy: S05 must resolve the conversation's
// trusted binding first. A client-provided scope must never replace that binding.
type Reader struct {
	environment string
	timeout     time.Duration
}

func NewReader(environment string, timeout time.Duration) (*Reader, error) {
	if (environment != "development" && environment != "test" && environment != "production") || timeout <= 0 || timeout > 5*time.Second {
		return nil, ports.ErrInvalid
	}
	return &Reader{environment: environment, timeout: timeout}, nil
}

// AuthorizeLocked holds the PHP guard's shared lock in the caller's transaction.
// S06 must commit the message using this SAME tx, never authorize then start a
// second transaction. expectedVersion is the applied S05 membership version.
// The caller must also revalidate the S03 session and account restrictions.
func (r *Reader) AuthorizeLocked(ctx context.Context, tx *sql.Tx, scope string, expectedVersion ports.ID, actor ports.Principal, action string) (ports.Grant, error) {
	if tx == nil || len(scope) < 1 || len(scope) > 96 || !positive(expectedVersion) || (action != "read" && action != "send") {
		return ports.Grant{}, ErrDenied
	}
	if !r.actor(actor, time.Now()) {
		return ports.Grant{}, ErrDenied
	}
	ctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()
	var value row
	var body sql.NullString
	err := tx.QueryRowContext(ctx, `SELECT scope_key,CAST(order_id AS CHAR),CAST(assignment_id AS CHAR),CAST(policy_version AS CHAR),state,
		CASE WHEN OCTET_LENGTH(snapshot_json)<=16384 THEN snapshot_json ELSE NULL END,snapshot_hash
		FROM chat_policy_guards WHERE scope_key=? LOCK IN SHARE MODE`, scope).Scan(&value.scope, &value.orderID, &value.assignmentID, &value.version, &value.state, &body, &value.hash)
	if errors.Is(err, sql.ErrNoRows) {
		return ports.Grant{}, ErrDenied
	}
	if err != nil {
		return ports.Grant{}, ports.ErrUnavailable
	}
	if !body.Valid {
		return ports.Grant{}, ErrDenied
	}
	if !r.actor(actor, time.Now()) {
		return ports.Grant{}, ErrDenied
	}
	value.body = body.String
	return evaluate(value, scope, expectedVersion, actor.ActorID, action)
}

func (r *Reader) actor(a ports.Principal, now time.Time) bool {
	return positive(a.ActorID) && positive(a.SourceID) && a.Kind == "mini" && a.Environment == r.environment &&
		hexVersion.MatchString(a.SessionID) && hexVersion.MatchString(a.SessionVersion) && now.Before(a.ExpiresAt) &&
		len(a.Scopes) == 1 && a.Scopes[0] == "chat:use"
}

func evaluate(value row, scope string, expectedVersion ports.ID, actor ports.ID, action string) (ports.Grant, error) {
	deny := func() (ports.Grant, error) { return ports.Grant{}, ErrDenied }
	if !positive(actor) || !positive(expectedVersion) || value.version != string(expectedVersion) || value.scope != scope || len(value.body) > 16384 || !hexVersion.MatchString(value.hash) {
		return deny()
	}
	digest := sha256.Sum256([]byte(value.body))
	if hex.EncodeToString(digest[:]) != value.hash {
		return deny()
	}
	var s snapshot
	if !object([]byte(value.body), &s, "schema_version", "scope_key", "order_id", "assignment_id", "episode_key", "state", "can_invite", "members") ||
		s.Schema != 1 || !positive(s.OrderID) || !positive(s.AssignmentID) || s.Scope != scope || s.Scope != "order:"+string(s.OrderID)+":assignment:"+string(s.AssignmentID) ||
		value.orderID != string(s.OrderID) || value.assignmentID != string(s.AssignmentID) || value.state != s.State || (s.State != "open" && s.State != "readonly") ||
		!validEpisode(s.Episode, s.AssignmentID) || s.CanInvite || len(s.Members) != 2 {
		return deny()
	}
	seen := map[ports.ID]bool{}
	roles := map[string]bool{}
	var found *member
	for i := range s.Members {
		m := &s.Members[i]
		if !positive(m.ActorID) || seen[m.ActorID] || roles[m.Role] || m.Kind != "mini" || (m.Role != "customer" && m.Role != "worker") || !m.CanRead || (s.State == "readonly" && m.CanSend) {
			return deny()
		}
		seen[m.ActorID] = true
		roles[m.Role] = true
		if m.ActorID == actor {
			found = m
		}
	}
	if found == nil || (action != "read" && action != "send") || (action == "send" && (!found.CanSend || s.State != "open")) {
		return deny()
	}
	return ports.Grant{CanRead: found.CanRead, CanSend: found.CanSend && s.State == "open", ScopeKey: s.Scope, Version: expectedVersion}, nil
}

func positive(id ports.ID) bool { return id.Valid() && id != "0" }

// The episode identity is supplied by PHP. Go does not infer repair eligibility.
func validEpisode(episode string, assignment ports.ID) bool {
	prefix := "assignment:" + string(assignment)
	if episode == prefix+":service" {
		return true
	}
	id, ok := strings.CutPrefix(episode, prefix+":repair:")
	return ok && positive(ports.ID(id))
}

// Exact required keys, including nested members: reject duplicates, case aliases,
// null fields, missing/unknown fields, non-object roots and trailing values.
func object(data []byte, target any, keys ...string) bool {
	decoder := json.NewDecoder(bytes.NewReader(data))
	token, err := decoder.Token()
	if err != nil || token != json.Delim('{') {
		return false
	}
	allowed := map[string]bool{}
	for _, key := range keys {
		allowed[key] = true
	}
	seen := map[string]bool{}
	for decoder.More() {
		token, err = decoder.Token()
		key, ok := token.(string)
		if err != nil || !ok || !allowed[key] || seen[key] {
			return false
		}
		seen[key] = true
		var raw json.RawMessage
		if decoder.Decode(&raw) != nil || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
			return false
		}
	}
	if token, err = decoder.Token(); err != nil || token != json.Delim('}') || len(seen) != len(allowed) {
		return false
	}
	if _, err = decoder.Token(); err != io.EOF {
		return false
	}
	return json.Unmarshal(data, target) == nil
}
