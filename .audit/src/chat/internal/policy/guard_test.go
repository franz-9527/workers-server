package policy

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

const validSnapshot = `{"schema_version":1,"scope_key":"order:77:assignment:100","order_id":"77","assignment_id":"100","episode_key":"assignment:100:service","state":"open","can_invite":false,"members":[{"actor_id":"1","kind":"mini","role":"customer","can_read":true,"can_send":true},{"actor_id":"18446744073709551615","kind":"mini","role":"worker","can_read":true,"can_send":true}]}`

func fixture(body string) row {
	sum := sha256.Sum256([]byte(body))
	return row{scope: "order:77:assignment:100", orderID: "77", assignmentID: "100", version: "7", state: "open", body: body, hash: hex.EncodeToString(sum[:])}
}

func TestGuardProtocolAndMembership(t *testing.T) {
	r := fixture(validSnapshot)
	for _, id := range []ports.ID{"1", "18446744073709551615"} {
		g, e := evaluate(r, r.scope, "7", id, "send")
		if e != nil || !g.CanRead || !g.CanSend || g.Version != "7" {
			t.Fatal("PHP member lost", g, e)
		}
	}
	for _, id := range []ports.ID{"0", "2", "01", "18446744073709551616"} {
		if _, e := evaluate(r, r.scope, "7", id, "read"); e != ErrDenied {
			t.Fatal("outsider granted", id, e)
		}
	}
	for _, version := range []ports.ID{"6", "8", "0", "07"} {
		if _, e := evaluate(r, r.scope, version, "1", "send"); e != ErrDenied {
			t.Fatal("unapplied/stale version granted", version, e)
		}
	}
	r.body = strings.ReplaceAll(strings.Replace(validSnapshot, `"state":"open"`, `"state":"readonly"`, 1), `"can_send":true`, `"can_send":false`)
	r = fixture(r.body)
	r.state = "readonly"
	grant, e := evaluate(r, r.scope, "7", "1", "read")
	if e != nil || !grant.CanRead || grant.CanSend {
		t.Fatal("closed history", grant, e)
	}
	if _, e = evaluate(r, r.scope, "7", "1", "send"); e != ErrDenied {
		t.Fatal("closed send allowed")
	}
}

func TestRepairEpisodeIdentity(t *testing.T) {
	for _, id := range []string{"1", "18446744073709551615"} {
		r := fixture(strings.Replace(validSnapshot, ":service", ":repair:"+id, 1))
		if g, e := evaluate(r, r.scope, "7", "1", "send"); e != nil || !g.CanSend {
			t.Fatal("explicit PHP repair episode", e)
		}
	}
	for _, id := range []string{"0", "01", "", "18446744073709551616", "1:extra"} {
		r := fixture(strings.Replace(validSnapshot, ":service", ":repair:"+id, 1))
		if _, e := evaluate(r, r.scope, "7", "1", "send"); e != ErrDenied {
			t.Fatal("invalid repair generation granted", id, e)
		}
	}
}

func TestMalformedAuthorityNeverGrants(t *testing.T) {
	for name, body := range map[string]string{
		"duplicate root":   strings.Replace(validSnapshot, `"schema_version":1`, `"schema_version":1,"schema_version":1`, 1),
		"duplicate member": strings.Replace(validSnapshot, `"actor_id":"1"`, `"actor_id":"1","actor_id":"2"`, 1),
		"case alias":       strings.Replace(validSnapshot, `"can_send"`, `"Can_Send"`, 1),
		"extra":            strings.Replace(validSnapshot, `"can_invite":false`, `"can_invite":false,"extra":1`, 1),
		"missing":          strings.Replace(validSnapshot, `"can_invite":false,`, "", 1),
		"null":             strings.Replace(validSnapshot, `"can_invite":false`, `"can_invite":null`, 1),
		"numeric ID":       strings.Replace(validSnapshot, `"order_id":"77"`, `"order_id":77`, 1),
		"scope mismatch":   strings.Replace(validSnapshot, `"order_id":"77"`, `"order_id":"78"`, 1),
		"unknown schema":   strings.Replace(validSnapshot, `"schema_version":1`, `"schema_version":2`, 1),
		"unknown episode":  strings.Replace(validSnapshot, `:service`, `:unapproved`, 1),
		"unknown state":    strings.Replace(validSnapshot, `"state":"open"`, `"state":"unknown"`, 1),
		"invitation":       strings.Replace(validSnapshot, `"can_invite":false`, `"can_invite":true`, 1),
		"duplicate actor":  strings.Replace(validSnapshot, `"18446744073709551615"`, `"1"`, 1),
		"duplicate role":   strings.Replace(validSnapshot, `"role":"worker"`, `"role":"customer"`, 1),
		"admin peer":       strings.Replace(validSnapshot, `"kind":"mini"`, `"kind":"admin"`, 1),
		"trailing":         validSnapshot + `{}`,
		"array":            "[" + validSnapshot + "]",
		"oversized":        strings.Repeat(" ", 16385) + validSnapshot,
	} {
		t.Run(name, func(t *testing.T) {
			r := fixture(body)
			if _, e := evaluate(r, r.scope, "7", "1", "send"); e != ErrDenied {
				t.Fatal("bad fact granted", e)
			}
		})
	}
	for _, change := range []func(*row){
		func(r *row) { r.hash = strings.Repeat("a", 64) }, func(r *row) { r.orderID = "78" }, func(r *row) { r.assignmentID = "101" }, func(r *row) { r.state = "readonly" },
	} {
		r := fixture(validSnapshot)
		change(&r)
		if _, e := evaluate(r, r.scope, "7", "1", "read"); e != ErrDenied {
			t.Fatal("row/snapshot mismatch granted", e)
		}
	}
}

