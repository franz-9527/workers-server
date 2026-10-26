package moderation

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func credential(token string) Credential {
	now := time.Now().Unix()
	v := Credential{Schema: 1, Environment: "test", AppID: "wx_s07_fixture", Token: token, IssuedAt: now, ExpiresAt: now + 3600}
	v.Generation = hash(v.Environment + "\n" + v.AppID + "\n" + v.Token)
	return v
}
func putCredential(t *testing.T, path string, v Credential) {
	t.Helper()
	body, _ := json.Marshal(v)
	if e := os.WriteFile(path, body, 0640); e != nil {
		t.Fatal(e)
	}
}
func TestCredentialBoundariesAndFallback(t *testing.T) {
	root := t.TempDir()
	path := filepath.Join(root, "credential.json")
	v := credential("s07_fixture_token_one")
	putCredential(t, path, v)
	c, e := NewCredentials(path, "test", v.AppID)
	if e != nil {
		t.Fatal(e)
	}
	if got, e := c.Get(); e != nil || got.Token != v.Token {
		t.Fatal("valid export refused", e)
	}
	os.Remove(path)
	if _, e = c.Get(); e != nil {
		t.Fatal("still-valid cached credential could not bridge outage")
	}
	putCredential(t, path, v)
	os.Chmod(path, 0644)
	if _, e = c.Get(); e == nil {
		t.Fatal("insecure export bypassed via cache")
	}
	os.Chmod(path, 0640)
	wrong := v
	wrong.Environment = "production"
	putCredential(t, path, wrong)
	if _, e = c.Get(); e == nil {
		t.Fatal("cross-environment export")
	}
	putCredential(t, path, v)
	c.Reject(v.Generation)
	if _, e = c.Get(); e == nil {
		t.Fatal("rejected generation reused")
	}
	next := credential("s07_fixture_token_two")
	putCredential(t, path, next)
	if _, e = c.Get(); e != nil {
		t.Fatal("rotation failed", e)
	}
	c.Reject(next.Generation)
	putCredential(t, path, v)
	if _, e = c.Get(); e == nil {
		t.Fatal("earlier rejected generation resurrected")
	}
	c.cached = credential("s07_fixture_old_cache")
	c.cached.ExpiresAt = time.Now().Unix() - 1
	os.Remove(path)
	if _, e = c.Get(); e == nil {
		t.Fatal("expired fallback")
	}
	putCredential(t, path, v)
	body, _ := json.Marshal(v)
	os.WriteFile(path, []byte(strings.Replace(string(body), `"env":`, `"env":"test","env":`, 1)), 0640)
	if _, e = c.Get(); e == nil {
		t.Fatal("duplicate envelope key")
	}
}

type httpFunc func(*http.Request) (*http.Response, error)

