package policy

import (
	"encoding/json"
	"os"
	"strconv"
	"strings"
	"testing"

	"github.com/franz-9527/workers/chat/internal/ports"
)

const collaborationFixture = `{"schema_version":3,"source_version":"1","conversation_mode":"collaboration","scope_key":"order:77:collaboration","order_id":"77","assignment_id":null,"episode_key":"order:77:collaboration:service","state":"open","can_invite":false,"member_limit":10,"members":[{"actor_id":"1","kind":"mini","role_source":"merchant_owner","membership_generation":"1","account_version":"1","display_role":"merchant","display_role_version":"1","can_read":true,"can_send":true},{"actor_id":"2","kind":"mini","role_source":"assigned_worker","membership_generation":"1","account_version":"1","display_role":"worker","display_role_version":"1","can_read":true,"can_send":true},{"actor_id":"4","kind":"mini","role_source":"invite","membership_generation":"1","account_version":"1","display_role":"designer","display_role_version":"1","can_read":true,"can_send":true,"inviter_actor_id":"1","invite_generation":"1"}]}`

func collaborationDecode(body string) (CollaborationProjection, error) {
	return DecodeCollaborationProjection([]byte(body), "order:77:collaboration", "7")
}
func collaborationMap(t *testing.T) map[string]any {
	t.Helper()
	var m map[string]any
	if err := json.Unmarshal([]byte(collaborationFixture), &m); err != nil {
		t.Fatal(err)
	}
	return m
}
func collaborationMarshal(t *testing.T, m map[string]any) string {
	t.Helper()
	b, err := json.Marshal(m)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}
func collaborationActor(t *testing.T, p CollaborationProjection, id ports.ID) CollaborationMember {
	t.Helper()
	for _, m := range p.Members {
		if m.ActorID == id {
			return m
		}
	}
	t.Fatalf("missing actor %s", id)
	return CollaborationMember{}
}
func TestCollaborationAuthorityAndIdentity(t *testing.T) {
	p, err := collaborationDecode(collaborationFixture)
	if err != nil || p.Scope != "order:77:collaboration" || p.MemberLimit != 10 || len(p.Members) != 3 {
		t.Fatal(p, err)
	}
	peer := collaborationActor(t, p, "4")
	if peer.Grant.MemberRole != "collaborator" || peer.RoleSource != "invite" || peer.Grant.MemberKey != "order:77:collaboration:mini:4:generation:1" {
		t.Fatal("self-selected role granted system authority", peer)
	}
	for _, role := range []string{"merchant", "merchant_service", "designer", "owner", "coordinator", "project_manager", "team_worker", "other"} {
		m := collaborationMap(t)
		invite := m["members"].([]any)[2].(map[string]any)
		invite["display_role"] = role
		invite["display_role_version"] = "2"
		next, e := collaborationDecode(collaborationMarshal(t, m))
		if e != nil {
			t.Fatal(role, e)
		}
		current := collaborationActor(t, next, "4")
		if current.Grant.MemberKey != peer.Grant.MemberKey || current.Grant.MemberRole != "collaborator" {
			t.Fatal("display edit changed authority/history", role, current)
		}
	}
	for _, change := range []map[string]any{
		{"invite_generation": "2"},
		{"inviter_actor_id": "999"},
	} {
		m := collaborationMap(t)
		invite := m["members"].([]any)[2].(map[string]any)
		for key, value := range change {
			invite[key] = value
		}
		next, e := collaborationDecode(collaborationMarshal(t, m))
		if e != nil || collaborationActor(t, next, "4").Grant.MemberKey != peer.Grant.MemberKey {
			t.Fatal("invite provenance changed continuous interval", change, e)
		}
	}
	m := collaborationMap(t)
	invite := m["members"].([]any)[2].(map[string]any)
	invite["membership_generation"] = "2"
	next, e := collaborationDecode(collaborationMarshal(t, m))
	if e != nil || collaborationActor(t, next, "4").Grant.MemberKey == peer.Grant.MemberKey {
		t.Fatal("rejoin reused authority generation", e)
	}
	m = collaborationMap(t)
	m["episode_key"] = "order:77:collaboration:repair:900"
	next, e = collaborationDecode(collaborationMarshal(t, m))
	if e != nil || collaborationActor(t, next, "4").Grant.MemberKey != peer.Grant.MemberKey {
		t.Fatal("repair reset independent member history", e)
	}
	m = collaborationMap(t)
	worker := m["members"].([]any)[1].(map[string]any)
	worker["actor_id"] = "5"
	worker["membership_generation"] = "2"
	next, e = collaborationDecode(collaborationMarshal(t, m))
	if e != nil || next.Scope != p.Scope || collaborationActor(t, next, "1").Grant.MemberKey != p.Members[0].Grant.MemberKey {
		t.Fatal("reassignment replaced group or merchant interval", e)
	}
	for _, member := range next.Members {
		if member.ActorID == "2" {
			t.Fatal("old worker retained")
		}
	}
}

