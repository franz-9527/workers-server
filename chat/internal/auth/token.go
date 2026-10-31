// Package auth verifies PHP-issued identities; it does not own accounts or roles.
package auth

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrUnauthorized = errors.New("CHAT_UNAUTHORIZED")
var hex64 = regexp.MustCompile(`^[a-f0-9]{64}$`)
var keyID = regexp.MustCompile(`^[a-z0-9_-]{1,32}$`)

type Claims struct {
	Issuer      string   `json:"iss"`
	Audience    string   `json:"aud"`
	ActorID     ports.ID `json:"sub"`
	Kind        string   `json:"kind"`
	SourceID    ports.ID `json:"source_id"`
	SessionID   string   `json:"session_id"`
	Version     string   `json:"session_version"`
	Environment string   `json:"env"`
	Scopes      []string `json:"scopes"`
	IssuedAt    int64    `json:"iat"`
	NotBefore   int64    `json:"nbf"`
	ExpiresAt   int64    `json:"exp"`
	JTI         string   `json:"jti"`
}

// SessionSource must read current persistent authority on every protected action.
// A successful signature never replaces logout/disable/permission revocation.
type SessionSource interface {
	Validate(context.Context, Claims) error
}

type Verifier struct {
	environment string
	keys        map[string]ed25519.PublicKey
}

func NewVerifier(environment string, keys map[string]ed25519.PublicKey) (*Verifier, error) {
	if !validEnvironment(environment) || len(keys) < 1 || len(keys) > 3 {
		return nil, ports.ErrInvalid
	}
	v := &Verifier{environment: environment, keys: make(map[string]ed25519.PublicKey)}
	for id, k := range keys {
		if !keyID.MatchString(id) || len(k) != ed25519.PublicKeySize {
			return nil, ports.ErrInvalid
		}
		v.keys[id] = append(ed25519.PublicKey(nil), k...)
	}
	return v, nil
}

func validEnvironment(s string) bool { return s == "development" || s == "test" || s == "production" }

func (v *Verifier) Verify(token string, now time.Time) (Claims, error) {
	var c Claims
	if len(token) > 4096 {
		return c, ErrUnauthorized
	}
	p := strings.Split(token, ".")
	if len(p) != 3 {
		return c, ErrUnauthorized
	}
	h, err := base64.RawURLEncoding.Strict().DecodeString(p[0])
	if err != nil {
		return c, ErrUnauthorized
	}
	var header struct {
		Algorithm string `json:"alg"`
		Type      string `json:"typ"`
		KeyID     string `json:"kid"`
	}
	if !strictJSON(h, &header) || header.Algorithm != "EdDSA" || header.Type != "JWT" {
		return c, ErrUnauthorized
	}
	k := v.keys[header.KeyID]
	if len(k) != ed25519.PublicKeySize {
		return c, ErrUnauthorized
	}
	sig, err := base64.RawURLEncoding.Strict().DecodeString(p[2])
	if err != nil || !ed25519.Verify(k, []byte(p[0]+"."+p[1]), sig) {
		return c, ErrUnauthorized
	}
	body, err := base64.RawURLEncoding.Strict().DecodeString(p[1])
	if err != nil || !strictJSON(body, &c) {
		return Claims{}, ErrUnauthorized
	}
	t := now.Unix()
	if c.Issuer != "bixuan-php:"+v.environment || c.Audience != "bixuan-chat" || c.Environment != v.environment || !c.ActorID.Valid() || c.ActorID == "0" || !hex64.MatchString(c.SessionID) || !hex64.MatchString(c.Version) || !hex64.MatchString(c.JTI) ||
		(c.Kind != "mini" && c.Kind != "admin" && c.Kind != "mini_audit") || !c.SourceID.Valid() || c.SourceID == "0" || c.IssuedAt <= 0 || c.NotBefore != c.IssuedAt || c.IssuedAt > t+5 || c.ExpiresAt <= t || c.ExpiresAt <= c.IssuedAt || c.ExpiresAt-c.IssuedAt > 300 || !validScopes(c.Kind, c.Scopes) {
		return Claims{}, ErrUnauthorized
	}
	return c, nil
}

func validScopes(kind string, scopes []string) bool {
	if len(scopes) < 1 || len(scopes) > 5 {
		return false
	}
	seen := map[string]bool{}
	for _, s := range scopes {
		if seen[s] || !(s == "chat:use" && kind == "mini" || s == "chat:audit" && kind == "mini_audit" || kind == "admin" && (s == "chat:audit" || s == "chat:moderate" || s == "chat:images" || s == "chat:evidence" || s == "chat:media")) {
			return false
		}
		seen[s] = true
	}
	return true
}

// Reject duplicate/unknown/case-alias keys, trailing values and non-object roots.
func strictJSON(data []byte, target any) bool {
	var fields map[string]json.RawMessage
	d := json.NewDecoder(bytes.NewReader(data))
	t, e := d.Token()
	if e != nil || t != json.Delim('{') {
		return false
	}
	seen := map[string]bool{}
	for d.More() {
		t, e = d.Token()
		s, ok := t.(string)
		if e != nil || !ok || seen[s] {
			return false
		}
		seen[s] = true
		var value json.RawMessage
		if d.Decode(&value) != nil {
			return false
		}
	}
	if _, e = d.Token(); e != nil {
		return false
	}
	if _, e = d.Token(); e != io.EOF {
		return false
	}
	if json.Unmarshal(data, &fields) != nil {
		return false
	}
	// Re-encode after typed decoding to compare the exact accepted field names.
	d = json.NewDecoder(bytes.NewReader(data))
	d.DisallowUnknownFields()
	if d.Decode(target) != nil {
		return false
	}
	encoded, e := json.Marshal(target)
	if e != nil {
		return false
	}
	var names map[string]json.RawMessage
	if json.Unmarshal(encoded, &names) != nil {
		return false
	}
	for name := range fields {
		if _, ok := names[name]; !ok {
			return false
		}
	}
	return true
}
