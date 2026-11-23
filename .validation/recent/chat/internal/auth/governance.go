package auth

import (
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
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
func miniAdminOpenids(raw string) map[string]bool {
	out := map[string]bool{}
	var list []string
	if json.Unmarshal([]byte(raw), &list) == nil {
		for _, value := range list {
			if value = strings.TrimSpace(value); value != "" {
				out[value] = true
			}
		}
		return out
	}
	for _, value := range strings.FieldsFunc(raw, func(r rune) bool {
		switch r {
		case ' ', '\t', '\r', '\n', ',', '，', ';', '；':
			return true
		}
		return false
	}) {
		if value = strings.TrimSpace(value); value != "" {
			out[value] = true
		}
	}
	return out
}

func (s *MySQLSource) miniAdminAuthorityLocked(ctx context.Context, tx *sql.Tx, actor ports.ID) (string, bool, error) {
	if tx == nil || !actor.Valid() || actor == "0" {
		return "", false, ErrUnauthorized
	}
	var appID, raw string
	if err := tx.QueryRowContext(ctx, `SELECT setting_value FROM system_settings WHERE setting_key='wechat.miniapp_appid' LIMIT 1 LOCK IN SHARE MODE`).Scan(&appID); err != nil {
		if err == sql.ErrNoRows {
			return "", false, ErrUnauthorized
		}
		return "", false, ports.ErrUnavailable
	}
	if err := tx.QueryRowContext(ctx, `SELECT setting_value FROM system_settings WHERE setting_key='wechat.admin_openids' LIMIT 1 LOCK IN SHARE MODE`).Scan(&raw); err != nil {
		if err == sql.ErrNoRows {
			return "", false, ErrUnauthorized
		}
		return "", false, ports.ErrUnavailable
	}
	appID = strings.TrimSpace(appID)
	admins := miniAdminOpenids(raw)
	if appID == "" || len(admins) == 0 {
		return "", false, ErrUnauthorized
	}
	var openid string
	err := tx.QueryRowContext(ctx, `SELECT openid FROM wechat_identities WHERE user_id=? AND status='active' AND BINARY app_id=BINARY ? ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE`, string(actor), appID).Scan(&openid)
	if err == sql.ErrNoRows {
		return digest(appID + "\n" + raw), false, nil
	}
	if err != nil {
		return "", false, ports.ErrUnavailable
	}
	return digest(appID + "\n" + raw), admins[strings.TrimSpace(openid)], nil
}

func (s *MySQLSource) IsMiniAdminLocked(ctx context.Context, tx *sql.Tx, actor ports.ID) (bool, error) {
	_, allowed, err := s.miniAdminAuthorityLocked(ctx, tx, actor)
	return allowed, err
}

func (s *MySQLSource) ValidateMiniModeratorLocked(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	if tx == nil || p.Kind != "mini_moderator" || !p.ActorID.Valid() || p.ActorID == "0" || !p.SourceID.Valid() || p.SourceID == "0" ||
		len(p.Scopes) != 1 || p.Scopes[0] != "chat:moderate" || !validScopes(p.Kind, p.Scopes) || !time.Now().Before(p.ExpiresAt) {
		return ErrUnauthorized
	}
	if err := s.checkRelease(ctx, tx); err != nil {
		return err
	}
	var actor, hash string
	var expires int64
	err := tx.QueryRowContext(ctx, `SELECT CAST(s.user_id AS CHAR),s.token_hash,UNIX_TIMESTAMP(s.expires_at)
		FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
		WHERE s.id=? AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
		AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0
		AND COALESCE(s.test_instance_id,0)=0 AND s.test_actor_expires_at IS NULL LIMIT 1 LOCK IN SHARE MODE`, string(p.SourceID)).Scan(&actor, &hash, &expires)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	authority, allowed, err := s.miniAdminAuthorityLocked(ctx, tx, p.ActorID)
	if err != nil {
		return err
	}
	if !allowed || actor != string(p.ActorID) || !hex64.MatchString(hash) ||
		p.SessionID != digest("mini\n"+string(p.SourceID)+"\n"+hash) ||
		p.SessionVersion != digest("mini-moderator-v1\n"+string(p.SourceID)+"\n"+hash+"\n"+actor+"\n"+authority) ||
		p.ExpiresAt.Unix() > expires {
		return ErrUnauthorized
	}
	return nil
}

func (s *MySQLSource) ValidateAdminLocked(ctx context.Context, tx *sql.Tx, p ports.Principal, scope string) error {
	if scope == "chat:audit" && p.Kind == "mini_audit" {
		return s.ValidateAuditMiniLocked(ctx, tx, p)
	}
	if scope == "chat:moderate" && p.Kind == "mini_moderator" {
		return s.ValidateMiniModeratorLocked(ctx, tx, p)
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
