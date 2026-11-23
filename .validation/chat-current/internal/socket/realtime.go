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
	call    *ports.CallChange
	kind    string
	source  *client
	exclude ports.ID
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
	g.offer(liveEvent{message: m, kind: "message.changed"})
}
func (g *Gateway) Committed(m ports.CommittedMessage) {
	g.offer(liveEvent{message: m, kind: "message.new"})
}
func (g *Gateway) ReceiptChanged(m ports.CommittedMessage, actor ports.ID) {
	g.offer(liveEvent{message: m, kind: "receipt.changed", exclude: actor})
}
func (g *Gateway) CallChanged(m ports.CallChange) {
	g.offer(liveEvent{call: &m, kind: "calls.changed"})
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
			conversationID := event.message.ConversationID
			if event.call != nil {
				conversationID = event.call.ConversationID
			}
			root := g.realtime.ctx
			if root == nil {
				root = context.Background()
			}
			ctx, cancel := context.WithTimeout(root, 2*time.Second)
			ids, e := g.realtime.policy.MemberCandidates(ctx, conversationID)
			cancel()
			if e != nil {
				continue
			}
			candidates := map[ports.ID]bool{}
			for _, id := range ids {
				candidates[id] = true
			}
			for _, c := range g.connections() {
				if event.exclude.Valid() && c.actor == event.exclude {
					continue
				}
				if c.kind != "mini" || !candidates[c.actor] || !c.foreground.Load() {
					continue
				}
				if event.kind == "presence.changed" {
					c.stateMu.Lock()
					focused := c.active == conversationID
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
				payload := map[string]any{"conversation_id": conversationID}
				if event.call != nil {
					payload["call_id"] = event.call.CallID
					payload["state"] = event.call.State
					payload["policy_version"] = event.call.PolicyVersion
					payload["attempt_epoch"] = event.call.AttemptEpoch
					payload["revision"] = event.call.Revision
				}
				if event.kind == "message.new" {
					payload["highest_seq"] = event.message.Seq
					payload["policy_version"] = event.message.PolicyVersion
				}
				if event.kind == "receipt.changed" {
					payload["read_seq"] = event.message.Seq
					payload["policy_version"] = event.message.PolicyVersion
				}
				if event.source != nil {
					payload["actor_id"] = event.source.actor
					payload["expires_at"] = event.until.UnixMilli()
				}
				c.live(frame{RequestID: "server"}, event.kind, payload, conversationID, event.source, event.until)
				if event.kind == "message.new" || event.kind == "message.changed" {
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
func (c *client) presenceMembers(ctx context.Context, claims auth.Claims, id ports.ID) ([]ports.ID, []ports.ID, error) {
	ids, err := c.g.realtime.policy.MemberCandidates(ctx, id)
	if err != nil { return nil, nil, err }
	candidates := map[ports.ID]bool{}
	for _, member := range ids { candidates[member] = true }
	online := []ports.ID{}
	typing := []ports.ID{}
	seen := map[ports.ID]bool{}
	typingSeen := map[ports.ID]bool{}
	for _, other := range c.g.connections() {
		if ctx.Err() != nil { return nil, nil, ctx.Err() }
		if other.kind != "mini" || !candidates[other.actor] || !other.healthy() { continue }
		principalClaims, err := other.identity.Current(ctx, c.g.identity)
		if err != nil || principalClaims.Environment != claims.Environment { continue }
		grant, err := c.g.realtime.policy.Authorize(ctx, ports.PolicyRequest{Actor: principal(principalClaims), ConversationID: id, Action: "read"})
		if err != nil { continue }
		if !seen[other.actor] { seen[other.actor] = true; online = append(online, other.actor) }
		other.stateMu.Lock()
		active, until := other.active, other.typingUntil
		other.stateMu.Unlock()
		if active == id && time.Now().Before(until) && grant.CanSend && !typingSeen[other.actor] {
			typingSeen[other.actor] = true
			typing = append(typing, other.actor)
		}
	}
	return online, typing, nil
}

func (c *client) transient(ctx context.Context, claims auth.Claims, f frame) (bool, bool) {
	if f.Type=="presence.batch" {
		return true,c.batchPresence(ctx,claims,f)
	}
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
		online, typing, e := c.presenceMembers(ctx, claims, q.ConversationID)
		if e != nil { return fail("CHAT_DEPENDENCY_UNAVAILABLE") }
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

func (c *client) batchPresence(ctx context.Context,claims auth.Claims,f frame) bool {
	var q struct{Conversations []ports.ID `json:"conversation_ids"`}
	if c.g.realtime==nil || !c.healthy() || !strict(f.Payload,&q) || len(q.Conversations)<1 || len(q.Conversations)>12 {
		return c.send(f,"error",map[string]any{"code":"CHAT_INVALID_REQUEST","retryable":false},false)
	}
	c.stateMu.Lock()
	throttled:=time.Since(c.batchPresenceAt)<4*time.Second
	if !throttled {c.batchPresenceAt=time.Now()}
	c.stateMu.Unlock()
	if throttled {return c.send(f,"error",map[string]any{"code":"CHAT_RESOURCE_BUSY","retryable":true},false)}
	candidates:=map[ports.ID]bool{}
	online:=[]ports.ID{}
	for _,id:=range q.Conversations {
		if !id.Valid() || id=="0" || candidates[id] {continue}
		candidates[id]=true
		if ctx.Err()!=nil{break}
		if _,err:=c.g.realtime.policy.Authorize(ctx,ports.PolicyRequest{Actor:principal(claims),ConversationID:id,Action:"read"});err!=nil{continue}
		members,_,err:=c.presenceMembers(ctx,claims,id)
		if err!=nil{continue}
		for _,actor:=range members { if actor!=c.actor{online=append(online,id);break} }
	}
	return c.send(f,"presence.batch.result",map[string]any{"online_conversations":online,"ttl_seconds":6},true)
}
