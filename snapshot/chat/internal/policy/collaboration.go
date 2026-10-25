package policy

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"strings"

	"github.com/franz-9527/workers/chat/internal/ports"
)

const CollaborationMaxMembers = 64
const CollaborationMaxSnapshotBytes = 32768

// CollaborationProjection is the PHP-owned S24 wire contract. Database access
// intersects its immutable hash, continuous policy version and current source epoch.
type CollaborationProjection struct {
	Scope, Episode, State, Hash string
	OrderID, Version            ports.ID
 SourceVersion ports.ID
	MemberLimit                 int
	StaffConfigVersion          ports.ID
	OrderContext                *ports.OrderContext
	Members                     []CollaborationMember
}

type CollaborationMember struct {
	ActorID              ports.ID    `json:"actor_id"`
	Kind                 string      `json:"kind"`
	RoleSource           string      `json:"role_source"`
	MembershipGeneration ports.ID    `json:"membership_generation"`
	DisplayRole          string      `json:"display_role"`
	DisplayRoleVersion   ports.ID    `json:"display_role_version"`
	CanRead              bool        `json:"can_read"`
	CanSend              bool        `json:"can_send"`
	InviterActorID       ports.ID    `json:"inviter_actor_id,omitempty"`
	InviteGeneration     ports.ID    `json:"invite_generation,omitempty"`
	AuthorityVersion     ports.ID    `json:"authority_version,omitempty"`
	Grant                ports.Grant `json:"-"`
}

func (m *CollaborationMember) UnmarshalJSON(body []byte) error {
	var header struct {
		Source string `json:"role_source"`
	}
	if json.Unmarshal(body, &header) != nil {
		return ErrDenied
	}
	keys := []string{"actor_id", "kind", "role_source", "membership_generation", "display_role", "display_role_version", "can_read", "can_send"}
	switch header.Source {
	case "invite":
		keys = append(keys, "inviter_actor_id", "invite_generation")
	case "platform_staff":
		keys = append(keys, "authority_version")
	case "merchant_owner", "assigned_worker", "owner_mobile_match":
	default:
		return ErrDenied
	}
	type plain CollaborationMember
	if !object(body, (*plain)(m), keys...) {
		return ErrDenied
	}
	return nil
}

type collaborationSnapshot struct {
 SourceVersion ports.ID `json:"source_version"`
	Schema             int                   `json:"schema_version"`
	Mode               string                `json:"conversation_mode"`
	Scope              string                `json:"scope_key"`
	OrderID            ports.ID              `json:"order_id"`
	AssignmentID       *ports.ID             `json:"assignment_id"`
	Episode            string                `json:"episode_key"`
	State              string                `json:"state"`
	CanInvite          bool                  `json:"can_invite"`
	MemberLimit        int                   `json:"member_limit"`
	Members            []CollaborationMember `json:"members"`
	StaffConfigVersion ports.ID              `json:"staff_config_version,omitempty"`
	OrderContext       *ports.OrderContext   `json:"order_context,omitempty"`
}

