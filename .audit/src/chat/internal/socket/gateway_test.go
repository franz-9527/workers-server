package socket

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/gorilla/websocket"
)

type tickets struct {
	sync.Mutex
	values map[string]string
	fail   atomic.Bool
}

func (m *tickets) Put(_ context.Context, k, v string, _ time.Duration) (bool, error) {
	m.Lock()
	defer m.Unlock()
	if m.fail.Load() {
		return false, ports.ErrUnavailable
	}
	m.values[k] = v
	return true, nil
}
func (m *tickets) Take(_ context.Context, k string) (string, error) {
	m.Lock()
	defer m.Unlock()
	if m.fail.Load() {
		return "", ports.ErrUnavailable
	}
	v := m.values[k]
	delete(m.values, k)
	return v, nil
}

type source struct {
	revoked atomic.Bool
	calls   atomic.Int64
}

func (s *source) Validate(context.Context, auth.Claims) error {
	s.calls.Add(1)
	if s.revoked.Load() {
		return auth.ErrUnauthorized
	}
	return nil
}

type echo struct {
	calls atomic.Int64
	block atomic.Bool
}

func (e *echo) Execute(ctx context.Context, p ports.Principal, typ string, raw json.RawMessage) (string, any, error) {
	e.calls.Add(1)
	if typ == "large" {
		return "large.result", strings.Repeat("x", 200<<10), nil
	}
	if e.block.Load() {
		<-ctx.Done()
		return "", nil, ctx.Err()
	}
	return typ + ".result", map[string]any{"actor_id": p.ActorID}, nil
}

type fixture struct {
	g        *Gateway
	s        *auth.Service
	src      *source
	store    *tickets
	commands *echo
	priv     ed25519.PrivateKey
	claims   auth.Claims
	server   *httptest.Server
	url      string
}

