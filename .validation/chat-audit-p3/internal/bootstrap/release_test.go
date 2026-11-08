package bootstrap

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestS23ReleaseProofBindsExactSourceEnvironmentAndCapabilities(t *testing.T) {
	path := filepath.Join(t.TempDir(), "release.json")
	key := []byte(strings.Repeat("k", 32))
	t.Setenv("CHAT_RELEASE_FIXTURE_KEY", base64.StdEncoding.EncodeToString(key))
	c := ReleaseConfig{File: path, KeyEnv: "CHAT_RELEASE_FIXTURE_KEY"}
	revision := strings.Repeat("a", 40)
	epoch := strings.Repeat("b", 64)
	body, _ := json.Marshal(map[string]any{"schema": 1, "environment": "production", "epoch": epoch, "revision": revision, "expires_at": time.Now().Add(time.Hour).Unix(), "capabilities": []string{"text", "images", "governance", "reminders"}})
	mac := hmac.New(sha256.New, key)
	mac.Write(body)
	envelope, _ := json.Marshal(map[string]string{"payload": base64.StdEncoding.EncodeToString(body), "mac": hex.EncodeToString(mac.Sum(nil))})
	if os.WriteFile(path, envelope, 0640) != nil {
		t.Fatal("private file")
	}
	if !c.Verify("production", epoch, revision, "false", "text", "images") {
		t.Fatal("valid seal rejected")
	}
	for _, bad := range []struct{ env, epoch, revision, dirty string }{{"test", epoch, revision, "false"}, {"production", strings.Repeat("c", 64), revision, "false"}, {"production", epoch, strings.Repeat("d", 40), "false"}, {"production", epoch, revision, "true"}} {
		if c.Verify(bad.env, bad.epoch, bad.revision, bad.dirty, "text") {
			t.Fatal("seal crossed boundary", bad)
		}
	}
	if c.Verify("production", epoch, revision, "false", "voice") {
		t.Fatal("unaccepted capability")
	}
	envelope[len(envelope)-5] ^= 1
	os.WriteFile(path, envelope, 0640)
	if c.Verify("production", epoch, revision, "false", "text") {
		t.Fatal("tampered seal")
	}
}
