package calls

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/ports"
	mysql "github.com/go-sql-driver/mysql"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

type fixtureAuthority struct{}

func (fixtureAuthority) Validate(_ context.Context, p ports.Principal) error {
	if p.Kind != "mini" || p.Environment != "test" || len(p.Scopes) != 1 || p.Scopes[0] != "chat:use" || !time.Now().Before(p.ExpiresAt) {
		return ErrUnavailable
	}
	return nil
}
func (g fixtureAuthority) Pair(ctx context.Context, tx *sql.Tx, p ports.Principal, cid, peer ports.ID, send bool) (ports.Grant, ports.Grant, error) {
	if e := g.Validate(ctx, p); e != nil {
		return ports.Grant{}, ports.Grant{}, e
	}
	var id string
	if e := tx.QueryRowContext(ctx, "SELECT CAST(id AS CHAR) FROM chat_conversations WHERE id=? FOR UPDATE", string(cid)).Scan(&id); e != nil {
		return ports.Grant{}, ports.Grant{}, e
	}
	grants := []ports.Grant{}
	for _, actor := range []ports.ID{p.ActorID, peer} {
		var key string
		var read, write bool
		if e := tx.QueryRowContext(ctx, "SELECT member_key,can_read,can_send FROM chat_members WHERE conversation_id=? AND actor_id=?", string(cid), string(actor)).Scan(&key, &read, &write); e != nil {
			return ports.Grant{}, ports.Grant{}, e
		}
		if !read || (send && !write) {
			return ports.Grant{}, ports.Grant{}, ErrUnavailable
		}
		grants = append(grants, ports.Grant{MemberKey: key, CanRead: read, CanSend: write})
	}
	return grants[0], grants[1], nil
}

type phpBrokerFixture struct {
	t        *testing.T
	database string
	mu       sync.Mutex
	drop     string
}

