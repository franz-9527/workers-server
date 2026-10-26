package bootstrap

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"time"
)

type ReleaseConfig struct {
	Staging bool   `json:"staging"`
	File    string `json:"file"`
	KeyEnv  string `json:"key_env"`
}

func (c ReleaseConfig) Verify(env, epoch, revision, dirty string, capabilities ...string) bool {
	if !regexp.MustCompile("^[0-9a-f]{40}$").MatchString(revision) || dirty != "false" || !filepath.IsAbs(c.File) || !regexp.MustCompile("^CHAT_[A-Z0-9_]{1,60}$").MatchString(c.KeyEnv) {
		return false
	}
	info, err := os.Lstat(c.File)
	if err != nil || !info.Mode().IsRegular() || info.Size() > 8192 || info.Mode().Perm()&0022 != 0 {
		return false
	}
	file, err := os.Open(c.File)
	if err != nil {
		return false
	}
	defer file.Close()
	raw, err := io.ReadAll(io.LimitReader(file, 8193))
	if err != nil || len(raw) > 8192 {
		return false
	}
	var envelope struct {
		Payload string `json:"payload"`
		MAC     string `json:"mac"`
	}
	if json.Unmarshal(raw, &envelope) != nil {
		return false
	}
	body, err := base64.StdEncoding.Strict().DecodeString(envelope.Payload)
	if err != nil || base64.StdEncoding.EncodeToString(body) != envelope.Payload {
		return false
	}
	keyText := os.Getenv(c.KeyEnv)
	key, err := base64.StdEncoding.Strict().DecodeString(keyText)
	if err != nil || len(key) != 32 || base64.StdEncoding.EncodeToString(key) != keyText {
		return false
	}
	got, err := hex.DecodeString(envelope.MAC)
	if err != nil {
		return false
	}
	mac := hmac.New(sha256.New, key)
	_, _ = mac.Write(body)
	if !hmac.Equal(got, mac.Sum(nil)) {
		return false
	}
	var p struct {
		Schema       int      `json:"schema"`
		Environment  string   `json:"environment"`
		Epoch        string   `json:"epoch"`
		Revision     string   `json:"revision"`
		Expires      int64    `json:"expires_at"`
		Capabilities []string `json:"capabilities"`
	}
	if json.Unmarshal(body, &p) != nil || p.Schema != 1 || p.Environment != env || p.Epoch != epoch || p.Revision != revision || p.Expires <= time.Now().Unix() || p.Expires > time.Now().Add(24*time.Hour).Unix() {
		return false
	}
	for _, required := range capabilities {
		found := false
		for _, cap := range p.Capabilities {
			if cap == required {
				found = true
			}
		}
		if !found {
			return false
		}
	}
	return true
}