func setup(t *testing.T) *fixture {
	t.Helper()
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	v, _ := auth.NewVerifier("test", map[string]ed25519.PublicKey{"v1": pub})
	src := &source{}
	store := &tickets{values: map[string]string{}}
	s, _ := auth.NewService(v, src, store, 16, 100*time.Millisecond)
	commands := &echo{}
	g, _ := New(s, commands)
	g.timing = timings{300 * time.Millisecond, 100 * time.Millisecond, 100 * time.Millisecond, 70 * time.Millisecond, 600 * time.Millisecond, 30 * time.Millisecond, 100 * time.Millisecond}
	server := httptest.NewServer(g)
	now := time.Now().Unix()
	c := auth.Claims{Issuer: "bixuan-php:test", Audience: "bixuan-chat", ActorID: "18446744073709551615", Kind: "mini", SourceID: "1", SessionID: strings.Repeat("a", 64), Version: strings.Repeat("b", 64), Environment: "test", Scopes: []string{"chat:use"}, IssuedAt: now, NotBefore: now, ExpiresAt: now + 300, JTI: strings.Repeat("c", 64)}
	f := &fixture{g, s, src, store, commands, priv, c, server, "ws" + strings.TrimPrefix(server.URL, "http") + "/chat/ws"}
	t.Cleanup(func() {
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		defer cancel()
		if err := g.Shutdown(ctx); err != nil {
			t.Error(err)
		}
		server.Close()
		if g.queuedBytes.Load() != 0 {
			t.Error("outbound byte budget leaked")
		}
	})
	return f
}
func (f *fixture) token(c auth.Claims) string {
	b, _ := json.Marshal(c)
	a := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"EdDSA","typ":"JWT","kid":"v1"}`)) + "." + base64.RawURLEncoding.EncodeToString(b)
	return a + "." + base64.RawURLEncoding.EncodeToString(ed25519.Sign(f.priv, []byte(a)))
}
func (f *fixture) ticket(t *testing.T) string {
	t.Helper()
	v, _, e := f.s.IssueTicket(context.Background(), f.token(f.claims))
	if e != nil {
		t.Fatal(e)
	}
	return v
}
func (f *fixture) dial(t *testing.T) *websocket.Conn {
	t.Helper()
	c, _, e := websocket.DefaultDialer.Dial(f.url, nil)
	if e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { c.Close() })
	return c
}
func send(t *testing.T, c *websocket.Conn, typ string, payload any) {
	t.Helper()
	b, e := json.Marshal(payload)
	if e != nil {
		t.Fatal(e)
	}
	if e = c.WriteJSON(frame{1, "r1", typ, b}); e != nil {
		t.Fatal(e)
	}
}
func read(t *testing.T, c *websocket.Conn, typ string) frame {
	t.Helper()
	c.SetReadDeadline(time.Now().Add(time.Second))
	var f frame
	if e := c.ReadJSON(&f); e != nil {
		t.Fatal(e)
	}
	if f.Type != typ || f.Version != 1 || f.RequestID != "r1" {
		t.Fatalf("wrong frame %s", f.Type)
	}
	return f
}
func (f *fixture) login(t *testing.T) *websocket.Conn {
	t.Helper()
	c := f.dial(t)
	send(t, c, "auth", map[string]string{"ticket": f.ticket(t)})
	read(t, c, "auth.ok")
	return c
}
func closed(t *testing.T, c *websocket.Conn) {
	t.Helper()
	c.SetReadDeadline(time.Now().Add(2 * time.Second))
	for {
		_, _, e := c.ReadMessage()
		if e != nil {
			var ce *websocket.CloseError
			if !errors.As(e, &ce) {
				t.Fatalf("connection timed out instead of closing: %v", e)
			}
			return
		}
	}
}

func TestFirstFrameReplayAndTimeout(t *testing.T) {
	f := setup(t)
	c := f.dial(t)
	closed(t, c)
	c = f.dial(t)
	send(t, c, "sync.request", map[string]string{})
	closed(t, c)
	ticket := f.ticket(t)
	c = f.dial(t)
	send(t, c, "auth", map[string]string{"ticket": ticket})
	read(t, c, "auth.ok")
	other := f.dial(t)
	send(t, other, "auth", map[string]string{"ticket": ticket})
	closed(t, other)
	if f.commands.calls.Load() != 0 {
		t.Fatal("unauthenticated command executed")
	}
}
func TestConcurrentTicketReplayOnlyOneConnection(t *testing.T) {
	f := setup(t)
	ticket := f.ticket(t)
	clients := make([]*websocket.Conn, 12)
	for i := range clients {
		clients[i] = f.dial(t)
	}
	var successes atomic.Int64
	var wg sync.WaitGroup
	for _, c := range clients {
		wg.Add(1)
		go func() {
			defer wg.Done()
			b, _ := json.Marshal(map[string]string{"ticket": ticket})
			c.WriteJSON(frame{1, "r1", "auth", b})
			c.SetReadDeadline(time.Now().Add(time.Second))
			var out frame
			if c.ReadJSON(&out) == nil && out.Type == "auth.ok" {
				successes.Add(1)
			}
		}()
	}
	wg.Wait()
	if successes.Load() != 1 {
		t.Fatalf("ticket accepted %d times", successes.Load())
	}
}
func TestRefreshIdentityRevocationAndTwoDevices(t *testing.T) {
	f := setup(t)
	a, b := f.login(t), f.login(t)
	send(t, a, "session.state", map[string]string{"state": "background"})
	read(t, a, "session.state")
	send(t, b, "sync.request", map[string]string{})
	read(t, b, "sync.request.result")
	next := f.claims
	next.IssuedAt++
	next.NotBefore++
	next.ExpiresAt++
	send(t, a, "auth.refresh", map[string]string{"access_token": f.token(next)})
	read(t, a, "auth.ok")
	next.ActorID = "2"
	send(t, a, "auth.refresh", map[string]string{"access_token": f.token(next)})
	closed(t, a)
	// Closing one device does not log out another; persistent PHP revocation does.
	send(t, b, "ping", map[string]string{"nonce": "alive"})
	read(t, b, "pong")
	f.src.revoked.Store(true)
	closed(t, b)
}
func TestExpiryCannotReviveAndIdleRevocation(t *testing.T) {
	f := setup(t)
	f.claims.ExpiresAt = time.Now().Unix() + 1
	c := f.login(t)
	closed(t, c)
	if f.commands.calls.Load() != 0 {
		t.Fatal("expiry executed a command")
	}
}
func TestHeartbeatRequiresMatchingPong(t *testing.T) {
	f := setup(t)
	c := f.login(t)
	c.SetPingHandler(func(string) error { return nil })
	closed(t, c)
	healthy := f.login(t)
	for i := 0; i < 5; i++ {
		time.Sleep(45 * time.Millisecond)
		send(t, healthy, "ping", map[string]string{"nonce": "app"})
		read(t, healthy, "pong")
	}
}
func TestRedisOutageDoesNotBypassTicketOrKillAuthenticatedHTTPIdentity(t *testing.T) {
	f := setup(t)
	c := f.login(t)
	ticket := f.ticket(t)
	f.store.fail.Store(true)
	newConnection := f.dial(t)
	send(t, newConnection, "auth", map[string]string{"ticket": ticket})
	closed(t, newConnection)
	send(t, c, "sync.request", map[string]string{})
	read(t, c, "sync.request.result")
	if _, e := f.s.Authenticate(context.Background(), f.token(f.claims)); e != nil {
		t.Fatal(e)
	}
}
func TestAdmissionAndProtocolBoundaries(t *testing.T) {
	f := setup(t)
	for _, tc := range []struct {
		suffix  string
		headers http.Header
	}{{"?ticket=private", nil}, {"?", nil}, {"", http.Header{"Authorization": {"Bearer private"}}}, {"", http.Header{"Sec-WebSocket-Protocol": {"private"}}}, {"", http.Header{"Origin": {"https://evil.invalid"}}}} {
		c, res, e := websocket.DefaultDialer.Dial(f.url+tc.suffix, tc.headers)
		if e == nil {
			c.Close()
			t.Fatal("unsafe upgrade accepted")
		}
		if res != nil {
			res.Body.Close()
		}
	}
	for _, raw := range []string{`{"protocol_version":1,"request_id":"r1","type":"auth","payload":{"ticket":"x","ticket":"x"}}`, `{"protocol_version":1,"request_id":"r1","type":"auth","payload":{},"TYPE":"auth"}`, `{"protocol_version":1,"request_id":"r1","type":"auth","payload":{"ticket":"\ud800"}}`} {
		c := f.dial(t)
		c.WriteMessage(websocket.TextMessage, []byte(raw))
		closed(t, c)
	}
	c := f.login(t)
	c.WriteMessage(websocket.BinaryMessage, []byte("binary"))
	closed(t, c)
	c = f.login(t)
	c.WriteMessage(websocket.TextMessage, []byte(strings.Repeat("x", (32<<10)+1)))
	closed(t, c)
}
func TestBoundedQueueAndShutdownIsolation(t *testing.T) {
	f := setup(t)
	healthy := f.login(t)
	ctx, cancel := context.WithCancel(context.Background())
	slow := &client{g: f.g, ctx: ctx, cancel: cancel, out: make(chan outbound, 8), stop: make(chan struct{})}
	for range 8 {
		if !slow.enqueue(outbound{}) {
			t.Fatal("early overflow")
		}
	}
	if slow.enqueue(outbound{}) {
		t.Fatal("unbounded queue")
	}
	if slow.code.Load() != websocket.CloseTryAgainLater {
		t.Fatal("slow client not disconnected")
	}
	for len(slow.out) > 0 {
		o := <-slow.out
		f.g.queuedBytes.Add(-int64(len(o.frame.Payload) + len(o.bytes) + 128))
	}
	send(t, healthy, "sync.request", map[string]string{})
	read(t, healthy, "sync.request.result")
	// Shutdown interrupts a blocked use case and owns the hijacked connection.
	f.commands.block.Store(true)
	send(t, healthy, "sync.request", map[string]string{})
	shutdown, stop := context.WithTimeout(context.Background(), time.Second)
	defer stop()
	if e := f.g.Shutdown(shutdown); e != nil {
		t.Fatal(e)
	}
	closed(t, healthy)
	_, res, e := websocket.DefaultDialer.Dial(f.url, nil)
	if e == nil || res == nil || res.StatusCode != 503 {
		t.Fatal("shutdown admitted a connection")
	}
	res.Body.Close()
}
func TestStrictEnvelope(t *testing.T) {
	for _, raw := range []string{`null`, `[]`, `{"protocol_version":2,"request_id":"r1","type":"ping","payload":{}}`, `{"protocol_version":1,"request_id":"bad\n","type":"ping","payload":{}}`, `{"protocol_version":1,"request_id":"r1","type":"ping","payload":null}`} {
		if _, ok := decode([]byte(raw)); ok {
			t.Fatal("invalid envelope accepted")
		}
	}
}

func TestNetworkSlowReaderDoesNotBlockAnotherConnection(t *testing.T) {
	f := setup(t)
	slow := f.login(t)
	healthy := f.login(t)
	// Make backpressure deterministic rather than relying on the OS default
	// several-megabyte TCP buffers or flooding the process with test data.
	slow.UnderlyingConn().(*net.TCPConn).SetReadBuffer(1024)
	f.g.mu.Lock()
	var serverSide *client
	for c := range f.g.clients {
		if c.ws.RemoteAddr().String() == slow.LocalAddr().String() {
			serverSide = c
			c.ws.UnderlyingConn().(*net.TCPConn).SetWriteBuffer(1024)
		}
	}
	f.g.mu.Unlock()
	if serverSide == nil {
		t.Fatal("connection registry")
	}
	for range 12 {
		send(t, slow, "large", map[string]string{})
	}
	send(t, healthy, "sync.request", map[string]string{})
	read(t, healthy, "sync.request.result")
	select {
	case <-serverSide.stop:
	case <-time.After(time.Second):
		t.Fatal("slow reader not bounded")
	}
}

func TestBoundedGlobalOutputBudget(t *testing.T) {
	f := setup(t)
	f.g.queuedBytes.Store(16 << 20)
	ctx, cancel := context.WithCancel(context.Background())
	c := &client{g: f.g, ctx: ctx, cancel: cancel, out: make(chan outbound, 8), stop: make(chan struct{})}
	if c.enqueue(outbound{}) || c.code.Load() != websocket.CloseTryAgainLater {
		t.Fatal("global byte budget bypassed")
	}
	if f.g.queuedBytes.Load() != 16<<20 {
		t.Fatal("failed enqueue retained bytes")
	}
	f.g.queuedBytes.Store(0)
}

type contentionSource struct {
	entered chan struct{}
	release chan struct{}
	revoked atomic.Bool
}

func (s *contentionSource) Validate(ctx context.Context, c auth.Claims) error {
	select {
	case s.entered <- struct{}{}:
	default:
	}
	select {
	case <-ctx.Done():
		return ports.ErrUnavailable
	case <-s.release:
	}
	if s.revoked.Load() {
		return auth.ErrUnauthorized
	}
	return nil
}
func TestS21SocketAuthorityContentionRetriesWithinBudgetAndStillRevokes(t *testing.T) {
	f := setup(t)
	pub := f.priv.Public().(ed25519.PublicKey)
	verifier, _ := auth.NewVerifier("test", map[string]ed25519.PublicKey{"v1": pub})
	src := &contentionSource{entered: make(chan struct{}, 1), release: make(chan struct{})}
	service, _ := auth.NewService(verifier, src, f.store, 1, 100*time.Millisecond)
	finished := make(chan error, 1)
	go func() { _, err := service.Check(context.Background(), f.token(f.claims)); finished <- err }()
	<-src.entered
	c := &client{ctx: context.Background(), g: &Gateway{identity: service, timing: timings{operation: 100 * time.Millisecond}}, identity: auth.NewSocketIdentity(f.claims)}
	timer := time.AfterFunc(20*time.Millisecond, func() { close(src.release) })
	defer timer.Stop()
	if _, err := c.current(); err != nil {
		t.Fatal("transient contention treated as revocation", err)
	}
	if err := <-finished; err != nil {
		t.Fatal(err)
	}
	src.revoked.Store(true)
	if _, err := c.current(); err != auth.ErrUnauthorized {
		t.Fatal("real revoke was softened", err)
	}
}
