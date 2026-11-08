package socket

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/gorilla/websocket"
)

type Config struct {
	Enabled bool `json:"enabled"`
}
type Commands interface {
	Execute(context.Context, ports.Principal, string, json.RawMessage) (string, any, error)
}
type Gateway struct {
	identity       *auth.Service
	commands       Commands
	authGate       chan struct{}
	readGate       chan struct{}
	writeGate      chan struct{}
	externalGate   chan struct{}
	mu             sync.Mutex
	clients        map[*client]struct{}
	preAuth        map[*client]struct{}
	preAuthIPs     map[string]*preAuthState
	authenticating int
	limits         admissionLimits
	stopping       bool
	queuedBytes    atomic.Int64
	wg             sync.WaitGroup
	timing         timings
	realtime       *realtime
}
type timings struct{ auth, write, ping, pong, idle, check, operation time.Duration }
type admissionLimits struct {
	preAuth, authenticated, perIP, attempts int
	window                                  time.Duration
}
type preAuthState struct {
	active, attempts int
	windowAt         time.Time
	lastSeen         time.Time
}

func New(identity *auth.Service, commands Commands) (*Gateway, error) {
	if identity == nil || commands == nil {
		return nil, ports.ErrInvalid
	}
	return &Gateway{
		identity: identity, commands: commands,
		authGate: make(chan struct{}, 8), readGate: make(chan struct{}, 32), writeGate: make(chan struct{}, 16), externalGate: make(chan struct{}, 8),
		clients: map[*client]struct{}{}, preAuth: map[*client]struct{}{}, preAuthIPs: map[string]*preAuthState{},
		limits: admissionLimits{preAuth: 48, authenticated: 300, perIP: 12, attempts: 30, window: time.Second},
		timing: timings{5 * time.Second, 2 * time.Second, 30 * time.Second, 10 * time.Second, 90 * time.Second, 15 * time.Second, 4 * time.Second},
	}, nil
}

type outbound struct {
	frame        frame
	control      int
	bytes        []byte
	protected    bool
	epoch        uint64
	conversation ports.ID
	typingSource *client
	typingUntil  time.Time
}
type client struct {
	g             *Gateway
	ws            *websocket.Conn
	transportMu   sync.Mutex
	expires       atomic.Int64
	authUntil     time.Time
	ctx           context.Context
	cancel        context.CancelFunc
	identity      *auth.SocketIdentity
	actor         ports.ID
	kind          string
	out           chan outbound
	queueMu       sync.Mutex
	stop          chan struct{}
	once          sync.Once
	code          atomic.Int32
	epoch         atomic.Uint64
	foreground    atomic.Bool
	heartbeatMu   sync.Mutex
	challenge     string
	pongUntil     time.Time
	lastActivity  time.Time
	lastAuthority atomic.Int64
	remoteIP      string
	expiryWake    chan struct{}
	budgetMu      sync.Mutex
	budget        int
	budgetAt      time.Time
	stateMu       sync.Mutex
	active        ports.ID
	presenceAt    time.Time
	typingAt      time.Time
	typingUntil   time.Time
}

func socketIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	remote := net.ParseIP(strings.TrimSpace(host))
	if remote == nil {
		return "unknown"
	}
	if remote.IsLoopback() {
		values := r.Header.Values("X-Real-IP")
		if len(values) == 1 {
			if forwarded := net.ParseIP(strings.TrimSpace(values[0])); forwarded != nil {
				return forwarded.String()
			}
		}
	}
	return remote.String()
}
func (g *Gateway) prunePreAuthIPsLocked(now time.Time) {
	if len(g.preAuthIPs) <= 1024 {
		return
	}
	for ip, state := range g.preAuthIPs {
		if state.active == 0 && now.Sub(state.lastSeen) > 2*time.Minute {
			delete(g.preAuthIPs, ip)
		}
	}
}
func (g *Gateway) admitPreAuth(c *client, now time.Time) (int, string) {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.stopping || len(g.preAuth) >= g.limits.preAuth {
		return http.StatusServiceUnavailable, "CHAT_RESOURCE_BUSY"
	}
	state := g.preAuthIPs[c.remoteIP]
	if state == nil {
		state = &preAuthState{windowAt: now}
		g.preAuthIPs[c.remoteIP] = state
	}
	if now.Sub(state.windowAt) >= g.limits.window {
		state.windowAt = now
		state.attempts = 0
	}
	state.lastSeen = now
	if state.active >= g.limits.perIP || state.attempts >= g.limits.attempts {
		return http.StatusTooManyRequests, "CHAT_SOCKET_RATE_LIMITED"
	}
	state.active++
	state.attempts++
	g.preAuth[c] = struct{}{}
	g.wg.Add(1)
	g.prunePreAuthIPsLocked(now)
	return 0, ""
}
func (g *Gateway) releasePreAuthLocked(c *client, now time.Time) {
	if _, ok := g.preAuth[c]; !ok {
		return
	}
	delete(g.preAuth, c)
	if state := g.preAuthIPs[c.remoteIP]; state != nil {
		if state.active > 0 {
			state.active--
		}
		state.lastSeen = now
	}
}
func (g *Gateway) removeClient(c *client) {
	g.mu.Lock()
	g.releasePreAuthLocked(c, time.Now())
	delete(g.clients, c)
	g.mu.Unlock()
}
func (g *Gateway) reserveAuthenticated() bool {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.stopping || len(g.clients)+g.authenticating >= g.limits.authenticated {
		return false
	}
	g.authenticating++
	return true
}
func (g *Gateway) finishAuthentication(c *client, success bool) bool {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.authenticating > 0 {
		g.authenticating--
	}
	if !success || g.stopping {
		return false
	}
	g.releasePreAuthLocked(c, time.Now())
	g.clients[c] = struct{}{}
	return true
}
func (g *Gateway) gateWait() time.Duration {
	wait := g.timing.operation / 4
	if wait < 25*time.Millisecond {
		return 25 * time.Millisecond
	}
	if wait > 500*time.Millisecond {
		return 500 * time.Millisecond
	}
	return wait
}
func (g *Gateway) acquire(ctx context.Context, gate chan struct{}) bool {
	if gate == nil {
		return true
	}
	timer := time.NewTimer(g.gateWait())
	defer timer.Stop()
	select {
	case gate <- struct{}{}:
		return true
	case <-ctx.Done():
		return false
	case <-timer.C:
		return false
	}
}
func (g *Gateway) commandGate(typ string) chan struct{} {
	if typ == "ping" || typ == "session.state" {
		return nil
	}
	if typ == "auth.refresh" {
		return g.authGate
	}
	if strings.HasPrefix(typ, "calls.") {
		return g.externalGate
	}
	switch typ {
	case "message.send", "message.recall", "conversation.preference", "receipt.read":
		return g.writeGate
	default:
		return g.readGate
	}
}
func (c *client) wakeExpiry() {
	select {
	case c.expiryWake <- struct{}{}:
	default:
	}
}

