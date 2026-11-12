package imagepipeline

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/sourceprobe"
)

var asrMirrorName = regexp.MustCompile(`^[a-f0-9]{64}\.mp3$`)
var asrMirrorHash = regexp.MustCompile(`^[a-f0-9]{64}$`)

type asrMirrorStorage struct {
	message.AVStorage
	client       *http.Client
	root, origin string
	ttl          time.Duration
}

func validASRMirrorOrigin(raw string) bool {
	u, e := url.Parse(strings.TrimRight(raw, "/"))
	return e == nil && u.Scheme == "https" && u.Hostname() != "" && u.User == nil &&
		u.RawQuery == "" && u.Fragment == "" && (u.Port() == "" || u.Port() == "443") &&
		u.Path == "/uploads/chat-asr"
}

func NewASRMirrorStorage(base *Qiniu, root, origin string, ttl time.Duration) (message.AVStorage, error) {
	info, e := os.Lstat(root)
	if base == nil || base.client == nil || e != nil || !filepath.IsAbs(root) || !info.IsDir() ||
		info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0022 != 0 ||
		!validASRMirrorOrigin(origin) || ttl < 5*time.Minute || ttl > 30*time.Minute {
		return nil, message.ErrMedia
	}
	return &asrMirrorStorage{
		AVStorage: base,
		client:    base.client,
		root:      filepath.Clean(root),
		origin:    strings.TrimRight(origin, "/"),
		ttl:       ttl,
	}, nil
}

func randomASRMirrorName() (string, error) {
	var raw [32]byte
	if _, e := rand.Read(raw[:]); e != nil {
		return "", e
	}
	return hex.EncodeToString(raw[:]) + ".mp3", nil
}

func (m *asrMirrorStorage) InspectionURL(ctx context.Context, key string) (string, error) {
	if !strings.HasSuffix(key, ".mp3") {
		return m.AVStorage.InspectionURL(ctx, key)
	}
	body, e := m.AVStorage.Read(ctx, key, message.MaxVoiceBytes)
	if e != nil || len(body) == 0 {
		return "", message.ErrMedia
	}
	parts := strings.Split(strings.Trim(key, "/"), "/")
	if len(parts) < 2 || !asrMirrorHash.MatchString(parts[len(parts)-2]) {
		return "", message.ErrMedia
	}
	sum := sha256.Sum256(body)
	if hex.EncodeToString(sum[:]) != parts[len(parts)-2] {
		return "", message.ErrMedia
	}
	name, e := randomASRMirrorName()
	if e != nil {
		return "", message.ErrMedia
	}
	tmp := filepath.Join(m.root, ".tmp-"+strings.TrimSuffix(name, ".mp3"))
	final := filepath.Join(m.root, name)
	file, e := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0644)
	if e != nil {
		return "", message.ErrMedia
	}
	ok := false
	defer func() {
		_ = file.Close()
		if !ok {
			_ = os.Remove(tmp)
			_ = os.Remove(final)
		}
	}()
	if n, err := file.Write(body); err != nil || n != len(body) || file.Sync() != nil || file.Close() != nil {
		return "", message.ErrMedia
	}
	if e = os.Rename(tmp, final); e != nil {
		return "", message.ErrMedia
	}
	_ = os.Chmod(final, 0644)
	publicURL := m.origin + "/" + name
	if e = sourceprobe.VerifyMP3(ctx, m.client, publicURL); e != nil {
		return "", e
	}
	ok = true
	return publicURL, nil
}

func (m *asrMirrorStorage) CleanInspection(ctx context.Context) error {
	entries, e := os.ReadDir(m.root)
	if e != nil {
		return message.ErrMedia
	}
	cutoff := time.Now().Add(-m.ttl)
	removed := 0
	for _, entry := range entries {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		name := entry.Name()
		if !asrMirrorName.MatchString(name) && !strings.HasPrefix(name, ".tmp-") {
			continue
		}
		info, err := entry.Info()
		if err != nil || !info.Mode().IsRegular() || !info.ModTime().Before(cutoff) {
			continue
		}
		if err = os.Remove(filepath.Join(m.root, name)); err != nil && !os.IsNotExist(err) {
			return message.ErrMedia
		}
		removed++
		if removed >= 20 {
			break
		}
	}
	return nil
}
