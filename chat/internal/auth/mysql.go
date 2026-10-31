package auth

import (
	"context"
	"crypto/subtle"
	"database/sql"
	"encoding/json"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// MySQLSource is SELECT-only on PHP-owned identity tables. No last_seen writes.
type MySQLSource struct{ db *sql.DB }

func NewMySQLSource(db *sql.DB) (*MySQLSource, error) {
	if db == nil {
		return nil, ports.ErrInvalid
	}
	return &MySQLSource{db}, nil
}
func (s *MySQLSource) Validate(ctx context.Context, c Claims) error {
	if c.Kind == "mini" || c.Kind == "mini_audit" {
		if err := s.checkRelease(ctx, s.db); err != nil {
			return err
		}
	}
	if c.Kind == "mini_audit" {
		tx, err := s.db.BeginTx(ctx, nil)
		if err != nil {
			return ports.ErrUnavailable
		}
		defer tx.Rollback()
		p := ports.Principal{ActorID: c.ActorID, SourceID: c.SourceID, Kind: c.Kind, Scopes: c.Scopes, SessionID: c.SessionID, SessionVersion: c.Version, ExpiresAt: time.Unix(c.ExpiresAt, 0)}
		if err = s.ValidateAuditMiniLocked(ctx, tx, p); err != nil {
			return err
		}
		return tx.Commit()
	}
	if c.Kind != "mini" {
		if c.Kind == "admin" {
			return s.validateAdmin(ctx, c)
		}
		return ErrUnauthorized
	}
	var actor, hash string
	var expires int64
	err := s.db.QueryRowContext(ctx, `SELECT CAST(s.user_id AS CHAR),s.token_hash,UNIX_TIMESTAMP(s.expires_at)
 FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
 WHERE s.id=? AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
 AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0
 AND COALESCE(s.test_instance_id,0)=0 AND s.test_actor_expires_at IS NULL LIMIT 1`, string(c.SourceID)).Scan(&actor, &hash, &expires)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	sid := digest("mini\n" + string(c.SourceID) + "\n" + hash)
	version := digest("mini-v1\n" + string(c.SourceID) + "\n" + hash + "\n" + actor)
	if actor != string(c.ActorID) || !hex64.MatchString(hash) || subtle.ConstantTimeCompare([]byte(sid), []byte(c.SessionID)) != 1 || subtle.ConstantTimeCompare([]byte(version), []byte(c.Version)) != 1 || expires <= time.Now().Unix() || c.ExpiresAt > expires {
		return ErrUnauthorized
	}
	return nil
}

// ValidatePrincipalLocked is the mini-session final check for a caller-owned
// authorization/message transaction. PHP logout/disable cannot commit past it.
// The Principal must already come from verification of a signed access token.
func (s *MySQLSource) ValidatePrincipalLocked(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	if tx != nil {
		if err := s.checkRelease(ctx, tx); err != nil {
			return err
		}
	}
	if tx == nil || p.Kind != "mini" || !p.ActorID.Valid() || p.ActorID == "0" || !p.SourceID.Valid() || p.SourceID == "0" ||
		!hex64.MatchString(p.SessionID) || !hex64.MatchString(p.SessionVersion) || !validScopes(p.Kind, p.Scopes) || !time.Now().Before(p.ExpiresAt) {
		return ErrUnauthorized
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
	if actor != string(p.ActorID) || !hex64.MatchString(hash) || p.SessionID != digest("mini\n"+string(p.SourceID)+"\n"+hash) ||
		p.SessionVersion != digest("mini-v1\n"+string(p.SourceID)+"\n"+hash+"\n"+actor) || p.ExpiresAt.Unix() > expires || !time.Now().Before(p.ExpiresAt) {
		return ErrUnauthorized
	}
	return nil
}

// Every request and final send transaction sees the shared operator switch.
func (s *MySQLSource) checkRelease(ctx context.Context, q interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}) error {
	var value string
	err := q.QueryRowContext(ctx, `SELECT setting_value FROM system_settings WHERE setting_key='chat.enabled' LIMIT 1`).Scan(&value)
	if err == sql.ErrNoRows {
		return nil
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	if value != "1" {
		return ErrUnauthorized
	}
	return nil
}

func (s *MySQLSource) validateAdmin(ctx context.Context, c Claims) error {
	var actor, sid, version, scopesJSON string
	var expires int64
	err := s.db.QueryRowContext(ctx, `SELECT CAST(s.admin_id AS CHAR),s.session_hash,s.session_version,s.scopes_json,UNIX_TIMESTAMP(s.expires_at)
 FROM chat_admin_sessions s
 WHERE s.id=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP()
 LIMIT 1`, string(c.SourceID)).Scan(&actor, &sid, &version, &scopesJSON, &expires)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	if actor != string(c.ActorID) || sid != c.SessionID || version != c.Version || expires <= time.Now().Unix() || c.ExpiresAt > expires {
		return ErrUnauthorized
	}
	var scopes []string
	if json.Unmarshal([]byte(scopesJSON), &scopes) != nil || !validScopes("admin", scopes) {
		return ErrUnauthorized
	}
	for _, wanted := range c.Scopes {
		found := false
		for _, allowed := range scopes {
			if wanted == allowed {
				found = true
			}
		}
		if !found {
			return ErrUnauthorized
		}
	}
	return nil
}

// ValidateMediaAdminLocked is a distinct prereview permission. Session/role
// changes revoke the persisted PHP-issued grant via the identity trigger.
func (s *MySQLSource) ValidateMediaAdminLocked(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	if p.Kind != "admin" || !p.ActorID.Valid() || !p.SourceID.Valid() || !time.Now().Before(p.ExpiresAt) {
		return ErrUnauthorized
	}
	allowed := false
	for _, scope := range p.Scopes {
		if scope == "chat:images" {
			allowed = true
		}
	}
	if !allowed {
		return ErrUnauthorized
	}
	var actor, sid, version, scopes string
	var expires int64
	err := tx.QueryRowContext(ctx, `SELECT CAST(s.admin_id AS CHAR),s.session_hash,s.session_version,s.scopes_json,UNIX_TIMESTAMP(s.expires_at) FROM chat_admin_sessions s WHERE s.id=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() LOCK IN SHARE MODE`, string(p.SourceID)).Scan(&actor, &sid, &version, &scopes, &expires)
	if err == sql.ErrNoRows {
		return ErrUnauthorized
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	var list []string
	json.Unmarshal([]byte(scopes), &list)
	moderate := false
	for _, v := range list {
		if v == "chat:images" {
			moderate = true
		}
	}
	if !moderate || actor != string(p.ActorID) || sid != p.SessionID || version != p.SessionVersion || p.ExpiresAt.Unix() > expires {
		return ErrUnauthorized
	}
	return nil
}
