package auth

import (
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Scope-specific authority stays live under the caller's transaction. Audit
// mini identities deliberately cannot enter the ordinary member authorizer.
func (s *MySQLSource) ValidateAuditMiniLocked(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	if p.Kind != "mini_audit" || len(p.Scopes) != 1 || p.Scopes[0] != "chat:audit" {
		return ErrUnauthorized
	}
	var hash string
	err := tx.QueryRowContext(ctx, `SELECT token_hash FROM login_sessions WHERE id=? LOCK IN SHARE MODE`, string(p.SourceID)).Scan(&hash)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	mini := p
	mini.Kind = "mini"
	mini.Scopes = []string{"chat:use"}
	mini.SessionVersion = digest("mini-v1\n" + string(p.SourceID) + "\n" + hash + "\n" + string(p.ActorID))
	if err = s.ValidatePrincipalLocked(ctx, tx, mini); err != nil {
		return err
	}
	var version string
	err = tx.QueryRowContext(ctx, `SELECT authority_version FROM chat_mini_auditors WHERE user_id=? AND enabled=1 LOCK IN SHARE MODE`, string(p.ActorID)).Scan(&version)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	if !hex64.MatchString(version) || p.SessionVersion != digest("mini-audit-v1\n"+string(p.SourceID)+"\n"+hash+"\n"+string(p.ActorID)+"\n"+version) {
		return ErrUnauthorized
	}
	return nil
}
func (s *MySQLSource) ValidateAdminLocked(ctx context.Context, tx *sql.Tx, p ports.Principal, scope string) error {
	if scope == "chat:audit" && p.Kind == "mini_audit" {
		return s.ValidateAuditMiniLocked(ctx, tx, p)
	}
	if tx == nil || p.Kind != "admin" || !p.ActorID.Valid() || p.ActorID == "0" || !p.SourceID.Valid() || p.SourceID == "0" || !validScopes(p.Kind, p.Scopes) || !time.Now().Before(p.ExpiresAt) {
		return ErrUnauthorized
	}
	found := false
	for _, v := range p.Scopes {
		if v == scope {
			found = true
		}
	}
	if !found {
		return ErrUnauthorized
	}
	var actor, sid, version, raw string
	var expires int64
	err := tx.QueryRowContext(ctx, `SELECT CAST(admin_id AS CHAR),session_hash,session_version,scopes_json,UNIX_TIMESTAMP(expires_at) FROM chat_admin_sessions WHERE id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LOCK IN SHARE MODE`, string(p.SourceID)).Scan(&actor, &sid, &version, &raw, &expires)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	var scopes []string
	if json.Unmarshal([]byte(raw), &scopes) != nil || !validScopes("admin", scopes) {
		return ErrUnauthorized
	}
	found = false
	for _, v := range scopes {
		if v == scope {
			found = true
		}
	}
	if !found || actor != string(p.ActorID) || sid != p.SessionID || version != p.SessionVersion || p.ExpiresAt.Unix() > expires {
		return ErrUnauthorized
	}
	return nil
}
