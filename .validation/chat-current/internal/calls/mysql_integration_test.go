package calls

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/policy"
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
func (g fixtureAuthority) Member(ctx context.Context, tx *sql.Tx, p ports.Principal, cid ports.ID, send bool) (ports.Grant, error) {
	if e := g.Validate(ctx, p); e != nil {
		return ports.Grant{}, e
	}
	var key string
	var read, write bool
	e := tx.QueryRowContext(ctx, "SELECT member_key,can_read,can_send FROM chat_members WHERE conversation_id=? AND actor_id=?", string(cid), string(p.ActorID)).Scan(&key, &read, &write)
	if errors.Is(e, sql.ErrNoRows) || (e == nil && (!read || (send && !write))) {
		return ports.Grant{}, policy.ErrDenied
	}
	if e != nil {
		return ports.Grant{}, e
	}
	return ports.Grant{Mode: "direct", Version: "1", MemberKey: key, CanRead: read, CanSend: write}, nil
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
		grants = append(grants, ports.Grant{Mode: "direct", Version: "1", MemberKey: key, CanRead: read, CanSend: write})
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

type fixturePublisher struct {
	committed   []ports.CommittedMessage
	callChanges []ports.CallChange
}

func (p *fixturePublisher) Committed(m ports.CommittedMessage) { p.committed = append(p.committed, m) }
func (p *fixturePublisher) CallChanged(m ports.CallChange)     { p.callChanges = append(p.callChanges, m) }

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
	schemaText := string(schema)
	if start := strings.Index(schemaText, "-- CHAT_BEGIN calls"); start >= 0 {
		end := strings.Index(schemaText[start:], "-- CHAT_END calls")
		if end < 0 {
			t.Fatal("call schema end marker missing")
		}
		schemaText = schemaText[start : start+end]
	}
	for _, q := range strings.Split(schemaText, ";") {
		if strings.TrimSpace(q) != "" {
			run(q)
		}
	}
	run("CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,scope_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'order:fixture',policy_version BIGINT UNSIGNED NOT NULL DEFAULT 1,last_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,last_change_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,last_message_id BIGINT UNSIGNED NULL,last_message_at DATETIME(6) NULL) ENGINE=InnoDB")
	run("CREATE TABLE chat_members(conversation_id BIGINT UNSIGNED,actor_id BIGINT UNSIGNED,member_key VARCHAR(128),can_read TINYINT,can_send TINYINT,PRIMARY KEY(conversation_id,actor_id)) ENGINE=InnoDB")
	run("CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,seq BIGINT UNSIGNED NOT NULL,sender_id BIGINT UNSIGNED NOT NULL,client_message_id VARCHAR(43) NOT NULL,policy_version BIGINT UNSIGNED NOT NULL,message_type VARCHAR(24) NOT NULL,text_body TEXT NULL,state VARCHAR(16) NOT NULL,state_version BIGINT UNSIGNED NOT NULL,safety_rule_version VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,retention_policy_version BIGINT UNSIGNED NOT NULL DEFAULT 0,retention_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'permanent',expires_at DATETIME(6) NULL,UNIQUE KEY uq_seq(conversation_id,seq),UNIQUE KEY uq_client(sender_id,conversation_id,client_message_id)) ENGINE=InnoDB")
	run("CREATE TABLE chat_storage_policy(id TINYINT UNSIGNED PRIMARY KEY,revision BIGINT UNSIGNED NOT NULL,policy_json JSON NOT NULL,apply_through_id BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB")
	run(`INSERT INTO chat_storage_policy(id,revision,policy_json,apply_through_id,updated_at) VALUES(1,7,'{"schema":1,"retention":{"text":{"mode":"permanent","days":0},"image":{"mode":"days","days":60},"audio":{"mode":"permanent","days":0},"video":{"mode":"days","days":60}},"tiering":{"enabled":false,"images":true,"audio":true,"videos":false,"age_days":60,"idle_days":30,"min_bytes":65536,"remaining_days":32,"batch":20},"quota":{"video_count":20,"video_bytes":104857600}}',0,UTC_TIMESTAMP(6))`)
	run("CREATE TABLE chat_reminder_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,seq BIGINT UNSIGNED NOT NULL,created_at DATETIME(6) NOT NULL,processed_at DATETIME(6) NULL,UNIQUE KEY uq_chat_reminder_event(conversation_id,seq)) ENGINE=InnoDB")
	run("CREATE TABLE chat_message_changes(conversation_id BIGINT UNSIGNED NOT NULL,change_seq BIGINT UNSIGNED NOT NULL,message_id BIGINT UNSIGNED NOT NULL,state_version BIGINT UNSIGNED NOT NULL,change_type VARCHAR(24) NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(conversation_id,change_seq)) ENGINE=InnoDB")
	run("INSERT INTO chat_conversations(id) VALUES(1),(2)")
	run("INSERT INTO chat_members VALUES(1,11,'member:11',1,1),(1,12,'member:12',1,1),(2,11,'member:11',1,1),(2,12,'member:12',1,1),(2,13,'member:13',1,1)")
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
	publisher := &fixturePublisher{}
	service, e := NewService(db, fixtureAuthority{}, broker, media)
	if e != nil {
		t.Fatal(e)
	}
	service = service.WithPublisher(publisher)
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Second)
	defer cancel()
	actor := func(id ports.ID) ports.Principal {
		return ports.Principal{ActorID: id, SourceID: id, Kind: "mini", Environment: "test", Scopes: []string{"chat:use"}, ExpiresAt: time.Now().Add(time.Hour)}
	}
	caller, callee := actor("11"), actor("12")
	// A non-member must not learn whether a guessed conversation has zero,
	// one or multiple peers from the availability response.
	outsider := actor("14")
	if _, err := service.Available(ctx, outsider, Request{ConversationID: "1"}); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("non-member learned peer count for direct conversation: %v", err)
	}
	if _, err := service.Available(ctx, outsider, Request{ConversationID: "999"}); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("non-member learned whether conversation exists: %v", err)
	}
	groupAvailability, err := service.Available(ctx, caller, Request{ConversationID: "2"})
	groupMap, ok := groupAvailability.(map[string]any)
	if err != nil || !ok || groupMap["reason"] != "no_peer" {
		t.Fatalf("authorized group availability %+v %v", groupAvailability, err)
	}
	create := Request{ConversationID: "1", PeerID: "12", Media: Audio, Instance: strings.Repeat("a", 32), Operation: sha256String("initial-call")}
	view, e := service.Create(ctx, caller, create)
	if e != nil {
		t.Fatal(e)
	}
	if view.PolicyVersion != "1" || len(publisher.callChanges) == 0 || publisher.callChanges[len(publisher.callChanges)-1].State != "ringing" {
		t.Fatalf("create did not publish immutable policy/state %+v changes=%+v", view, publisher.callChanges)
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
	var callPayload struct {
		Event     string `json:"event"`
		CallID    string `json:"call_id"`
		Outcome   string `json:"outcome"`
		Attention string `json:"attention_actor_id"`
	}
	var raw string
	if e = db.QueryRow("SELECT text_body FROM chat_messages WHERE message_type='system_event' ORDER BY id LIMIT 1").Scan(&raw); e != nil || json.Unmarshal([]byte(raw), &callPayload) != nil || callPayload.Event != "call" || callPayload.CallID != string(view.CallID) || callPayload.Outcome != "ended" {
		t.Fatalf("ended call message %q %+v %v", raw, callPayload, e)
	}
	var retention uint64
	var retentionMode string
	var retentionExpiry sql.NullTime
	if e = db.QueryRow("SELECT retention_policy_version,retention_mode,expires_at FROM chat_messages WHERE message_type='system_event' ORDER BY id LIMIT 1").Scan(&retention, &retentionMode, &retentionExpiry); e != nil || retention != 7 || retentionMode != "permanent" || retentionExpiry.Valid || callPayload.Attention != "" {
		t.Fatalf("system event retention/attention %d %q %+v %q %v", retention, retentionMode, retentionExpiry, callPayload.Attention, e)
	}
	var reminderCount int
	if e = db.QueryRow("SELECT COUNT(*) FROM chat_reminder_events").Scan(&reminderCount); e != nil || reminderCount != 0 {
		t.Fatalf("handled call created reminder %d %v", reminderCount, e)
	}
	if e = service.ensureTerminalMessage(ctx, view.CallID); e != nil {
		t.Fatal("idempotent terminal message", e)
	}
	if count := 0; db.QueryRow("SELECT COUNT(*) FROM chat_messages").Scan(&count) != nil || count != 1 || len(publisher.committed) != 1 {
		t.Fatalf("terminal message duplicated count=%d wake=%d", count, len(publisher.committed))
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
	var rejectedRaw string
	if e = db.QueryRow("SELECT text_body FROM chat_messages WHERE message_type='system_event' ORDER BY id DESC LIMIT 1").Scan(&rejectedRaw); e != nil || json.Unmarshal([]byte(rejectedRaw), &callPayload) != nil || callPayload.CallID != string(view.CallID) || callPayload.Outcome != "rejected" || callPayload.Attention != "" {
		t.Fatalf("rejected call message %q %+v %v", rejectedRaw, callPayload, e)
	}
	if e = db.QueryRow("SELECT COUNT(*) FROM chat_reminder_events").Scan(&reminderCount); e != nil || reminderCount != 0 {
		t.Fatalf("explicit rejection created unread reminder %d %v", reminderCount, e)
	}
	// A caller cancellation before answer is a missed incoming call for the callee.
	cancelq := create
	cancelq.Operation = sha256String("direct-cancel")
	view, e = service.Create(ctx, caller, cancelq)
	if e != nil {
		t.Fatal(e)
	}
	cancelled := action(caller, "end", create.Instance)
	if cancelled.State != Cancelled {
		t.Fatal("caller cancellation did not become terminal")
	}
	if e = service.Tick(ctx); e != nil {
		t.Fatal(e)
	}
	var cancelledRaw string
	if e = db.QueryRow("SELECT text_body FROM chat_messages WHERE message_type='system_event' ORDER BY id DESC LIMIT 1").Scan(&cancelledRaw); e != nil || json.Unmarshal([]byte(cancelledRaw), &callPayload) != nil || callPayload.CallID != string(view.CallID) || callPayload.Outcome != "cancelled" || callPayload.Attention != "12" {
		t.Fatalf("cancelled call attention %q %+v %v", cancelledRaw, callPayload, e)
	}
	if e = db.QueryRow("SELECT COUNT(*) FROM chat_reminder_events").Scan(&reminderCount); e != nil || reminderCount != 1 {
		t.Fatalf("missed incoming call reminder %d %v", reminderCount, e)
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
	if e != nil || switched.Provider != Shengwang || switched.Epoch != "2" || switched.State != Preparing || switched.PolicyVersion != view.PolicyVersion {
		t.Fatalf("fallback epoch/policy binding %+v original=%+v %v", switched, view, e)
	}
	if len(publisher.callChanges) == 0 {
		t.Fatal("fallback state was not published")
	}
	fallbackWake := publisher.callChanges[len(publisher.callChanges)-1]
	if fallbackWake.CallID != view.CallID || fallbackWake.State != "preparing" || fallbackWake.AttemptEpoch != "2" || fallbackWake.PolicyVersion != view.PolicyVersion {
		t.Fatalf("fallback wake lost state/epoch/policy %+v", fallbackWake)
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
