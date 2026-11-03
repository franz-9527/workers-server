package bootstrap

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/dependencies"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
	"net/http/httptest"
	"testing"
)

func TestS10SocketConfigRequiresRealDependencies(t *testing.T) {
	c := testConfig()
	c.Socket.Enabled = true
	if f := c.validate(); f == nil || f.Code != "CHAT_CONFIG_SOCKET_INVALID" {
		t.Fatal("WSS enabled without real identity/Redis")
	}
	var r dependencies.Runtime
	if e := r.EnableSocket(socket.Config{Enabled: true}); e == nil {
		t.Fatal("runtime accepted missing dependencies")
	}
	if e := r.EnableSocket(socket.Config{}); e != nil {
		t.Fatal(e)
	}
	c.Socket.Enabled = false
	c.Messages.Enabled = true
	if f := c.validate(); f == nil || f.Code != "CHAT_MESSAGES_NOT_READY" {
		t.Fatal("WSS bypassed send gate")
	}
}
func TestS10InternalListenerNeverRegistersSocket(t *testing.T) {
	// A nonnil gateway must still never be invoked on the internal listener.
	deps := &dependencies.Runtime{Socket: &socket.Gateway{}}
	for _, path := range []string{"/chat/ws", "/chat/ws?ticket=private"} {
		w := httptest.NewRecorder()
		healthHandler(true, deps).ServeHTTP(w, httptest.NewRequest("GET", path, nil))
		if w.Code != 404 {
			t.Fatal(w.Code)
		}
	}
}
func TestS10DefaultCommandsDoNotSend(t *testing.T) {
	_, _, err := (message.SocketCommands{}).Execute(context.Background(), ports.Principal{}, "message.send", json.RawMessage(`{}`))
	if err == nil || err.Error() != "CHAT_MESSAGES_NOT_READY" {
		t.Fatal(err)
	}
}
