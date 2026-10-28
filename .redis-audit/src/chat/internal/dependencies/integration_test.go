package dependencies

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	mysql "github.com/go-sql-driver/mysql"
)

// Explicit opt-in, loopback only, isolated fixture database/user. Never load a
// production config or migrate the application schema from a test.
func TestMySQL57RedisIntegration(t *testing.T) {
	if os.Getenv("CHAT_INTEGRATION") != "1" {
		t.Skip("set CHAT_INTEGRATION=1 for isolated real services")
	}
	ma, ra := os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR"), os.Getenv("CHAT_INTEGRATION_REDIS_ADDR")
	if !loopback(ma) || !loopback(ra) {
		t.Fatal("integration requires explicit loopback endpoints")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	c := mysql.NewConfig()
	c.Net = "tcp"
	c.Addr = ma
	c.User = "root"
	c.Passwd = os.Getenv("CHAT_INTEGRATION_ADMIN_PASSWORD")
	c.Timeout = time.Second
	c.ReadTimeout = time.Second
	c.WriteTimeout = time.Second
	c.Logger = silentMySQLLog{}
	connector, err := mysql.NewConnector(c)
	if err != nil {
		t.Fatal("admin connector failed")
	}
	admin := sql.OpenDB(connector)
	t.Cleanup(func() { _ = admin.Close() })
	if err := admin.PingContext(ctx); err != nil {
		var dbError *mysql.MySQLError
		if errors.As(err, &dbError) {
			t.Fatalf("isolated MySQL admin error code=%d", dbError.Number)
		}
		t.Fatalf("isolated MySQL unavailable: error type %T", err)
	}
	suffix := strconv.FormatInt(time.Now().UnixNano(), 36)
	database, user := "bixuan_test_s02_"+suffix, "bxchat_test_"+suffix
	var b [16]byte
	if _, err = rand.Read(b[:]); err != nil {
		t.Fatal("fixture secret failed")
	}
	pw := hex.EncodeToString(b[:])
	fixtureHost := os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST")
	if fixtureHost == "" {
		fixtureHost = "127.0.0.1"
	}
	if fixtureHost != "127.0.0.1" && fixtureHost != "%" {
		t.Fatal("isolated fixture host")
	}
	exec := func(query string) {
		t.Helper()
		if _, err := admin.ExecContext(ctx, query); err != nil {
			t.Fatal("isolated fixture operation failed")
		}
	}
	exec("CREATE DATABASE `" + database + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
	t.Cleanup(func() { _, _ = admin.Exec("DROP DATABASE IF EXISTS `" + database + "`") })
	exec("CREATE USER '" + user + "'@'" + fixtureHost + "' IDENTIFIED BY '" + pw + "'")
	t.Cleanup(func() { _, _ = admin.Exec("DROP USER IF EXISTS '" + user + "'@'" + fixtureHost + "'") })
	exec("CREATE TABLE `" + database + "`.chat_contract_probe(id BIGINT UNSIGNED PRIMARY KEY, body TEXT, created_at DATETIME(6)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")
	exec("CREATE TABLE `" + database + "`.orders(id BIGINT PRIMARY KEY)")
	exec("INSERT INTO `" + database + "`.orders(id) VALUES(1)")
	exec("CREATE TABLE `" + database + "`.user_wallets(id BIGINT PRIMARY KEY)")
	exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `" + database + "`.chat_contract_probe TO '" + user + "'@'" + fixtureHost + "'")
	exec("GRANT SELECT ON `" + database + "`.orders TO '" + user + "'@'" + fixtureHost + "'")
	t.Setenv("CHAT_MYSQL_PASSWORD", pw)
	s := Defaults()
	s.MySQL.Enabled = true
	s.MySQL.Address = ma
	s.MySQL.Database = database
	s.MySQL.Username = user
	s.MySQL.MaxOpenServe = 2
	s.Redis.Enabled = true
	s.Redis.Address = ra
	s.Redis.KeyPrefix = "bxchat:test:"
	r, err := Open(s, "test", "serve", "disabled")
	if err != nil {
		t.Fatal("resource open failed")
	}
	defer r.Close()
	if got := r.Refresh(ctx); !got.InfrastructureReady || got.Redis.State != "ready" || got.BusinessEnabled {
		t.Fatalf("real dependency contract failed: %#v", got)
	}
	var version string
	if r.sql.QueryRowContext(ctx, "SELECT VERSION()").Scan(&version) != nil {
		t.Fatal("version query failed")
	}
	t.Log("MySQL verified:", version)
	stamp := time.Date(2026, 10, 21, 1, 2, 3, 123456000, time.UTC)
	body := "墙纸🧱 尺寸12.5 金额100"
	if _, err = r.sql.ExecContext(ctx, "INSERT INTO chat_contract_probe(id,body,created_at) VALUES (?,?,?)", "18446744073709551615", body, stamp); err != nil {
		t.Fatal("utf8mb4/bigint/time insert failed")
	}
	var gotID, gotBody string
	var gotTime time.Time
	if r.sql.QueryRowContext(ctx, "SELECT id,body,created_at FROM chat_contract_probe").Scan(&gotID, &gotBody, &gotTime) != nil || gotID != "18446744073709551615" || gotBody != body || !gotTime.Equal(stamp) || gotTime.Location() != time.UTC {
		t.Fatal("cross-language data contract failed")
	}
	for _, query := range []string{"UPDATE orders SET id=2", "UPDATE user_wallets SET id=2", "CREATE TABLE forbidden_ddl(id INT)"} {
		_, err = r.sql.ExecContext(ctx, query)
		var denied *mysql.MySQLError
		if !errors.As(err, &denied) || denied.Number != 1142 {
			t.Fatal("business write/DDL not denied by MySQL")
		}
	}
	tx, err := r.sql.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal("read-lock transaction failed")
	}
	var orderID int
	err = tx.QueryRowContext(ctx, "SELECT id FROM orders WHERE id=1 FOR UPDATE").Scan(&orderID)
	_ = tx.Rollback()
	if err != nil || orderID != 1 {
		t.Fatal("SELECT-only permission cannot lock authority row")
	}
	t.Run("pool_and_cancellation", func(t *testing.T) {
		one, e := r.sql.Conn(ctx)
		if e != nil {
			t.Fatal("lease failed")
		}
		two, e := r.sql.Conn(ctx)
		if e != nil {
			one.Close()
			t.Fatal("lease failed")
		}
		short, stop := context.WithTimeout(ctx, 40*time.Millisecond)
		_, e = r.sql.Conn(short)
		stop()
		one.Close()
		two.Close()
		if e == nil {
			t.Fatal("pool admission deadline ignored")
		}
		var wg sync.WaitGroup
		for range 8 {
			wg.Add(1)
			go func() { defer wg.Done(); var n int; _ = r.sql.QueryRowContext(ctx, "SELECT SLEEP(0.02)").Scan(&n) }()
		}
		wg.Wait()
		if r.sql.Stats().OpenConnections > 2 {
			t.Fatal("MySQL pool exceeded budget")
		}
		short, stop = context.WithTimeout(ctx, 40*time.Millisecond)
		defer stop()
		var n int
		if r.sql.QueryRowContext(short, "SELECT SLEEP(1)").Scan(&n) == nil {
			t.Fatal("query deadline ignored")
		}
	})
	key := s.Redis.KeyPrefix + "s02:" + suffix
	if r.redis.Set(ctx, key, "临时票据", time.Minute).Err() != nil {
		t.Fatal("Redis set failed")
	}
	defer r.redis.Del(context.Background(), key)
	if value, e := r.redis.GetDel(ctx, key).Result(); e != nil || value != "临时票据" {
		t.Fatal("Redis atomic consume failed")
	}
	if r.redis.PoolStats().TotalConns > uint32(s.Redis.PoolServe) {
		t.Fatal("Redis pool exceeded cap")
	}
	info, e := r.redis.Info(ctx, "server").Result()
	if e != nil {
		t.Fatal("Redis version query failed")
	}
	for _, line := range strings.Split(info, "\r\n") {
		if strings.HasPrefix(line, "redis_version:") {
			t.Log(line)
		}
	}
	degraded := s
	degraded.Redis.Address = "127.0.0.1:1"
	d, e := Open(degraded, "test", "serve", "disabled")
	if e != nil {
		t.Fatal("degraded resource open failed")
	}
	defer d.Close()
	if got := d.Refresh(ctx); !got.InfrastructureReady || got.Redis.State != "unavailable" {
		t.Fatal("Redis failure blocked required dependency")
	}
	failed := s
	failed.MySQL.Address = "127.0.0.1:1"
	f, e := Open(failed, "test", "serve", "disabled")
	if e != nil {
		t.Fatal("failed resource open failed")
	}
	defer f.Close()
	if got := f.Refresh(ctx); got.InfrastructureReady || got.MySQL.State != "unavailable" {
		t.Fatal("MySQL outage reported ready")
	}
	if binary := os.Getenv("CHAT_INTEGRATION_CHATD_BIN"); binary != "" {
		testProcessReadiness(t, binary, s)
	}
	exec("GRANT UPDATE ON `" + database + "`.orders TO '" + user + "'@'" + fixtureHost + "'")
	if got := r.Refresh(ctx); got.InfrastructureReady || got.MySQL.Code != "CHAT_DATABASE_CONTRACT_INVALID" {
		t.Fatal("overprivileged account reported ready")
	}
}

func testProcessReadiness(t *testing.T, binary string, s Settings) {
	t.Helper()
	if !filepath.IsAbs(binary) {
		t.Fatal("binary must have an absolute local path")
	}
	for _, test := range []struct {
		name         string
		mysql, redis bool
		status       int
	}{{"healthy", true, true, 200}, {"redis_degraded", true, false, 200}, {"mysql_down", false, true, 503}} {
		t.Run("process_"+test.name, func(t *testing.T) {
			deps := s
			if !test.mysql {
				deps.MySQL.Address = "127.0.0.1:1"
			}
			if !test.redis {
				deps.Redis.Address = "127.0.0.1:1"
			}
			addresses := []string{}
			for range 2 {
				listener, e := net.Listen("tcp", "127.0.0.1:0")
				if e != nil {
					t.Fatal("test port allocation failed")
				}
				addresses = append(addresses, listener.Addr().String())
				_ = listener.Close()
			}
			cfg := map[string]any{"env": "test", "http": map[string]string{"listen": addresses[0], "internal_listen": addresses[1]}, "shutdown_timeout": "1s", "log_level": "error", "provider_mode": "disabled", "dependencies": deps}
			data, _ := json.Marshal(cfg)
			path := filepath.Join(t.TempDir(), "chat.json")
			if os.WriteFile(path, data, 0600) != nil {
				t.Fatal("process config write failed")
			}
			cmd := exec.Command(binary, "serve", "--config", path)
			cmd.Stdout = io.Discard
			cmd.Stderr = io.Discard
			if cmd.Start() != nil {
				t.Fatal("process start failed")
			}
			done := make(chan error, 1)
			go func() { done <- cmd.Wait() }()
			defer func() {
				_ = cmd.Process.Signal(syscall.SIGTERM)
				select {
				case err := <-done:
					if err != nil {
						t.Error("process exited unsuccessfully")
					}
				case <-time.After(3 * time.Second):
					_ = cmd.Process.Kill()
					<-done
					t.Error("process shutdown timed out")
				}
			}()
			client := &http.Client{Timeout: time.Second, Transport: &http.Transport{Proxy: nil}}
			defer client.CloseIdleConnections()
			url := "http://" + addresses[1] + "/readyz"
			var response *http.Response
			var err error
			deadline := time.Now().Add(5 * time.Second)
			for time.Now().Before(deadline) {
				response, err = client.Get(url)
				if err == nil {
					break
				}
				time.Sleep(10 * time.Millisecond)
			}
			if err != nil {
				t.Fatal("health endpoint unavailable")
			}
			var snapshot Snapshot
			err = json.NewDecoder(response.Body).Decode(&snapshot)
			_ = response.Body.Close()
			if err != nil || response.StatusCode != test.status || snapshot.BusinessEnabled || snapshot.Scope != "infrastructure_only" || snapshot.InfrastructureReady != test.mysql {
				t.Fatal("process readiness contract failed")
			}
			if test.redis != (snapshot.Redis.State == "ready") {
				t.Fatal("Redis degraded state missing")
			}
			for _, path := range []string{"/readyz", "/chat/api/v1/messages", "/chat/ws"} {
				resp, e := client.Get("http://" + addresses[0] + path)
				if e != nil {
					t.Fatal("public endpoint failed")
				}
				_ = resp.Body.Close()
				if resp.StatusCode != 404 {
					t.Fatal("private/business endpoint exposed")
				}
			}
		})
	}
}

func TestRedisPasswordCompatibility(t *testing.T) {
	address := os.Getenv("CHAT_REDIS_AUTH_TEST_ADDRESS")
	if address == "" {
		t.Skip("isolated password-protected Redis not provided")
	}
	if !loopback(address) {
		t.Fatal("isolated loopback required")
	}
	secret := os.Getenv("CHAT_REDIS_AUTH_TEST_PASSWORD")
	if secret == "" {
		t.Fatal("isolated Redis test password required")
	}
	s := Defaults()
	s.Redis.Enabled, s.Redis.Address, s.Redis.KeyPrefix = true, address, "bxchat:test:"
	s.Redis.PasswordEnv = "CHAT_REDIS_AUTH_TEST_PASSWORD"
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	good, err := openRedis(s, "serve")
	if err != nil {
		t.Fatal("password client construction failed")
	}
	defer good.Close()
	if good.Ping(ctx).Err() != nil {
		t.Fatal("configured Redis password was not applied")
	}
	store, err := auth.NewRedisStore(good, "test", s.Redis.KeyPrefix)
	if err != nil {
		t.Fatal(err)
	}
	suffix := strconv.FormatInt(time.Now().UnixNano(), 36)
	digest := sha256.Sum256([]byte(suffix))
	key := "nonce:" + hex.EncodeToString(digest[:])
	defer good.Del(context.Background(), s.Redis.KeyPrefix+"auth:"+key)
	if ok, err := store.Put(ctx, key, "password-ok", time.Second); err != nil || !ok {
		t.Fatal("authenticated SET NX failed")
	}
	if value, err := store.Take(ctx, key); err != nil || value != "password-ok" {
		t.Fatal("authenticated GETDEL failed")
	}
	for _, test := range []struct {
		name string
		value string
		configured bool
	}{
		{"wrong_password", secret+"-wrong", true},
		{"no_password", "", false},
	} {
		t.Run(test.name, func(t *testing.T) {
			local := s
			if test.configured {
				t.Setenv("CHAT_REDIS_AUTH_TEST_PASSWORD", test.value)
			} else {
				local.Redis.PasswordEnv = ""
			}
			bad, err := openRedis(local, "serve")
			if err != nil {
				t.Fatal("credential failure must be checked at command boundary")
			}
			defer bad.Close()
			if bad.Ping(ctx).Err() == nil {
				t.Fatal("password-protected Redis accepted invalid credentials")
			}
			denied, err := auth.NewRedisStore(bad, "test", local.Redis.KeyPrefix)
			if err != nil {
				t.Fatal(err)
			}
			if _, err := denied.Put(ctx, key, "must-not-store", time.Second); err != ports.ErrUnavailable {
				t.Fatal("invalid Redis credentials did not fail closed")
			}
			if _, err := denied.Take(ctx, key); err != ports.ErrUnavailable {
				t.Fatal("invalid Redis credentials accepted a nonce")
			}
		})
	}
}