func TestCollaborationMalformedFactsFailClosed(t *testing.T) {
	for key, value := range map[string]any{
		"schema_version": 2, "conversation_mode": "group", "scope_key": "order:78:collaboration", "order_id": "78",
		"assignment_id": "100", "state": "unknown", "can_invite": true, "member_limit": 51,
		"episode_key": "order:77:collaboration:repair:0", "staff_config_version": "1",
	} {
		t.Run(key, func(t *testing.T) {
			m := collaborationMap(t)
			m[key] = value
			if _, err := collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
				t.Fatal(key, err)
			}
		})
	}
	for _, field := range []string{"assignment_id", "members", "member_limit", "conversation_mode"} {
		m := collaborationMap(t)
		delete(m, field)
		if _, err := collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
			t.Fatal("omitted", field, err)
		}
	}
	for _, body := range []string{
		strings.Replace(collaborationFixture, `"schema_version":3`, `"schema_version":3,"schema_version":3`, 1),
		strings.Replace(collaborationFixture, `"assignment_id":null`, `"assignment_id":null,"assignment_id":null`, 1),
		strings.Replace(collaborationFixture, `"display_role":"designer"`, `"display_role":"designer","display_role":"owner"`, 1),
		strings.Replace(collaborationFixture, `"order_id":"77"`, `"order_id":77`, 1),
		strings.Replace(collaborationFixture, `"member_limit":10`, `"member_limit":10.0`, 1),
		strings.Replace(collaborationFixture, `"member_limit":10`, `"member_limit":3e0`, 1),
		strings.Replace(collaborationFixture, `"can_invite":false`, `"can_invite":null`, 1),
		strings.Replace(collaborationFixture, `"can_read":true`, `"Can_Read":true`, 1),
		strings.Replace(collaborationFixture, `"can_read":true`, `"can_read":null`, 1),
		strings.Replace(collaborationFixture, `"actor_id":"4"`, `"actor_id":"4","phone":"13800138000"`, 1),
		strings.Replace(collaborationFixture, `"assignment_id":null`, `"assignment_id":"0"`, 1),
		strings.Replace(collaborationFixture, `"assignment_id":null`, `"assignment_id":0`, 1),
		"[" + collaborationFixture + "]", collaborationFixture + "{}", "null",
		strings.Repeat(" ", CollaborationMaxSnapshotBytes+1) + collaborationFixture,
	} {
		if _, err := collaborationDecode(body); err != ErrDenied {
			t.Fatal("malformed accepted", body, err)
		}
	}
	for key, value := range map[string]any{
		"actor_id": "1", "kind": "admin", "role_source": "merchant_owner", "membership_generation": "0", "account_version": 0,
		"display_role_version": 1, "display_role": "worker", "inviter_actor_id": "4",
		"invite_generation": "01", "authority_version": "1", "extra": "forged",
	} {
		m := collaborationMap(t)
		m["members"].([]any)[2].(map[string]any)[key] = value
		if _, err := collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
			t.Fatal("bad invited field", key, err)
		}
	}
	for _, version := range []ports.ID{"0", "01", "18446744073709551616"} {
		if _, err := DecodeCollaborationProjection([]byte(collaborationFixture), "order:77:collaboration", version); err != ErrDenied {
			t.Fatal("bad policy version", version, err)
		}
	}
	if _, err := DecodeCollaborationProjection([]byte(collaborationFixture), "order:78:collaboration", "7"); err != ErrDenied {
		t.Fatal("wrong scope", err)
	}
}

