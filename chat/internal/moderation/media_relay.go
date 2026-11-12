package moderation

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

const (
	MediaRelayPath = "/chat/internal/v1/av/receipt"
	relayURL = "http://127.0.0.1:19502" + MediaRelayPath
	relayCapacity = 4096
	relayLifetime = time.Hour
)

var ErrReceiptUnowned = errors.New("media receipt has no local job")

type OwnedAudioReceiver interface {
	ReceiveOwnedAudio(context.Context, ports.AudioReceipt) error
}

type MediaRelayConfig struct {
	Mode string `json:"mode"`
	KeyEnv string `json:"key_env"`
	Spool string `json:"spool"`
}

func (c MediaRelayConfig) Key() ([]byte, error) {
	if c.KeyEnv != "CHAT_MEDIA_RELAY_KEY" {
		return nil, ports.ErrInvalid
	}
	key, err := hex.DecodeString(os.Getenv(c.KeyEnv))
	if err != nil || len(key) != 32 {
		return nil, ports.ErrInvalid
	}
	return key, nil
}

func (c MediaRelayConfig) Validate(environment string) error {
	switch c.Mode {
	case "":
		if c.Spool != "" { return ports.ErrInvalid }
		return nil
	case "receive":
		if environment != "test" || c.Spool != "" { return ports.ErrInvalid }
	case "dispatch":
		if environment != "production" || !filepath.IsAbs(c.Spool) { return ports.ErrInvalid }
		st, err := os.Lstat(c.Spool)
		if err != nil || !st.IsDir() || st.Mode().Perm() & 0077 != 0 { return ports.ErrInvalid }
	default:
		return ports.ErrInvalid
	}
	_, err := c.Key()
	return err
}

func validRelayReceipt(v ports.AudioReceipt) bool {
	if !appPattern.MatchString(v.AppID) || !tracePattern.MatchString(v.TraceID) ||
		v.Code != "official_"+v.Decision || v.Fingerprint != hash(v.AppID+"\n"+v.TraceID+"\n"+v.Decision) {
		return false
	}
	switch v.Decision {
	case "pass", "risky", "review", "error":
		return true
	}
	return false
}

func relayMAC(key []byte, stamp string, body []byte) []byte {
	mac := hmac.New(sha256.New, key)
	_, _ = io.WriteString(mac, "chat-media-receipt-v1\n"+MediaRelayPath+"\n"+stamp+"\n")
	_, _ = mac.Write(body)
	return mac.Sum(nil)
}

func NewMediaRelayReceiver(key []byte, receiver OwnedAudioReceiver) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		if r.Method != http.MethodPost || r.URL.RequestURI() != MediaRelayPath {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		stamps, signatures := r.Header.Values("X-Chat-Relay-Time"), r.Header.Values("X-Chat-Relay-Signature")
		body, err := io.ReadAll(io.LimitReader(r.Body, 1025))
		if err != nil || len(body) > 1024 || len(stamps) != 1 || len(signatures) != 1 {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		now := time.Now().Unix()
		stamp, stampErr := strconv.ParseInt(stamps[0], 10, 64)
		sig, sigErr := hex.DecodeString(signatures[0])
		if stampErr != nil || stamp < now-30 || stamp > now+30 || sigErr != nil || !hmac.Equal(sig, relayMAC(key, stamps[0], body)) {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		var receipt ports.AudioReceipt
		if json.Unmarshal(body, &receipt) != nil || !validRelayReceipt(receipt) {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), time.Second)
		defer cancel()
		switch err := receiver.ReceiveOwnedAudio(ctx, receipt); {
		case err == nil:
			w.WriteHeader(http.StatusNoContent)
		case errors.Is(err, ErrReceiptUnowned):
			w.WriteHeader(http.StatusNotFound)
		default:
			w.WriteHeader(http.StatusServiceUnavailable)
		}
	})
}

type MediaRelay struct {
	dir string
	key []byte
	local OwnedAudioReceiver
	client *http.Client
	writers sync.Mutex
	cursor int
}

