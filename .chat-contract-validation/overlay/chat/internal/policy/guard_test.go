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
