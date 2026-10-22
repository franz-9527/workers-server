package socket

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
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
	identity    *auth.Service
	commands    Commands
	gate        chan struct{}
	mu          sync.Mutex
	clients     map[*client]struct{}
	stopping    bool
	queuedBytes atomic.Int64
	wg          sync.WaitGroup
	timing      timings
}
type timings struct{ auth, write, ping, pong, idle, check, operation time.Duration }

func New(identity *auth.Service, commands Commands) (*Gateway, error) {
	if identity == nil || commands == nil {
		return nil, ports.ErrInvalid
	}
	return &Gateway{identity: identity, commands: commands, gate: make(chan struct{}, 16), clients: map[*client]struct{}{}, timing: timings{5 * time.Second, 2 * time.Second, 30 * time.Second, 10 * time.Second, 90 * time.Second, 5 * time.Second, 4 * time.Second}}, nil
}

type outbound struct {
	frame     frame
	control   int
	bytes     []byte
	protected bool
	epoch     uint64
}
type client struct {
	g            *Gateway
	ws           *websocket.Conn
	transportMu  sync.Mutex
	expires      atomic.Int64
	authUntil    time.Time
	ctx          context.Context
	cancel       context.CancelFunc
	identity     *auth.SocketIdentity
	out          chan outbound
	queueMu      sync.Mutex
	stop         chan struct{}
	once         sync.Once
	code         atomic.Int32
	epoch        atomic.Uint64
	foreground   atomic.Bool
	heartbeatMu  sync.Mutex
	challenge    string
	pongUntil    time.Time
	lastActivity time.Time
	budgetMu     sync.Mutex
	budget       int
	budgetAt     time.Time
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
	c := &client{g: g, ctx: ctx, cancel: cancel, out: make(chan outbound, 8), stop: make(chan struct{}), lastActivity: time.Now(), budgetAt: time.Now(), authUntil: time.Now().Add(g.timing.auth)}
	g.mu.Lock()
	if g.stopping || len(g.clients) >= 300 {
		g.mu.Unlock()
		cancel()
		failHTTP(w, r, 503, "CHAT_RESOURCE_BUSY")
		return
	}
	g.clients[c] = struct{}{}
	g.wg.Add(1)
	g.mu.Unlock()
	defer func() { cancel(); g.mu.Lock(); delete(g.clients, c); g.mu.Unlock(); g.wg.Done() }()
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
	select {
	case g.gate <- struct{}{}:
	default:
		c.close(websocket.CloseTryAgainLater)
		return
	}
	op, stop := context.WithDeadline(c.ctx, minTime(c.authUntil, time.Now().Add(g.timing.operation)))
	claims, e := g.identity.ConsumeTicket(op, q.Ticket)
	stop()
	<-g.gate
	if e != nil {
		c.close(websocket.ClosePolicyViolation)
		return
	}
	c.identity = auth.NewSocketIdentity(claims)
	c.expires.Store(claims.ExpiresAt)
	c.epoch.Store(1)
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
		_ = json.NewEncoder(w).Encode(map[string]any{"request_id": id, "code": code, "retryable": status == 503, "server_time": time.Now().UTC()})
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
	c.once.Do(func() { c.foreground.Store(false); c.code.Store(int32(code)); close(c.stop); c.cancel() })
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
	return c.identity.Current(ctx, c.g.identity)
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
	select {
	case c.g.gate <- struct{}{}:
		defer func() { <-c.g.gate }()
	default:
		return c.send(f, "error", map[string]any{"code": "CHAT_RESOURCE_BUSY", "retryable": true}, false)
	}
	claims, e := c.current()
	if e != nil {
		c.close(websocket.ClosePolicyViolation)
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
		return c.send(f, "auth.ok", map[string]any{"actor_id": claims.ActorID, "expires_at": claims.ExpiresAt}, true)
	case "session.state":
		var q struct {
			State string `json:"state"`
		}
		if !strict(f.Payload, &q) || (q.State != "foreground" && q.State != "background") {
			return c.send(f, "error", map[string]any{"code": "CHAT_INVALID_REQUEST", "retryable": false}, false)
		}
		c.foreground.Store(q.State == "foreground")
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
	tick := time.NewTicker(min(c.g.timing.check, 100*time.Millisecond))
	defer tick.Stop()
	for {
		select {
		case <-c.stop:
			return
		case now := <-tick.C:
			if c.epoch.Load() == 0 {
				if !now.Before(c.authUntil) {
					c.close(websocket.ClosePolicyViolation)
					return
				}
			} else if now.Unix() >= c.expires.Load() {
				c.close(websocket.ClosePolicyViolation)
				return
			}
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
	tick := time.NewTicker(min(c.g.timing.check, 100*time.Millisecond))
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
				if _, e := c.current(); e != nil {
					c.close(websocket.ClosePolicyViolation)
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
				if _, e := c.current(); e != nil {
					c.close(websocket.ClosePolicyViolation)
					continue
				}
				nextCheck = now.Add(c.g.timing.check)
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
	for c := range g.clients {
		c.close(websocket.CloseGoingAway)
	}
	g.mu.Unlock()
	done := make(chan struct{})
	go func() { g.wg.Wait(); close(done) }()
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
		g.mu.Unlock()
		return ctx.Err()
	}
}
