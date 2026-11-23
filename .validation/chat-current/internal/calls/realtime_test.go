package calls

import (
	"testing"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type callWakePublisher struct {
	committed int
	changed   []ports.CallChange
}

func (p *callWakePublisher) Committed(ports.CommittedMessage) { p.committed++ }
func (p *callWakePublisher) CallChanged(m ports.CallChange) {
	p.changed = append(p.changed, m)
}

func TestNotifyCallUsesRealtimeWakeWithoutCreatingMessage(t *testing.T) {
	publisher := &callWakePublisher{}
	service := &Service{publisher: publisher}
	service.notifyCall(View{CallID: "9", ConversationID: "7", PolicyVersion: "3", Epoch: "2", Revision: "5", State: Rejected})
	if publisher.committed != 0 || len(publisher.changed) != 1 {
		t.Fatalf("unexpected wake counts committed=%d changed=%d", publisher.committed, len(publisher.changed))
	}
	wake := publisher.changed[0]
	if wake.ConversationID != "7" || wake.CallID != "9" || wake.PolicyVersion != "3" || wake.AttemptEpoch != "2" || wake.Revision != "5" || wake.State != "rejected" {
		t.Fatalf("unexpected call wake %+v", wake)
	}
}