func principal() ports.Principal {
	return ports.Principal{ActorID: "1", Kind: "mini", SourceID: "1", Environment: "test", SessionID: strings.Repeat("a", 64), SessionVersion: strings.Repeat("b", 64), ExpiresAt: time.Now().Add(time.Minute), Scopes: []string{"chat:use"}}
}

func TestReaderIdentityBoundary(t *testing.T) {
	r, e := NewReader("test", time.Second)
	if e != nil {
		t.Fatal(e)
	}
	if !r.actor(principal(), time.Now()) {
		t.Fatal("valid principal")
	}
	for _, change := range []func(*ports.Principal){
		func(p *ports.Principal) { p.Kind = "admin" }, func(p *ports.Principal) { p.Environment = "production" }, func(p *ports.Principal) { p.SourceID = "0" },
		func(p *ports.Principal) { p.ExpiresAt = time.Now().Add(-time.Second) }, func(p *ports.Principal) { p.Scopes = []string{"chat:audit"} },
		func(p *ports.Principal) { p.SessionVersion = "" }, func(p *ports.Principal) { p.Scopes = append(p.Scopes, "chat:moderate") },
	} {
		p := principal()
		change(&p)
		if r.actor(p, time.Now()) {
			t.Fatal("invalid identity accepted")
		}
	}
	if _, e = r.AuthorizeLocked(context.Background(), nil, "order:77:assignment:100", "1", principal(), "send"); e != ErrDenied {
		t.Fatal("must own caller transaction")
	}
	if _, e = NewReader("test", 6*time.Second); e != ports.ErrInvalid {
		t.Fatal("unbounded timeout")
	}
}

// Missing authority generations never become valid platform permissions.
func TestPlatformMissingAuthorityRejected(t *testing.T) {
	for _, body := range []string{
		strings.Replace(validSnapshot, `"schema_version":1`, `"schema_version":2`, 1),
		strings.Replace(strings.Replace(validSnapshot, `"schema_version":1`, `"schema_version":2`, 1), `"role":"customer"`, `"role":"platform_staff"`, 1),
		strings.Replace(validSnapshot, `"role":"customer"`, `"role":"platform_staff"`, 1),
	} {
		r := fixture(body)
		for _, action := range []string{"read", "send"} {
			if _, e := evaluate(r, r.scope, "7", "1", action); e != ErrDenied {
				t.Fatal("unintegrated platform authority granted", e)
			}
		}
	}
}

func TestEventEnvelopeAndProjection(t *testing.T) {
	body := `{"schema_version":1,"policy_version":"7","snapshot":` + validSnapshot + `}`
	p, err := DecodeEvent([]byte(body), "order:77:assignment:100", "7")
	if err != nil || len(p.Members) != 2 || p.Version != "7" || p.Members[0].Grant.MemberKey != "assignment:100:service:mini:1:customer" {
		t.Fatal("projection contract", p, err)
	}
	for _, bad := range []string{
		strings.Replace(body, `"policy_version":"7"`, `"policy_version":"8"`, 1),
		strings.Replace(body, `"schema_version":1`, `"schema_version":2`, 1),
		strings.Replace(body, `"policy_version":"7"`, `"policy_version":"7","policy_version":"7"`, 1),
		strings.Replace(body, `"snapshot":`, `"Snapshot":`, 1), body + `{}`, `null`, strings.Repeat(" ", 32769),
	} {
		if _, err = DecodeEvent([]byte(bad), "order:77:assignment:100", "7"); err != ErrDenied {
			t.Fatal("malformed event accepted", err)
		}
	}
}

