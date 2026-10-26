package dependencies

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func TestSettingsAndOwnership(t *testing.T) {
	if Defaults().Validate("test") != nil {
		t.Fatal("defaults invalid")
	}
	for _, mutate := range []func(*Settings){
		func(s *Settings) { s.MySQL.MaxOpenServe = 32 }, func(s *Settings) { s.Redis.PoolWorker = 16 }, func(s *Settings) { s.OperationTimeout = "0s" },
		func(s *Settings) { s.ExternalConcurrency = 0 }, func(s *Settings) { s.ProbeInterval = "1ms" },
		func(s *Settings) { s.MySQL.Enabled = true; s.MySQL.Username = "root" },
		func(s *Settings) { s.Redis.Enabled = true; s.Redis.KeyPrefix = "bxchat:production:" },
		func(s *Settings) {
			s.Redis.Enabled = true
			s.Redis.Address = "8.8.8.8:6379"
			s.Redis.KeyPrefix = "bxchat:test:"
		},
	} {
		s := Defaults()
		mutate(&s)
		if s.Validate("test") == nil {
			t.Fatal("unsafe budget or environment accepted")
		}
	}
	for _, line := range []string{
		"GRANT SELECT, INSERT, UPDATE, DELETE ON `bixuan_test_chat`.`chat_reminder_intents` TO 'u'@'h'",
		"GRANT SELECT, INSERT, UPDATE, DELETE ON `bixuan_test_chat`.`chat_image_uploads` TO 'u'@'h'",
		"GRANT SELECT, INSERT ON `bixuan_test_chat`.`chat_governance_audit` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`chat_mini_auditors` TO 'u'@'h'",
		"GRANT USAGE ON *.* TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`orders` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`chat_admin_sessions` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`chat_staff_authority_versions` TO 'u'@'h'",
		"GRANT SELECT, INSERT, UPDATE, DELETE ON `bixuan_test_chat`.`chat_messages` TO 'u'@'h'",
		"GRANT SELECT, INSERT, UPDATE, DELETE ON `bixuan_test_chat`.`chat_member_reads` TO 'u'@'h'",
	} {
		if !safeGrant(line, "bixuan_test_chat", "test") {
			t.Fatal("valid grant refused")
		}
	}
	for _, line := range []string{
		"GRANT DELETE ON `bixuan_test_chat`.`chat_governance_audit` TO 'u'@'h'",
		"GRANT UPDATE ON `bixuan_test_chat`.`chat_image_audit` TO 'u'@'h'",
		"GRANT ALL PRIVILEGES ON *.* TO 'u'@'h'", "GRANT UPDATE ON `bixuan_test_chat`.`orders` TO 'u'@'h'",
		"GRANT INSERT ON `bixuan_test_chat`.`chat_policy_guards` TO 'u'@'h'",
		"GRANT UPDATE ON `bixuan_test_chat`.`chat_staff_authority_versions` TO 'u'@'h'",
		"GRANT UPDATE ON `bixuan_test_chat`.`chat_calls` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`user_wallets` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`admin_users` TO 'u'@'h'",
		"GRANT UPDATE ON `bixuan_test_chat`.`chat_admin_sessions` TO 'u'@'h'",
		"GRANT UPDATE ON `other_db`.`chat_messages` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.* TO 'u'@'h'", "GRANT CREATE ON `bixuan_test_chat`.`chat_messages` TO 'u'@'h'",
		"GRANT SELECT ON `bixuan_test_chat`.`orders` TO 'u'@'h' WITH GRANT OPTION",
	} {
		if safeGrant(line, "bixuan_test_chat", "test") {
			t.Fatal("unsafe grant accepted")
		}
	}
}
func fixture() Scenario {
	return Scenario{Credential: "test-token", Principal: ports.Principal{ActorID: "1", SessionID: "test-session", Environment: "test", Scopes: []string{"chat"}},
		ConversationID: "2", Grant: ports.Grant{CanRead: true, CanSend: true, ScopeKey: "order:test:1", Version: "3"},
		Fingerprint: "fixture-hash", Text: "施工尺寸12.5", Safety: ports.SafetyResult{Decision: "passed", RuleVersion: "test-rule"},
		ObjectKey: "test/object", Media: ports.MediaResult{Decision: "passed", ObjectVersion: "v1", ManifestHash: "manifest"},
		Reminder: ports.Reminder{IntentID: "1", RecipientID: "2", TargetSeq: "3", ClusterVersion: "4", OperationID: "operation-1"},
		Receipt:  ports.Receipt{OperationID: "operation-1", State: "accepted"}}
}
func TestPortsAreExplicitAndFailClosed(t *testing.T) {
	ctx := context.Background()
	s := fixture()
	p, err := NewProviders("test", "fake", 2, time.Second, s)
	if err != nil {
		t.Fatal(err)
	}
	a, err := p.Identity.Authenticate(ctx, "test-token")
	if err != nil || a.ActorID != "1" {
		t.Fatal("fixture identity failed")
	}
	a.Scopes[0] = "tampered"
	again, _ := p.Identity.Authenticate(ctx, "test-token")
	if again.Scopes[0] != "chat" {
		t.Fatal("fixture alias")
	}
	if _, err = p.Identity.Authenticate(ctx, "forged"); err == nil {
		t.Fatal("forged credential accepted")
	}
	if _, err = p.Policy.Authorize(ctx, ports.PolicyRequest{Actor: again, ConversationID: "2", Action: "send"}); err != nil {
		t.Fatal("fixture grant failed")
	}
	if _, err = p.Policy.Authorize(ctx, ports.PolicyRequest{Actor: again, ConversationID: "99", Action: "send"}); err == nil {
		t.Fatal("cross-conversation grant")
	}
	if _, err = p.Safety.CheckText(ctx, ports.TextRequest{Actor: again, ConversationID: "2", Text: s.Text, Fingerprint: s.Fingerprint}); err != nil {
		t.Fatal("fixture safety failed")
	}
	if _, err = p.Media.Inspect(ctx, ports.MediaRequest{Actor: again, ObjectKey: s.ObjectKey, ObjectVersion: "v1", ManifestHash: "manifest"}); err != nil {
		t.Fatal("fixture media failed")
	}
	if _, err = p.Media.Inspect(ctx, ports.MediaRequest{Actor: again, ObjectKey: s.ObjectKey, ObjectVersion: "v2", ManifestHash: "manifest"}); err == nil {
		t.Fatal("wrong object version accepted")
	}
	for range 2 {
		if _, err = p.Notification.Submit(ctx, s.Reminder); err != nil {
			t.Fatal("same operation failed")
		}
	}
	changed := s.Reminder
	changed.TargetSeq = "99"
	if _, err = p.Notification.Submit(ctx, changed); !errors.Is(err, ports.ErrInvalid) {
		t.Fatal("operation conflict accepted")
	}
	if _, err = NewProviders("production", "fake", 1, time.Second, s); err == nil {
		t.Fatal("production fake allowed")
	}
	for _, mode := range []string{"disabled", "fake"} {
		empty, _ := NewProviders("test", mode, 1, time.Second, Scenario{})
		if _, err = empty.Identity.Authenticate(ctx, "test-token"); err == nil {
			t.Fatal("empty provider allowed identity")
		}
		if _, err = empty.Safety.CheckText(ctx, ports.TextRequest{Fingerprint: "x"}); err == nil {
			t.Fatal("empty provider passed safety")
		}
	}
}
func TestProviderDeadlineAndConcurrency(t *testing.T) {
	s := fixture()
	s.Delay = time.Second
	p, _ := NewProviders("test", "fake", 1, 50*time.Millisecond, s)
	done := make(chan error, 1)
	go func() { _, err := p.Identity.Authenticate(context.Background(), s.Credential); done <- err }()
	impl := p.Identity.(*provider)
	deadline := time.Now().Add(time.Second)
	for len(impl.gate) == 0 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	if _, err := p.Identity.Authenticate(context.Background(), s.Credential); !errors.Is(err, ports.ErrBusy) {
		t.Fatal("concurrency not bounded")
	}
	if err := <-done; !errors.Is(err, context.DeadlineExceeded) {
		t.Fatal("deadline ignored")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := p.Identity.Authenticate(ctx, s.Credential); !errors.Is(err, context.Canceled) {
		t.Fatal("cancel ignored")
	}
}
func TestHTTPBudgetsAndNoRedirect(t *testing.T) {
	entered := make(chan struct{}, 1)
	release := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/slow":
			entered <- struct{}{}
			<-release
		case "/large":
			_, _ = io.WriteString(w, strings.Repeat("x", 100))
		case "/redirect":
			http.Redirect(w, r, "/forbidden", 302)
		case "/forbidden":
			t.Error("followed redirect")
		default:
			_, _ = io.WriteString(w, "ok")
		}
	}))
	defer server.Close()
	defer func() {
		select {
		case <-release:
		default:
			close(release)
		}
	}()
	h, _ := NewHTTP(1, 80*time.Millisecond, 64)
	defer h.Close()
	request := func(path string) *http.Request { r, _ := http.NewRequest("GET", server.URL+path, nil); return r }
	if _, status, err := h.Do(context.Background(), request("/redirect")); err != nil || status != 302 {
		t.Fatal("redirect behavior")
	}
	if _, _, err := h.Do(context.Background(), request("/large")); !errors.Is(err, ports.ErrUnavailable) {
		t.Fatal("body limit ignored")
	}
	done := make(chan error, 1)
	go func() { _, _, err := h.Do(context.Background(), request("/slow")); done <- err }()
	<-entered
	if _, _, err := h.Do(context.Background(), request("/")); !errors.Is(err, ports.ErrBusy) {
		t.Fatal("HTTP concurrency ignored")
	}
	err := <-done
	close(release)
	if !errors.Is(err, ports.ErrUnavailable) {
		t.Fatal("HTTP deadline ignored")
	}
}
func TestDisabledReadinessAndStop(t *testing.T) {
	r, err := Open(Defaults(), "test", "serve", "disabled")
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	s := r.Refresh(context.Background())
	if s.InfrastructureReady || s.BusinessEnabled || s.MySQL.State != "disabled" || s.MySQL.Required != true || s.Redis.Required {
		t.Fatal("false readiness")
	}
	r.Stop()
	if r.Refresh(context.Background()).InfrastructureReady {
		t.Fatal("stopping became ready")
	}
}
