package socket

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type readyWait struct {
	target   *client
	epoch    uint64
	listener uint64
	instance string
	answer   chan ports.CallReadyProof
}

func (c *client) healthy() bool {
	if !c.foreground.Load() || c.epoch.Load() == 0 || time.Now().Unix() >= c.expires.Load() {
		return false
	}
	select {
	case <-c.stop:
		return false
	default:
	}
	c.heartbeatMu.Lock()
	defer c.heartbeatMu.Unlock()
	return time.Since(c.lastActivity) < c.g.timing.idle &&
		(c.pongUntil.IsZero() || time.Now().Before(c.pongUntil))
}

func (c *client) listener() (string, uint64) {
	c.stateMu.Lock()
	defer c.stateMu.Unlock()
	return c.callInstance, c.callListener
}

func (c *client) setListener(instance string) {
	c.stateMu.Lock()
	defer c.stateMu.Unlock()
	if c.callInstance != instance {
		c.callInstance = instance
		c.callListener++
	}
}

func validNonce(raw string, size int) bool {
	bytes, err := hex.DecodeString(raw)
	return err == nil && len(bytes) == size && hex.EncodeToString(bytes) == raw
}

func (g *Gateway) callTargets(ctx context.Context, cid, peer ports.ID, environment, instance string) ([]*client, bool, bool) {
	targets := []*client{}
	online := false
	unknown := false
	if g == nil || g.realtime == nil {
		return targets, false, true
	}
	for _, c := range g.connections() {
		if c.actor != peer || c.kind != "mini" || !c.healthy() {
			continue
		}
		claims, err := c.identity.Current(ctx, g.identity)
		if err != nil {
			if !errors.Is(err, auth.ErrUnauthorized) {
				unknown = true
			}
			continue
		}
		if claims.Environment != environment {
			continue
		}
		grant, err := g.realtime.policy.Authorize(ctx, ports.PolicyRequest{Actor: principal(claims), ConversationID: cid, Action: "read"})
		if err != nil {
			if !errors.Is(err, auth.ErrUnauthorized) && !errors.Is(err, policy.ErrDenied) {
				unknown = true
			}
			continue
		}
		if grant.ConversationType != ports.OrderScene || grant.Mode != "direct" {
			continue
		}
		online = true
		registered, _ := c.listener()
		if !grant.CanSend || registered == "" || instance != "" && instance != registered {
			continue
		}
		targets = append(targets, c)
		if len(targets) >= 3 {
			break
		}
	}
	return targets, online, unknown
}

func (g *Gateway) CallReachable(ctx context.Context, cid, peer ports.ID, environment, instance string) (string, bool) {
	list, online, unknown := g.callTargets(ctx, cid, peer, environment, instance)
	if ctx.Err() != nil || g == nil || g.realtime == nil {
		return "unknown", false
	}
	if online {
		return "online", len(list) > 0
	}
	if unknown {
		return "unknown", false
	}
	return "offline", false
}

func (g *Gateway) CallInstanceReady(actor ports.ID, instance string) bool {
	if g == nil || instance == "" {
		return false
	}
	for _, c := range g.connections() {
		if c.actor == actor && c.kind == "mini" && c.healthy() {
			registered, _ := c.listener()
			if registered == instance {
				return true
			}
		}
	}
	return false
}

// Used by offline reminders on the single active gateway. Unavailable presence
// suppresses the reminder until the gateway is ready.
func (g *Gateway) AccountPresence(actor ports.ID) (known, online bool) {
	if g == nil {
		return false, false
	}
	g.mu.Lock()
	unavailable := g.stopping || g.realtime == nil
	g.mu.Unlock()
	if unavailable {
		return false, false
	}
	for _, c := range g.connections() {
		if c.actor == actor && c.kind == "mini" && c.healthy() {
			return true, true
		}
	}
	return true, false
}

func (g *Gateway) ProbeCall(ctx context.Context, p ports.Principal, cid, peer ports.ID, media, operation string) (ports.CallReadyProof, error) {
	if g == nil || g.realtime == nil || !validNonce(operation, 32) || media != "audio" && media != "video" {
		return ports.CallReadyProof{}, ports.ErrCallPeerNotReady
	}
	ctx, cancel := context.WithTimeout(ctx, 2500*time.Millisecond)
	defer cancel()
	select {
	case g.probeSlots <- struct{}{}:
		defer func() { <-g.probeSlots }()
	default:
		return ports.CallReadyProof{}, ports.ErrUnavailable
	}
	now := time.Now()
	g.probeMu.Lock()
	for key, deadline := range g.probeRate {
		if !now.Before(deadline) {
			delete(g.probeRate, key)
		}
	}
	source, target := "from:"+string(p.ActorID), "to:"+string(peer)
	if len(g.probeRate) >= 2048 || now.Before(g.probeRate[source]) || now.Before(g.probeRate[target]) {
		g.probeMu.Unlock()
		return ports.CallReadyProof{}, ports.ErrUnavailable
	}
	g.probeRate[source], g.probeRate[target] = now.Add(2*time.Second), now.Add(time.Second)
	g.probeMu.Unlock()

	targets, online, unknown := g.callTargets(ctx, cid, peer, p.Environment, "")
	if ctx.Err() != nil || !online && unknown {
		return ports.CallReadyProof{}, ports.ErrUnavailable
	}
	if !online {
		return ports.CallReadyProof{}, ports.ErrCallPeerOffline
	}
	if len(targets) == 0 {
		return ports.CallReadyProof{}, ports.ErrCallPeerNotReady
	}
	answer := make(chan ports.CallReadyProof, 1)
	ids := []string{}
	defer func() {
		g.probeMu.Lock()
		defer g.probeMu.Unlock()
		for _, id := range ids {
			delete(g.probes, id)
		}
	}()
	for _, target := range targets {
		var nonce [24]byte
		if _, err := rand.Read(nonce[:]); err != nil {
			return ports.CallReadyProof{}, ports.ErrUnavailable
		}
		id := hex.EncodeToString(nonce[:])
		instance, listener := target.listener()
		if instance == "" || !target.healthy() {
			continue
		}
		wait := readyWait{target: target, epoch: target.epoch.Load(), listener: listener, instance: instance, answer: answer}
		g.probeMu.Lock()
		g.probes[id] = wait
		g.probeMu.Unlock()
		ids = append(ids, id)
		target.live(frame{RequestID: "server"}, "calls.probe", map[string]string{"challenge_id": id, "media": media}, cid, nil, time.Time{})
	}
	select {
	case ready := <-answer:
		if ready.Valid() {
			return ready, nil
		}
	case <-ctx.Done():
	}
	return ports.CallReadyProof{}, ports.ErrCallPeerNotReady
}

func (c *client) acknowledgeReady(id string) {
	c.g.probeMu.Lock()
	wait, ok := c.g.probes[id]
	if ok && wait.target == c {
		delete(c.g.probes, id)
	}
	c.g.probeMu.Unlock()
	if !ok || wait.target != c {
		return
	}
	until := time.Now().Add(3 * time.Second)
	proof := ports.CallReadyProof{Instance: wait.instance, Valid: func() bool {
		instance, listener := c.listener()
		return time.Now().Before(until) && c.healthy() && c.epoch.Load() == wait.epoch && instance == wait.instance && listener == wait.listener
	}}
	if !proof.Valid() {
		return
	}
	select {
	case wait.answer <- proof:
	default:
	}
}
