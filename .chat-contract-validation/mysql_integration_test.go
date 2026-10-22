package conversation

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	mysql "github.com/go-sql-driver/mysql"
)

type fixtureDB struct {
	t          *testing.T
	ctx        context.Context
	root, goDB *sql.DB
	store      *Store
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
	user := "bxchat_test_s05_" + suffix
	f.user = user
	f.exec("CREATE USER '" + user + "'@'" + fixtureHost + "' IDENTIFIED BY 's05-fixture-only'")
	t.Cleanup(func() { root.Exec("DROP USER IF EXISTS '" + user + "'@'" + fixtureHost + "'") })
	for _, table := range []string{"users", "login_sessions", "chat_policy_guards", "chat_business_events", "chat_staff_authority_versions"} {
		f.exec("GRANT SELECT ON `" + name + "`." + table + " TO '" + user + "'@'" + fixtureHost + "'")
	}
	for _, table := range []string{"chat_conversations", "chat_members", "chat_member_intervals", "chat_event_receipts"} {
		f.exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `" + name + "`." + table + " TO '" + user + "'@'" + fixtureHost + "'")
	}
	c.User = user
	c.Passwd = "s05-fixture-only"
	connector, _ = mysql.NewConnector(c)
	f.goDB = sql.OpenDB(connector)
	f.goDB.SetMaxOpenConns(4)
	t.Cleanup(func() { f.goDB.Close() })
	f.store, err = NewStore(f.goDB, "test", 3*time.Second)
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
func (f *fixtureDB) poll() Batch {
	f.t.Helper()
	b, err := f.store.Poll(f.ctx, 100)
	if err != nil {
		f.t.Fatal(err)
	}
	if b.Rejected != 0 || b.Deferred != 0 {
		f.t.Fatalf("unexpected projection result %+v", b)
	}
	return b
}
func (f *fixtureDB) event(version string) string {
	return f.text("SELECT event_id FROM chat_business_events WHERE aggregate_key='order:401:assignment:401' AND aggregate_version=?", version)
}
func (f *fixtureDB) claim(v string) Lease {
	f.t.Helper()
	lease, err := f.store.Claim(f.ctx, f.event(v))
	if err != nil {
		f.t.Fatal(err)
	}
	return lease
}
func (f *fixtureDB) check(id ports.ID, actor, action string, allowed bool) {
	f.t.Helper()
	g, err := f.store.Authorize(f.ctx, ports.PolicyRequest{Actor: principal(actor), ConversationID: id, Action: action})
	if allowed && (err != nil || !g.CanRead) {
		f.t.Fatalf("authorized actor denied %s %s: %v", actor, action, err)
	}
	if !allowed && err == nil {
		f.t.Fatal("stale/outsider authorization allowed")
	}
}
func (f *fixtureDB) visible(id ports.ID, version string, want bool) {
	f.t.Helper()
	tx, err := f.goDB.BeginTx(f.ctx, nil)
	if err != nil {
		f.t.Fatal(err)
	}
	defer tx.Rollback()
	ok, err := f.store.VisibleVersionLocked(f.ctx, tx, ports.PolicyRequest{Actor: principal("10203"), ConversationID: id}, ports.ID(version))
	if err != nil || ok != want {
		f.t.Fatalf("history version %s: %v %v", version, ok, err)
	}
}

func TestPHPProjectionLeaseIntervalsAndAuthorization(t *testing.T) {
	f := newFixture(t)
	if f.poll().Applied != 1 {
		t.Fatal("first PHP event not applied")
	}
	id, err := f.store.FindOrder(f.ctx, principal("10203"), "401", "401")
	if err != nil || id == "401" {
		t.Fatal("independent binding", id, err)
	}
	f.check(id, "10203", "send", true)
	f.check(id, "10202", "send", true)
	f.check(id, "10201", "read", false)
	if f.poll().Claimed != 0 {
		t.Fatal("completed event replayed")
	}
	oldHistory, err := f.goDB.BeginTx(f.ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer oldHistory.Rollback()
	var oldCount int
	if oldHistory.QueryRowContext(f.ctx, "SELECT COUNT(*) FROM chat_member_intervals WHERE end_version IS NULL").Scan(&oldCount) != nil {
		t.Fatal("history RR snapshot")
	}
	if _, err = f.store.FindOrder(f.ctx, principal("10203"), "401", "402"); err == nil {
		t.Fatal("wrong assignment binding accepted")
	}
	f.exec(`UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'`)
	f.check(id, "10203", "read", false)
	f.check(id, "10202", "send", false) // Entire stale platform snapshot is fenced.
	f.check(id, "10202", "read", true)  // Another actor keeps legitimate history after staff revocation.
	f.php("sync")
	f.check(id, "10202", "read", false) // Current PHP version has not been materialized yet.
	lease := f.claim("2")
	f.exec(`CREATE TRIGGER fixture_projection_fail BEFORE UPDATE ON chat_conversations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'`)
	if err = f.store.Consume(f.ctx, lease); err == nil {
		t.Fatal("injected failure ignored")
	}
	if f.text("SELECT CAST(policy_version AS CHAR) FROM chat_conversations WHERE id=?", string(id)) != "1" || f.text("SELECT COUNT(*) FROM chat_member_intervals WHERE end_version IS NOT NULL") != "0" {
		t.Fatal("partial projection committed")
	}
	f.exec("DROP TRIGGER fixture_projection_fail")
	if err = f.store.Consume(f.ctx, lease); err != nil {
		t.Fatal(err)
	}
	f.check(id, "10202", "read", true)
	f.check(id, "10202", "send", false)
	f.check(id, "10203", "read", false)
	f.exec(`UPDATE system_settings SET setting_value='["PlatformStaff"]' WHERE setting_key='wechat.admin_openids'`)
	f.php("sync")
	old := f.claim("3")
	f.exec("UPDATE chat_event_receipts SET lease_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE event_id=?", old.EventID)
	newOwner := f.claim("3")
	if old.Owner == newOwner.Owner {
		t.Fatal("lease generation reused")
	}
	if err = f.store.Consume(f.ctx, old); !errors.Is(err, ErrLease) {
		t.Fatal("old worker committed", err)
	}
	if f.text("SELECT CAST(policy_version AS CHAR) FROM chat_conversations WHERE id=?", string(id)) != "2" {
		t.Fatal("lost lease mutated projection")
	}
	if err = f.store.Consume(f.ctx, newOwner); err != nil {
		t.Fatal(err)
	}
	f.check(id, "10203", "send", true)
	f.visible(id, "1", true)
	f.visible(id, "2", false)
	f.visible(id, "3", true)
	visible, err := f.store.VisibleVersionLocked(f.ctx, oldHistory, ports.PolicyRequest{Actor: principal("10203"), ConversationID: id}, "2")
	if err != nil || visible {
		t.Fatal("old RR interval snapshot resurrected gap", visible, err)
	}
	oldHistory.Rollback()
	if f.text("SELECT COUNT(DISTINCT member_key) FROM chat_member_intervals WHERE actor_id=10203") != "2" {
		t.Fatal("return reused revoked authority")
	}
	// A higher version may be selected first, but never opens a gap in history.
	f.exec("UPDATE users SET status='disabled' WHERE id=10203")
	f.php("sync")
	f.exec("UPDATE users SET status='active' WHERE id=10203")
	f.php("sync")
	high := f.claim("5")
	if err = f.store.Consume(f.ctx, high); !errors.Is(err, ErrGap) {
		t.Fatal("out-of-order event skipped version", err)
	}
	if err = f.store.deferLease(f.ctx, high, ErrGap); err != nil {
		t.Fatal(err)
	}
	low := f.claim("4")
	if err = f.store.Consume(f.ctx, low); err != nil {
		t.Fatal(err)
	}
	f.exec("UPDATE chat_event_receipts SET next_attempt_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE event_id=?", high.EventID)
	f.poll()
	f.visible(id, "4", false)
	f.visible(id, "5", true)
	f.visible(id, "1", true)
	// Session final check uses current locking reads even inside an old RR tx.
	tx, err := f.goDB.BeginTx(f.ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	var status string
	if tx.QueryRowContext(f.ctx, "SELECT status FROM login_sessions WHERE id=10202").Scan(&status) != nil {
		t.Fatal("RR fixture")
	}
	f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
	if _, err = f.store.AuthorizeLocked(f.ctx, tx, ports.PolicyRequest{Actor: principal("10202"), ConversationID: id, Action: "read"}); err == nil {
		t.Fatal("old identity snapshot granted")
	}
	tx.Rollback()
	f.exec("UPDATE login_sessions SET status='active' WHERE id=10202")
	tx, err = f.goDB.BeginTx(f.ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback()
	if _, err = f.store.AuthorizeLocked(f.ctx, tx, ports.PolicyRequest{Actor: principal("10202"), ConversationID: id, Action: "read"}); err != nil {
		t.Fatal(err)
	}
	conn, err := f.root.Conn(f.ctx)
	if err != nil {
		t.Fatal(err)
	}
	if _, err = conn.ExecContext(f.ctx, "SET SESSION innodb_lock_wait_timeout=1"); err != nil {
		t.Fatal(err)
	}
	_, err = conn.ExecContext(f.ctx, "UPDATE login_sessions SET status='revoked' WHERE id=10202")
	var dbErr *mysql.MySQLError
	if !errors.As(err, &dbErr) || dbErr.Number != 1205 {
		t.Fatal("session shared lock not retained", err)
	}
	conn.Close()
	tx.Rollback()
	// Source tables are SELECT-only to this worker.
	for _, q := range []string{"DELETE FROM chat_business_events", "UPDATE chat_policy_guards SET policy_version=1", "UPDATE orders SET lifecycle_status='completed'"} {
		if _, err = f.goDB.ExecContext(f.ctx, q); err == nil {
			t.Fatal("worker changed PHP source")
		}
	}
	f.php("close")
	f.poll()
	f.check(id, "10203", "read", true)
	f.check(id, "10203", "send", false)
	serviceKey := f.text("SELECT member_key FROM chat_members WHERE conversation_id=? AND actor_id=10203", string(id))
	f.php("repair")
	f.poll()
	f.check(id, "10203", "send", true)
	repairKey := f.text("SELECT member_key FROM chat_members WHERE conversation_id=? AND actor_id=10203", string(id))
	if serviceKey == repairKey || !strings.Contains(repairKey, ":repair:") {
		t.Fatal("repair reused original membership episode")
	}
	f.php("repair-close")
	f.poll()
	f.check(id, "10203", "send", false)
	// Same order, same worker, new assignment: a separate immutable conversation.
	f.exec(`INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,
		booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,
		estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at)
		SELECT 778,REPEAT('N',26),order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,
		booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,
		estimated_offline_collect_cents,estimated_net_income_cents,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM assignments WHERE id=401`)
	f.exec("UPDATE orders SET current_assignment_id=778,lifecycle_status='active',assignment_status='accepted' WHERE id=401")
	f.php("sync")
	f.poll()
	newID, err := f.store.FindOrder(f.ctx, principal("10203"), "401", "778")
	if err != nil || newID == id {
		t.Fatal("reassignment merged old conversation", err)
	}
	f.check(id, "10203", "send", false)
	f.check(newID, "10203", "send", true)
	if f.text("SELECT COUNT(*) FROM chat_member_intervals WHERE conversation_id=? AND start_version=1", string(newID)) != "2" {
		t.Fatal("new scope inherited old intervals")
	}
	t.Log("real PHP snapshots, version history gaps, lease takeover, rollback, S03 session/current guard locks passed")
}

func TestLateCommitWithoutWakeAndConcurrentClaims(t *testing.T) {
	f := newFixture(t)
	f.poll()
	body := f.text("SELECT snapshot_json FROM chat_policy_guards WHERE order_id=401")
	var snapshot map[string]any
	if json.Unmarshal([]byte(body), &snapshot) != nil {
		t.Fatal("snapshot")
	}
	// Model an uncommitted PHP authority change without inferring order enums.
	snapshot["state"] = "open"
	for _, m := range snapshot["members"].([]any) {
		m.(map[string]any)["can_send"] = false
	}
	raw, err := json.Marshal(snapshot)
	if err != nil {
		t.Fatal(err)
	}
	payload := `{"schema_version":1,"policy_version":"2","snapshot":` + string(raw) + `}`
	late, err := f.root.BeginTx(f.ctx, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer late.Rollback()
	if _, err = late.ExecContext(f.ctx, `UPDATE chat_policy_guards SET policy_version=2,state='open',snapshot_json=?,snapshot_hash=? WHERE order_id=401`, string(raw), digest(string(raw))); err != nil {
		t.Fatal(err)
	}
	if _, err = late.ExecContext(f.ctx, `INSERT INTO chat_business_events(event_id,aggregate_key,aggregate_version,schema_version,event_type,payload_json,created_at)
		VALUES(?,'order:401:assignment:401',2,1,'chat.policy.changed',?,UTC_TIMESTAMP())`, strings.Repeat("a", 32), payload); err != nil {
		t.Fatal(err)
	}
	f.php("sync", "402") // Higher ID, different scope, commits first.
	if f.poll().Applied != 1 {
		t.Fatal("independent higher committed event not applied")
	}
	if err = late.Commit(); err != nil {
		t.Fatal(err)
	}
	if f.poll().Applied != 1 || f.text("SELECT status FROM chat_event_receipts WHERE event_id=?", strings.Repeat("a", 32)) != "done" {
		t.Fatal("lower late commit was skipped")
	}
	f.exec(`UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'`)
	f.php("sync")
	event := f.event("3")
	var wg sync.WaitGroup
	results := make(chan error, 2)
	leases := make(chan Lease, 2)
	for range 2 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			l, e := f.store.Claim(f.ctx, event)
			results <- e
			if e == nil {
				leases <- l
			}
		}()
	}
	wg.Wait()
	close(results)
	success, busy := 0, 0
	for e := range results {
		if e == nil {
			success++
		} else if errors.Is(e, ports.ErrBusy) {
			busy++
		} else {
			t.Fatal(e)
		}
	}
	if success != 1 || busy != 1 {
		t.Fatal("non-exclusive event lease", success, busy)
	}
	lease := <-leases
	// The immutable source hash is checked before any projection mutation.
	f.exec("UPDATE chat_business_events SET payload_json=CONCAT(payload_json,' ') WHERE event_id=?", event)
	if err = f.store.Consume(f.ctx, lease); !errors.Is(err, policy.ErrDenied) {
		t.Fatal("changed source accepted", err)
	}
	if err = f.store.deferLease(f.ctx, lease, policy.ErrDenied); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT status FROM chat_event_receipts WHERE event_id=?", event) != "rejected" {
		t.Fatal("poison event spins without quarantine")
	}
	t.Log("lower-ID late commit discovered without Redis; concurrent lease exclusive; tampered source quarantined")
}

func TestConfiguredProjectionWorkerLifecycle(t *testing.T) {
	f := newFixture(t)
	binary, _ := filepath.Abs("../../bin/chatd")
	if _, err := os.Stat(binary); err != nil {
		t.Fatal("build.sh must run before isolated worker lifecycle test")
	}
	cfg := map[string]any{"env": "test", "http": map[string]string{"listen": "127.0.0.1:19501", "internal_listen": "127.0.0.1:19502"},
		"dependencies": map[string]any{"operation_timeout": "3s", "mysql": map[string]any{"enabled": true, "address": os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR"), "database": f.name, "username": f.user, "password_env": "CHAT_S05_MYSQL_PASSWORD"}},
		"auth":         map[string]any{"enabled": true, "public_keys": map[string]string{"chat-v1": base64.StdEncoding.EncodeToString(make([]byte, 32))}}, "conversations": map[string]bool{"enabled": true}}
	body, err := json.Marshal(cfg)
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "worker.json")
	if os.WriteFile(path, body, 0600) != nil {
		t.Fatal("config fixture")
	}
	logFile, err := os.CreateTemp(t.TempDir(), "worker-log")
	if err != nil {
		t.Fatal(err)
	}
	defer logFile.Close()
	cmd := exec.CommandContext(f.ctx, binary, "worker", "--config", path)
	cmd.Env = append(os.Environ(), "CHAT_S05_MYSQL_PASSWORD=s05-fixture-only")
	cmd.Stdout = logFile
	cmd.Stderr = logFile
	if err = cmd.Start(); err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	t.Cleanup(func() { cmd.Process.Kill() })
	deadline := time.NewTimer(10 * time.Second)
	defer deadline.Stop()
	ticker := time.NewTicker(50 * time.Millisecond)
	defer ticker.Stop()
	for f.text("SELECT COUNT(*) FROM chat_event_receipts WHERE status='done'") != "1" {
		select {
		case err = <-done:
			t.Fatal("worker exited before projecting", err)
		case <-deadline.C:
			t.Fatal("worker failed to consume without Redis")
		case <-ticker.C:
		}
	}
	if err = cmd.Process.Signal(os.Interrupt); err != nil {
		t.Fatal(err)
	}
	select {
	case err = <-done:
		if err != nil {
			t.Fatal("worker shutdown", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("worker did not stop")
	}
	logFile.Sync()
	logs, err := os.ReadFile(logFile.Name())
	if err != nil || !strings.Contains(string(logs), `"mode":"conversation_projection"`) || !strings.Contains(string(logs), "worker_stopped") {
		t.Fatal("configured worker lifecycle not observed")
	}
	t.Log("actual chatd worker: independent table grants, configured projection without Redis, clean shutdown")
}