func (g *Gateway) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	if r.URL.Path != "/chat/ws" {
		failHTTP(w, r, 404, "CHAT_ROUTE_NOT_FOUND")
		return
	}
	if r.Method != "GET" {
		w.Header().Set("Allow", "GET")
		failHTTP(w, r, 405, "CHAT_METHOD_NOT_ALLOWED")
		return
	}
	// Credentials are accepted only in the first frame. No query or protocol
	// token can leak through access logs, referers or proxy diagnostics.
	if r.URL.RawQuery != "" || r.URL.ForceQuery || r.URL.RawPath != "" || len(r.Header.Values("Authorization")) != 0 || len(r.Header.Values("Sec-WebSocket-Protocol")) != 0 || r.ContentLength > 0 {
		failHTTP(w, r, 400, "CHAT_INVALID_REQUEST")
		return
	}
	ctx, cancel := context.WithCancel(context.Background())
	now := time.Now()
	c := &client{g: g, ctx: ctx, cancel: cancel, out: make(chan outbound, 8), stop: make(chan struct{}), lastActivity: now, budgetAt: now, authUntil: now.Add(g.timing.auth), remoteIP: socketIP(r), expiryWake: make(chan struct{}, 1)}
	if status, code := g.admitPreAuth(c, now); status != 0 {
		cancel()
		failHTTP(w, r, status, code)
		return
	}
	defer func() {
		cancel()
		c.clearTransient()
		g.removeClient(c)
		g.wg.Done()
	}()
	up := websocket.Upgrader{HandshakeTimeout: g.timing.write, ReadBufferSize: 4096, WriteBufferSize: 4096, EnableCompression: false, Error: func(w http.ResponseWriter, r *http.Request, status int, _ error) {
		failHTTP(w, r, status, "CHAT_SOCKET_UPGRADE_INVALID")
	}, CheckOrigin: func(r *http.Request) bool {
		origins := r.Header.Values("Origin")
		if len(origins) == 0 {
			return true
		}
		if len(origins) != 1 {
			return false
		}
		u, e := url.Parse(origins[0])
		return e == nil && u.Scheme == "https" && u.Host == r.Host && u.User == nil && u.RawQuery == "" && u.Fragment == "" && u.Path == ""
	}}
	ws, e := up.Upgrade(w, r, nil)
	if e != nil {
		return
	}
	c.transportMu.Lock()
	c.ws = ws
	c.transportMu.Unlock()
	defer ws.Close()
	select {
	case <-c.stop:
		return
	default:
	}
	ws.SetReadLimit(32 << 10)
	_ = ws.SetReadDeadline(time.Now().Add(g.timing.auth))
	// Control replies also go through the one writer. Control floods consume
	// the same in-memory budget as application commands.
	ws.SetPingHandler(func(s string) error {
		if !c.allow() {
			return ports.ErrBusy
		}
		if !c.enqueue(outbound{control: websocket.PongMessage, bytes: []byte(s)}) {
			return ports.ErrBusy
		}
		return nil
	})
	ws.SetPongHandler(func(s string) error { c.pong(s); return nil })
	ws.SetCloseHandler(func(int, string) error { return nil })
	writerDone := make(chan struct{})
	go func() { defer close(writerDone); c.writeLoop() }()
	expiryDone := make(chan struct{})
	go func() { defer close(expiryDone); c.expiryLoop() }()
	defer func() { c.close(websocket.CloseNormalClosure); <-writerDone; <-expiryDone }()
	kind, raw, e := ws.ReadMessage()
	f, ok := decode(raw)
	var q struct {
		Ticket string `json:"ticket"`
	}
	if e != nil || kind != websocket.TextMessage || !ok || f.Type != "auth" || !strict(f.Payload, &q) {
		c.close(websocket.ClosePolicyViolation)
		return
	}
	if !g.reserveAuthenticated() {
		c.close(websocket.CloseTryAgainLater)
		return
	}
	if !g.acquire(c.ctx, g.authGate) {
		g.finishAuthentication(c, false)
		c.close(websocket.CloseTryAgainLater)
		return
	}
	op, stop := context.WithDeadline(c.ctx, minTime(c.authUntil, time.Now().Add(g.timing.operation)))
	claims, e := g.identity.ConsumeTicket(op, q.Ticket)
	stop()
	<-g.authGate
	if e != nil {
		g.finishAuthentication(c, false)
		c.close(websocket.ClosePolicyViolation)
		return
	}
	if !g.finishAuthentication(c, true) {
		c.close(websocket.CloseTryAgainLater)
		return
	}
	c.identity = auth.NewSocketIdentity(claims)
	c.actor = claims.ActorID
	c.kind = claims.Kind
	c.expires.Store(claims.ExpiresAt)
	c.epoch.Store(1)
	c.lastAuthority.Store(time.Now().UnixNano())
	c.wakeExpiry()
	c.foreground.Store(true)
	c.send(f, "auth.ok", map[string]any{"actor_id": claims.ActorID, "expires_at": claims.ExpiresAt, "heartbeat_seconds": 30}, true)
	_ = ws.SetReadDeadline(time.Now().Add(g.timing.idle))
	for {
		kind, raw, e = ws.ReadMessage()
		if e != nil {
			return
		}
		if kind != websocket.TextMessage {
			c.close(websocket.CloseUnsupportedData)
			return
		}
		if !c.allow() {
			c.close(websocket.ClosePolicyViolation)
			return
		}
		f, ok = decode(raw)
		if !ok {
			c.close(websocket.ClosePolicyViolation)
			return
		}
		c.heartbeatMu.Lock()
		c.lastActivity = time.Now()
		c.heartbeatMu.Unlock()
		if f.Type == "pong" {
			var q struct {
				Nonce string `json:"nonce"`
			}
			if !strict(f.Payload, &q) {
				c.close(websocket.ClosePolicyViolation)
				return
			}
			c.pong(q.Nonce)
			continue
		}
		if !c.handle(f) {
			return
		}
	}
}