func (b *phpBrokerFixture) call(ctx context.Context, q any, out any) error {
	raw, _ := json.Marshal(q)
	script, _ := filepath.Abs("../../../server/tests/chat_calls_mysql_contract.php")
	cmd := exec.CommandContext(ctx, os.Getenv("CHAT_CALLS_TEST_PHP"), script, "--resource", string(raw))
	cmd.Env = append(os.Environ(), "CHAT_CALLS_DATABASE="+b.database)
	data, e := cmd.CombinedOutput()
	if e != nil {
		var fault struct {
			Code string `json:"code"`
		}
		if json.Unmarshal(data, &fault) == nil && strings.HasPrefix(fault.Code, "CALL_") {
			return errors.New(fault.Code)
		}
		return fmt.Errorf("synthetic PHP broker: %v %s", e, data)
	}
	if e = json.Unmarshal(data, out); e != nil {
		return e
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if request, ok := q.(ResourceRequest); ok && request.Action == b.drop {
		b.drop = ""
		return ErrUnavailable
	}
	return nil
}
func (b *phpBrokerFixture) Availability(ctx context.Context) (Availability, error) {
	var r Availability
	e := b.call(ctx, map[string]string{"action": "availability"}, &r)
	return r, e
}
func (b *phpBrokerFixture) Resource(ctx context.Context, q ResourceRequest) (Permit, error) {
	var r Permit
	e := b.call(ctx, q, &r)
	return r, e
}
func (b *phpBrokerFixture) Identities(context.Context, ResourceRequest) (map[string]NativePerson, error) {
	return nil, ErrUnavailable
}

type fixtureMedia struct{ stopped bool }

func (*fixtureMedia) Ready(Provider) bool { return true }
func (*fixtureMedia) Credentials(_ context.Context, s Session, p Permit, a ports.ID) (any, error) {
	return map[string]any{"provider": s.Provider, "token": "synthetic-not-a-provider-token", "uid": a, "expires_at": p.Until}, nil
}
func (m *fixtureMedia) Stop(context.Context, Session, Permit) (bool, error) { return m.stopped, nil }

func TestMySQLPHPCallLifecycleAndConcurrency(t *testing.T) {
	address := os.Getenv("CHAT_CALLS_MYSQL_ADDR")
	if address == "" {
		t.Skip("explicit isolated calls MySQL required")
	}
	host, _, e := net.SplitHostPort(address)
	if e != nil || host != "127.0.0.1" || os.Getenv("CHAT_CALLS_TEST_PHP") == "" {
		t.Fatal("isolated endpoint/PHP required")
	}
	cfg := mysql.NewConfig()
	cfg.Net = "tcp"
	cfg.Addr = address
	cfg.User = "root"
	cfg.Passwd = os.Getenv("CHAT_CALLS_MYSQL_PASSWORD")
	cfg.ParseTime = true
	cfg.Params = map[string]string{"time_zone": "'+00:00'"}
	cfg.Apply(mysql.Charset("utf8mb4", "utf8mb4_unicode_ci"))
	connector, e := mysql.NewConnector(cfg)
	if e != nil {
		t.Fatal(e)
	}
	root := sql.OpenDB(connector)
	defer root.Close()
	name := "bixuan_test_calls_go_" + strconv.FormatInt(time.Now().UnixNano(), 36)
	if _, e = root.Exec("CREATE DATABASE `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); e != nil {
		t.Fatal(e)
	}
	defer root.Exec("DROP DATABASE `" + name + "`")
	cfg.DBName = name
	connector, _ = mysql.NewConnector(cfg)
	db := sql.OpenDB(connector)
	db.SetMaxOpenConns(16)
	defer db.Close()
	run := func(q string, args ...any) {
		t.Helper()
		if _, e := db.Exec(q, args...); e != nil {
			t.Fatal(e)
		}
	}
	schema, e := os.ReadFile(os.Getenv("CHAT_CALLS_SCHEMA_FILE"))
	if e != nil {
		t.Fatal(e)
	}
	for _, q := range strings.Split(string(schema), ";") {
		if strings.TrimSpace(q) != "" {
			run(q)
		}
	}
	run("CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,policy_version BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB")
	run("CREATE TABLE chat_members(conversation_id BIGINT UNSIGNED,actor_id BIGINT UNSIGNED,member_key VARCHAR(128),can_read TINYINT,can_send TINYINT,PRIMARY KEY(conversation_id,actor_id)) ENGINE=InnoDB")
	run("INSERT INTO chat_conversations(id) VALUES(1)")
	run("INSERT INTO chat_members VALUES(1,11,'member:11',1,1),(1,12,'member:12',1,1)")
	now := time.Now().Unix()
	facts := map[string]any{"schema_version": 1, "provider": "wechat", "quota_scope_id": "synthetic-app", "cycle_id": "synthetic-cycle", "config_revision": "1", "unit": "wechat_equivalent", "enabled": true, "verified": true, "hard_stop_verified": true, "healthy": true, "valid_from": now - 3600, "expires_at": now + 3600, "verified_at": now, "usage_observed_at": now, "max_age_seconds": 3600, "free_total": "6000000", "budget_limit": "6000000", "official_used": "0", "local_uncovered": "0", "reserved": "0", "safety_margin": "0", "external_uncertainty": "0"}
	profile := map[string]any{"app_id": strings.Repeat("a", 32), "evidence_hash": strings.Repeat("f", 64), "audio": Meter{1, 1, 60}, "video": Meter{1, 1, 60}}
	fjson, _ := json.Marshal(facts)
	pjson, _ := json.Marshal(profile)
	run("INSERT INTO chat_call_resource_pools(provider,scope_key,cycle_key,revision,facts_json,meter_json,observation_at,updated_at) VALUES('wechat','synthetic-app','synthetic-cycle',1,?,?,?,UTC_TIMESTAMP(6))", fjson, pjson, now)
	facts["provider"] = "shengwang"
	facts["quota_scope_id"] = "synthetic-sw"
	facts["cycle_id"] = "synthetic-sw-cycle"
	facts["unit"] = "shengwang_standard"
	profile["app_id"] = strings.Repeat("b", 32)
	profile["audio"] = Meter{143, 100, 1}
	profile["video"] = Meter{429, 100, 1}
	swfacts, _ := json.Marshal(facts)
	swprofile, _ := json.Marshal(profile)
	run("INSERT INTO chat_call_resource_pools(provider,scope_key,cycle_key,revision,facts_json,meter_json,observation_at,updated_at) VALUES('shengwang','synthetic-sw','synthetic-sw-cycle',1,?,?,?,UTC_TIMESTAMP(6))", swfacts, swprofile, now)
	run("UPDATE chat_call_settings SET config_json=JSON_SET(config_json,'$.enabled',CAST('true' AS JSON)) WHERE id=1")
	broker := &phpBrokerFixture{t: t, database: name}
	media := &fixtureMedia{}
	service, e := NewService(db, fixtureAuthority{}, broker, media)
	if e != nil {
		t.Fatal(e)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Second)
	defer cancel()
	actor := func(id ports.ID) ports.Principal {
		return ports.Principal{ActorID: id, SourceID: id, Kind: "mini", Environment: "test", Scopes: []string{"chat:use"}, ExpiresAt: time.Now().Add(time.Hour)}
	}
	caller, callee := actor("11"), actor("12")
	create := Request{ConversationID: "1", PeerID: "12", Media: Audio, Instance: strings.Repeat("a", 32), Operation: sha256String("initial-call")}
	view, e := service.Create(ctx, caller, create)
	if e != nil {
		t.Fatal(e)
	}
	again, e := service.Create(ctx, caller, create)
	if e != nil || again.CallID != view.CallID {
		t.Fatalf("create replay %+v %v", again, e)
	}
	if _, e = service.Create(ctx, caller, Request{ConversationID: "1", PeerID: "12", Media: Video, Instance: create.Instance, Operation: create.Operation}); e == nil {
		t.Fatal("same operation different media")
	}
	if _, e = service.Create(ctx, callee, Request{ConversationID: "1", PeerID: "11", Media: Audio, Instance: strings.Repeat("b", 32), Operation: sha256String("mutual")}); !errors.Is(e, ErrBusy) {
		t.Fatalf("mutual call: %v", e)
	}
	action := func(p ports.Principal, name string, instance string) View {
		t.Helper()
		current, e := service.Status(ctx, p, Request{CallID: view.CallID})
		if e != nil {
			t.Fatal(e)
		}
		out, e := service.Action(ctx, p, name, Request{CallID: view.CallID, Epoch: ports.ID(current.Epoch), Revision: ports.ID(current.Revision), Instance: instance, Operation: sha256String(string(view.CallID) + ":" + name + ":" + string(p.ActorID) + ":" + current.Revision)})
		if e != nil {
			t.Fatalf("%s: %v", name, e)
		}
		return out
	}
	action(callee, "accept", strings.Repeat("b", 32))
	broker.drop = "reserve"
	prepare := Request{CallID: view.CallID, Epoch: "1", Instance: create.Instance}
	if _, e = service.Prepare(ctx, caller, prepare); e == nil {
		t.Fatal("lost PHP acknowledgment must be unknown")
	}
	unknown, _ := service.Status(ctx, caller, Request{CallID: view.CallID})
	if unknown.Epoch != "1" || unknown.Provider != Wechat {
		t.Fatal("unknown reserve triggered unsafe fallback")
	}
	var reserved int64
	if e = db.QueryRow("SELECT reserved_units FROM chat_call_resource_pools WHERE id=1").Scan(&reserved); e != nil || reserved != 24000 {
		t.Fatalf("lost reserve retained %d %v", reserved, e)
	}
	if _, e = service.Prepare(ctx, caller, prepare); e != nil {
		t.Fatal("retry same durable resource operation", e)
	}
	if _, e = service.Prepare(ctx, callee, Request{CallID: view.CallID, Epoch: "1", Instance: strings.Repeat("b", 32)}); e != nil {
		t.Fatal(e)
	}
	if _, e = service.Prepare(ctx, callee, Request{CallID: view.CallID, Epoch: "1", Instance: strings.Repeat("c", 32)}); e == nil {
		t.Fatal("another callee device stole media")
	}
	if _, e = service.Prepare(ctx, caller, Request{CallID: view.CallID, Epoch: "2", Instance: create.Instance}); e == nil {
		t.Fatal("old/new epoch bypass")
	}
	action(caller, "prepared", create.Instance)
	action(callee, "prepared", strings.Repeat("b", 32))
	action(caller, "ready", create.Instance)
	active := action(callee, "ready", strings.Repeat("b", 32))
	if active.State != Active || active.Prepared != 2 {
		t.Fatalf("dual ready %+v", active)
	}
	ended := action(caller, "end", create.Instance)
	if ended.State != Stopping {
		t.Fatal("client end prematurely released media")
	}
	if e = service.Tick(ctx); e != nil {
		t.Fatal(e)
	}
	current, e := service.Status(ctx, caller, Request{CallID: view.CallID})
	if e != nil || current.State != Stopping {
		t.Fatal("unknown provider stop must persist")
	}
	var occupants int
	if e = db.QueryRow("SELECT COUNT(*) FROM chat_call_accounts WHERE active_call_id IS NOT NULL").Scan(&occupants); e != nil || occupants != 2 {
		t.Fatalf("unknown stop occupancy %d %v", occupants, e)
	}
	if e = service.ConfirmStopped(ctx, view.CallID, "2", strings.Repeat("f", 64)); e == nil {
		t.Fatal("wrong confirmation epoch")
	}
	if e = service.ConfirmStopped(ctx, view.CallID, "1", strings.Repeat("f", 64)); e != nil {
		t.Fatal(e)
	}
	if e = service.Tick(ctx); e != nil {
		t.Fatal(e)
	}
	if e = db.QueryRow("SELECT COUNT(*) FROM chat_call_accounts WHERE active_call_id IS NOT NULL").Scan(&occupants); e != nil || occupants != 0 {
		t.Fatalf("settled occupancy %d %v", occupants, e)
	}
	if e = db.QueryRow("SELECT reserved_units FROM chat_call_resource_pools WHERE id=1").Scan(&reserved); e != nil || reserved != 0 {
		t.Fatalf("settled R %d %v", reserved, e)
	}
	// A callee can reject directly without first accepting or owning a leg.
	rejectq := create
	rejectq.Operation = sha256String("direct-reject")
	view, e = service.Create(ctx, caller, rejectq)
	if e != nil {
		t.Fatal(e)
	}
	rejected := action(callee, "reject", strings.Repeat("b", 32))
	if rejected.State != Rejected {
		t.Fatal("callee could not directly reject")
	}
	if e = service.Tick(ctx); e != nil {
		t.Fatal(e)
	}
	// Only a known pre-media refusal may cross providers after old fencing ACK.
	fq := create
	fq.Operation = sha256String("fallback-fixture")
	view, e = service.Create(ctx, caller, fq)
	if e != nil {
		t.Fatal(e)
	}
	action(callee, "accept", strings.Repeat("b", 32))
	run("UPDATE chat_call_resource_pools SET facts_json=JSON_SET(facts_json,'$.enabled',CAST('false' AS JSON)) WHERE id=1")
	if _, e = service.Prepare(ctx, caller, Request{CallID: view.CallID, Epoch: "1", Instance: create.Instance}); !errors.Is(e, ErrConflict) {
		t.Fatalf("pre-media fallback %v", e)
	}
	switched, e := service.Status(ctx, caller, Request{CallID: view.CallID})
	if e != nil || switched.Provider != Shengwang || switched.Epoch != "2" || switched.State != Preparing {
		t.Fatalf("fallback epoch %+v %v", switched, e)
	}
	if _, e = service.Prepare(ctx, caller, Request{CallID: view.CallID, Epoch: "1", Instance: create.Instance}); e == nil {
		t.Fatal("old provider epoch was accepted")
	}
	var oldState string
	if e = db.QueryRow("SELECT state FROM chat_call_resource_calls WHERE call_id=? AND attempt_epoch=1", string(view.CallID)).Scan(&oldState); e != nil || oldState != "closed" {
		t.Fatal("old attempt was not durably fenced")
	}
	action(caller, "end", create.Instance)
	if e = service.Tick(ctx); e != nil {
		t.Fatal(e)
	}
	run("UPDATE chat_call_resource_pools SET facts_json=JSON_SET(facts_json,'$.enabled',CAST('true' AS JSON)) WHERE id=1")
	// Eight simultaneous independent operations: exactly one may acquire both actors.
	var wg sync.WaitGroup
	success := make(chan View, 8)
	failures := make(chan error, 8)
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			q := create
			q.Operation = sha256String(fmt.Sprintf("parallel-%d", i))
			v, e := service.Create(ctx, caller, q)
			if e == nil {
				success <- v
			} else {
				failures <- e
			}
		}(i)
	}
	wg.Wait()
	close(success)
	close(failures)
	if len(success) != 1 {
		t.Fatalf("concurrent calls admitted %d", len(success))
	}
	for e := range failures {
		if !errors.Is(e, ErrBusy) {
			t.Fatalf("unexpected concurrency failure: %v", e)
		}
	}
	view = <-success
	run("UPDATE chat_members SET member_key='new-generation' WHERE actor_id=11")
	if _, e = service.Status(ctx, caller, Request{CallID: view.CallID}); e == nil {
		t.Fatal("rejoined identity inherited old call")
	}
	t.Log("real MySQL + actual PHP ledger: idempotency, unknown reserve, dual-device, epoch, readiness, unknown stop, evidence, settlement and concurrent busy locks passed")
}