func TestPlatformStaffProtocolAndGenerations(t *testing.T) {
	base := strings.Replace(validSnapshot, `"schema_version":1`, `"schema_version":2,"staff_config_version":"3"`, 1)
	base = strings.Replace(base, `"role":"customer"`, `"role":"platform_staff","authority_version":"5"`, 1)
	r := fixture(base)
	first, e := evaluate(r, r.scope, "7", "1", "send")
	if e != nil || first.StaffConfigVersion != "3" || first.StaffUserVersion != "5" || first.MemberKey == "" {
		t.Fatal("staff generation missing", e)
	}
	for _, replacement := range []string{
		strings.Replace(base, `"staff_config_version":"3"`, `"staff_config_version":"4"`, 1),
		strings.Replace(base, `"authority_version":"5"`, `"authority_version":"6"`, 1),
	} {
		next := fixture(replacement)
		g, e := evaluate(next, next.scope, "7", "1", "send")
		if e != nil || g.MemberKey == first.MemberKey {
			t.Fatal("returning staff reused its old interval", e)
		}
	}
	for _, body := range []string{
		strings.Replace(base, `"staff_config_version":"3"`, `"staff_config_version":"0"`, 1),
		strings.Replace(base, `"authority_version":"5"`, `"authority_version":"0"`, 1),
		strings.Replace(base, `"authority_version":"5"`, `"authority_version":5`, 1),
		strings.Replace(base, `"authority_version":"5",`, "", 1),
		strings.Replace(validSnapshot, `"role":"customer"`, `"role":"customer","authority_version":""`, 1),
	} {
		bad := fixture(body)
		if _, e := evaluate(bad, bad.scope, "7", "1", "send"); e != ErrDenied {
			t.Fatal("malformed staff authority accepted", e)
		}
	}
}

func FuzzGuardSnapshot(f *testing.F) {
	f.Add(validSnapshot)
	f.Add(`null`)
	f.Add(`{"members":[{}]}`)
	f.Fuzz(func(t *testing.T, body string) {
		r := fixture(body)
		g, e := evaluate(r, r.scope, "7", "1", "send")
		if e == nil && (!g.CanRead || !g.CanSend || g.ScopeKey != r.scope || g.Version != "7") {
			t.Fatal("inconsistent grant")
		}
	})
}

func TestS12OrderContextIsOptionalBoundedAndNotAuthority(t *testing.T) {
 display:=`"order_context":{"public_no":"ORDER-77","title":"墙布施工","workflow":"construction"},`
 body:=strings.Replace(validSnapshot,`"members":`,display+`"members":`,1)
 r:=fixture(body);g,e:=evaluate(r,r.scope,"7","1","read")
 if e!=nil||g.OrderContext==nil||g.OrderContext.PublicNo!="ORDER-77"||g.MemberRole!="customer" {t.Fatal(g,e)}
 if _,e=evaluate(r,r.scope,"7","2","read");e!=ErrDenied {t.Fatal("display metadata authorized outsider",e)}
 for _,bad:=range []string{
  strings.Replace(body,`"ORDER-77"`,`"../77"`,1),
  strings.Replace(body,`"construction"`,`"other"`,1),
  strings.Replace(body,`"title":"墙布施工"`,`"title":null`,1),
  strings.Replace(body,`"title":"墙布施工"`,`"title":"x","title":"y"`,1),
  strings.Replace(body,`"title":"墙布施工"`,`"title":"`+strings.Repeat("字",121)+`"`,1),
 } {v:=fixture(bad);if _,e=evaluate(v,v.scope,"7","1","read");e!=ErrDenied{t.Fatal("bad display accepted",bad,e)}}
 legacy:=fixture(validSnapshot);g,e=evaluate(legacy,legacy.scope,"7","1","read")
 if e!=nil||g.OrderContext!=nil{t.Fatal("legacy authorization changed",g,e)}
}
