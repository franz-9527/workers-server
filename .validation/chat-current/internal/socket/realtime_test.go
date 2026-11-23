package socket

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func readLive(t *testing.T, c interface {
	SetReadDeadline(time.Time) error
	ReadJSON(any) error
}, typ string) frame {
	t.Helper()
	_ = c.SetReadDeadline(time.Now().Add(time.Second))
	for {
		var f frame
		if e := c.ReadJSON(&f); e != nil {
			t.Fatal(e)
		}
		if f.RequestID == "server" && f.Type == "presence.changed" {
			continue
		}
		if f.Type != typ {
			t.Fatalf("got %s want %s", f.Type, typ)
		}
		return f
	}
}

type livePolicy struct {
	deny  atomic.Bool
	calls atomic.Int64
	scene string
	mode  string
}

func (p *livePolicy) Authorize(context.Context, ports.PolicyRequest) (ports.Grant, error) {
	p.calls.Add(1)
	if p.deny.Load() { return ports.Grant{}, policy.ErrDenied }
	scene, mode := p.scene, p.mode
	if scene == "" { scene = ports.OrderScene }
	if mode == "" { mode = "direct" }
	return ports.Grant{ConversationType: scene, Mode: mode, CanRead: true, CanSend: true, Version: "1"}, nil
}
func (p *livePolicy) MemberCandidates(context.Context, ports.ID) ([]ports.ID, error) {
	return []ports.ID{"18446744073709551615", "2"}, nil
}
func liveSetup(t *testing.T) (*fixture, *livePolicy) {
	f := setup(t)
	p := &livePolicy{}
	f.g.realtime = &realtime{policy: p, events: make(chan liveEvent, 64), stop: make(chan struct{})}
	f.g.realtime.workers.Add(1)
	go func() { defer f.g.realtime.workers.Done(); f.g.deliver() }()
	return f, p
}

