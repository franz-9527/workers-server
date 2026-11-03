package socket

import (
	"context"
	"encoding/json"
	"sync"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type liveEvent struct {
	message ports.CommittedMessage
	kind    string
	source  *client
	until   time.Time
}
type realtime struct {
	policy  ports.RealtimePolicy
	events  chan liveEvent
	stop    chan struct{}
	once    sync.Once
	workers sync.WaitGroup
	ctx     context.Context
	cancel  context.CancelFunc
}

func NewWithPolicy(identity *auth.Service, commands Commands, policy ports.RealtimePolicy) (*Gateway, error) {
	if policy == nil {
		return nil, ports.ErrInvalid
	}
	g, e := New(identity, commands)
	if e != nil {
		return nil, e
	}
	g.realtime = &realtime{policy: policy, events: make(chan liveEvent, 64), stop: make(chan struct{})}
	g.realtime.ctx, g.realtime.cancel = context.WithCancel(context.Background())
	for range 2 {
		g.realtime.workers.Add(1)
		go func() { defer g.realtime.workers.Done(); g.deliver() }()
	}
	return g, nil
}

// Called only AFTER MySQL COMMIT. Admission is bounded and performs no I/O.
// A lost wakeup is recovered by account reconciliation and both opaque cursors.
func (g *Gateway) Changed(m ports.CommittedMessage) {
    g.offer(liveEvent{message:m,kind:"message.changed"})
}
func (g *Gateway) Committed(m ports.CommittedMessage) {
	g.offer(liveEvent{message: m, kind: "message.new"})
}
func (g *Gateway) offer(e liveEvent) {
	if g.realtime == nil {
		return
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.stopping {
		return
	}
	select {
	case g.realtime.events <- e:
	default:
	}
}
func (g *Gateway) connections() []*client {
	g.mu.Lock()
	defer g.mu.Unlock()
	list := make([]*client, 0, len(g.clients))
	for c := range g.clients {
		if c.epoch.Load() > 0 {
			list = append(list, c)
		}
	}
	return list
}
func (g *Gateway) deliver() {
	for {
		select {
		case <-g.realtime.stop:
			return
		case event := <-g.realtime.events:
			root := g.realtime.ctx
			if root == nil {
				root = context.Background()
			}
			ctx, cancel := context.WithTimeout(root, 2*time.Second)
			ids, e := g.realtime.policy.MemberCandidates(ctx, event.message.ConversationID)
			cancel()
			if e != nil {
				continue
			}
			candidates := map[ports.ID]bool{}
			for _, id := range ids {
				candidates[id] = true
			}
			for _, c := range g.connections() {
				if c.kind != "mini" || !candidates[c.actor] || !c.foreground.Load() {
					continue
				}
				if event.kind == "presence.changed" {
					c.stateMu.Lock()
					focused := c.active == event.message.ConversationID
					c.stateMu.Unlock()
					if !focused {
						continue
					}
				}
				if event.source != nil {
					c.stateMu.Lock()
					focused := c.active == event.message.ConversationID
					c.stateMu.Unlock()
					if !focused || c.actor == event.source.actor {
						continue
					}
				}
				payload := map[string]any{"conversation_id": event.message.ConversationID}
				if event.kind == "message.new" {
					payload["highest_seq"] = event.message.Seq
					payload["policy_version"] = event.message.PolicyVersion
				}
				if event.source != nil {
					payload["actor_id"] = event.source.actor
					payload["expires_at"] = event.until.UnixMilli()
				}
				c.live(frame{RequestID: "server"}, event.kind, payload, event.message.ConversationID, event.source, event.until)
				if event.kind == "message.new" || event.kind=="message.changed" {
					c.live(frame{RequestID: "server"}, "account.summary.changed", map[string]bool{"reconcile": true}, event.message.ConversationID, nil, time.Time{})
				}
			}
		}
	}
}
func (c *client) live(f frame, typ string, payload any, id ports.ID, source *client, until time.Time) bool {
	raw, e := json.Marshal(payload)
	if e != nil {
		return false
	}
	return c.enqueue(outbound{frame: frame{1, f.RequestID, typ, raw}, protected: true, epoch: c.epoch.Load(), conversation: id, typingSource: source, typingUntil: until})
}
func (c *client) clearTransient() {
	c.stateMu.Lock()
	previous := c.active
	c.active = ""
	c.typingUntil = time.Time{}
	c.stateMu.Unlock()
	if previous != "" {
		c.g.offer(liveEvent{message: ports.CommittedMessage{ConversationID: previous}, kind: "presence.changed"})
	}
}
func (c *client) canType(id ports.ID, until time.Time) bool {
	select {
	case <-c.stop:
		return false
	default:
	}
	if !c.foreground.Load() || time.Now().Unix() >= c.expires.Load() {
		return false
	}
	c.stateMu.Lock()
	valid := c.active == id && c.typingUntil.Equal(until) && (until.IsZero() || time.Now().Before(until))
	c.stateMu.Unlock()
	if !valid {
		return false
	}
	claims, e := c.current()
	if e != nil {
		return false
	}
	ctx, cancel := context.WithTimeout(c.ctx, c.g.timing.operation)
	defer cancel()
	action := "send"
	if until.IsZero() {
		action = "read"
	}
	_, e = c.g.realtime.policy.Authorize(ctx, ports.PolicyRequest{Actor: principal(claims), ConversationID: id, Action: action})
	return e == nil
}
func (c *client) transient(ctx context.Context, claims auth.Claims, f frame) (bool, bool) {
	switch f.Type {
	case "conversation.focus", "presence.query", "typing.start", "typing.stop":
	default:
		return false, false
	}
	fail := func(code string) (bool, bool) {
		return true, c.send(f, "error", map[string]any{"code": code, "retryable": false}, false)
	}
	if c.g.realtime == nil {
		return fail("CHAT_REALTIME_NOT_READY")
	}
	var q struct {
		ConversationID ports.ID `json:"conversation_id"`
	}
	if !strict(f.Payload, &q) || !q.ConversationID.Valid() {
		return fail("CHAT_INVALID_REQUEST")
	}
	if f.Type == "conversation.focus" && q.ConversationID == "0" {
		c.clearTransient()
		return true, c.send(f, "conversation.focus.result", q, true)
	}
	action := "read"
	if f.Type == "typing.start" {
		action = "send"
	}
	if _, e := c.g.realtime.policy.Authorize(ctx, ports.PolicyRequest{Actor: principal(claims), ConversationID: q.ConversationID, Action: action}); e != nil {
		return fail("CHAT_PERMISSION_DENIED")
	}
	switch f.Type {
	case "conversation.focus":
		c.clearTransient()
		c.stateMu.Lock()
		c.active = q.ConversationID
		c.stateMu.Unlock()
		c.g.offer(liveEvent{message: ports.CommittedMessage{ConversationID: q.ConversationID}, kind: "presence.changed"})
		return true, c.live(f, "conversation.focus.result", q, q.ConversationID, nil, time.Time{})
	case "presence.query":
		c.stateMu.Lock()
		if time.Since(c.presenceAt) < time.Second {
			c.stateMu.Unlock()
			return fail("CHAT_RESOURCE_BUSY")
		}
		c.presenceAt = time.Now()
		c.stateMu.Unlock()
		ids, e := c.g.realtime.policy.MemberCandidates(ctx, q.ConversationID)
		if e != nil {
			return fail("CHAT_DEPENDENCY_UNAVAILABLE")
		}
		candidates := map[ports.ID]bool{}
		for _, id := range ids {
			candidates[id] = true
		}
		online := []ports.ID{}
		typing := []ports.ID{}
		seen := map[ports.ID]bool{}
		typingSeen := map[ports.ID]bool{}
		for _, other := range c.g.connections() {
			if ctx.Err() != nil {
				return fail("CHAT_DEPENDENCY_UNAVAILABLE")
			}
			if other.kind != "mini" || !candidates[other.actor] || !other.foreground.Load() {
				continue
			}
			p, e := other.identity.Current(ctx, c.g.identity)
			if e != nil {
				continue
			}
			grant, e := c.g.realtime.policy.Authorize(ctx, ports.PolicyRequest{Actor: principal(p), ConversationID: q.ConversationID, Action: "read"})
			if e != nil {
				continue
			}
			other.heartbeatMu.Lock()
			healthy := time.Since(other.lastActivity) < c.g.timing.idle && (other.pongUntil.IsZero() || time.Now().Before(other.pongUntil))
			other.heartbeatMu.Unlock()
			if !healthy {
				continue
			}
			if !seen[other.actor] {
				seen[other.actor] = true
				online = append(online, other.actor)
			}
			other.stateMu.Lock()
			active, until := other.active, other.typingUntil
			other.stateMu.Unlock()
			if active == q.ConversationID && time.Now().Before(until) && grant.CanSend && !typingSeen[other.actor] {
				typingSeen[other.actor] = true
				typing = append(typing, other.actor)
			}
		}
		return true, c.live(f, "presence.snapshot", map[string]any{"conversation_id": q.ConversationID, "online_actor_ids": online, "typing_actor_ids": typing, "ttl_seconds": 6}, q.ConversationID, nil, time.Time{})
	case "typing.start", "typing.stop":
		if !c.foreground.Load() {
			return fail("CHAT_PERMISSION_DENIED")
		}
		c.stateMu.Lock()
		if c.active != q.ConversationID {
			c.stateMu.Unlock()
			return fail("CHAT_PERMISSION_DENIED")
		}
		now := time.Now()
		if f.Type == "typing.start" {
			if now.Sub(c.typingAt) < 2*time.Second {
				c.stateMu.Unlock()
				return true, c.send(f, "typing.result", map[string]bool{"throttled": true}, true)
			}
			c.typingAt = now
			c.typingUntil = now.Add(6 * time.Second)
		} else {
			c.typingUntil = time.Time{}
		}
		until := c.typingUntil
		c.stateMu.Unlock()
		// Stop is best-effort; no stale start may pass the writer's source check.
		if f.Type == "typing.start" || f.Type == "typing.stop" {
			c.g.offer(liveEvent{message: ports.CommittedMessage{ConversationID: q.ConversationID}, kind: f.Type, source: c, until: until})
		}
		return true, c.live(f, "typing.result", map[string]bool{"active": f.Type == "typing.start"}, q.ConversationID, nil, time.Time{})
	}
	return false, false
}
