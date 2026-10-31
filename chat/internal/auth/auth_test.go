package auth

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type memoryStore struct {
	mu     sync.Mutex
	values map[string]string
	fail   bool
}

func (m *memoryStore) Put(_ context.Context, k, v string, _ time.Duration) (bool, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.fail {
		return false, ports.ErrUnavailable
	}
	if _, ok := m.values[k]; ok {
		return false, nil
	}
	m.values[k] = v
	return true, nil
}
func (m *memoryStore) Take(_ context.Context, k string) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.fail {
		return "", ports.ErrUnavailable
	}
	v := m.values[k]
	delete(m.values, k)
	return v, nil
}

type sourceFunc func(context.Context, Claims) error

func (f sourceFunc) Validate(ctx context.Context, c Claims) error { return f(ctx, c) }
func fixture(t *testing.T) (*Service, ed25519.PrivateKey, Claims, *memoryStore) {
	t.Helper()
	pub, priv, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	v, e := NewVerifier("test", map[string]ed25519.PublicKey{"v1": pub})
	if e != nil {
		t.Fatal(e)
	}
	m := &memoryStore{values: map[string]string{}}
	s, e := NewService(v, sourceFunc(func(context.Context, Claims) error { return nil }), m, 2, 100*time.Millisecond)
	if e != nil {
		t.Fatal(e)
	}
	now := time.Now().Unix()
	c := Claims{"bixuan-php:test", "bixuan-chat", "18446744073709551615", "mini", "1", strings.Repeat("a", 64), strings.Repeat("b", 64), "test", []string{"chat:use"}, now, now, now + 300, strings.Repeat("c", 64)}
	return s, priv, c, m
}
func sign(priv ed25519.PrivateKey, header, body string) string {
	a := base64.RawURLEncoding.EncodeToString([]byte(header)) + "." + base64.RawURLEncoding.EncodeToString([]byte(body))
	return a + "." + base64.RawURLEncoding.EncodeToString(ed25519.Sign(priv, []byte(a)))
}
func token(priv ed25519.PrivateKey, c Claims) string {
	b, _ := json.Marshal(c)
	return sign(priv, `{"alg":"EdDSA","typ":"JWT","kid":"v1"}`, string(b))
}

func TestTokenBoundaries(t *testing.T) {
	s, priv, c, _ := fixture(t)
	now := time.Now()
	good := token(priv, c)
	if got, e := s.Check(context.Background(), good); e != nil || got.ActorID != c.ActorID {
		t.Fatal(got, e)
	}
	for _, mutate := range []func(*Claims){func(c *Claims) { c.Environment = "production" }, func(c *Claims) { c.Audience = "other" }, func(c *Claims) { c.ActorID = "0" }, func(c *Claims) { c.ExpiresAt = c.IssuedAt + 301 }, func(c *Claims) { c.ExpiresAt = now.Unix() }, func(c *Claims) { c.IssuedAt = now.Unix() + 20; c.NotBefore = c.IssuedAt }, func(c *Claims) { c.Scopes = []string{"chat:audit"} }, func(c *Claims) { c.SessionID = "old" }, func(c *Claims) { c.Scopes = []string{"chat:use", "chat:use"} }} {
		bad := c
		mutate(&bad)
		if _, e := s.verifier.Verify(token(priv, bad), now); e != ErrUnauthorized {
			t.Fatal("accepted invalid claims", bad, e)
		}
	}
	b, _ := json.Marshal(c)
	for _, h := range []string{`{"alg":"none","typ":"JWT","kid":"v1"}`, `{"alg":"EdDSA","typ":"JWT","kid":"v1","kid":"v1"}`, `{"ALG":"EdDSA","typ":"JWT","kid":"v1"}`, `{"alg":"EdDSA","typ":"JWT","kid":"missing"}`, `{"alg":"EdDSA","typ":"JWT","kid":"v1","jku":"https://invalid"}`} {
		if _, e := s.verifier.Verify(sign(priv, h, string(b)), now); e != ErrUnauthorized {
			t.Fatal("header accepted", h)
		}
	}
	for _, body := range []string{string(b[:len(b)-1]) + `,"sub":"2"}`, strings.Replace(string(b), `"sub":"18446744073709551615"`, `"sub":18446744073709551615`, 1), string(b) + ` {}`, strings.Replace(string(b), `"env"`, `"ENV"`, 1)} {
		if _, e := s.verifier.Verify(sign(priv, `{"alg":"EdDSA","typ":"JWT","kid":"v1"}`, body), now); e != ErrUnauthorized {
			t.Fatal("body accepted")
		}
	}
}