func failHTTP(w http.ResponseWriter, r *http.Request, status int, code string) {
	var b [16]byte
	_, _ = rand.Read(b[:])
	id := hex.EncodeToString(b[:])
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("X-Request-ID", id)
	w.WriteHeader(status)
	if r.Method != "HEAD" {
		_ = json.NewEncoder(w).Encode(map[string]any{"request_id": id, "code": code, "retryable": status == http.StatusServiceUnavailable || status == http.StatusTooManyRequests, "server_time": time.Now().UTC()})
	}
}
func (c *client) allow() bool {
	c.budgetMu.Lock()
	defer c.budgetMu.Unlock()
	if time.Since(c.budgetAt) >= time.Second {
		c.budget = 0
		c.budgetAt = time.Now()
	}
	c.budget++
	return c.budget <= 20
}
func (c *client) pong(nonce string) {
	c.heartbeatMu.Lock()
	defer c.heartbeatMu.Unlock()
	if c.challenge != "" && nonce == c.challenge {
		c.challenge = ""
		c.pongUntil = time.Time{}
		c.lastActivity = time.Now()
		_ = c.ws.SetReadDeadline(time.Now().Add(c.g.timing.idle))
	}
}
func (c *client) close(code int) {
	c.once.Do(func() {
		c.foreground.Store(false)
		c.code.Store(int32(code))
		close(c.stop)
		c.cancel()
	})
}
func (c *client) enqueue(o outbound) bool {
	c.queueMu.Lock()
	defer c.queueMu.Unlock()
	select {
	case <-c.stop:
		return false
	default:
	}
	size := int64(len(o.frame.Payload) + len(o.bytes) + 128)
	if c.g.queuedBytes.Add(size) > 16<<20 {
		c.g.queuedBytes.Add(-size)
		c.close(websocket.CloseTryAgainLater)
		return false
	}
	select {
	case c.out <- o:
		return true
	default:
		c.g.queuedBytes.Add(-size)
		c.close(websocket.CloseTryAgainLater)
		return false
	}
}
func (c *client) send(f frame, typ string, payload any, protected bool) bool {
	raw, e := json.Marshal(payload)
	if e != nil || len(raw) > 256<<10 {
		c.close(websocket.CloseInternalServerErr)
		return false
	}
	return c.enqueue(outbound{frame: frame{1, f.RequestID, typ, raw}, protected: protected, epoch: c.epoch.Load()})
}
func (c *client) current() (auth.Claims, error) {
	ctx, cancel := context.WithTimeout(c.ctx, c.g.timing.operation)
	defer cancel()
	for {
		claims, err := c.identity.Current(ctx, c.g.identity)
		if !errors.Is(err, ports.ErrBusy) {
			if err == nil {
				c.lastAuthority.Store(time.Now().UnixNano())
			}
			return claims, err
		}
		// Socket admission already bounds the number of waiters. A brief
		// concurrency collision must not masquerade as revoked authority.
		var jitter [1]byte
		_, _ = rand.Read(jitter[:])
		wait := time.NewTimer(time.Duration(5+int(jitter[0])%20) * time.Millisecond)
		select {
		case <-ctx.Done():
			wait.Stop()
			return auth.Claims{}, ports.ErrUnavailable
		case <-wait.C:
		}
	}
}
func (c *client) authorityClose(err error) {
	if errors.Is(err, auth.ErrUnauthorized) {
		c.close(websocket.ClosePolicyViolation)
	} else {
		c.close(websocket.CloseTryAgainLater)
	}
}
func minTime(a, b time.Time) time.Time {
	if a.Before(b) {
		return a
	}
	return b
}
func principal(c auth.Claims) ports.Principal {
	return ports.Principal{ActorID: c.ActorID, Kind: c.Kind, SourceID: c.SourceID, SessionID: c.SessionID, Environment: c.Environment, SessionVersion: c.Version, Scopes: c.Scopes, ExpiresAt: time.Unix(c.ExpiresAt, 0).UTC()}
}
func (c *client) handle(f frame) bool {
	gate := c.g.commandGate(f.Type)
	if !c.g.acquire(c.ctx, gate) {
		return c.send(f, "error", map[string]any{"code": "CHAT_RESOURCE_BUSY", "retryable": true}, false)
	}
	if gate != nil {
		defer func() { <-gate }()
	}
	claims, e := c.current()
	if e != nil {
		c.authorityClose(e)
		return false
	}
	ctx, cancel := context.WithTimeout(c.ctx, c.g.timing.operation)
	defer cancel()
	switch f.Type {
	case "auth.refresh":
		var q struct {
			AccessToken string `json:"access_token"`
		}
		if !strict(f.Payload, &q) || len(q.AccessToken) > 4096 {
			c.close(websocket.ClosePolicyViolation)
			return false
		}
		if c.identity.Refresh(ctx, c.g.identity, q.AccessToken) != nil {
			c.close(websocket.ClosePolicyViolation)
			return false
		}
		c.epoch.Add(1)
		claims, e = c.current()
		if e != nil {
			c.close(websocket.ClosePolicyViolation)
			return false
		}
		c.expires.Store(claims.ExpiresAt)
		c.wakeExpiry()
		return c.send(f, "auth.ok", map[string]any{"actor_id": claims.ActorID, "expires_at": claims.ExpiresAt}, true)
	case "session.state":
		var q struct {
			State string `json:"state"`
		}
		if !strict(f.Payload, &q) || (q.State != "foreground" && q.State != "background") {
			return c.send(f, "error", map[string]any{"code": "CHAT_INVALID_REQUEST", "retryable": false}, false)
		}
		c.foreground.Store(q.State == "foreground")
		if q.State == "background" {
			c.clearTransient()
		}
		return c.send(f, "session.state", q, true)
	case "ping":
		var q struct {
			Nonce string `json:"nonce"`
		}
		if !strict(f.Payload, &q) || len(q.Nonce) > 64 {
			return c.send(f, "error", map[string]any{"code": "CHAT_INVALID_REQUEST", "retryable": false}, false)
		}
		return c.send(f, "pong", q, false)
	case "auth":
		c.close(websocket.ClosePolicyViolation)
		return false
	}
	if handled, ok := c.transient(ctx, claims, f); handled {
		return ok
	}
	typ, data, e := c.g.commands.Execute(ctx, principal(claims), f.Type, f.Payload)
	if e != nil {
		code, retry := "CHAT_DEPENDENCY_UNAVAILABLE", true
		var safe interface{ PublicCode() (string, bool) }
		if errors.As(e, &safe) {
			code, retry = safe.PublicCode()
		}
		return c.send(f, "error", map[string]any{"code": code, "retryable": retry}, false)
	}
	return c.send(f, typ, data, true)
}

