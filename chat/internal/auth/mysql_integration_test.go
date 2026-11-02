package auth

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	mysql "github.com/go-sql-driver/mysql"
)

func TestMySQLAuthorityAndPHPIssuer(t *testing.T) {
	if os.Getenv("CHAT_AUTH_TEST_INTEGRATION") != "1" {
		t.Skip("isolated MySQL/PHP not provided")
	}
	address := os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR")
	host, port, e := net.SplitHostPort(address)
	if e != nil || host != "127.0.0.1" {
		t.Fatal("explicit isolated endpoint required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	config := mysql.NewConfig()
	config.Net = "tcp"
	config.Addr = address
	config.User = "root"
	config.Passwd = os.Getenv("CHAT_INTEGRATION_ADMIN_PASSWORD")
	config.Timeout = time.Second
	config.ReadTimeout = time.Second
	config.WriteTimeout = time.Second
	config.Params = map[string]string{"time_zone": "'+00:00'"}
	connector, e := mysql.NewConnector(config)
	if e != nil {
		t.Fatal("fixture connector")
	}
	root := sql.OpenDB(connector)
	root.SetMaxOpenConns(1)
	defer root.Close()
	fixtureHost := os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST")
	if fixtureHost == "" {
		fixtureHost = "127.0.0.1"
	}
	if fixtureHost != "127.0.0.1" && fixtureHost != "%" {
		t.Fatal("invalid fixture host")
	}
	suffix := strconv.FormatInt(time.Now().UnixNano(), 36)
	database, user := "bixuan_test_auth_"+suffix, "bxchat_test_"+suffix
	pw, _ := randomID()
	execute := func(query string) {
		t.Helper()
		if _, e := root.ExecContext(ctx, query); e != nil {
			var databaseError *mysql.MySQLError
			if errors.As(e, &databaseError) {
				t.Fatalf("isolated fixture MySQL code=%d", databaseError.Number)
			}
			t.Fatal("isolated fixture query failed")
		}
	}
	execute("CREATE DATABASE `" + database + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
	defer root.Exec("DROP DATABASE IF EXISTS `" + database + "`")
	execute("CREATE USER '" + user + "'@'" + fixtureHost + "' IDENTIFIED BY '" + pw + "'")
	defer root.Exec("DROP USER IF EXISTS '" + user + "'@'" + fixtureHost + "'")
	execute("CREATE TABLE `" + database + "`.system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value VARCHAR(20)) ENGINE=InnoDB")
	execute("INSERT INTO `" + database + "`.system_settings VALUES('chat.enabled','1')")
	execute("CREATE TABLE `" + database + "`.users(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(24)) ENGINE=InnoDB")
	execute("CREATE TABLE `" + database + "`.login_sessions(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin UNIQUE,status VARCHAR(24),expires_at DATETIME,last_seen_at DATETIME NULL,demo_actor_user_id BIGINT UNSIGNED NULL,test_actor_user_id BIGINT UNSIGNED NULL,test_instance_id BIGINT UNSIGNED NULL,test_actor_expires_at DATETIME NULL) ENGINE=InnoDB")
	execute("INSERT INTO `" + database + "`.users VALUES(18446744073709551615,'active')")
	loginToken, _ := randomID()
	hash := digest(loginToken)
	execute("INSERT INTO `" + database + "`.login_sessions(id,user_id,token_hash,status,expires_at) VALUES(7,18446744073709551615,'" + hash + "','active',UTC_TIMESTAMP()+INTERVAL 1 HOUR)")
	for _, table := range []string{"users", "login_sessions", "system_settings"} {
		execute("GRANT SELECT ON `" + database + "`." + table + " TO '" + user + "'@'" + fixtureHost + "'")
	}
	readConfig := *config
	readConfig.User = user
	readConfig.Passwd = pw
	readConfig.DBName = database
	connector, e = mysql.NewConnector(&readConfig)
	if e != nil {
		t.Fatal("source connector")
	}
	db := sql.OpenDB(connector)
	db.SetMaxOpenConns(2)
	defer db.Close()
	source, _ := NewMySQLSource(db)
	var seed [32]byte
	if _, e = rand.Read(seed[:]); e != nil {
		t.Fatal(e)
	}
	priv := ed25519.NewKeyFromSeed(seed[:])
	v, _ := NewVerifier("test", map[string]ed25519.PublicKey{"chat-v1": priv.Public().(ed25519.PublicKey)})
	service, _ := NewService(v, source, nil, 2, 500*time.Millisecond)
	php := os.Getenv("CHAT_AUTH_TEST_PHP")
	if php == "" {
		t.Fatal("explicit PHP runtime required")
	}
	path, e := filepath.Abs("../../../server/tests/chat_identity_contract.php")
	if e != nil {
		t.Fatal(e)
	}
	cmd := exec.CommandContext(ctx, php, path, "--issue-mini")
	cmd.Env = append(os.Environ(), "CHAT_AUTH_TEST_MYSQL_HOST=127.0.0.1", "CHAT_AUTH_TEST_MYSQL_PORT="+port, "CHAT_AUTH_TEST_MYSQL_DATABASE="+database, "CHAT_AUTH_TEST_MYSQL_USER="+user, "CHAT_AUTH_TEST_MYSQL_PASSWORD="+pw, "CHAT_AUTH_TEST_LOGIN_TOKEN="+loginToken, "CHAT_ACCESS_SEED="+base64.StdEncoding.EncodeToString(seed[:]))
	output, e := cmd.Output()
	if e != nil {
		t.Fatal("PHP real exchange failed")
	}
	var result struct {
		Token string `json:"access_token"`
	}
	if json.Unmarshal(output, &result) != nil || result.Token == "" {
		t.Fatal("exchange envelope")
	}
	c, e := service.Check(ctx, result.Token)
	if e != nil || c.ActorID != "18446744073709551615" || c.SourceID != "7" {
		t.Fatal("cross-language identity mismatch", e)
	}
	var lastSeen sql.NullString
	if db.QueryRowContext(ctx, "SELECT last_seen_at FROM login_sessions WHERE id=7").Scan(&lastSeen) != nil || lastSeen.Valid {
		t.Fatal("authentication wrote last_seen")
	}
	for _, tc := range []struct{ change, undo string }{
		{"UPDATE system_settings SET setting_value='0' WHERE setting_key='chat.enabled'", "UPDATE system_settings SET setting_value='1' WHERE setting_key='chat.enabled'"},
		{"UPDATE login_sessions SET status='revoked' WHERE id=7", "UPDATE login_sessions SET status='active' WHERE id=7"},
		{"UPDATE users SET status='disabled'", "UPDATE users SET status='active'"},
		{"UPDATE login_sessions SET demo_actor_user_id=2", "UPDATE login_sessions SET demo_actor_user_id=NULL"},
		{"UPDATE login_sessions SET test_instance_id=2", "UPDATE login_sessions SET test_instance_id=NULL"},
		{"UPDATE login_sessions SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND", "UPDATE login_sessions SET expires_at=UTC_TIMESTAMP()+INTERVAL 1 HOUR"},
		{"UPDATE login_sessions SET token_hash=REPEAT('f',64)", "UPDATE login_sessions SET token_hash='" + hash + "'"},
	} {
		if _, e = root.ExecContext(ctx, "USE `"+database+"`"); e != nil {
			t.Fatal("select fixture")
		}
		execute(tc.change)
		if e = service.Validate(ctx, c); e != ErrUnauthorized {
			t.Fatal("revocation not observed", e)
		}
		execute(tc.undo)
		if e = service.Validate(ctx, c); e != nil {
			t.Fatal("fixture recovery", e)
		}
	}
	if _, e = db.ExecContext(ctx, "UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=7"); e == nil {
		t.Fatal("Go identity unexpectedly has writes")
	}

	// Admin exchange uses the existing PHP Session and a PHP-only write account.
	schemaPath, _ := filepath.Abs("../../../server/database/database.sql")
	schema, e := os.ReadFile(schemaPath)
	if e != nil {
		t.Fatal("install schema fixture")
	}
	start := strings.Index(string(schema), "CREATE TABLE `admin_users` (")
	end := strings.Index(string(schema)[start:], ";\n")
	if start < 0 || end < 0 {
		t.Fatal("admin schema fixture")
	}
	execute(string(schema)[start : start+end])
	migrationPath, _ := filepath.Abs("../../../server/database/upgrades/2.7.0.sql")
	migration, e := os.ReadFile(migrationPath)
	if e != nil {
		t.Fatal("chat migration fixture")
	}
	identitySQL := strings.SplitN(string(migration), "-- CHAT_SECTION identity\n", 2)
	if len(identitySQL) != 2 {
		t.Fatal("identity section missing")
	}
	section := strings.SplitN(identitySQL[1], "-- CHAT_END identity", 2)
	if len(section) != 2 {
		t.Fatal("identity section boundary missing")
	}
	for range 2 {
		for _, statement := range strings.Split(section[0], ";") {
			if strings.TrimSpace(statement) != "" {
				execute(statement)
			}
		}
	}
	execute("INSERT INTO admin_users(id,username,password_hash,real_name,role,status,must_change_password,created_at,updated_at) VALUES(2,'chat-fixture','fixture-password-hash','','super_admin','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
	for _, table := range []string{"chat_admin_sessions"} {
		execute("GRANT SELECT ON `" + database + "`." + table + " TO '" + user + "'@'" + fixtureHost + "'")
	}
	phpUser := "bxphp_test_" + suffix
	phpPassword, _ := randomID()
	execute("CREATE USER '" + phpUser + "'@'" + fixtureHost + "' IDENTIFIED BY '" + phpPassword + "'")
	defer root.Exec("DROP USER IF EXISTS '" + phpUser + "'@'" + fixtureHost + "'")
	execute("GRANT SELECT ON `" + database + "`.admin_users TO '" + phpUser + "'@'" + fixtureHost + "'")
	execute("GRANT SELECT,INSERT,UPDATE ON `" + database + "`.chat_admin_sessions TO '" + phpUser + "'@'" + fixtureHost + "'")
	sessionID, _ := randomID()
	sessionDir := t.TempDir()
	issueAdmin := func(mode string) string {
		t.Helper()
		command := exec.CommandContext(ctx, php, path, mode)
		command.Env = append(cmd.Env, "CHAT_AUTH_TEST_MYSQL_USER="+phpUser, "CHAT_AUTH_TEST_MYSQL_PASSWORD="+phpPassword, "CHAT_AUTH_TEST_SESSION_DIR="+sessionDir, "CHAT_AUTH_TEST_SESSION_ID="+sessionID)
		data, err := command.Output()
		if err != nil {
			t.Fatal("PHP admin operation failed")
		}
		var r struct {
			Token string `json:"access_token"`
		}
		if json.Unmarshal(data, &r) != nil {
			t.Fatal("admin envelope")
		}
		return r.Token
	}
	adminToken := issueAdmin("--issue-admin")
	adminClaims, e := service.Check(ctx, adminToken)
	if e != nil || adminClaims.Kind != "admin" || adminClaims.ActorID != "2" || len(adminClaims.Scopes) != 5 {
		t.Fatal("admin identity mismatch", e)
	}
	evidenceScope := false
	for _, scope := range adminClaims.Scopes {
		if scope == "chat:evidence" {
			evidenceScope = true
		}
	}
	if !evidenceScope {
		t.Fatal("super admin missing independently minted evidence scope")
	}
	fresh := issueAdmin("--issue-admin")
	freshClaims, e := service.Check(ctx, fresh)
	if e != nil || freshClaims.Version != adminClaims.Version || freshClaims.SourceID != adminClaims.SourceID {
		t.Fatal("renewal rotated a valid session")
	}
	for _, tc := range []struct{ change, undo string }{
		{"UPDATE admin_users SET role='viewer' WHERE id=2", "UPDATE admin_users SET role='super_admin' WHERE id=2"},
		{"UPDATE admin_users SET password_hash='changed' WHERE id=2", "UPDATE admin_users SET password_hash='fixture-password-hash' WHERE id=2"},
		{"UPDATE admin_users SET status='disabled' WHERE id=2", "UPDATE admin_users SET status='active' WHERE id=2"},
		{"UPDATE admin_users SET must_change_password=1 WHERE id=2", "UPDATE admin_users SET must_change_password=0 WHERE id=2"},
	} {
		execute(tc.change)
		if e = service.Validate(ctx, adminClaims); e != ErrUnauthorized {
			t.Fatal("admin revocation not observed", e)
		}
		execute(tc.undo)
		// Re-authenticated PHP can issue a new version, never revive an old JWT.
		previous := adminClaims.Version
		adminToken = issueAdmin("--issue-admin")
		adminClaims, e = service.Check(ctx, adminToken)
		if e != nil || adminClaims.Version == previous {
			t.Fatal("revoked version was reused", e)
		}
	}
	if _, err := db.QueryContext(ctx, "SELECT password_hash FROM admin_users"); err == nil {
		t.Fatal("Go can read admin passwords")
	}
	if _, e = db.ExecContext(ctx, "UPDATE chat_admin_sessions SET revoked_at=UTC_TIMESTAMP()"); e == nil {
		t.Fatal("Go can write PHP admin sessions")
	}
	execute("UPDATE admin_users SET role='finance' WHERE id=2")
	financeToken := issueAdmin("--issue-admin")
	financeClaims, e := service.Check(ctx, financeToken)
	if e != nil || len(financeClaims.Scopes) != 1 || financeClaims.Scopes[0] != "chat:audit" || financeClaims.Version == adminClaims.Version {
		t.Fatal("admin downscope mismatch", e)
	}
	if e = service.Validate(ctx, adminClaims); e != ErrUnauthorized {
		t.Fatal("old admin version survived downgrade", e)
	}
	issueAdmin("--logout-admin")
	if e = service.Validate(ctx, financeClaims); e != ErrUnauthorized {
		t.Fatal("PHP logout did not revoke Go", e)
	}
	execute("DROP TABLE `" + database + "`.login_sessions")
	if _, e = service.Check(ctx, result.Token); e == nil || e == ErrUnauthorized {
		t.Fatal("database outage must be unavailable")
	}
}