func (f httpFunc) Do(r *http.Request) (*http.Response, error) { return f(r) }
func TestOfficialResponseContract(t *testing.T) {
	cases := []struct {
		name, body, decision string
		status               int
		unavailable          bool
	}{
		{"pass", `{"errcode":0,"trace_id":"fixture-trace","result":{"suggest":"pass","label":100},"detail":[]}`, "passed", 200, false},
		{"risky", `{"errcode":0,"result":{"suggest":"risky","label":200}}`, "blocked", 200, false},
		{"review", `{"errcode":0,"result":{"suggest":"review"}}`, "review", 200, false},
		{"blocked_code", `{"errcode":87014}`, "blocked", 200, false},
		{"empty", `{}`, "", 200, true},
		{"missing_result", `{"errcode":0}`, "", 200, true},
		{"missing_code", `{"result":{"suggest":"pass"}}`, "", 200, true},
		{"null", `{"errcode":0,"result":null}`, "", 200, true},
		{"unknown", `{"errcode":0,"result":{"suggest":"unknown"}}`, "", 200, true},
		{"duplicate", `{"errcode":0,"errcode":0,"result":{"suggest":"pass"}}`, "", 200, true},
		{"case_alias", `{"ERRCODE":0,"result":{"suggest":"pass"}}`, "", 200, true},
		{"quota", `{"errcode":45009,"errmsg":"sensitive-fixture"}`, "", 200, true},
		{"http_error", `{"errcode":0,"result":{"suggest":"pass"}}`, "", 503, true},
		{"oversized", strings.Repeat("x", (32<<10)+1), "", 200, true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			s := &Service{client: httpFunc(func(r *http.Request) (*http.Response, error) {
				if r.URL.Host != "api.weixin.qq.com" || r.URL.Path != "/wxa/msg_sec_check" || r.URL.Scheme != "https" || r.Method != "POST" || r.URL.Query().Get("access_token") != "s07_fixture_token_one" {
					t.Fatal("incorrect official request")
				}
				raw, _ := io.ReadAll(r.Body)
				var body struct {
					Content, OpenID string
					Version, Scene  int
				}
				if json.Unmarshal(raw, &body) != nil || body.Content != "尺寸12.5米\n🛠" || body.OpenID != "oS07_abcdefghijklmnopqrstuv" || body.Version != 2 || body.Scene != 1 {
					t.Fatal("text identity or bytes changed", string(raw))
				}
				return &http.Response{StatusCode: c.status, Body: io.NopCloser(strings.NewReader(c.body))}, nil
			})}
			out, e := s.inspect(context.Background(), credential("s07_fixture_token_one"), "oS07_abcdefghijklmnopqrstuv", "尺寸12.5米\n🛠")
			if c.unavailable {
				if e != ports.ErrUnavailable {
					t.Fatalf("fail-open %+v %v", out, e)
				}
			} else if e != nil || out.Decision != c.decision {
				t.Fatalf("decision %+v %v", out, e)
			}
			if c.decision == "review" && (out.Finding == nil || out.Finding.Confirmed) {
				t.Fatal("review counted as violation")
			}
		})
	}
}
func TestExpiredOfficialCredentialIsQuarantined(t *testing.T) {
	path := filepath.Join(t.TempDir(), "credential.json")
	v := credential("s07_fixture_token_one")
	putCredential(t, path, v)
	c, _ := NewCredentials(path, "test", v.AppID)
	s := &Service{credentials: c, client: httpFunc(func(*http.Request) (*http.Response, error) {
		return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"errcode":40001}`))}, nil
	})}
	if _, e := s.inspect(context.Background(), v, "oS07_abcdefghijklmnopqrstuv", "fixture"); e == nil {
		t.Fatal("invalid access token allowed")
	}
	if _, e := c.Get(); e == nil {
		t.Fatal("bad generation remained usable")
	}
}

func TestRejectedCredentialHintIsScopedAndContainsNoSecret(t *testing.T) {
	root := t.TempDir()
	if e := os.Chmod(root, 0750); e != nil {
		t.Fatal(e)
	}
	path := filepath.Join(root, "credential.json")
	v := credential("hint_fixture_private_token")
	putCredential(t, path, v)
	c, _ := NewCredentials(path, "test", v.AppID)
	if c.setRejectionDirectory(root) != nil {
		t.Fatal("hint directory")
	}
	if _, e := c.Get(); e != nil {
		t.Fatal(e)
	}
	c.Reject(v.Generation)
	name := filepath.Join(root, "test-"+hash(v.AppID)+"-"+v.Generation+".json")
	raw, e := os.ReadFile(name)
	if e != nil || strings.Contains(string(raw), v.Token) {
		t.Fatal("missing/secret hint")
	}
	var hint map[string]any
	if json.Unmarshal(raw, &hint) != nil || hint["env"] != "test" || hint["app_id"] != v.AppID || hint["generation"] != v.Generation {
		t.Fatal("hint scope")
	}
	info, _ := os.Stat(name)
	if info.Mode().Perm() != 0640 {
		t.Fatal("hint mode")
	}
	if _, e = c.Get(); e == nil {
		t.Fatal("rejected credential reused before PHP refresh")
	}
	next := credential("hint_fixture_rotated_token")
	putCredential(t, path, next)
	if got, e := c.Get(); e != nil || got.Generation != next.Generation {
		t.Fatal("PHP rotation not observed")
	}
}
