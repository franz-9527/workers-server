package calls

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"testing"
	"time"
)

type sandboxBroker struct{ a Availability }
func (b sandboxBroker) Availability(context.Context) (Availability, error) { return b.a, nil }
func (b sandboxBroker) Resource(context.Context, ResourceRequest) (Permit, error) { return Permit{}, ErrUnavailable }
func (b sandboxBroker) Identities(context.Context, ResourceRequest) (map[string]NativePerson, error) { return nil, ErrUnavailable }

type sandboxMedia struct{}
func (sandboxMedia) Ready(Provider) bool { return true }
func (sandboxMedia) Credentials(context.Context, Session, Permit, ports.ID) (any, error) { return nil, ErrUnavailable }
func (sandboxMedia) Stop(context.Context, Session, Permit) (bool, error) { return false, nil }

func TestSandboxRoutingSeparatesMiniAppFromBrowserHarness(t *testing.T) {
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
	for i, provider := range []Provider{Wechat, Shengwang} {
		unit := "wechat_equivalent"
		if provider == Shengwang { unit = "shengwang_standard" }
		id := ports.ID("1")
		if i == 1 { id = "2" }
		a.Pools = append(a.Pools, Pool{ID:id,Decision:ResourceDecision{SchemaVersion:1,Provider:provider,ScopeID:"fixture",CycleID:"fixture",ConfigRevision:"1",Unit:unit,Status:"available",AvailableUnits:"99999999",ExpiresAt:time.Now().Unix()+300},Audio:Meter{1,1,1},Video:Meter{1,1,1}})
	}
	s := &Service{broker:sandboxBroker{a},provider:sandboxMedia{}}
	p := ports.Principal{ActorID:"7",Kind:"mini",Environment:"test",SourceID:"9000000000000000001"}

	mini, _, err := s.selectRequestPool(context.Background(), p, Request{}, Audio)
	if err != nil || mini.Decision.Provider != Wechat { t.Fatal("miniapp must honor configured priority", mini, err) }

	web, _, err := s.selectRequestPool(context.Background(), p, Request{ClientSurface:"web_test"}, Audio)
	if err != nil || web.Decision.Provider != Shengwang { t.Fatal("browser harness must stay web-compatible", web, err) }

	ordinary := p
	ordinary.SourceID = "1"
	if _, _, err = s.selectRequestPool(context.Background(), ordinary, Request{ClientSurface:"web_test"}, Audio); err == nil {
		t.Fatal("ordinary clients cannot request browser-test routing")
	}
}
