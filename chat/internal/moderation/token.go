// Package moderation implements official text checks and local diversion rules.
// It never grants order authority or writes PHP-owned identity tables.
package moderation

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type Credential struct {
	Schema      int    `json:"schema_version"`
	Environment string `json:"env"`
	AppID       string `json:"app_id"`
	Token       string `json:"token"`
	IssuedAt    int64  `json:"issued_at"`
	ExpiresAt   int64  `json:"expires_at"`
	Generation  string `json:"generation"`
}
type Credentials struct {
	mu                       sync.Mutex
	path, environment, appID string
	rejectionDirectory       string
	cached                   Credential
	rejected                 map[string]int64
}

func NewCredentials(path, environment, appID string) (*Credentials, error) {
	if !filepath.IsAbs(path) || !validEnvironment(environment) || !appPattern.MatchString(appID) {
		return nil, ports.ErrInvalid
	}
	return &Credentials{path: path, environment: environment, appID: appID, rejected: map[string]int64{}}, nil
}

// Rejection hints contain only a generation hash. PHP remains the only token
// refresher and verifies a hint against its exact current AppID/environment.
func (c *Credentials) setRejectionDirectory(path string) error {
	if path == "" {
		return nil
	}
	info, e := os.Lstat(path)
	if !filepath.IsAbs(path) || e != nil || !info.IsDir() || info.Mode().Perm()&0007 != 0 {
		return ports.ErrInvalid
	}
	c.rejectionDirectory = path
	return nil
}
func (c *Credentials) writeRejection(generation string) {
	if c.rejectionDirectory == "" {
		return
	}
	info, e := os.Lstat(c.rejectionDirectory)
	if e != nil || !info.IsDir() || info.Mode().Perm()&0007 != 0 {
		return
	}
	name := filepath.Join(c.rejectionDirectory, c.environment+"-"+hash(c.appID)+"-"+generation+".json")
	raw, _ := json.Marshal(map[string]any{"schema_version": 1, "env": c.environment, "app_id": c.appID, "generation": generation})
	// A complete bounded file is published atomically, never a token or a URL.
	f, e := os.CreateTemp(c.rejectionDirectory, ".reject-")
	if e != nil {
		return
	}
	temporary := f.Name()
	defer os.Remove(temporary)
	if f.Chmod(0640) != nil {
		f.Close()
		return
	}
	n, e := f.Write(raw)
	closeErr := f.Close()
	if e == nil && n == len(raw) && closeErr == nil {
		_ = os.Rename(temporary, name)
	}
}
func hash(s string) string { v := sha256.Sum256([]byte(s)); return hex.EncodeToString(v[:]) }
func (c *Credentials) valid(v Credential, now time.Time) bool {
	return v.Schema == 1 && v.Environment == c.environment && v.AppID == c.appID && len(v.Token) <= 2048 && tokenPattern.MatchString(v.Token) && v.IssuedAt > 0 && v.IssuedAt <= now.Unix()+5 && v.ExpiresAt > now.Unix()+5 && v.ExpiresAt > v.IssuedAt && v.ExpiresAt-v.IssuedAt <= 7200 && v.Generation == hash(v.Environment+"\n"+v.AppID+"\n"+v.Token) && c.rejected[v.Generation] <= now.Unix() && len(c.rejected) < 32
}
func (c *Credentials) Get() (Credential, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	now := time.Now()
	for key, until := range c.rejected {
		if until <= now.Unix() {
			delete(c.rejected, key)
		}
	}
	// Refresh the protected file on every check to observe revocations/rotation;
	// cache only bridges file/proxy outages while a known credential is valid.
	info, e := os.Lstat(c.path)
	if e == nil && info.Mode().IsRegular() && info.Mode().Perm()&0027 == 0 && info.Size() <= 4096 {
		parent, err := os.Stat(filepath.Dir(c.path))
		if err == nil && parent.IsDir() && parent.Mode().Perm()&0022 == 0 {
			f, err := os.Open(c.path)
			if err == nil {
				actual, err := f.Stat()
				var body []byte
				if err == nil && os.SameFile(info, actual) {
					body, err = io.ReadAll(io.LimitReader(f, 4097))
				}
				f.Close()
				var v Credential
				if err == nil && len(body) <= 4096 && strictObject(body, &v) && c.valid(v, now) {
					c.cached = v
					return v, nil
				}
			}
		}
	}
	if e != nil && (os.IsNotExist(e) || os.IsPermission(e)) && c.valid(c.cached, now) {
		return c.cached, nil
	}
	return Credential{}, ports.ErrUnavailable
}
func (c *Credentials) Reject(generation string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if hexPattern.MatchString(generation) {
		until := time.Now().Add(2 * time.Hour).Unix()
		if c.cached.Generation == generation {
			until = c.cached.ExpiresAt
		}
		c.rejected[generation] = until
		c.writeRejection(generation)
		if c.cached.Generation == generation {
			c.cached = Credential{}
		}
	}
}

// Duplicate/case/unknown keys are forbidden even on trusted on-disk envelopes.
func strictObject(body []byte, value any) bool {
	var raw map[string]json.RawMessage
	if json.Unmarshal(body, &raw) != nil || raw == nil {
		return false
	}
	decoder := json.NewDecoder(bytes.NewReader(body))
	token, e := decoder.Token()
	if e != nil || token != json.Delim('{') {
		return false
	}
	seen := map[string]bool{}
	for decoder.More() {
		k, e := decoder.Token()
		key, ok := k.(string)
		if e != nil || !ok || seen[key] || key != strings.ToLower(key) {
			return false
		}
		seen[key] = true
		var v json.RawMessage
		if decoder.Decode(&v) != nil || string(v) == "null" {
			return false
		}
	}
	if _, e = decoder.Token(); e != nil {
		return false
	}
	if _, e = decoder.Token(); e != io.EOF {
		return false
	}
	decoder = json.NewDecoder(bytes.NewReader(body))
	decoder.DisallowUnknownFields()
	return decoder.Decode(value) == nil
}
