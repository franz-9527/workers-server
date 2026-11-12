package moderation

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type ownedReceiptFixture map[string]int

func (f ownedReceiptFixture) ReceiveOwnedAudio(_ context.Context, receipt ports.AudioReceipt) error {
	if _, ok := f[receipt.TraceID]; !ok {
		return ErrReceiptUnowned
	}
	f[receipt.TraceID]++
	return nil
}

type relayTestTransport func(*http.Request) (*http.Response, error)

func (f relayTestTransport) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }

func relayFixture(trace string) ports.AudioReceipt {
	const app = "wx0123456789abcdef"
	return ports.AudioReceipt{AppID: app, TraceID: trace, Decision: "pass", Code: "official_pass", Fingerprint: hash(app + "\n" + trace + "\npass")}
}

func TestMediaRelayDeliversOnlyToOwningSite(t *testing.T) {
	dir := t.TempDir()
	key := []byte(strings.Repeat("k", 32))
	local := ownedReceiptFixture{"production-trace": 0}
	test := ownedReceiptFixture{}
	h := NewMediaRelayReceiver(key, test)
	relay := NewMediaRelay(dir, key, local)
	available := false
	relay.client.Transport = relayTestTransport(func(req *http.Request) (*http.Response, error) {
		if req.URL.String() != relayURL {
			t.Fatal("unexpected relay destination")
		}
		if !available {
			return nil, ports.ErrUnavailable
		}
		res := httptest.NewRecorder()
		h.ServeHTTP(res, req)
		return res.Result(), nil
	})
	ctx := context.Background()
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	prod, dev, unknown := relayFixture("production-trace"), relayFixture("test-trace"), relayFixture("unknown-trace")
	for _, receipt := range []ports.AudioReceipt{prod, dev, dev, unknown} {
		if err := relay.ReceiveAudio(ctx, receipt); err != nil {
			t.Fatal(err)
		}
	}
	relay.sweep(ctx, logger)
	if local[prod.TraceID] != 1 || len(test) != 0 {
		t.Fatal("an unavailable test site interfered with production or accepted an unowned receipt")
	}
	devPath := filepath.Join(dir, dev.Fingerprint+".json")
	if _, err := os.Stat(devPath); err != nil {
		t.Fatal("the test callback must survive an outage", err)
	}
	available = true
	relay.sweep(ctx, logger)
	if len(test) != 0 {
		t.Fatal("receipt was written before the test trace existed")
	}
	test[dev.TraceID] = 0
	relay.sweep(ctx, logger)
	if test[dev.TraceID] != 1 || len(local) != 1 || len(test) != 1 {
		t.Fatal("receipt was duplicated or crossed site ownership")
	}
	if _, err := os.Stat(devPath); !os.IsNotExist(err) {
		t.Fatal("confirmed test delivery was not cleared", err)
	}
	old := time.Now().Add(-2 * relayLifetime)
	unknownPath := filepath.Join(dir, unknown.Fingerprint+".json")
	if err := os.Chtimes(unknownPath, old, old); err != nil {
		t.Fatal(err)
	}
	relay.sweep(ctx, logger)
	if _, err := os.Stat(unknownPath); !os.IsNotExist(err) {
		t.Fatal("expired unknown callback was retained", err)
	}
}

func TestMediaRelayReceiverRejectsUnsignedStaleOrChangedReceipt(t *testing.T) {
	key := []byte(strings.Repeat("k", 32))
	receiver := ownedReceiptFixture{"test-trace": 0}
	handler := NewMediaRelayReceiver(key, receiver)
	raw, err := json.Marshal(relayFixture("test-trace"))
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	for _, tc := range []struct {
		name  string
		body  []byte
		stamp time.Time
		sign  bool
		code  int
	}{
		{"unsigned", raw, now, false, http.StatusBadRequest},
		{"stale", raw, now.Add(-time.Minute), true, http.StatusUnauthorized},
		{"modified", append(append([]byte(nil), raw...), ' '), now, true, http.StatusUnauthorized},
		{"valid", raw, now, true, http.StatusNoContent},
	} {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest(http.MethodPost, MediaRelayPath, bytes.NewReader(tc.body))
			if tc.sign {
				stamp := strconv.FormatInt(tc.stamp.Unix(), 10)
				req.Header.Set("X-Chat-Relay-Time", stamp)
				req.Header.Set("X-Chat-Relay-Signature", hex.EncodeToString(relayMAC(key, stamp, raw)))
			}
			res := httptest.NewRecorder()
			handler.ServeHTTP(res, req)
			if res.Code != tc.code {
				t.Fatalf("got %d, want %d", res.Code, tc.code)
			}
		})
	}
	if receiver["test-trace"] != 1 {
		t.Fatal("unauthenticated relay was processed")
	}
}

func TestMediaRelayModesAreEnvironmentBound(t *testing.T) {
	const env = "CHAT_MEDIA_RELAY_KEY"
	t.Setenv(env, strings.Repeat("e", 64))
	dir := t.TempDir()
	if err := os.Chmod(dir, 0700); err != nil {
		t.Fatal(err)
	}
	send := MediaRelayConfig{Mode: "dispatch", KeyEnv: env, Spool: dir}
	receive := MediaRelayConfig{Mode: "receive", KeyEnv: env}
	if send.Validate("production") != nil || receive.Validate("test") != nil {
		t.Fatal("valid relay configuration was rejected")
	}
	if send.Validate("test") == nil || receive.Validate("production") == nil {
		t.Fatal("relay accepted a crossed environment")
	}
	if err := os.Chmod(dir, 0755); err != nil {
		t.Fatal(err)
	}
	if send.Validate("production") == nil {
		t.Fatal("relay accepted a nonprivate spool")
	}
}

func TestVerifiedWeChatCallbackCanQueueBeforeFormalAVIsOpen(t *testing.T) {
	dir := t.TempDir()
	relay := NewMediaRelay(dir, bytes.Repeat([]byte("r"), 32), nil)
	app := "wx0123456789abcdef"
	callback, err := NewMediaCallback(app, strings.Repeat("t", 24), base64.RawStdEncoding.EncodeToString(bytes.Repeat([]byte("k"), 32)), relay)
	if err != nil {
		t.Fatal(err)
	}
	event := `{"appid":"wx0123456789abcdef","Event":"wxa_media_check","trace_id":"pending-test-trace","version":2,"errcode":0,"result":{"suggest":"pass"}}`
	req := callbackRequest(t, callback, event, false)
	res := httptest.NewRecorder()
	callback.ServeHTTP(res, req)
	if res.Code != http.StatusOK || res.Body.String() != "success" {
		t.Fatal("a verified event was not queued", res.Code)
	}
	entries, err := os.ReadDir(dir)
	if err != nil || len(entries) != 1 {
		t.Fatal("acknowledgement requires durable storage", err)
	}
	req = callbackRequest(t, callback, event, false)
	req.URL.RawQuery = strings.Replace(req.URL.RawQuery, "msg_signature=", "msg_signature=0", 1)
	res = httptest.NewRecorder()
	callback.ServeHTTP(res, req)
	if res.Code != http.StatusForbidden {
		t.Fatal("an invalid WeChat signature was accepted")
	}
	relay.dir = filepath.Join(dir, "missing")
	res = httptest.NewRecorder()
	callback.ServeHTTP(res, callbackRequest(t, callback, event, false))
	if res.Code != http.StatusServiceUnavailable {
		t.Fatal("a receipt was acknowledged without durable storage")
	}
}