func TestCollaborationCapacityAndReadonly(t *testing.T) {
	m := collaborationMap(t)
	members := m["members"].([]any)
	// Lowering to 3 must retain 4 current peers, and must not silently grant
	// an extra invitation. Admission remains a PHP transaction responsibility.
	extra := map[string]any{}
	for key, value := range members[2].(map[string]any) {
		extra[key] = value
	}
	extra["actor_id"] = "5"
	m["members"] = append(members, extra)
	m["member_limit"] = 3
	if p, err := collaborationDecode(collaborationMarshal(t, m)); err != nil || len(p.Members) != 4 {
		t.Fatal("downsize removed existing member", p, err)
	}
	m["state"] = "readonly"
	for _, raw := range m["members"].([]any) {
		raw.(map[string]any)["can_send"] = false
	}
	p, err := collaborationDecode(collaborationMarshal(t, m))
	if err != nil {
		t.Fatal(err)
	}
	for _, member := range p.Members {
		if member.Grant.CanSend {
			t.Fatal("readonly can send")
		}
	}
	m["members"].([]any)[2].(map[string]any)["can_send"] = true
	if _, err = collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
		t.Fatal("closed grant accepted", err)
	}
	m = collaborationMap(t)
	m["members"] = m["members"].([]any)[:1]
	m["members"].([]any)[0].(map[string]any)["can_send"] = false
	if _, err = collaborationDecode(collaborationMarshal(t, m)); err != nil {
		t.Fatal("merchant-only current roster rejected", err)
	}
}

func TestCollaborationStaffEpochs(t *testing.T) {
	m := collaborationMap(t)
	staff := map[string]any{"actor_id": "8", "kind": "mini", "role_source": "platform_staff",
		"membership_generation": "1", "account_version": "1", "display_role": "platform", "display_role_version": "1",
		"can_read": true, "can_send": true, "authority_version": "5"}
	m["members"] = append(m["members"].([]any), staff)
	m["staff_config_version"] = "3"
	p, err := collaborationDecode(collaborationMarshal(t, m))
	if err != nil {
		t.Fatal(err)
	}
	first := collaborationActor(t, p, "8").Grant
	if first.StaffConfigVersion != "3" || first.StaffUserVersion != "5" {
		t.Fatal(first)
	}
	for _, change := range []string{"user", "config"} {
		if change == "user" {
			staff["authority_version"] = "6"
		} else {
			m["staff_config_version"] = "4"
		}
		p, err = collaborationDecode(collaborationMarshal(t, m))
		if err != nil || collaborationActor(t, p, "8").Grant.MemberKey == first.MemberKey {
			t.Fatal("staff epoch reused interval", change, err)
		}
	}
	delete(staff, "authority_version")
	if _, err = collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
		t.Fatal("missing staff epoch", err)
	}
}

// New schema is intentionally unreachable through existing database/read/send
// paths until S24.a2 installs nullable bindings and the PHP-only authority writer.
func TestCollaborationIntegratedProjectionKeepsAssignmentBoundary(t *testing.T) {
	p, err := DecodeProjection([]byte(collaborationFixture), "order:77:collaboration", "7")
	if err != nil || p.Mode != "collaboration" || p.AssignmentID != "" || p.SourceVersion != "1" {
		t.Fatal("collaboration projection", p, err)
	}
	event := `{"schema_version":1,"policy_version":"7","snapshot":` + collaborationFixture + "}"
	if _, err := DecodeEvent([]byte(event), "order:77:collaboration", "7"); err != nil {
		t.Fatal(err)
	}
	if _, err := DecodeProjection([]byte(validSnapshot), "order:77:assignment:100", "7"); err != nil {
		t.Fatal("assignment regression", err)
	}
}