func TestTicketConcurrentReplayAndRevocation(t *testing.T) {
	s, priv, c, m := fixture(t)
	ticket, exp, e := s.IssueTicket(context.Background(), token(priv, c))
	if e != nil || time.Until(exp) > 30*time.Second {
		t.Fatal(e)
	}
	var successes atomic.Int32
	var wg sync.WaitGroup
	for range 24 {
		wg.Go(func() {
			if _, e := s.ConsumeTicket(context.Background(), ticket); e == nil {
				successes.Add(1)
			}
		})
	}
	wg.Wait()
	if successes.Load() != 1 {
		t.Fatal(successes.Load())
	}
	ticket, _, e = s.IssueTicket(context.Background(), token(priv, c))
	if e != nil {
		t.Fatal(e)
	}
	s.source = sourceFunc(func(context.Context, Claims) error { return ErrUnauthorized })
	if _, e = s.ConsumeTicket(context.Background(), ticket); e != ErrUnauthorized {
		t.Fatal("revoked ticket", e)
	}
	s.source = sourceFunc(func(context.Context, Claims) error { return nil })
	m.fail = true
	if _, _, e = s.IssueTicket(context.Background(), token(priv, c)); e != ports.ErrUnavailable {
		t.Fatal(e)
	}
	if _, e = s.Check(context.Background(), token(priv, c)); e != nil {
		t.Fatal("Redis must not block access-token HTTP", e)
	}
}

func TestSessionDeadlineBudgetAndSocketRefresh(t *testing.T) {
	s, priv, c, _ := fixture(t)
	socket := NewSocketIdentity(c)
	fresh := c
	fresh.ExpiresAt--
	if e := socket.Refresh(context.Background(), s, token(priv, fresh)); e != ErrUnauthorized {
		t.Fatal(e)
	}
	fresh = c
	fresh.ActorID = "3"
	if e := socket.Refresh(context.Background(), s, token(priv, fresh)); e != ErrUnauthorized {
		t.Fatal(e)
	}
	if e := socket.Refresh(context.Background(), s, token(priv, c)); e != nil {
		t.Fatal(e)
	}
	s.source = sourceFunc(func(context.Context, Claims) error { return ErrUnauthorized })
	if _, e := socket.Current(context.Background(), s); e != ErrUnauthorized {
		t.Fatal(e)
	}
	entered := make(chan struct{}, 2)
	s.source = sourceFunc(func(ctx context.Context, _ Claims) error { entered <- struct{}{}; <-ctx.Done(); return ctx.Err() })
	var wg sync.WaitGroup
	for range 2 {
		wg.Go(func() { s.Check(context.Background(), token(priv, c)) })
	}
	<-entered
	<-entered
	if _, e := s.Check(context.Background(), token(priv, c)); e != ports.ErrBusy {
		t.Fatal(e)
	}
	wg.Wait()
}

func TestInternalSignatureBindsRequestAndNonce(t *testing.T) {
	_, _, _, m := fixture(t)
	a, e := NewInternal("test", map[string][]byte{"v1": []byte(strings.Repeat("k", 32))}, m, 100*time.Millisecond)
	if e != nil {
		t.Fatal(e)
	}
	body := []byte(`{"actor_id":"1"}`)
	now := time.Now()
	r := httptest.NewRequest("POST", "http://127.0.0.1/internal?a=%2F&b=1", nil)
	r.RequestURI = r.URL.RequestURI()
	if e = a.Sign(r, body, "v1", now); e != nil {
		t.Fatal(e)
	}
	if e = a.Verify(context.Background(), r, []byte(`{}`), now); e != ErrUnauthorized {
		t.Fatal("body tamper", e)
	}
	other := r.Clone(context.Background())
	other.RequestURI = "/internal?b=1&a=%2F"
	if e = a.Verify(context.Background(), other, body, now); e != ErrUnauthorized {
		t.Fatal("query reorder", e)
	}
	var wg sync.WaitGroup
	var successes atomic.Int32
	for range 20 {
		wg.Go(func() {
			if a.Verify(context.Background(), r, body, now) == nil {
				successes.Add(1)
			}
		})
	}
	wg.Wait()
	if successes.Load() != 1 {
		t.Fatal(successes.Load())
	}
	a.Sign(r, body, "v1", now.Add(-31*time.Second))
	if e = a.Verify(context.Background(), r, body, now); e != ErrUnauthorized {
		t.Fatal(e)
	}
	a.Sign(r, body, "v1", now)
	r.Header.Add("X-Chat-Environment", "test")
	if e = a.Verify(context.Background(), r, body, now); e != ErrUnauthorized {
		t.Fatal(e)
	}
	a.Sign(r, body, "v1", now)
	m.fail = true
	if e = a.Verify(context.Background(), r, body, now); e != ports.ErrUnavailable {
		t.Fatal(e)
	}
}