func NewMediaRelay(dir string, key []byte, local OwnedAudioReceiver) *MediaRelay {
	return &MediaRelay{dir: dir, key: key, local: local, client: &http.Client{
		Timeout: 500*time.Millisecond,
		Transport: &http.Transport{MaxIdleConnsPerHost: 2, IdleConnTimeout: 30*time.Second},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}}
}

func (r *MediaRelay) ReceiveAudio(_ context.Context, receipt ports.AudioReceipt) error {
	if !validRelayReceipt(receipt) { return ports.ErrInvalid }
	r.writers.Lock()
	defer r.writers.Unlock()
	name := filepath.Join(r.dir, receipt.Fingerprint+".json")
	if _, err := os.Lstat(name); err == nil { return nil }
	entries, err := os.ReadDir(r.dir)
	if err != nil || len(entries) >= relayCapacity { return ports.ErrUnavailable }
	raw, err := json.Marshal(receipt)
	if err != nil { return err }
	f, err := os.CreateTemp(r.dir, ".receipt-")
	if err != nil { return err }
	defer os.Remove(f.Name())
	_, writeErr := f.Write(raw)
	syncErr, closeErr := f.Sync(), f.Close()
	if writeErr != nil || syncErr != nil || closeErr != nil { return ports.ErrUnavailable }
	if err := os.Rename(f.Name(), name); err != nil { return err }
	dir, err := os.Open(r.dir)
	if err == nil {
		defer dir.Close()
		err = dir.Sync()
	}
	if err != nil { _ = os.Remove(name) }
	return err
}

func (r *MediaRelay) deliver(ctx context.Context, receipt ports.AudioReceipt) error {
	if r.local != nil {
		err := r.local.ReceiveOwnedAudio(ctx, receipt)
		if !errors.Is(err, ErrReceiptUnowned) { return err }
	}
	raw, err := json.Marshal(receipt)
	if err != nil { return err }
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, relayURL, bytes.NewReader(raw))
	if err != nil { return err }
	stamp := strconv.FormatInt(time.Now().Unix(), 10)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Chat-Relay-Time", stamp)
	req.Header.Set("X-Chat-Relay-Signature", hex.EncodeToString(relayMAC(r.key, stamp, raw)))
	resp, err := r.client.Do(req)
	if err != nil { return ports.ErrUnavailable }
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusNoContent { return ports.ErrUnavailable }
	return nil
}

func (r *MediaRelay) sweep(ctx context.Context, logger *slog.Logger) {
	entries, err := os.ReadDir(r.dir)
	if err != nil || len(entries) == 0 { return }
	for i := 0; i < len(entries) && i < 64 && ctx.Err() == nil; i++ {
		entry := entries[r.cursor%len(entries)]
		r.cursor = (r.cursor+1)%len(entries)
		prefix, hasSuffix := strings.CutSuffix(entry.Name(), ".json")
		_, nameErr := hex.DecodeString(prefix)
		isReceipt := hasSuffix && len(prefix) == 64 && nameErr == nil
		if !isReceipt && !strings.HasPrefix(entry.Name(), ".receipt-") { continue }
		info, err := entry.Info()
		if err != nil || !info.Mode().IsRegular() { continue }
		name := filepath.Join(r.dir, entry.Name())
		if time.Since(info.ModTime()) > relayLifetime {
			if os.Remove(name) == nil && isReceipt {
				logger.Warn("chat_media_relay_expired")
			}
			continue
		}
		if !isReceipt || info.Size() > 1024 { continue }
		raw, err := os.ReadFile(name)
		var receipt ports.AudioReceipt
		if err != nil || json.Unmarshal(raw, &receipt) != nil || !validRelayReceipt(receipt) { continue }
		attempt, cancel := context.WithTimeout(ctx, time.Second)
		err = r.deliver(attempt, receipt)
		cancel()
		if err == nil { _ = os.Remove(name) }
	}
}

func (r *MediaRelay) Run(ctx context.Context, logger *slog.Logger) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	defer r.client.CloseIdleConnections()
	for {
		pass, cancel := context.WithTimeout(ctx, 3*time.Second)
		r.sweep(pass, logger)
		cancel()
		select {
		case <-ctx.Done(): return
		case <-ticker.C:
		}
	}
}