func TestCollaborationPHPWireFixtures(t *testing.T) {
	path := os.Getenv("CHAT_S24_PHP_FIXTURES")
	if path == "" {
		t.Skip("run PHP producer with --emit-fixtures for cross-language contract")
	}
	data, err := os.ReadFile(path)
	if err != nil || len(data) > 1024*1024 {
		t.Fatal("bounded PHP fixture file", err)
	}
	var fixtures []struct {
		Name    string   `json:"name"`
		Body    string   `json:"body"`
		Scope   string   `json:"scope"`
		Version ports.ID `json:"version"`
	}
	if json.Unmarshal(data, &fixtures) != nil || len(fixtures) != 16 {
		t.Fatalf("missing PHP scenarios: %d", len(fixtures))
	}
	var baseline CollaborationProjection
	for _, fixture := range fixtures {
		p, err := DecodeCollaborationProjection([]byte(fixture.Body), fixture.Scope, fixture.Version)
		if err != nil {
			t.Fatal(fixture.Name, err)
		}
		if fixture.Name == "four_party" {
			baseline = p
		}
		if strings.HasPrefix(fixture.Name, "display_") {
			if collaborationActor(t, p, "4").Grant.MemberKey != collaborationActor(t, baseline, "4").Grant.MemberKey ||
				collaborationActor(t, p, "4").Grant.MemberRole != "collaborator" {
				t.Fatal("PHP display edits changed authority", fixture.Name)
			}
		}
		if fixture.Name == "reassigned" || fixture.Name == "repair" {
			if p.Scope != baseline.Scope || collaborationActor(t, p, "1").Grant.MemberKey != collaborationActor(t, baseline, "1").Grant.MemberKey {
				t.Fatal("PHP reassignment/repair lost group continuity", fixture.Name)
			}
		}
		if fixture.Name == "rejoined" && collaborationActor(t, p, "4").Grant.MemberKey == collaborationActor(t, baseline, "4").Grant.MemberKey {
			t.Fatal("PHP rejoin reused old interval")
		}
	}
}

func FuzzCollaborationSnapshot(f *testing.F) {
	f.Add(collaborationFixture)
	f.Add("null")
	f.Fuzz(func(t *testing.T, body string) {
		p, err := collaborationDecode(body)
		if err == nil {
			if p.Scope != "order:77:collaboration" || p.Version != "7" || len(p.Members) == 0 || len(p.Members) > CollaborationMaxMembers {
				t.Fatal("invalid accepted projection")
			}
			for _, m := range p.Members {
				if !m.Grant.CanRead || len(m.Grant.MemberKey) > 240 || (p.State == "readonly" && m.Grant.CanSend) {
					t.Fatal("invalid grant", m)
				}
			}
		}
	})
}

func TestCollaborationAbsoluteBoundsAndProvenance(t *testing.T) {
	m := collaborationMap(t)
	members := m["members"].([]any)
	template := members[2].(map[string]any)
	for i := 5; len(members) < CollaborationMaxMembers; i++ {
		next := map[string]any{}
		for key, value := range template {
			next[key] = value
		}
		next["actor_id"] = strconv.Itoa(i)
		members = append(members, next)
	}
	m["members"] = members
	m["member_limit"] = 50
	if _, err := collaborationDecode(collaborationMarshal(t, m)); err != nil {
		t.Fatal("bounded system reserve rejected", err)
	}
	extra := map[string]any{}
	for key, value := range template {
		extra[key] = value
	}
	extra["actor_id"] = "1000"
	m["members"] = append(members, extra)
	if _, err := collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
		t.Fatal("unbounded roster accepted", err)
	}
	for _, limit := range []int{0, 2, 51} {
		m = collaborationMap(t)
		m["member_limit"] = limit
		if _, err := collaborationDecode(collaborationMarshal(t, m)); err != ErrDenied {
			t.Fatal("bad member limit", limit, err)
		}
	}
	m = collaborationMap(t)
	peer := m["members"].([]any)[2].(map[string]any)
	// A departed inviter may leave independent designer/coordinator members.
	// Their provenance is retained without granting their inviter any rights.
	peer["inviter_actor_id"] = "999"
	if _, err := collaborationDecode(collaborationMarshal(t, m)); err != nil {
		t.Fatal("departed inviter invalidated independent member", err)
	}
}
