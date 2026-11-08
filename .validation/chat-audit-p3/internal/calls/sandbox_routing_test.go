package calls

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"testing"
	"time"
)

type sandboxBroker struct{ a Availability }

func (b sandboxBroker) Availability(context.Context) (Availability, error) { return b.a, nil }
func (b sandboxBroker) Resource(context.Context, ResourceRequest) (Permit, error) {
	return Permit{}, ErrUnavailable
}
func (b sandboxBroker) Identities(context.Context, ResourceRequest) (map[string]NativePerson, error) {
	return nil, ErrUnavailable
}

type sandboxMedia struct{}

func (sandboxMedia) Ready(Provider) bool { return true }
func (sandboxMedia) Credentials(context.Context, Session, Permit, ports.ID) (any, error) {
	return nil, ErrUnavailable
}
func (sandboxMedia) Stop(context.Context, Session, Permit) (bool, error) { return false, nil }
func TestSandboxRoutingUsesWebCompatiblePoolWithoutChangingOrdinaryPriority(t *testing.T) {
	a := Availability{}
	a.Settings.Revision = "1"
	c := &a.Settings.Config
	c.Enabled = true
	c.AudioEnabled = true
	c.VideoEnabled = true
	c.AllowFallback = false
	c.AudioPriority = [2]Provider{Wechat, Shengwang}
	c.VideoPriority = c.AudioPriority
	c.Window = 45
	c.Margin = 15
	c.MaxActive = 10
	for i, p := range []Provider{Wechat, Shengwang} {
		unit := "wechat_equivalent"
		if p == Shengwang {
			unit = "shengwang_standard"
		}
		id := ports.ID("1")
		if i == 1 {
			id = "2"
		}
		a.Pools = append(a.Pools, Pool{ID: id, Decision: ResourceDecision{SchemaVersion: 1, Provider: p, ScopeID: "fixture", CycleID: "fixture", ConfigRevision: "1", Unit: unit, Status: "available", AvailableUnits: "99999999", ExpiresAt: time.Now().Unix() + 300}, Audio: Meter{1, 1, 1}, Video: Meter{1, 1, 1}})
	}
	s := &Service{broker: sandboxBroker{a}, provider: sandboxMedia{}}
	normal, _, e := s.selectPool(context.Background(), Audio)
	if e != nil || normal.Decision.Provider != Wechat {
		t.Fatal("ordinary route changed", normal, e)
	}
	test, _, e := s.selectSandboxPool(context.Background(), Video)
	if e != nil || test.Decision.Provider != Shengwang {
		t.Fatal("web route", test, e)
	}
	a.Pools = a.Pools[:1]
	s.broker = sandboxBroker{a}
	if _, _, e = s.selectSandboxPool(context.Background(), Audio); e == nil {
		t.Fatal("missing web pool fell back to native")
	}
	a.Settings.Config.Enabled = false
	s.broker = sandboxBroker{a}
	if _, _, e = s.selectSandboxPool(context.Background(), Audio); e == nil {
		t.Fatal("disabled global call gate bypassed")
	}
}
