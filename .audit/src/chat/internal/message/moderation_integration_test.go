package message

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/moderation"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type moderationHTTP func(*http.Request) (*http.Response, error)

func (f moderationHTTP) Do(r *http.Request) (*http.Response, error) { return f(r) }
func moderated(t *testing.T, respond func(*fixtureDB, http.ResponseWriter, *http.Request)) (*fixtureDB, *Service, *moderation.Service, Request, *atomic.Int32) {
	t.Helper()
	f, s, r := setupMessage(t, safetyFunc(pass))
	f.exec(`INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10202,'chat-platform-app','oS07Worker_abcdefghijklmnopqrstuv','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())`)
	directory, e := os.MkdirTemp("", "bixuan-s07-")
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { os.RemoveAll(directory) })
	path, _ := filepath.Abs("../../../server/tests/chat_text_security_contract.php")
	cmd := exec.CommandContext(f.ctx, os.Getenv("CHAT_AUTH_TEST_PHP"), path, "--export", directory)
	cmd.Env = os.Environ()
	out, e := cmd.CombinedOutput()
	if e != nil || strings.TrimSpace(string(out)) != "exported" {
		t.Fatalf("real PHP credential exporter: %s", out)
	}
	count := &atomic.Int32{}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) { count.Add(1); respond(f, w, req) }))
	t.Cleanup(server.Close)
	address, _ := url.Parse(server.URL)
	client := moderationHTTP(func(req *http.Request) (*http.Response, error) {
		if req.URL.Scheme != "https" || req.URL.Host != "api.weixin.qq.com" || req.URL.Path != "/wxa/msg_sec_check" || req.URL.Query().Get("access_token") != "s07_fixture_official_token" {
			return nil, ports.ErrInvalid
		}
		copy := req.Clone(req.Context())
		target := *req.URL
		copy.URL = &target
		copy.URL.Host = address.Host
		copy.URL.Scheme = address.Scheme
		return server.Client().Do(copy)
	})
	export := filepath.Join(directory, "export", "test-"+digest("chat-platform-app")+".json")
	f.credentialFile = export
	safety, e := moderation.New(f.goDB, "test", moderation.Config{Enabled: true, AppID: "chat-platform-app", CredentialFile: export}, 4, 2*time.Second, client)
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(safety.Close)
	s.safety = safety
	return f, s, safety, r, count
}
func officialPass(_ *fixtureDB, w http.ResponseWriter, req *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	var body struct {
		Content string `json:"content"`
		OpenID  string `json:"openid"`
		Scene   int    `json:"scene"`
		Version int    `json:"version"`
	}
	if json.NewDecoder(req.Body).Decode(&body) != nil || body.OpenID != "oS07Worker_abcdefghijklmnopqrstuv" || body.Scene != 1 || body.Version != 2 || body.Content == "" {
		w.WriteHeader(400)
		return
	}
	io.WriteString(w, `{"errcode":0,"trace_id":"s07-fixture","result":{"suggest":"pass","label":100},"detail":[]}`)
}
func TestMySQLS07OfficialPassUsesPHPTokenAndBoundedCache(t *testing.T) {
	f, s, safety, r, count := moderated(t, officialPass)
	first := accepted(t, s, r)
	r.ClientMessageID = client(2)
	second := accepted(t, s, r)
	if first.Seq != "1" || second.Seq != "2" || count.Load() != 1 {
		t.Fatalf("cache or send order %s %s calls=%d", first.Seq, second.Seq, count.Load())
	}
	if f.text("SELECT COUNT(*) FROM chat_text_checks") != "2" || f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" {
		t.Fatal("normal sends created risk or lost context")
	}
	f.exec("UPDATE chat_text_checks SET created_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE,pass_until=UTC_TIMESTAMP()-INTERVAL 1 MINUTE")
	if e := safety.Clean(f.ctx); e != nil {
		t.Fatal(e)
	}
	if f.text("SELECT COUNT(*) FROM chat_text_checks") != "0" || f.text("SELECT COUNT(*) FROM chat_send_intents") != "2" {
		t.Fatal("context cleanup removed idempotency facts")
	}
	if got, e := s.Query(f.ctx, principal("10202"), r.ConversationID, r.ClientMessageID); e != nil || got.MessageID != second.MessageID {
		t.Fatalf("cleanup lost accepted result %+v %v", got, e)
	}
}
func TestMySQLS07OfficialRejectionReviewAndFaults(t *testing.T) {
	for _, mode := range []string{"risky", "review", "quota", "malformed", "timeout"} {
		t.Run(mode, func(t *testing.T) {
			var response atomic.Value
			response.Store(mode)
			f, s, _, r, count := moderated(t, func(f *fixtureDB, w http.ResponseWriter, req *http.Request) {
				switch response.Load().(string) {
				case "pass":
					officialPass(f, w, req)
				case "risky":
					io.WriteString(w, `{"errcode":0,"trace_id":"s07-risk","result":{"suggest":"risky","label":200}}`)
				case "review":
					io.WriteString(w, `{"errcode":0,"trace_id":"s07-review","result":{"suggest":"review"}}`)
				case "quota":
					io.WriteString(w, `{"errcode":45009,"errmsg":"fixture diagnostic never forwarded"}`)
				case "malformed":
					io.WriteString(w, `{"errcode":0}`)
				case "timeout":
					// Drain the POST body so net/http can observe a closed peer.
					// Bound the fixture too: server cleanup must not wait forever.
					io.Copy(io.Discard, req.Body)
					select {
					case <-req.Context().Done():
					case <-time.After(3 * time.Second):
					}
				}
			})
			out, e := s.Send(f.ctx, principal("10202"), r)
			noMessages(t, f)
			if mode == "risky" || mode == "review" {
				want := "rejected"
				confirmed := "1"
				if mode == "review" {
					want = "review_pending"
					confirmed = "0"
				}
				if e != nil || out.State != want || out.RiskEventID == "" {
					t.Fatalf("risk outcome %+v %v", out, e)
				}
				if f.text("SELECT CAST(confirmed AS CHAR) FROM chat_risk_events WHERE id=?", string(out.RiskEventID)) != confirmed {
					t.Fatal("review incorrectly counted as violation")
				}
				if f.text("SELECT text_evidence FROM chat_risk_events WHERE id=?", string(out.RiskEventID)) != r.Text {
					t.Fatal("risk evidence is not original text")
				}
				again, e := s.Send(f.ctx, principal("10202"), r)
				if e != nil || again.RiskEventID != out.RiskEventID || count.Load() != 1 || f.text("SELECT COUNT(*) FROM chat_risk_events") != "1" {
					t.Fatalf("risk retry duplicated %+v %v", again, e)
				}
				got, e := s.Query(f.ctx, principal("10202"), r.ConversationID, r.ClientMessageID)
				if e != nil || got.RiskEventID != out.RiskEventID {
					t.Fatalf("risk status query %+v %v", got, e)
				}
			} else {
				if e != ErrCheck || f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" {
					t.Fatalf("fault recorded a violation %+v %v", out, e)
				}
				response.Store("pass")
				// A timeout may leave the checking lease until takeover; the same ID still
				// owns the fingerprint, never creates a second logical action.
				f.exec("UPDATE chat_send_intents SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE state='checking'")
				accepted(t, s, r)
				if f.text("SELECT COUNT(*) FROM chat_text_checks") != "1" {
					t.Fatal("retry added another fragment")
				}
			}
		})
	}
}
func TestMySQLS07LocalAndSplitRules(t *testing.T) {
	f, s, _, r, count := moderated(t, officialPass)
	r.Text = "电话138001"
	accepted(t, s, r)
	next := r
	next.ClientMessageID = client(2)
	next.Text = "38000"
	out, e := s.Send(f.ctx, principal("10202"), next)
	if e != nil || out.State != "rejected" || out.RiskEventID == "" || count.Load() != 1 {
		t.Fatalf("split contact bypass %+v %v calls=%d", out, e, count.Load())
	}
	if f.text("SELECT reason_code FROM chat_risk_events WHERE id=?", string(out.RiskEventID)) != "split_private_phone" || f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("wrong split disposition")
	}
	if f.text("SELECT context_evidence FROM chat_risk_events WHERE id=?", string(out.RiskEventID)) == "" {
		t.Fatal("split evidence lost contributing action snapshots")
	}
	if again, e := s.Send(f.ctx, principal("10202"), next); e != nil || again.RiskEventID != out.RiskEventID {
		t.Fatal("blocked action was re-counted")
	}
	// Same person's other order has independent context; a naked numeric fragment
	// is not itself confirmed diversion evidence.
	f.php("sync", "402")
	if _, e = f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	id, e := f.store.FindOrder(f.ctx, principal("10202"), "402", "402")
	if e != nil {
		t.Fatal(e)
	}
	other := next
	other.ConversationID = id
	other.ClientMessageID = client(3)
	accepted(t, s, other)
	// Old context is ineligible, while minimum intent/risk facts remain.
	f.exec("UPDATE chat_text_checks SET created_at=UTC_TIMESTAMP()-INTERVAL 3 MINUTE WHERE conversation_id=?", string(r.ConversationID))
	next.ClientMessageID = client(4)
	accepted(t, s, next)
	direct := r
	direct.ClientMessageID = client(5)
	direct.Text = "加我 ＶＸ：abc_123456"
	if out, e = s.Send(f.ctx, principal("10202"), direct); e != nil || out.State != "rejected" {
		t.Fatalf("direct diversion %+v %v", out, e)
	}
}
func TestMySQLS07RevalidatesAuthorityBeforeEveryFinalResult(t *testing.T) {
	for _, mode := range []string{"identity-version", "ban", "close"} {
		for _, decision := range []string{"pass", "risky", "review"} {
			t.Run(mode+"/"+decision, func(t *testing.T) {
				f, s, _, r, _ := moderated(t, func(f *fixtureDB, w http.ResponseWriter, req *http.Request) {
					// These real writes complete during official inspection, proving no SQL
					// identity/authority lease spans network I/O.
					switch mode {
					case "identity-version":
						f.exec("UPDATE wechat_identities SET session_version=session_version+1 WHERE user_id=10202")
					case "ban":
						f.exec("UPDATE chat_restrictions SET permanent=1,version=version+1 WHERE actor_id=10202")
					case "close":
						f.php("close")
					}
					if decision == "pass" {
						officialPass(f, w, req)
					} else {
						io.WriteString(w, `{"errcode":0,"result":{"suggest":"`+decision+`"}}`)
					}
				})
				if out, e := s.Send(f.ctx, principal("10202"), r); e == nil {
					t.Fatalf("stale authority finalized: %+v", out)
				}
				noMessages(t, f)
				if f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" {
					t.Fatal("authorization change counted as violation")
				}
			})
		}
	}
}
func TestMySQLS07MissingOrAmbiguousOpenIDFailsClosed(t *testing.T) {
	f, s, _, r, count := moderated(t, officialPass)
	f.exec("UPDATE wechat_identities SET status='disabled' WHERE user_id=10202")
	if _, e := s.Send(f.ctx, principal("10202"), r); e != ErrCheck {
		t.Fatalf("missing real identity %v", e)
	}
	noMessages(t, f)
	f.exec("UPDATE wechat_identities SET status='active' WHERE user_id=10202")
	f.exec(`INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10202,'chat-platform-app','oS07Other_abcdefghijklmnopqrstuv','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())`)
	r.ClientMessageID = client(2)
	if _, e := s.Send(f.ctx, principal("10202"), r); e != ErrCheck {
		t.Fatalf("ambiguous identity %v", e)
	}
	if count.Load() != 0 || f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" {
		t.Fatal("untrusted identity used externally or recorded as violation")
	}
}

