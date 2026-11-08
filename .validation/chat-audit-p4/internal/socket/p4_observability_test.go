package socket

import (
	"net/http"
	"testing"
	"time"
)

func TestP4AdmissionCountersAreObservable(t *testing.T) {
	g := &Gateway{
		clients: map[*client]struct{}{}, preAuth: map[*client]struct{}{},
		preAuthIPs: map[string]*preAuthState{},
		limits: admissionLimits{preAuth: 0, authenticated: 1, perIP: 1, attempts: 1, window: time.Second},
	}
	status, code := g.admitPreAuth(&client{remoteIP: "203.0.113.10"}, time.Now())
	if status != http.StatusServiceUnavailable || code != "CHAT_RESOURCE_BUSY" {
		t.Fatalf("unexpected admission result: %d %s", status, code)
	}
	s := g.Stats()
	if s.HandshakeRejected != 1 || s.ResourceBusy != 1 || s.Connections != 0 || s.PreAuth != 0 {
		t.Fatalf("unexpected stats: %+v", s)
	}
}
