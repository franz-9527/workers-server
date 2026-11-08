package auth

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"time"
)

// Sandbox claims are accepted only in test and only while their real parent
// login and selected actor remain valid. They cannot authorize normal scopes.
func (s *MySQLSource) validateSandboxLocked(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	id, ok := ports.SandboxSource(p.SourceID)
	if !ok || !ports.SandboxPrincipal(p) || !validScopes(p.Kind, p.Scopes) || !time.Now().Before(p.ExpiresAt) {
		return ErrUnauthorized
	}
	var key, actor, kind, origin, version, session, wire string
	var expires int64
	err := tx.QueryRowContext(ctx, `SELECT session_key,actor_key,origin_kind,CAST(origin_id AS CHAR),origin_version,session_version,wire_id,UNIX_TIMESTAMP(expires_at) FROM chat_sandbox_sessions WHERE id=? AND expires_at>UTC_TIMESTAMP() LOCK IN SHARE MODE`, id).Scan(&key, &actor, &kind, &origin, &version, &session, &wire, &expires)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	digest := sha256.Sum256([]byte("sandbox\n" + string(p.SourceID) + "\n" + key))
	if wire != string(p.ActorID) || hex.EncodeToString(digest[:]) != p.SessionID || session != p.SessionVersion || p.ExpiresAt.Unix() > expires {
		return ErrUnauthorized
	}
	if kind == "admin" {
		var parent string
		err = tx.QueryRowContext(ctx, `SELECT s.session_version FROM chat_admin_sessions s JOIN admin_users a ON a.id=s.admin_id AND a.role='super_admin' AND a.status IN ('active','1') AND a.must_change_password=0 WHERE s.id=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() LOCK IN SHARE MODE`, origin).Scan(&parent)
		if err == sql.ErrNoRows {
			return ErrUnauthorized
		}
		if err != nil {
			return ports.ErrUnavailable
		}
		if parent != version {
			return ErrUnauthorized
		}
	} else if kind == "mini" {
		if !strings.HasPrefix(actor, "user:") {
			return ErrUnauthorized
		}
		var hash, selected string
		err = tx.QueryRowContext(ctx, `SELECT s.token_hash,CAST(s.demo_actor_user_id AS CHAR) FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active' JOIN users d ON d.id=s.demo_actor_user_id AND d.status='active' WHERE s.id=? AND s.status='active' AND s.expires_at>UTC_TIMESTAMP() AND COALESCE(s.test_actor_user_id,0)=0 AND COALESCE(s.test_instance_id,0)=0 AND s.test_actor_expires_at IS NULL LOCK IN SHARE MODE`, origin).Scan(&hash, &selected)
		if err == sql.ErrNoRows {
			return ErrUnauthorized
		}
		if err != nil {
			return ports.ErrUnavailable
		}
		if hash != version || actor != "user:"+selected {
			return ErrUnauthorized
		}
	} else if kind == "mini_adm" {
		user, ok := ports.SandboxMiniAdminUser(actor, p.ActorID)
		if !ok {
			return ErrUnauthorized
		}
		return validateSandboxMiniAdminLocked(ctx, tx, origin, user, version)
	} else {
		return ErrUnauthorized
	}
	var active int
	if strings.HasPrefix(actor, "admin:") {
		err = tx.QueryRowContext(ctx, "SELECT 1 FROM admin_users WHERE id=? AND status IN ('active','1') AND must_change_password=0 LOCK IN SHARE MODE", strings.TrimPrefix(actor, "admin:")).Scan(&active)
	} else if strings.HasPrefix(actor, "user:") {
		err = tx.QueryRowContext(ctx, "SELECT 1 FROM users WHERE id=? AND status='active' LOCK IN SHARE MODE", strings.TrimPrefix(actor, "user:")).Scan(&active)
	} else {
		return ErrUnauthorized
	}
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}

func validateSandboxMiniAdminLocked(ctx context.Context, tx *sql.Tx, origin string, user ports.ID, version string) error {
	var hash string
	err := tx.QueryRowContext(ctx, `SELECT s.token_hash FROM login_sessions s
 JOIN users u ON u.id=s.user_id AND u.status='active'
 WHERE s.id=? AND s.user_id=? AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
 AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0
 AND COALESCE(s.test_instance_id,0)=0 AND s.test_actor_expires_at IS NULL LOCK IN SHARE MODE`, origin, string(user)).Scan(&hash)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	var config, staff string
	for _, entry := range []struct {
		id      ports.ID
		version *string
	}{{user, &staff}, {"0", &config}} {
		err = tx.QueryRowContext(ctx, "SELECT CAST(authority_version AS CHAR) FROM chat_staff_authority_versions WHERE user_id=? LOCK IN SHARE MODE", string(entry.id)).Scan(entry.version)
		if err == sql.ErrNoRows {
			return ErrUnauthorized
		}
		if err != nil {
			return ports.ErrUnavailable
		}
	}
	current := sha256.Sum256([]byte(hash + "\n" + config + "\n" + staff))
	if hex.EncodeToString(current[:]) != version {
		return ErrUnauthorized
	}
	return nil
}
