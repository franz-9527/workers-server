package message

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"fmt"

	"encoding/hex"

	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"

	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/conversation"
	"github.com/franz-9527/workers/chat/internal/ports"
	mysql "github.com/go-sql-driver/mysql"
)

type fixtureDB struct {
	t          *testing.T
	ctx        context.Context
	root, goDB *sql.DB
	store      *conversation.Store
	name       string
	user       string
}

func digest(s string) string { v := sha256.Sum256([]byte(s)); return hex.EncodeToString(v[:]) }
func newFixture(t *testing.T) *fixtureDB {
	t.Helper()
	if os.Getenv("CHAT_AUTH_TEST_INTEGRATION") != "1" {
		t.Skip("explicit isolated MySQL/PHP required")
	}
	host, _, err := net.SplitHostPort(os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR"))
	if err != nil || host != "127.0.0.1" {
		t.Fatal("loopback fixture required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 80*time.Second)
	t.Cleanup(cancel)
	c := mysql.NewConfig()
	c.Net = "tcp"
	c.Addr = os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR")
	c.User = "root"
	c.Passwd = os.Getenv("CHAT_INTEGRATION_ADMIN_PASSWORD")
	c.Timeout = time.Second
	c.ParseTime = true
	c.Apply(mysql.Charset("utf8mb4", "utf8mb4_unicode_ci"))
	c.Params = map[string]string{"time_zone": "'+00:00'"}
	connector, err := mysql.NewConnector(c)
	if err != nil {
		t.Fatal(err)
	}
	admin := sql.OpenDB(connector)
	defer admin.Close()
	suffix := strconv.FormatInt(time.Now().UnixNano(), 36)
	name := "bixuan_test_policy_platform_" + suffix
	if _, err = admin.ExecContext(ctx, "CREATE DATABASE `"+name+"` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); err != nil {
		t.Fatal(err)
	}
	c.DBName = name
	connector, _ = mysql.NewConnector(c)
	root := sql.OpenDB(connector)
	root.SetMaxOpenConns(4)
	f := &fixtureDB{t: t, ctx: ctx, root: root, name: name}
	t.Cleanup(func() { root.Exec("DROP DATABASE IF EXISTS `" + name + "`"); root.Close() })
	f.php("init")
	fixtureHost := os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST")
	if fixtureHost == "" {
		fixtureHost = "127.0.0.1"
	}
	if fixtureHost != "127.0.0.1" && fixtureHost != "%" {
		t.Fatal("fixture user host")
	}
	user := "bxchat_test_s06_" + suffix
	f.user = user
	f.exec("CREATE USER '" + user + "'@'" + fixtureHost + "' IDENTIFIED BY 's05-fixture-only'")
	t.Cleanup(func() { root.Exec("DROP USER IF EXISTS '" + user + "'@'" + fixtureHost + "'") })
	for _, table := range []string{"users", "login_sessions", "chat_policy_guards", "chat_business_events", "chat_staff_authority_versions"} {
		f.exec("GRANT SELECT ON `" + name + "`." + table + " TO '" + user + "'@'" + fixtureHost + "'")
	}
	for _, table := range []string{"chat_conversations", "chat_members", "chat_member_intervals", "chat_event_receipts", "chat_restrictions", "chat_send_intents", "chat_messages", "chat_message_changes"} {
		f.exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `" + name + "`." + table + " TO '" + user + "'@'" + fixtureHost + "'")
	}
	c.User = user
	c.Passwd = "s05-fixture-only"
	connector, _ = mysql.NewConnector(c)
	f.goDB = sql.OpenDB(connector)
	f.goDB.SetMaxOpenConns(4)
	t.Cleanup(func() { f.goDB.Close() })
	f.store, err = conversation.NewStore(f.goDB, "test", 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	for _, actor := range []string{"10201", "10202", "10203"} {
		f.exec(`INSERT INTO login_sessions(id,user_id,token_hash,status,expires_at,created_at) VALUES(?,?,?,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP())`, actor, actor, digest("login:"+actor))
	}
	return f
}
func (f *fixtureDB) exec(q string, args ...any) {
	f.t.Helper()
	if _, err := f.root.ExecContext(f.ctx, q, args...); err != nil {
		f.t.Fatal("fixture SQL", err)
	}
}
func (f *fixtureDB) text(q string, args ...any) string {
	f.t.Helper()
	var s string
	if err := f.root.QueryRowContext(f.ctx, q, args...).Scan(&s); err != nil {
		f.t.Fatal(err)
	}
	return s
}
func (f *fixtureDB) php(action string, args ...string) {
	f.t.Helper()
	php := os.Getenv("CHAT_AUTH_TEST_PHP")
	if php == "" {
		f.t.Fatal("explicit PHP required")
	}
	path, _ := filepath.Abs("../../../server/tests/chat_platform_fixture.php")
	cmd := exec.CommandContext(f.ctx, php, append([]string{path, action}, args...)...)
	cmd.Env = append(os.Environ(), "CHAT_POLICY_FIXTURE_DATABASE="+f.name)
	out, err := cmd.CombinedOutput()
	if err != nil || strings.TrimSpace(string(out)) != "committed" {
		f.t.Fatalf("real PHP %s: %s", action, out)
	}
}
func principal(actor string) ports.Principal {
	return ports.Principal{ActorID: ports.ID(actor), SourceID: ports.ID(actor), Kind: "mini", Environment: "test", Scopes: []string{"chat:use"}, SessionID: digest("mini\n" + actor + "\n" + digest("login:"+actor)), SessionVersion: digest("mini-v1\n" + actor + "\n" + digest("login:"+actor) + "\n" + actor), ExpiresAt: time.Now().Add(time.Minute)}
}

type safetyFunc func(context.Context, ports.TextRequest) (ports.SafetyResult, error)

func (f safetyFunc) CheckText(ctx context.Context, r ports.TextRequest) (ports.SafetyResult, error) {
	return f(ctx, r)
}
func pass(_ context.Context, _ ports.TextRequest) (ports.SafetyResult, error) {
	return ports.SafetyResult{Decision: "passed", RuleVersion: "s06-fixture-v1"}, nil
}
func setupMessage(t *testing.T, safety ports.Safety) (*fixtureDB, *Service, Request) {
	t.Helper()
	f := newFixture(t)
	b, e := f.store.Poll(f.ctx, 100)
	if e != nil || b.Applied < 1 {
		t.Fatalf("projection %+v %v", b, e)
	}
	id, e := f.store.FindOrder(f.ctx, principal("10202"), "401", "401")
	if e != nil {
		t.Fatal(e)
	}
	s, e := NewService(f.goDB, f.store, safety, 3*time.Second)
	if e != nil {
		t.Fatal(e)
	}
	r := request(1)
	r.ConversationID = id
	return f, s, r
}
func accepted(t *testing.T, s *Service, r Request) Result {
	t.Helper()
	out, e := s.Send(context.Background(), principal("10202"), r)
	if e != nil || out.State != "accepted" {
		t.Fatalf("send %+v %v", out, e)
	}
	return out
}
func noMessages(t *testing.T, f *fixtureDB) {
	t.Helper()
	if f.text("SELECT COUNT(*) FROM chat_messages") != "0" || f.text("SELECT SUM(last_seq+last_change_seq) FROM chat_conversations") != "0" || f.text("SELECT COUNT(*) FROM chat_message_changes") != "0" {
		t.Fatal("rejected/checking action published state")
	}
}
func TestMySQLSendIdempotencyAndSafeResult(t *testing.T) {
	f, s, r := setupMessage(t, safetyFunc(pass))
	out := accepted(t, s, r)
	if out.Seq != "1" || out.PolicyVersion != "1" {
		t.Fatalf("wrong seq/version %+v", out)
	}
	again := accepted(t, s, r)
	if again.MessageID != out.MessageID {
		t.Fatal("duplicate message")
	}
	altered := r
	altered.Text += " changed"
	if _, e := s.Send(f.ctx, principal("10202"), altered); e != ErrConflict {
		t.Fatalf("conflict %v", e)
	}
	if f.text("SELECT text_body FROM chat_messages WHERE id=?", string(out.MessageID)) != r.Text {
		t.Fatal("body changed")
	}
	f.exec("UPDATE chat_restrictions SET permanent=1,version=version+1 WHERE actor_id=10202")
	if got, e := s.Query(f.ctx, principal("10202"), r.ConversationID, r.ClientMessageID); e != nil || got.MessageID != out.MessageID {
		t.Fatalf("ban prevented original result %+v %v", got, e)
	}
	if got := accepted(t, s, r); got.MessageID != out.MessageID {
		t.Fatal("ban retry re-sent")
	}
	newer := r
	newer.ClientMessageID = client(2)
	if _, e := s.Send(f.ctx, principal("10202"), newer); e != ErrRestricted {
		t.Fatalf("ban bypass %v", e)
	}
	f.exec("UPDATE chat_messages SET state='recalled',state_version=2 WHERE id=?", string(out.MessageID))
	got, e := s.Query(f.ctx, principal("10202"), r.ConversationID, r.ClientMessageID)
	if e != nil || got.MessageState != "recalled" || got.StateVersion != "2" {
		t.Fatalf("unsafe state %+v %v", got, e)
	}
	f.php("close")
	if _, e = f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	if got, e = s.Query(f.ctx, principal("10202"), r.ConversationID, r.ClientMessageID); e != nil || got.MessageID != out.MessageID {
		t.Fatalf("closure lost result %+v %v", got, e)
	}
	if _, e = s.Query(f.ctx, principal("10203"), r.ConversationID, r.ClientMessageID); e != ErrUnknown {
		t.Fatalf("other author result leaked %v", e)
	}
	if f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("retries published again")
	}
}
func TestMySQLUnavailableAndBlockedDoNotPublish(t *testing.T) {
	for _, mode := range []string{"unavailable", "blocked", "malformed"} {
		t.Run(mode, func(t *testing.T) {
			f, s, r := setupMessage(t, safetyFunc(func(context.Context, ports.TextRequest) (ports.SafetyResult, error) {
				if mode == "unavailable" {
					return ports.SafetyResult{}, ports.ErrUnavailable
				}
				if mode == "malformed" {
					return ports.SafetyResult{Decision: "passed"}, nil
				}
				return ports.SafetyResult{Decision: "blocked", RuleVersion: "fixture"}, nil
			}))
			out, e := s.Send(f.ctx, principal("10202"), r)
			if mode == "blocked" {
				if e != nil || out.State != "rejected" || out.Code != ErrBlocked.Error() {
					t.Fatalf("blocked %+v %v", out, e)
				}
			} else if e != ErrCheck {
				t.Fatalf("fail-open %+v %v", out, e)
			}
			noMessages(t, f)
			got, e := s.Query(f.ctx, principal("10202"), r.ConversationID, r.ClientMessageID)
			if e != nil || got.State == "accepted" {
				t.Fatalf("result %+v %v", got, e)
			}
			if f.text("SELECT COUNT(*) FROM chat_send_intents WHERE retain_until>=action_expires_at+INTERVAL 30 DAY") != "1" {
				t.Fatal("retention")
			}
			if mode != "blocked" {
				s.safety = safetyFunc(pass)
				accepted(t, s, r)
			}
		})
	}
}
func TestMySQLFinalRevalidationAndSequenceOrder(t *testing.T) {
	for _, change := range []string{"ban", "close", "logout", "user-inactive", "staff-revoke"} {
		t.Run(change, func(t *testing.T) {
			var f *fixtureDB
			safety := safetyFunc(func(context.Context, ports.TextRequest) (ports.SafetyResult, error) {
				// Every operation commits while the inspector is running, proving no
				// long authority/conversation transaction is held over external calls.
				switch change {
				case "ban":
					f.exec("UPDATE chat_restrictions SET permanent=1,version=version+1 WHERE actor_id=10202")
				case "close":
					f.php("close")
				case "logout":
					f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
				case "user-inactive":
					f.exec("UPDATE users SET status='disabled' WHERE id=10202")
				case "staff-revoke":
					f.exec("UPDATE users SET status='disabled' WHERE id=10203")
				}
				return pass(context.Background(), ports.TextRequest{})
			})
			var s *Service
			var r Request
			f, s, r = setupMessage(t, safety)
			if out, e := s.Send(f.ctx, principal("10202"), r); e == nil && out.State == "accepted" {
				t.Fatal("changed authority published")
			}
			noMessages(t, f)
		})
	}
	// A slow checked message does not reserve seq. A later fast message commits
	// first; a retry of the slow action observes its single accepted identity.
	entered := make(chan struct{})
	release := make(chan struct{})
	f, s, r := setupMessage(t, safetyFunc(func(ctx context.Context, r ports.TextRequest) (ports.SafetyResult, error) {
		if r.Text == "slow" {
			close(entered)
			select {
			case <-release:
			case <-ctx.Done():
				return ports.SafetyResult{}, ctx.Err()
			}
		}
		return pass(ctx, r)
	}))
	slow := r
	slow.Text = "slow"
	done := make(chan Result, 1)
	errs := make(chan error, 1)
	go func() { out, e := s.Send(f.ctx, principal("10202"), slow); done <- out; errs <- e }()
	<-entered
	concurrent, e := s.Send(f.ctx, principal("10202"), slow)
	if e != nil || concurrent.State != "checking" {
		t.Fatalf("concurrent retry %+v %v", concurrent, e)
	}
	fast := r
	fast.ClientMessageID = client(2)
	fast.Text = "fast"
	first := accepted(t, s, fast)
	if first.Seq != "1" {
		t.Fatal("slow reserved seq")
	}
	close(release)
	second := <-done
	if e := <-errs; e != nil || second.Seq != "2" {
		t.Fatalf("commit order %+v %v", second, e)
	}
	if got := accepted(t, s, slow); got.MessageID != second.MessageID {
		t.Fatal("double-send")
	}
	if f.text("SELECT CONCAT(last_seq,':',last_change_seq,':',last_message_id) FROM chat_conversations WHERE id=?", string(r.ConversationID)) != "2:2:"+string(second.MessageID) {
		t.Fatal("summary not atomic")
	}
}
func TestMySQLExpiredIDsAndLeaseOwner(t *testing.T) {
	f, s, r := setupMessage(t, safetyFunc(pass))
	expired := r
	expired.ClientMessageID = fmt.Sprintf("%d.%032x", time.Now().Add(-25*time.Hour).Unix(), 1)
	if _, e := s.Send(f.ctx, principal("10202"), expired); e != ErrExpired {
		t.Fatalf("expired ID accepted %v", e)
	}
	if _, e := s.Query(f.ctx, principal("10202"), expired.ConversationID, expired.ClientMessageID); e != ErrExpired {
		t.Fatalf("missing expired result %v", e)
	}
	noMessages(t, f)
	// Simulate takeover while old inspector is outstanding. Old token cannot
	// accept even though it obtained a positive safety result.
	s.safety = safetyFunc(func(ctx context.Context, r ports.TextRequest) (ports.SafetyResult, error) {
		f.exec("UPDATE chat_send_intents SET lease_owner=REPEAT('f',32) WHERE id=?", string(r.IntentID))
		return pass(ctx, r)
	})
	if _, e := s.Send(f.ctx, principal("10202"), r); e != ports.ErrBusy {
		t.Fatalf("old lease published %v", e)
	}
	noMessages(t, f)
	f.exec("UPDATE chat_send_intents SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND")
	s.safety = safetyFunc(pass)
	accepted(t, s, r)
}

func TestMySQLPublicationRollbackAndStableRestrictionLock(t *testing.T) {
	f, s, r := setupMessage(t, safetyFunc(pass))
	f.exec("CREATE TRIGGER s06_reject_change BEFORE INSERT ON chat_message_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated rollback test'")
	if _, e := s.Send(f.ctx, principal("10202"), r); e != ports.ErrUnavailable {
		t.Fatalf("forced rollback %v", e)
	}
	noMessages(t, f)
	f.exec("DROP TRIGGER s06_reject_change")
	f.exec("UPDATE chat_send_intents SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND")
	if out := accepted(t, s, r); out.Seq != "1" {
		t.Fatal("rollback consumed seq")
	}
	// Even an unrestricted actor has a stable row locked through commit. A
	// concurrent ban cannot commit until this final-authorization tx releases it.
	tx, e := s.begin(f.ctx)
	if e != nil {
		t.Fatal(e)
	}
	defer tx.Rollback()
	if _, e = s.authorize(f.ctx, tx, principal("10202"), r.ConversationID); e != nil {
		t.Fatal(e)
	}
	blocked := make(chan error, 1)
	go func() {
		_, e := f.root.ExecContext(f.ctx, "UPDATE chat_restrictions SET permanent=1,version=version+1 WHERE actor_id=10202")
		blocked <- e
	}()
	select {
	case e := <-blocked:
		t.Fatalf("ban escaped stable row lock: %v", e)
	case <-time.After(100 * time.Millisecond):
	}
	if e = tx.Commit(); e != nil {
		t.Fatal(e)
	}
	if e = <-blocked; e != nil {
		t.Fatal(e)
	}
	r.ClientMessageID = client(3)
	if _, e = s.Send(f.ctx, principal("10202"), r); e != ErrRestricted {
		t.Fatalf("new ban not authoritative %v", e)
	}
}

func TestMySQLResultDoesNotOutliveCredential(t *testing.T) {
	f, s, r := setupMessage(t, safetyFunc(pass))
	accepted(t, s, r)
	tx, e := f.root.BeginTx(f.ctx, nil)
	if e != nil {
		t.Fatal(e)
	}
	defer tx.Rollback()
	if _, e = tx.ExecContext(f.ctx, "UPDATE chat_send_intents SET updated_at=updated_at WHERE conversation_id=?", string(r.ConversationID)); e != nil {
		t.Fatal(e)
	}
	p := principal("10202")
	p.ExpiresAt = time.Now().Add(300 * time.Millisecond)
	done := make(chan error, 1)
	go func() { _, e := s.Query(f.ctx, p, r.ConversationID, r.ClientMessageID); done <- e }()
	timer := time.NewTimer(400 * time.Millisecond)
	defer timer.Stop()
	<-timer.C
	if e = tx.Commit(); e != nil {
		t.Fatal(e)
	}
	if e = <-done; e == nil {
		t.Fatal("result released after credential expired")
	}
	// An old nonterminal intent can never remain actionable, even before cleanup.
	old := fmt.Sprintf("%d.%032x", time.Now().Add(-25*time.Hour).Unix(), 9)
	f.exec("INSERT INTO chat_send_intents(sender_id,conversation_id,client_message_id,fingerprint,state,attempts,action_expires_at,retain_until,created_at,updated_at) VALUES(10202,?,?,REPEAT('a',64),'retryable',1,UTC_TIMESTAMP()-INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 30 DAY,UTC_TIMESTAMP(),UTC_TIMESTAMP())", string(r.ConversationID), old)
	out, e := s.Query(f.ctx, principal("10202"), r.ConversationID, old)
	if e != nil || out.State != "rejected" || out.Code != ErrExpired.Error() {
		t.Fatalf("expired nonterminal result %+v %v", out, e)
	}
}
