package message

import (
    "context"
    "testing"
    "github.com/franz-9527/workers/chat/internal/ports"
)

func TestMySQLS09UnreadEligibilityAndAnchor(t *testing.T) {
    f, _, h, r := historyFixture(t, 6)
    // S08 fixture sends as 10202. A sender's own published messages are never unread.
    p, err := h.unreadSince(context.Background(), principal("10202"), r.ConversationID, "0")
    if err != nil || p.Count != 0 || p.FirstMessage != "" || p.SnapshotSeq != "6" {
        t.Fatalf("self messages counted: %+v, %v", p, err)
    }
    f.exec(`UPDATE chat_messages SET sender_id=10203 WHERE conversation_id=? AND seq IN (2,3,5)`, string(r.ConversationID))
    f.exec(`UPDATE chat_messages SET state='recalled',state_version=2 WHERE conversation_id=? AND seq=3`, string(r.ConversationID))
    p, err = h.unreadSince(context.Background(), principal("10202"), r.ConversationID, "1")
    if err != nil || p.Count != 2 || p.FirstSeq != "2" || p.FirstMessage == "" {
        t.Fatalf("incorrect unread window: %+v, %v", p, err)
    }
    p, err = h.unreadSince(context.Background(), principal("10202"), r.ConversationID, "2")
    if err != nil || p.Count != 1 || p.FirstSeq != "5" {
        t.Fatalf("read through anchor: %+v, %v", p, err)
    }
    if _, err = h.unreadSince(context.Background(), principal("10202"), r.ConversationID, "7"); err != ports.ErrInvalid {
        t.Fatalf("future watermark accepted: %v", err)
    }
    if _, err = h.unreadSince(context.Background(), principal("10202"), r.ConversationID, "not-a-number"); err != ports.ErrInvalid {
        t.Fatalf("invalid watermark accepted: %v", err)
    }
    f.exec(`UPDATE chat_messages SET policy_version=999 WHERE conversation_id=? AND seq=5`, string(r.ConversationID))
    p, err = h.unreadSince(context.Background(), principal("10202"), r.ConversationID, "2")
    if err != nil || p.Count != 0 {
        t.Fatalf("future policy visible: %+v, %v", p, err)
    }
}