func TestMySQLS07RiskCASAndEvidenceRollback(t *testing.T) {
	t.Run("stale-owner", func(t *testing.T) {
		f, s, _, r, _ := moderated(t, func(f *fixtureDB, w http.ResponseWriter, req *http.Request) {
			f.exec("UPDATE chat_send_intents SET lease_owner=REPEAT('f',32) WHERE sender_id=10202 AND state='checking'")
			io.WriteString(w, `{"errcode":0,"result":{"suggest":"risky"}}`)
		})
		if _, e := s.Send(f.ctx, principal("10202"), r); e != ports.ErrBusy {
			t.Fatalf("old owner recorded risk %v", e)
		}
		noMessages(t, f)
		if f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" {
			t.Fatal("old inspection created evidence/count")
		}
	})
	t.Run("rollback", func(t *testing.T) {
		f, s, _, r, _ := moderated(t, func(_ *fixtureDB, w http.ResponseWriter, _ *http.Request) {
			io.WriteString(w, `{"errcode":0,"result":{"suggest":"risky"}}`)
		})
		f.exec("CREATE TRIGGER s07_risk_failure BEFORE INSERT ON chat_risk_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated risk rollback'")
		if _, e := s.Send(f.ctx, principal("10202"), r); e != ports.ErrUnavailable {
			t.Fatalf("risk insert failure %v", e)
		}
		if f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" || f.text("SELECT COUNT(*) FROM chat_send_intents WHERE state='rejected'") != "0" {
			t.Fatal("risk/evidence terminal transaction partially committed")
		}
		f.exec("DROP TRIGGER s07_risk_failure")
		f.exec("UPDATE chat_send_intents SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND")
		out, e := s.Send(f.ctx, principal("10202"), r)
		if e != nil || out.RiskEventID == "" {
			t.Fatalf("same-ID recovery %+v %v", out, e)
		}
		noMessages(t, f)
	})
}
func TestMySQLS07ExpiredCredentialCannotUseCachedPass(t *testing.T) {
	f, s, _, r, count := moderated(t, officialPass)
	accepted(t, s, r)
	data, e := os.ReadFile(f.credentialFile)
	if e != nil {
		t.Fatal(e)
	}
	var envelope map[string]any
	json.Unmarshal(data, &envelope)
	envelope["expires_at"] = time.Now().Unix() - 1
	bad, _ := json.Marshal(envelope)
	os.WriteFile(f.credentialFile, bad, 0640)
	r.ClientMessageID = client(2)
	if _, e = s.Send(f.ctx, principal("10202"), r); e != ErrCheck {
		t.Fatalf("expired credential bypassed via pass cache %v", e)
	}
	if count.Load() != 1 || f.text("SELECT COUNT(*) FROM chat_messages") != "1" || f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" {
		t.Fatal("expired credential published or recorded risk")
	}
	os.WriteFile(f.credentialFile, data, 0640)
	accepted(t, s, r)
}