func TestCallReadyProbeBindsAuthenticatedForegroundListener(t *testing.T) {
	f, _ := liveSetup(t)
	c := f.login(t)
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer cancel()
	actor := ports.ID("18446744073709551615")
	if online, ready := f.g.CallReachable(ctx, "77", actor, "test", ""); online != "online" || ready {
		t.Fatalf("unregistered client: %s %t", online, ready)
	}
	instance := strings.Repeat("c", 32)
	send(t, c, "calls.listen", map[string]string{"instance_id": instance})
	readLive(t, c, "calls.listen.result")
	type result struct {
		proof ports.CallReadyProof
		err   error
	}
	finished := make(chan result, 1)
	go func() {
		proof, err := f.g.ProbeCall(ctx, ports.Principal{ActorID: "55", Environment: "test"}, "77", actor, "video", strings.Repeat("e", 64))
		finished <- result{proof, err}
	}()
	prompt := readLive(t, c, "calls.probe")
	var payload map[string]string
	if err := json.Unmarshal(prompt.Payload, &payload); err != nil || payload["media"] != "video" || len(payload["challenge_id"]) != 48 {
		t.Fatalf("unexpected call challenge: %v", err)
	}
	send(t, c, "calls.probe_ack", map[string]string{"challenge_id": payload["challenge_id"]})
	readLive(t, c, "calls.probe_ack.result")
	select {
	case ready := <-finished:
		if ready.err != nil || ready.proof.Instance != instance || !ready.proof.Valid() {
			t.Fatalf("readiness proof: %v", ready.err)
		}
		send(t, c, "session.state", map[string]string{"state": "background"})
		readLive(t, c, "session.state")
		if ready.proof.Valid() || f.g.CallInstanceReady(actor, instance) {
			t.Fatal("background kept a ready proof")
		}
		if known, online := f.g.AccountPresence(actor); !known || online {
			t.Fatalf("background presence: %t %t", known, online)
		}
	case <-ctx.Done():
		t.Fatal("probe did not finish")
	}
}
func TestS11CommittedHintContainsNoMessageBody(t *testing.T) {
	f, p := liveSetup(t)
	c := f.login(t)
	f.g.Committed(ports.CommittedMessage{ConversationID: "77", Seq: "103", PolicyVersion: "1"})
	c.SetReadDeadline(time.Now().Add(time.Second))
	var event frame
	if e := c.ReadJSON(&event); e != nil {
		t.Fatal(e)
	}
	if event.Type != "message.new" || event.RequestID != "server" {
		t.Fatal(event.Type)
	}
	var payload map[string]any
	json.Unmarshal(event.Payload, &payload)
	if len(payload) != 3 || payload["highest_seq"] != "103" || payload["text"] != nil {
		t.Fatal(payload)
	}
	if e := c.ReadJSON(&event); e != nil || event.Type != "account.summary.changed" {
		t.Fatal("account wakeup", e)
	}
	if p.calls.Load() < 2 {
		t.Fatal("output did not reauthorize")
	}
}
func TestS11WriterDropsQueuedHintAfterMembershipLoss(t *testing.T) {
	f, p := liveSetup(t)
	c := f.login(t)
	p.deny.Store(true)
	f.g.Committed(ports.CommittedMessage{ConversationID: "77", Seq: "1", PolicyVersion: "1"})
	// Ordered application response can pass, but no unauthorized hint may pass.
	send(t, c, "ping", map[string]string{"nonce": "barrier"})
	read(t, c, "pong")
	time.Sleep(45 * time.Millisecond)
	send(t, c, "ping", map[string]string{"nonce": "barrier2"})
	read(t, c, "pong")
}
func TestS11PresenceBackgroundAndTypingThrottle(t *testing.T) {
	f, _ := liveSetup(t)
	c := f.login(t)
	send(t, c, "conversation.focus", map[string]string{"conversation_id": "77"})
	readLive(t, c, "conversation.focus.result")
	send(t, c, "typing.start", map[string]string{"conversation_id": "77"})
	readLive(t, c, "typing.result")
	send(t, c, "typing.start", map[string]string{"conversation_id": "77"})
	r := readLive(t, c, "typing.result")
	var throttled map[string]bool
	json.Unmarshal(r.Payload, &throttled)
	if !throttled["throttled"] {
		t.Fatal("typing not throttled")
	}
	send(t, c, "presence.query", map[string]string{"conversation_id": "77"})
	r = readLive(t, c, "presence.snapshot")
	var snapshot struct {
		Online []ports.ID `json:"online_actor_ids"`
		Typing []ports.ID `json:"typing_actor_ids"`
	}
	json.Unmarshal(r.Payload, &snapshot)
	if len(snapshot.Online) != 1 || len(snapshot.Typing) != 1 {
		t.Fatal("live snapshot")
	}
	send(t, c, "session.state", map[string]string{"state": "background"})
	readLive(t, c, "session.state")
	// Background sockets cannot publish ephemeral activity. The ordinary state
	// acknowledgement remains allowed so the client can complete its transition.
	send(t, c, "typing.start", map[string]string{"conversation_id": "77"})
	readLive(t, c, "error")
}
func TestS11BatchPresenceUsesSameSupportPresenceRule(t *testing.T) {
	f, p := liveSetup(t)
	p.scene = ports.SupportScene
	p.mode = "support"
	owner := f.login(t)
	original := f.claims
	peerClaims := original
	peerClaims.ActorID = "2"
	peerClaims.SourceID = "2"
	peerClaims.SessionID = strings.Repeat("d", 64)
	peerClaims.Version = strings.Repeat("e", 64)
	peerClaims.JTI = strings.Repeat("f", 64)
	f.claims = peerClaims
	_ = f.login(t)
	f.claims = original
	send(t, owner, "presence.batch", map[string]any{"conversation_ids": []string{"77"}})
	r := readLive(t, owner, "presence.batch.result")
	var snapshot struct { Online []ports.ID `json:"online_conversations"` }
	if err := json.Unmarshal(r.Payload, &snapshot); err != nil { t.Fatal(err) }
	if len(snapshot.Online) != 1 || snapshot.Online[0] != "77" {
		t.Fatalf("support presence did not match focused presence: %+v", snapshot.Online)
	}
}

func TestS11CommitQueueNeverBlocksAndDoesNotGrow(t *testing.T) {
	f := setup(t)
	f.g.realtime = &realtime{policy: &livePolicy{}, events: make(chan liveEvent, 64), stop: make(chan struct{})}
	for range 10000 {
		f.g.Committed(ports.CommittedMessage{ConversationID: "1", Seq: "1", PolicyVersion: "1"})
	}
	if len(f.g.realtime.events) != 64 {
		t.Fatal("unbounded event queue")
	}
}
