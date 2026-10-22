package policy

import (
	"context"
	"database/sql"
	"errors"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
	mysql "github.com/go-sql-driver/mysql"
)

func TestPHPGuardSharedLockAndVersions(t *testing.T) {
	if os.Getenv("CHAT_AUTH_TEST_INTEGRATION") != "1" {
		t.Skip("explicit isolated MySQL/PHP fixture not provided")
	}
	address := os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR")
	host, _, err := net.SplitHostPort(address)
	if err != nil || host != "127.0.0.1" {
		t.Fatal("explicit loopback required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	c := mysql.NewConfig()
	c.Net = "tcp"
	c.Addr = address
	c.User = "root"
	c.Passwd = os.Getenv("CHAT_INTEGRATION_ADMIN_PASSWORD")
	c.Timeout = time.Second
	c.ReadTimeout = 3 * time.Second
	c.WriteTimeout = time.Second
	connector, err := mysql.NewConnector(c)
	if err != nil {
		t.Fatal("fixture connector")
	}
	root := sql.OpenDB(connector)
	defer root.Close()
	root.SetMaxOpenConns(1)
	suffix := strconv.FormatInt(time.Now().UnixNano(), 36)
	name, user := "bixuan_test_policy_go_"+suffix, "bxchat_test_"+suffix
	pw := strings.Repeat("a", 32) + suffix
	fixtureHost := os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST")
	if fixtureHost == "" {
		fixtureHost = "127.0.0.1"
	}
	if fixtureHost != "127.0.0.1" && fixtureHost != "%" {
		t.Fatal("fixture user host")
	}
	execute := func(query string) {
		t.Helper()
		if _, e := root.ExecContext(ctx, query); e != nil {
			var code *mysql.MySQLError
			if errors.As(e, &code) {
				t.Fatalf("fixture MySQL code=%d", code.Number)
			}
			t.Fatal("fixture SQL failed")
		}
	}
	execute("CREATE DATABASE `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
	defer root.Exec("DROP DATABASE IF EXISTS `" + name + "`")
	execute("USE `" + name + "`")
	for _, query := range []string{
		"CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(24)) ENGINE=InnoDB",
		"CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED) ENGINE=InnoDB",
		"CREATE TABLE assignments(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,worker_id BIGINT UNSIGNED,status VARCHAR(24),accepted_at DATETIME) ENGINE=InnoDB",
		"CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,is_demo TINYINT,workflow_profile VARCHAR(24),order_source_type VARCHAR(24),lifecycle_status VARCHAR(40),payment_status VARCHAR(24),assignment_status VARCHAR(24),current_assignment_id BIGINT UNSIGNED,assigned_worker_id BIGINT UNSIGNED) ENGINE=InnoDB",
		"INSERT INTO users VALUES(1,'active'),(2,'active')", "INSERT INTO worker_profiles VALUES(10,2)",
		"INSERT INTO assignments VALUES(100,77,10,'accepted',UTC_TIMESTAMP())",
		"INSERT INTO orders VALUES(77,1,0,'construction','consumer','active','paid','accepted',100,10)",
	} {
		execute(query)
	}
	php := os.Getenv("CHAT_AUTH_TEST_PHP")
	if php == "" {
		t.Fatal("explicit PHP required")
	}
	script, err := filepath.Abs("../../../server/tests/chat_policy_fixture.php")
	if err != nil {
		t.Fatal("fixture path")
	}
	callPHP := func(action, expected string) {
		t.Helper()
		command := exec.CommandContext(ctx, php, script, action)
		command.Env = append(os.Environ(), "CHAT_POLICY_FIXTURE_DATABASE="+name)
		output, e := command.Output()
		if e != nil || strings.TrimSpace(string(output)) != expected {
			t.Fatal("real PHP guard transaction failed", action)
		}
	}
	callPHP("init", "committed")
	execute("CREATE USER '" + user + "'@'" + fixtureHost + "' IDENTIFIED BY '" + pw + "'")
	defer root.Exec("DROP USER IF EXISTS '" + user + "'@'" + fixtureHost + "'")
	execute("GRANT SELECT ON `" + name + "`.chat_policy_guards TO '" + user + "'@'" + fixtureHost + "'")
	rc := *c
	rc.DBName = name
	rc.User = user
	rc.Passwd = pw
	connector, err = mysql.NewConnector(&rc)
	if err != nil {
		t.Fatal("read connector")
	}
	db := sql.OpenDB(connector)
	defer db.Close()
	db.SetMaxOpenConns(2)
	r, _ := NewReader("test", 2*time.Second)
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		t.Fatal("guard transaction")
	}
	defer tx.Rollback()
	grant, err := r.AuthorizeLocked(ctx, tx, "order:77:assignment:100", "1", principal(), "send")
	if err != nil || !grant.CanSend {
		t.Fatal("actual PHP snapshot not understood", err)
	}
	// PHP really attempts closure and times out on the Go reader's shared lock.
	callPHP("close-blocked", "blocked")
	var lifecycle string
	if root.QueryRowContext(ctx, "SELECT lifecycle_status FROM orders WHERE id=77").Scan(&lifecycle) != nil || lifecycle != "active" {
		t.Fatal("blocked PHP close partially committed")
	}
	if tx.Commit() != nil {
		t.Fatal("release guard lease")
	}
	callPHP("close", "committed")
	check := func(version, action string, wantDenied bool, wantSend bool) {
		t.Helper()
		transaction, e := db.BeginTx(ctx, nil)
		if e != nil {
			t.Fatal("check transaction")
		}
		defer transaction.Rollback()
		g, e := r.AuthorizeLocked(ctx, transaction, "order:77:assignment:100", ports.ID(version), principal(), action)
		if wantDenied {
			if e != ErrDenied {
				t.Fatal("revoked/stale policy granted", e)
			}
		} else if e != nil || !g.CanRead || g.CanSend != wantSend {
			t.Fatal("closed history not bounded", g, e)
		}
	}
	check("1", "read", true, false)
	check("2", "send", true, false)
	check("2", "read", false, false)
	callPHP("repair", "committed")
	check("2", "read", true, false)
	check("3", "send", false, true)
	callPHP("repair-close", "committed")
	check("3", "send", true, false)
	check("4", "send", true, false)
	check("4", "read", false, false)
	if _, err = db.ExecContext(ctx, "UPDATE chat_policy_guards SET state='open'"); err == nil {
		t.Fatal("Go read account can mutate PHP authority")
	}
	execute("UPDATE chat_policy_guards SET snapshot_hash=REPEAT('f',64)")
	check("4", "read", true, false)
	execute("DELETE FROM chat_policy_guards")
	check("4", "read", true, false)
}