// Expiry must not wait behind a slow write or a persistent-session lookup.
// The writer remains the sole frame writer; cancellation interrupts use cases.
func (c *client) expiryLoop() {
	for {
		deadline := c.authUntil
		if c.epoch.Load() > 0 {
			deadline = time.Unix(c.expires.Load(), 0)
		}
		wait := time.Until(deadline)
		if wait <= 0 {
			c.close(websocket.ClosePolicyViolation)
			return
		}
		timer := time.NewTimer(wait)
		select {
		case <-c.stop:
			timer.Stop()
			return
		case <-c.expiryWake:
			if !timer.Stop() {
				select {
				case <-timer.C:
				default:
				}
			}
		case <-timer.C:
		}
	}
}
func (c *client) writeLoop() {
	defer c.ws.Close()
	defer func() {
		c.queueMu.Lock()
		defer c.queueMu.Unlock()
		for {
			select {
			case o := <-c.out:
				c.g.queuedBytes.Add(-int64(len(o.frame.Payload) + len(o.bytes) + 128))
			default:
				return
			}
		}
	}()
	tickEvery := c.g.timing.check
	if c.g.timing.ping < tickEvery {
		tickEvery = c.g.timing.ping
	}
	if tickEvery > time.Second {
		tickEvery = time.Second
	}
	if tickEvery < 25*time.Millisecond {
		tickEvery = 25 * time.Millisecond
	}
	tick := time.NewTicker(tickEvery)
	defer tick.Stop()
	nextPing := time.Now().Add(c.g.timing.ping)
	nextCheck := time.Now().Add(c.g.timing.check)
	var warned uint64
	for {
		select {
		case <-c.stop:
			_ = c.ws.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(int(c.code.Load()), ""), time.Now().Add(c.g.timing.write))
			return
		case o := <-c.out:
			c.g.queuedBytes.Add(-int64(len(o.frame.Payload) + len(o.bytes) + 128))
			if o.protected {
				if o.epoch != c.epoch.Load() {
					continue
				}
				if o.conversation != "" {
					claims, e := c.current()
					if e != nil {
						c.authorityClose(e)
						continue
					}
					if !c.foreground.Load() || c.g.realtime == nil {
						continue
					}
					ctx, cancel := context.WithTimeout(c.ctx, c.g.timing.operation)
					_, e = c.g.realtime.policy.Authorize(ctx, ports.PolicyRequest{Actor: principal(claims), ConversationID: o.conversation, Action: "read"})
					cancel()
					if e != nil {
						continue
					}
					if o.typingSource != nil {
						c.stateMu.Lock()
						focused := c.active == o.conversation
						c.stateMu.Unlock()
						if !focused || !o.typingSource.canType(o.conversation, o.typingUntil) {
							continue
						}
					}
				}
				if _, e := c.current(); e != nil {
					c.authorityClose(e)
					continue
				}
				if o.epoch != c.epoch.Load() {
					continue
				}
			}
			select {
			case <-c.stop:
				continue
			default:
			}
			deadline := time.Now().Add(c.g.timing.write)
			if o.protected {
				deadline = minTime(deadline, time.Unix(c.expires.Load(), 0))
			}
			_ = c.ws.SetWriteDeadline(deadline)
			var e error
			if o.control != 0 {
				e = c.ws.WriteControl(o.control, o.bytes, time.Now().Add(c.g.timing.write))
			} else {
				e = c.ws.WriteJSON(o.frame)
			}
			if e != nil {
				c.close(websocket.CloseTryAgainLater)
			}
		case now := <-tick.C:
			// epoch publishes identity after first-frame auth. Heartbeats never write
			// last_seen, Redis presence, read progress or successful-event logs.
			if c.epoch.Load() == 0 {
				if !now.Before(c.authUntil) {
					c.close(websocket.ClosePolicyViolation)
				}
				continue
			}
			if now.Unix() >= c.expires.Load() {
				c.close(websocket.ClosePolicyViolation)
				continue
			}
			if c.expires.Load()-now.Unix() <= 30 && warned != c.epoch.Load() {
				warned = c.epoch.Load()
				c.send(frame{RequestID: "server"}, "auth.required", map[string]any{"expires_at": c.expires.Load()}, true)
			}
			c.heartbeatMu.Lock()
			expired := !c.pongUntil.IsZero() && !now.Before(c.pongUntil) || now.Sub(c.lastActivity) >= c.g.timing.idle
			c.heartbeatMu.Unlock()
			if expired {
				c.close(websocket.CloseGoingAway)
				continue
			}
			if !now.Before(nextCheck) {
				last := c.lastAuthority.Load()
				if last > 0 {
					validatedAt := time.Unix(0, last)
					if next := validatedAt.Add(c.g.timing.check); now.Before(next) {
						nextCheck = next
					} else {
						if _, e := c.current(); e != nil {
							c.close(websocket.ClosePolicyViolation)
							continue
						}
						nextCheck = now.Add(c.g.timing.check)
					}
				} else {
					if _, e := c.current(); e != nil {
						c.close(websocket.ClosePolicyViolation)
						continue
					}
					nextCheck = now.Add(c.g.timing.check)
				}
			}
			if !now.Before(nextPing) {
				var b [16]byte
				if _, e := rand.Read(b[:]); e != nil {
					c.close(websocket.CloseInternalServerErr)
					continue
				}
				nonce := hex.EncodeToString(b[:])
				c.heartbeatMu.Lock()
				c.challenge = nonce
				c.pongUntil = now.Add(c.g.timing.pong)
				c.heartbeatMu.Unlock()
				if e := c.ws.WriteControl(websocket.PingMessage, []byte(nonce), now.Add(c.g.timing.write)); e != nil {
					c.close(websocket.CloseGoingAway)
				}
				nextPing = now.Add(c.g.timing.ping)
			}
		}
	}
}