func TestMySQLP1ExplicitContactsAndAmbiguousBusinessValues(t *testing.T) {
	for index, tc := range []struct {
		text    string
		blocked bool
	}{
		{"13800138000", true},
		{"这是13800138000，稍后沟通", true},
		{"12345678901", true},
		{"请勿发送电话13800138000", true},
		{"微信：abc___", true},
		{"工单号13800138000，联系我核对施工尺寸", false},
		{"产品型号VX13800138000，报价1380元", false},
		{"加微信", false},
		{"abc_123456", false},
	} {
		t.Run(tc.text, func(t *testing.T) {
			f, s, _, r, count := moderated(t, officialPass)
			r.ClientMessageID = client(1000 + index)
			r.Text = tc.text
			out, e := s.Send(f.ctx, principal("10202"), r)
			if e != nil {
				t.Fatal(e)
			}
			if tc.blocked {
				if out.State != "rejected" || out.RiskEventID == "" || count.Load() != 0 || f.text("SELECT COUNT(*) FROM chat_messages") != "0" {
					t.Fatal("explicit contact not blocked before official call", out, count.Load())
				}
				again, e := s.Send(f.ctx, principal("10202"), r)
				if e != nil || again.RiskEventID != out.RiskEventID || f.text("SELECT COUNT(*) FROM chat_risk_events") != "1" {
					t.Fatal("retry re-counted contact", again, e)
				}
			} else if out.State != "accepted" || count.Load() != 1 || f.text("SELECT COUNT(*) FROM chat_risk_events") != "0" || f.text("SELECT COUNT(*) FROM chat_restrictions WHERE permanent=1 OR blocked_until>UTC_TIMESTAMP(6)") != "0" {
				t.Fatal("ambiguous/business value blocked or punished", out, count.Load())
			}
		})
	}
}
