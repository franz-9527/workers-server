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
	"unicode"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrDenied = errors.New("CHAT_POLICY_DENIED")
var hexVersion = regexp.MustCompile(`^[a-f0-9]{64}$`)

type member struct {
	ActorID          ports.ID `json:"actor_id"`
	Kind             string   `json:"kind"`
	Role             string   `json:"role"`
	CanRead          bool     `json:"can_read"`
	CanSend          bool     `json:"can_send"`
	AuthorityVersion ports.ID `json:"authority_version,omitempty"`
}

func (m *member) UnmarshalJSON(data []byte) error {
	type plain member
	if object(data, (*plain)(m), "actor_id", "kind", "role", "can_read", "can_send") {
		return nil
	}
	if object(data, (*plain)(m), "actor_id", "kind", "role", "can_read", "can_send", "authority_version") && m.Role == "platform_staff" {
		return nil
	}
	return ErrDenied
}

type snapshot struct {
	OrderContext *ports.OrderContext `json:"order_context,omitempty"`
	Schema             int      `json:"schema_version"`
	Scope              string   `json:"scope_key"`
	OrderID            ports.ID `json:"order_id"`
	AssignmentID       ports.ID `json:"assignment_id"`
	Episode            string   `json:"episode_key"`
	State              string   `json:"state"`
	CanInvite          bool     `json:"can_invite"`
	Members            []member `json:"members"`
	StaffConfigVersion ports.ID `json:"staff_config_version,omitempty"`
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
	p, err := r.CurrentLocked(ctx, tx, scope)
	if err != nil || p.Version != expectedVersion {
		if err != nil {
			return ports.Grant{}, err
		}
		return ports.Grant{}, ErrDenied
	}
	if err = r.CheckEpochsLocked(ctx, tx, p, actor.ActorID, action); err != nil {
		return ports.Grant{}, err
	}
	var grant ports.Grant
	for _, m := range p.Members {
		if m.ActorID == actor.ActorID {
			grant = m.Grant
		}
	}
	if !grant.CanRead || (action == "send" && !grant.CanSend) || !r.actor(actor, time.Now()) {
		return ports.Grant{}, ErrDenied
	}
	return grant, nil
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
	keys := []string{"schema_version", "scope_key", "order_id", "assignment_id", "episode_key", "state", "can_invite", "members"}
	var header struct {
		Schema int `json:"schema_version"`
		Order json.RawMessage `json:"order_context"`
	}
	if json.Unmarshal([]byte(value.body), &header) != nil {
		return deny()
	}
	if len(header.Order) > 0 {
		var display ports.OrderContext
		if !object(header.Order, &display, "public_no", "title", "workflow") || !validOrderContext(display) { return deny() }
		keys = append(keys, "order_context")
	}
	if header.Schema == 2 {
		keys = append(keys, "staff_config_version")
	}
	if !object([]byte(value.body), &s, keys...) ||
		(s.Schema != 1 && s.Schema != 2) || (s.Schema == 2 && !positive(s.StaffConfigVersion)) || !positive(s.OrderID) || !positive(s.AssignmentID) || s.Scope != scope || s.Scope != "order:"+string(s.OrderID)+":assignment:"+string(s.AssignmentID) ||
		value.orderID != string(s.OrderID) || value.assignmentID != string(s.AssignmentID) || value.state != s.State || (s.State != "open" && s.State != "readonly") ||
		!validEpisode(s.Episode, s.AssignmentID) || s.CanInvite || len(s.Members) < 1 || len(s.Members) > 10 || (s.Schema == 1 && len(s.Members) != 2) {
		return deny()
	}
	seen := map[ports.ID]bool{}
	roles := map[string]bool{}
	var found *member
	for i := range s.Members {
		m := &s.Members[i]
		staff := m.Role == "platform_staff"
		if !positive(m.ActorID) || seen[m.ActorID] || (roles[m.Role] && !staff) || m.Kind != "mini" || (m.Role != "customer" && m.Role != "worker" && !staff) || !m.CanRead || (s.State == "readonly" && m.CanSend) ||
			(staff && (s.Schema != 2 || !positive(m.AuthorityVersion))) || (!staff && m.AuthorityVersion != "") || (len(s.Members) == 1 && m.CanSend) {
			return deny()
		}
		seen[m.ActorID] = true
		roles[m.Role] = true
		if m.ActorID == actor {
			found = m
		}
	}
	if !roles["worker"] || (s.Schema == 1 && !roles["customer"]) {
		return deny()
	}
	if found == nil || (action != "read" && action != "send") || (action == "send" && (!found.CanSend || s.State != "open")) {
		return deny()
	}
	grant := ports.Grant{OrderContext: s.OrderContext, MemberRole: found.Role, CanRead: found.CanRead, CanSend: found.CanSend && s.State == "open", ScopeKey: s.Scope, Version: expectedVersion,
		MemberKey: s.Episode + ":mini:" + string(actor) + ":" + found.Role}
	if found.Role == "platform_staff" {
		grant.StaffConfigVersion = s.StaffConfigVersion
		grant.StaffUserVersion = found.AuthorityVersion
		grant.MemberKey += ":config:" + string(s.StaffConfigVersion) + ":user:" + string(found.AuthorityVersion)
	}
	return grant, nil
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

// Display metadata is optional for pre-S12 guards and cannot change authority.
func validOrderContext(c ports.OrderContext) bool {
 if len(c.PublicNo) < 1 || len(c.PublicNo) > 80 || (c.Workflow != "construction" && c.Workflow != "measurement") || !utf8.ValidString(c.Title) || utf8.RuneCountInString(c.Title)>120 { return false }
 for _, r := range c.PublicNo { if !(r>='0'&&r<='9'||r>='A'&&r<='Z'||r>='a'&&r<='z'||r=='-'||r=='_') { return false } }
 for _, r := range c.Title { if unicode.IsControl(r) { return false } }
 return true
}