// Shutdown explicitly owns hijacked connections, which http.Server.Shutdown
// does not wait for. Cancellation blocks admission and interrupts use cases.
func (g *Gateway) Shutdown(ctx context.Context) error {
	g.mu.Lock()
	g.stopping = true
	if g.realtime != nil {
		g.realtime.once.Do(func() {
			if g.realtime.cancel != nil {
				g.realtime.cancel()
			}
			close(g.realtime.stop)
		})
	}
	for c := range g.clients {
		c.close(websocket.CloseGoingAway)
	}
	for c := range g.preAuth {
		c.close(websocket.CloseGoingAway)
	}
	g.mu.Unlock()
	done := make(chan struct{})
	go func() {
		g.wg.Wait()
		if g.realtime != nil {
			g.realtime.workers.Wait()
		}
		close(done)
	}()
	select {
	case <-done:
		return nil
	case <-ctx.Done():
		g.mu.Lock()
		for c := range g.clients {
			c.transportMu.Lock()
			if c.ws != nil {
				_ = c.ws.Close()
			}
			c.transportMu.Unlock()
		}
		for c := range g.preAuth {
			c.transportMu.Lock()
			if c.ws != nil {
				_ = c.ws.Close()
			}
			c.transportMu.Unlock()
		}
		g.mu.Unlock()
		return ctx.Err()
	}
}
