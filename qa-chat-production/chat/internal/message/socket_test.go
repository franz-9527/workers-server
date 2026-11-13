package message

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"testing"
)

func TestS10RuntimeSocketReleaseGates(t *testing.T) {
	c := SocketCommands{}
	for typ, want := range map[string]string{"message.send": "CHAT_MESSAGES_NOT_READY", "message.status": "CHAT_MESSAGES_NOT_READY", "sync.request": "CHAT_HISTORY_NOT_READY", "receipt.read": "CHAT_HISTORY_NOT_READY", "typing.start": "CHAT_COMMAND_UNKNOWN", "method.inject": "CHAT_COMMAND_UNKNOWN"} {
		_, _, err := c.Execute(context.Background(), ports.Principal{}, typ, json.RawMessage(`{}`))
		if err == nil || err.Error() != want {
			t.Fatalf("%s %v", typ, err)
		}
	}
}
