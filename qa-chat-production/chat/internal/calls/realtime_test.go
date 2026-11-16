package calls

import (
	"testing"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type callWakePublisher struct {
	committed int
	changed   []ports.CommittedMessage
}

func (p *callWakePublisher) Committed(ports.CommittedMessage) { p.committed++ }
func (p *callWakePublisher) CallChanged(m ports.CommittedMessage) {
	p.changed = append(p.changed, m)
}

func TestNotifyCallUsesRealtimeWakeWithoutCreatingMessage(t *testing.T) {
	publisher := &callWakePublisher{}
	service := &Service{publisher: publisher}
	service.notifyCall(View{ConversationID: "7", PolicyVersion: "3"})
	if publisher.committed != 0 || len(publisher.changed) != 1 {
		t.Fatalf("unexpected wake counts committed=%d changed=%d", publisher.committed, len(publisher.changed))
	}
	if publisher.changed[0].ConversationID != "7" || publisher.changed[0].PolicyVersion != "3" {
		t.Fatalf("unexpected call wake %+v", publisher.changed[0])
	}
}