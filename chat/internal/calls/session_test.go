package calls

import (
	"math"
	"testing"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func session(t *testing.T) Session {
	t.Helper()
	s, err := NewSession("1", "2", "3", "4", Wechat, Audio, 100, 130)
	if err != nil {
		t.Fatal(err)
	}
	return s
}
func apply(t *testing.T, s Session, name string, actor ports.ID, at int64) Session {
	t.Helper()
	next, err := s.Apply(Event{Name: name, ActorID: actor, Epoch: s.Epoch, ExpectedRevision: s.Revision, At: at})
	if err != nil {
		t.Fatalf("%s at %s: %v", name, s.State, err)
	}
	return next
}
func TestSessionLifecycle(t *testing.T) {
	s := session(t)
	s = apply(t, s, "accept", "4", 101)
	s = apply(t, s, "media_start", "", 102)
	s = apply(t, s, "media_ready", "", 103)
	if s.State != Active {
		t.Fatal(s)
	}
	// No fixed single-call duration cap. Resource renewal is a separate
	// durable PHP authorization; ending is allowed much later.
	s = apply(t, s, "end", "3", 100000)
	if s.State != Stopping || s.State.Terminal() {
		t.Fatal("unknown stop must retain exposure")
	}
	s = apply(t, s, "media_stopped", "", 100001)
	if s.State != Ended || !s.State.Terminal() {
		t.Fatal(s)
	}
	if _, err := s.Apply(Event{Name: "media_ready", Epoch: s.Epoch, ExpectedRevision: s.Revision, At: 100002}); err == nil {
		t.Fatal("late ready resurrected ended call")
	}
}

func TestRingingActions(t *testing.T) {
	for _, tc := range []struct {
		name  string
		actor ports.ID
		at    int64
		want  State
	}{
		{"reject", "4", 101, Rejected}, {"cancel", "3", 101, Cancelled}, {"ring_timeout", "", 130, Missed},
		{"revoke", "", 101, Failed},
	} {
		s := apply(t, session(t), tc.name, tc.actor, tc.at)
		if s.State != tc.want {
			t.Fatal(s)
		}
	}
	for _, tc := range []struct {
		name  string
		actor ports.ID
		at    int64
	}{
		{"accept", "3", 101}, {"accept", "9", 101}, {"accept", "4", 130},
		{"reject", "4", 130}, {"cancel", "3", 130}, {"ring_timeout", "", 129}, {"ring_timeout", "3", 130},
		{"media_ready", "", 101}, {"end", "3", 101}, {"revoke", "4", 101},
	} {
		before := session(t)
		after, err := before.Apply(Event{Name: tc.name, ActorID: tc.actor, Epoch: 1, ExpectedRevision: 1, At: tc.at})
		if err == nil || after != before {
			t.Fatalf("rejected action changed state: %+v", tc)
		}
	}
}

func TestFallbackFencing(t *testing.T) {
	s := apply(t, session(t), "accept", "4", 101)
	old := s
	s, err := s.Apply(Event{Name: "fallback", Epoch: s.Epoch, ExpectedRevision: s.Revision, At: 102, NextProvider: Shengwang})
	if err != nil || s.Epoch != 2 || s.Provider != Shengwang || !s.FallbackUsed || s.State != Preparing {
		t.Fatalf("%+v %v", s, err)
	}
	if _, err := s.Apply(Event{Name: "media_start", Epoch: old.Epoch, ExpectedRevision: s.Revision, At: 103}); err != ErrConflict {
		t.Fatal("old epoch")
	}
	if _, err := s.Apply(Event{Name: "media_start", Epoch: s.Epoch, ExpectedRevision: old.Revision, At: 103}); err != ErrConflict {
		t.Fatal("stale CAS")
	}
	if _, err := s.Apply(Event{Name: "fallback", Epoch: s.Epoch, ExpectedRevision: s.Revision, At: 103, NextProvider: Wechat}); err == nil {
		t.Fatal("provider loop")
	}
	s = apply(t, s, "media_start", "", 103)
	if _, err := s.Apply(Event{Name: "fallback", Epoch: s.Epoch, ExpectedRevision: s.Revision, At: 104, NextProvider: Wechat}); err == nil {
		t.Fatal("unknown join switched provider")
	}
	s = apply(t, s, "resource_stop", "", 104)
	if s.State != Stopping {
		t.Fatal("unconfirmed media released resources")
	}
	if _, err := s.Apply(Event{Name: "media_stopped", ActorID: "4", Epoch: s.Epoch, ExpectedRevision: s.Revision, At: 105}); err == nil {
		t.Fatal("client claimed safe stop")
	}
	s = apply(t, s, "media_stopped", "", 105)
	if s.State != Ended {
		t.Fatal(s)
	}
}

func TestControlAndFailureMatrix(t *testing.T) {
	preparing := apply(t, session(t), "accept", "4", 101)
	connecting := apply(t, preparing, "media_start", "", 102)
	active := apply(t, connecting, "media_ready", "", 103)
	for _, s := range []Session{connecting, active} {
		for _, name := range []string{"revoke", "resource_stop", "media_failure"} {
			if got := apply(t, s, name, "", 104); got.State != Stopping {
				t.Fatal("must await confirmed stop")
			}
		}
		if got := apply(t, s, "end", "4", 104); got.State != Stopping {
			t.Fatal("callee cannot end")
		}
	}
	if got := apply(t, preparing, "media_failure", "", 102); got.State != Failed {
		t.Fatal("no media exposure failure")
	}
	if got := apply(t, preparing, "end", "3", 102); got.State != Ended {
		t.Fatal("pre-media end")
	}
	for _, event := range []Event{
		{Name: "media_start", ActorID: "4", Epoch: 1, ExpectedRevision: 2, At: 102},
		{Name: "fallback", ActorID: "3", Epoch: 1, ExpectedRevision: 2, At: 102, NextProvider: Shengwang},
		{Name: "fallback", Epoch: 1, ExpectedRevision: 2, At: 102, NextProvider: Wechat},
		{Name: "media_start", Epoch: 1, ExpectedRevision: 2, At: 100},
		{Name: "media_start", Epoch: 1, ExpectedRevision: 2, At: 102, NextProvider: Shengwang},
		{Name: "unknown", Epoch: 1, ExpectedRevision: 2, At: 102},
	} {
		got, err := preparing.Apply(event)
		if err == nil || got != preparing {
			t.Fatalf("unsafe event %+v", event)
		}
	}
	exhausted := preparing
	exhausted.Revision = math.MaxUint64
	if _, err := exhausted.Apply(Event{Name: "media_start", Epoch: 1, ExpectedRevision: math.MaxUint64, At: 102}); err == nil {
		t.Fatal("revision overflow")
	}
	if _, err := NewSession("1", "2", "3", "3", Wechat, Audio, 100, 130); err == nil {
		t.Fatal("self call")
	}
	if _, err := NewSession("0", "2", "3", "4", Wechat, Audio, 100, 130); err == nil {
		t.Fatal("zero id")
	}
	if _, err := NewSession("1", "2", "3", "4", "unknown", Audio, 100, 130); err == nil {
		t.Fatal("provider")
	}
}
