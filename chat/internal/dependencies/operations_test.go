package dependencies

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestS22IndependentGateNeverAcceptsRestoredOrMalformedReady(t *testing.T) {
	path := filepath.Join(t.TempDir(), "gate.json")
	epoch := strings.Repeat("a", 64)
	c := OperationsConfig{path, epoch}
	if c.Ready("test") || !c.Validate(true) || (OperationsConfig{}).Validate(true) {
		t.Fatal("missing recovery evidence")
	}
	write := func(raw string) {
		t.Helper()
		if os.WriteFile(path, []byte(raw), 0640) != nil {
			t.Fatal("gate write")
		}
	}
	good := `{"schema":1,"environment":"test","epoch":"` + epoch + `","state":"ready"}`
	write(good)
	if !c.Ready("test") || c.Ready("production") {
		t.Fatal("environment isolation")
	}
	for _, raw := range []string{strings.Replace(good, "ready", "blocked", 1), strings.Replace(good, epoch, strings.Repeat("b", 64), 1), good + "{}", strings.Replace(good, `"state":"ready"`, `"state":"blocked","state":"ready"`, 1)} {
		write(raw)
		if c.Ready("test") {
			t.Fatal("invalid gate accepted")
		}
	}
	write(good)
	if os.Chmod(path, 0666) != nil {
		t.Fatal("chmod")
	}
	if c.Ready("test") {
		t.Fatal("world-writable ready accepted")
	}
}

type gateCommandFixture struct{ called bool }

func (g *gateCommandFixture) Execute(context.Context, ports.Principal, string, json.RawMessage) (string, any, error) {
	g.called = true
	return "", nil, nil
}
func TestS22GateProtectsAlreadyAuthenticatedWSSAndPrivateMetrics(t *testing.T) {
	r := &Runtime{environment: "test", operations: OperationsConfig{filepath.Join(t.TempDir(), "missing.json"), strings.Repeat("a", 64)}}
	inner := &gateCommandFixture{}
	g := guardedCommands{r, inner}
	if _, _, err := g.Execute(context.Background(), ports.Principal{}, "history.request", nil); err == nil || inner.called {
		t.Fatal("old socket bypassed recovery")
	}
	w := httptest.NewRecorder()
	r.Metrics(w, httptest.NewRequest("GET", "/metrics", nil))
	if !strings.Contains(w.Body.String(), "chat_recovery_ready 0") || strings.Contains(w.Body.String(), "missing.json") {
		t.Fatal("metric privacy")
	}
}