func DecodeCollaborationProjection(body []byte, scope string, version ports.ID) (CollaborationProjection, error) {
	deny := func() (CollaborationProjection, error) { return CollaborationProjection{}, ErrDenied }
	if len(body) > CollaborationMaxSnapshotBytes || !positive(version) {
		return deny()
	}
	var header map[string]json.RawMessage
	if json.Unmarshal(body, &header) != nil {
		return deny()
	}
	keys := []string{"schema_version", "source_version", "conversation_mode", "scope_key", "order_id", "assignment_id", "episode_key", "state", "can_invite", "member_limit", "members"}
	if _, ok := header["staff_config_version"]; ok {
		keys = append(keys, "staff_config_version")
	}
	if raw, ok := header["order_context"]; ok {
		var display ports.OrderContext
		if !object(raw, &display, "public_no", "title", "workflow") || !validOrderContext(display) {
			return deny()
		}
		keys = append(keys, "order_context")
	}
	var s collaborationSnapshot
	if !collaborationObject(body, &s, keys) || s.Schema != 3 || !positive(s.SourceVersion) || s.Mode != "collaboration" ||
		!positive(s.OrderID) || s.Scope != scope || s.Scope != "order:"+string(s.OrderID)+":collaboration" ||
		s.AssignmentID != nil || (s.State != "open" && s.State != "readonly") || s.CanInvite ||
		s.MemberLimit < 3 || s.MemberLimit > 50 || len(s.Members) < 1 || len(s.Members) > CollaborationMaxMembers {
		return deny()
	}
	prefix := "order:" + string(s.OrderID) + ":collaboration:"
	if s.Episode != prefix+"service" {
		id, ok := strings.CutPrefix(s.Episode, prefix+"repair:")
		if !ok || !positive(ports.ID(id)) {
			return deny()
		}
	}
	sum := sha256.Sum256(body)
	p := CollaborationProjection{Scope: s.Scope, Episode: s.Episode, State: s.State, Hash: hex.EncodeToString(sum[:]),
		OrderID: s.OrderID, Version: version, SourceVersion:s.SourceVersion, MemberLimit: s.MemberLimit, StaffConfigVersion: s.StaffConfigVersion, OrderContext: s.OrderContext}
	seen := map[ports.ID]bool{}
	roles := map[string]int{}
	for _, m := range s.Members {
		if !positive(m.ActorID) || seen[m.ActorID] || m.Kind != "mini" || !positive(m.MembershipGeneration) ||
			!positive(m.DisplayRoleVersion) || !m.CanRead || (s.State == "readonly" && m.CanSend) ||
			(len(s.Members) == 1 && m.CanSend) {
			return deny()
		}
		seen[m.ActorID] = true
		roles[m.RoleSource]++
		role := ""
		switch m.RoleSource {
		case "merchant_owner":
			if m.DisplayRole != "merchant" {
				return deny()
			}
			role = "customer"
		case "assigned_worker":
			if m.DisplayRole != "worker" {
				return deny()
			}
			role = "worker"
		case "owner_mobile_match":
			if m.DisplayRole != "owner" {
				return deny()
			}
			role = "owner"
		case "platform_staff":
			if m.DisplayRole != "platform" || !positive(m.AuthorityVersion) || !positive(s.StaffConfigVersion) {
				return deny()
			}
			role = "platform_staff"
		case "invite":
			if !validCollaborationDisplayRole(m.DisplayRole) || !positive(m.InviterActorID) ||
				m.InviterActorID == m.ActorID || !positive(m.InviteGeneration) {
				return deny()
			}
			role = "collaborator"
		default:
			return deny()
		}
		// A display/source edit or repair episode never resets a lawful interval.
		// PHP must advance the generation for leave/rejoin or lost authority.
		key := s.Scope + ":mini:" + string(m.ActorID) + ":generation:" + string(m.MembershipGeneration)
		g := ports.Grant{OrderContext: s.OrderContext, MemberRole: role, CanRead: true, Mode:"collaboration", RoleSource:m.RoleSource, DisplayRole:m.DisplayRole, DisplayRoleVersion:m.DisplayRoleVersion, CanSend: m.CanSend && s.State == "open",
			ScopeKey: s.Scope, Version: version, MemberKey: key}
		if m.RoleSource == "platform_staff" {
			g.StaffConfigVersion = s.StaffConfigVersion
			g.StaffUserVersion = m.AuthorityVersion
			g.MemberKey += ":config:" + string(s.StaffConfigVersion) + ":user:" + string(m.AuthorityVersion)
		}
		if len(g.MemberKey) > 240 {
			return deny()
		}
		m.Grant = g
		p.Members = append(p.Members, m)
	}
	if roles["merchant_owner"] != 1 || roles["assigned_worker"] > 1 || roles["owner_mobile_match"] > 1 || roles["platform_staff"] > 8 ||
		(roles["platform_staff"] == 0 && s.StaffConfigVersion != "") {
		return deny()
	}
	return p, nil
}

func validCollaborationDisplayRole(role string) bool {
	switch role {
	case "merchant", "merchant_service", "designer", "owner", "coordinator", "project_manager", "team_worker", "other":
		return true
	}
	return false
}

// Same exact-key contract as object(), with one deliberate nullable field:
// assignment_id must be present and null, never omitted, zero or a current worker.
func collaborationObject(body []byte, target any, keys []string) bool {
	d := json.NewDecoder(bytes.NewReader(body))
	t, err := d.Token()
	if err != nil || t != json.Delim('{') {
		return false
	}
	allowed := map[string]bool{}
	for _, key := range keys {
		allowed[key] = true
	}
	seen := map[string]bool{}
	for d.More() {
		token, err := d.Token()
		key, ok := token.(string)
		if err != nil || !ok || !allowed[key] || seen[key] {
			return false
		}
		seen[key] = true
		var raw json.RawMessage
		if d.Decode(&raw) != nil {
			return false
		}
		isNull := bytes.Equal(bytes.TrimSpace(raw), []byte("null"))
		if (key == "assignment_id") != isNull {
			return false
		}
	}
	if t, err = d.Token(); err != nil || t != json.Delim('}') || len(seen) != len(allowed) {
		return false
	}
	if _, err = d.Token(); err != io.EOF {
		return false
	}
	return json.Unmarshal(body, target) == nil
}
