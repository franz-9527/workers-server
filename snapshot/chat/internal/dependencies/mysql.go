package dependencies

import (
	"context"
	"database/sql"
	"regexp"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
	mysql "github.com/go-sql-driver/mysql"
)

type silentMySQLLog struct{}

func (silentMySQLLog) Print(...any) {}

func openMySQL(s Settings, role string) (*sql.DB, error) {
	pw, err := password(s.MySQL.PasswordEnv, true)
	if err != nil {
		return nil, err
	}
	c := mysql.NewConfig()
	c.Net = "tcp"
	c.Addr = s.MySQL.Address
	c.DBName = s.MySQL.Database
	c.User = s.MySQL.Username
	c.Passwd = pw
	c.Collation = "utf8mb4_unicode_ci"
	c.ParseTime = true
	c.Loc = time.UTC
	c.Timeout = s.Timeout()
	c.ReadTimeout = s.Timeout()
	c.WriteTimeout = s.Timeout()
	c.Logger = silentMySQLLog{}
	if err := c.Apply(mysql.Charset("utf8mb4", "utf8mb4_unicode_ci")); err != nil {
		return nil, ports.ErrInvalid
	}
	c.Params = map[string]string{"time_zone": "'+00:00'", "sql_mode": "'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"}
	connector, err := mysql.NewConnector(c)
	if err != nil {
		return nil, ports.ErrInvalid
	}
	db := sql.OpenDB(connector)
	max := s.MySQL.MaxOpenServe
	if role == "worker" {
		max = s.MySQL.MaxOpenWorker
	}
	db.SetMaxOpenConns(max)
	db.SetMaxIdleConns(s.MySQL.MaxIdle)
	db.SetConnMaxLifetime(3 * time.Minute)
	db.SetConnMaxIdleTime(time.Minute)
	return db, nil
}

var mysql57 = regexp.MustCompile(`^5\.7\.[0-9]+(?:[-.].*)?$`)

func checkMySQL(ctx context.Context, db *sql.DB, database, environment string) error {
	// One lease keeps session invariants and SHOW GRANTS on the same connection.
	conn, err := db.Conn(ctx)
	if err != nil {
		return ports.ErrUnavailable
	}
	defer conn.Close()
	var version, zone, collation, charset, mode, schemaCollation string
	err = conn.QueryRowContext(ctx, `SELECT VERSION(), @@session.time_zone, @@session.collation_connection, @@session.character_set_connection, @@session.sql_mode`).Scan(&version, &zone, &collation, &charset, &mode)
	if err != nil {
		return ports.ErrUnavailable
	}
	if !mysql57.MatchString(version) || zone != "+00:00" || collation != "utf8mb4_unicode_ci" || charset != "utf8mb4" || !strings.Contains(mode, "STRICT_TRANS_TABLES") {
		return ports.ErrInvalid
	}
	if conn.QueryRowContext(ctx, `SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()`).Scan(&schemaCollation) != nil {
		return ports.ErrUnavailable
	}
	if schemaCollation != "utf8mb4_unicode_ci" {
		return ports.ErrInvalid
	}
	rows, err := conn.QueryContext(ctx, "SHOW GRANTS")
	if err != nil {
		return ports.ErrUnavailable
	}
	defer rows.Close()
	count := 0
	for rows.Next() {
		var grant string
		if rows.Scan(&grant) != nil || !safeGrant(grant, database, environment) {
			return ports.ErrInvalid
		}
		count++
	}
	if rows.Err() != nil {
		return ports.ErrUnavailable
	}
	if count == 0 {
		return ports.ErrInvalid
	}
	return nil
}

// Exact table ownership, never a chat_* wildcard: PHP owns several chat_* tables.
var goOwned = map[string]bool{
	"chat_av_uploads": true, "chat_av_parts": true, "chat_av_jobs": true, "chat_av_objects": true, "chat_av_items": true, "chat_av_listens": true, "chat_av_raw_receipts": true, "chat_av_asr_requests": true, "chat_av_budgets": true,

	"chat_storage_applications": true, "chat_storage_application_errors": true,
	"chat_conversations": true, "chat_members": true, "chat_member_intervals": true, "chat_member_reads": true,
	"chat_messages": true, "chat_message_changes": true, "chat_send_intents": true, "chat_preference_accounts": true, "chat_user_preferences": true,
	"chat_restrictions": true, "chat_text_checks": true, "chat_risk_events": true, "chat_event_receipts": true,
	"chat_image_uploads": true, "chat_image_bundles": true, "chat_image_items": true, "chat_image_objects": true, "chat_image_reports": true,
	"chat_audit_accounts": true, "chat_audit_snapshots": true, "chat_audit_snapshot_items": true, "chat_cases": true,
	"chat_reminder_events": true, "chat_reminder_clusters": true, "chat_reminder_budgets": true, "chat_reminder_intents": true,
}
var appendOwned = map[string]bool{"chat_governance_audit": true, "chat_image_audit": true}
var phpReadable = map[string]bool{"chat_storage_policy": true, "chat_storage_policy_audit": true, "wechat_identities": true, "users": true, "login_sessions": true, "chat_admin_sessions": true, "chat_mini_auditors": true, "orders": true, "assignments": true, "after_sales": true, "chat_policy_guards": true, "chat_business_events": true, "chat_staff_authority_versions": true}

func safeGrant(grant, database, environment string) bool {
	if !strings.HasPrefix(grant, "GRANT ") || strings.Contains(grant, " WITH GRANT OPTION") {
		return false
	}
	after, subject, ok := strings.Cut(strings.TrimPrefix(grant, "GRANT "), " ON ")
	if !ok {
		return false
	}
	target, _, ok := strings.Cut(subject, " TO ")
	if !ok {
		return false
	}
	if after == "USAGE" && target == "*.*" {
		return true
	}
	parts := strings.Split(strings.ReplaceAll(target, "`", ""), ".")
	if len(parts) != 2 || parts[0] != database || !identifier.MatchString(parts[1]) {
		return false
	}
	owned := goOwned[parts[1]] || (environment != "production" && parts[1] == "chat_contract_probe")
	for _, privilege := range strings.Split(after, ", ") {
		if privilege == "SELECT" && (owned || appendOwned[parts[1]] || phpReadable[parts[1]]) {
			continue
		}
		if appendOwned[parts[1]] && privilege == "INSERT" {
			continue
		}
		if owned && (privilege == "INSERT" || privilege == "UPDATE" || privilege == "DELETE") {
			continue
		}
		return false
	}
	return true
}
