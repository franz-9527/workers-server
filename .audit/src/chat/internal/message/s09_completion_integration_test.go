package message

import (
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestMySQLS09LargeUnreadCapAndRealFirstAnchor(t *testing.T) {
	f, _, h, r := historyFixture(t, 100000)
	f.exec(`UPDATE chat_messages SET sender_id=10203 WHERE conversation_id=?`, string(r.ConversationID))
	f.exec(`UPDATE chat_messages SET sender_id=10202 WHERE conversation_id=? AND seq<=10`, string(r.ConversationID))
	f.exec(`UPDATE chat_messages SET state='recalled',state_version=2 WHERE conversation_id=? AND seq=20`, string(r.ConversationID))
	u, e := h.Unread(f.ctx, principal("10202"), r.ConversationID)
	if e != nil || u.Count != 1000 || !u.CountCapped || u.FirstSeq != "11" {
		t.Fatalf("bounded unread %+v %v", u, e)
	}
	located := readPage(t, h, HistoryRequest{ConversationID: r.ConversationID, TargetID: u.FirstMessage}, "locate")
	if len(located.Messages) > 30 {
		t.Fatal("anchor expanded history")
	}
	if f.text(`SELECT COUNT(*) FROM chat_member_reads`) != "0" {
		t.Fatal("unread/anchor wrote read receipt")
	}
	boot := readPage(t, h, HistoryRequest{ConversationID: r.ConversationID}, "bootstrap")
	proof, e := h.parse(boot.SyncCursor, "sync")
	if e != nil {
		t.Fatal(e)
	}
	proof.Seq = "99000"
	proof.Position = "99000"
	if _, e = h.Acknowledge(f.ctx, principal("10202"), r.ConversationID, h.sign(proof)); e != nil {
		t.Fatal(e)
	}
	u, e = h.Unread(f.ctx, principal("10202"), r.ConversationID)
	if e != nil || u.Count != 1000 || u.CountCapped || u.FirstSeq != "99001" {
		t.Fatalf("exact count at cap %+v %v", u, e)
	}
	if _, e = h.Acknowledge(f.ctx, principal("10202"), r.ConversationID, boot.SyncCursor); e != nil {
		t.Fatal(e)
	}
	u, e = h.Unread(f.ctx, principal("10202"), r.ConversationID)
	if e != nil || u.Count != 0 || u.CountCapped || u.ReadSeq != "100000" {
		t.Fatal(u, e)
	}
}

func TestMySQLS09UnreadHistoryGapAndRetainedAcknowledgement(t *testing.T) {
	f, _, h, r := historyFixture(t, 6)
	f.php("close")
	if _, e := f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	f.php("repair")
	if _, e := f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	f.exec(`DELETE FROM chat_member_intervals WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	f.exec(`INSERT INTO chat_member_intervals(conversation_id,actor_id,member_key,start_version,end_version) SELECT conversation_id,actor_id,member_key,1,2 FROM chat_members WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	f.exec(`INSERT INTO chat_member_intervals(conversation_id,actor_id,member_key,start_version) SELECT conversation_id,actor_id,member_key,3 FROM chat_members WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	f.exec(`UPDATE chat_messages SET sender_id=10203,policy_version=CASE WHEN seq IN(2,5) THEN 2 WHEN seq IN(4,6) THEN 3 ELSE 1 END WHERE conversation_id=?`, string(r.ConversationID))
	u, e := h.Unread(f.ctx, principal("10202"), r.ConversationID)
	if e != nil || u.Count != 4 || u.FirstSeq != "1" {
		t.Fatal("gap counted", u, e)
	}
	boot := readPage(t, h, HistoryRequest{ConversationID: r.ConversationID}, "bootstrap")
	proof, _ := h.parse(boot.SyncCursor, "sync")
	proof.Pending = true
	if _, e = h.Acknowledge(f.ctx, principal("10202"), r.ConversationID, h.sign(proof)); e != ErrResync {
		t.Fatal("unfinished sync acknowledged", e)
	}
	proof.Pending = false
	proof.Seq = "1"
	proof.Position = "1"
	f.exec(`UPDATE chat_conversations SET sync_floor_seq=2 WHERE id=?`, string(r.ConversationID))
	if _, e = h.Acknowledge(f.ctx, principal("10202"), r.ConversationID, h.sign(proof)); e != ErrResync {
		t.Fatal("obsolete retained proof acknowledged", e)
	}
	if f.text(`SELECT COUNT(*) FROM chat_member_reads`) != "0" {
		t.Fatal("invalid proof wrote read state")
	}
}

func TestMySQLS09SlowBodyStopsWithinReadBudget(t *testing.T) {
	_, _, h, _ := historyFixture(t, 0)
	h.timeout = 100 * time.Millisecond
	handler, e := NewHistoryHandler(h, historyFixtureIdentity{}, 2)
	if e != nil {
		t.Fatal(e)
	}
	server := httptest.NewServer(handler)
	defer server.Close()
	reader, writer := io.Pipe()
	defer reader.Close()
	defer writer.Close()
	request, _ := http.NewRequest("POST", server.URL+"/chat/api/v1/conversations/unread", reader)
	request.Header.Set("Authorization", "Bearer fixture-only")
	request.Header.Set("Content-Type", "application/json")
	client := server.Client()
	client.Timeout = 2 * time.Second
	start := time.Now()
	response, e := client.Do(request)
	if e != nil {
		t.Fatal(e)
	}
	defer response.Body.Close()
	if response.StatusCode != 408 || time.Since(start) > time.Second {
		t.Fatal("slow body exceeded budget", response.StatusCode, time.Since(start))
	}
}

func TestMySQLS12DigestActivityUsesVisibleMessageTime(t *testing.T) {
	f, _, h, r := historyFixture(t, 3)
	f.exec(`UPDATE chat_messages SET created_at=CASE seq WHEN 1 THEN '2026-10-01 10:00:00' WHEN 2 THEN '2026-10-01 11:00:00' ELSE '2026-10-01 12:00:00' END WHERE conversation_id=?`, string(r.ConversationID))
	u, e := h.Unread(f.ctx, principal("10202"), r.ConversationID)
	if e != nil || u.LastMessageAt == nil || u.LastMessageAt.Format(time.RFC3339) != "2026-10-01T12:00:00Z" || u.Count != 0 {
		t.Fatal("own/read messages still determine activity", u, e)
	}
	if u.LastMessage==nil||u.LastMessage.Text==nil||*u.LastMessage.Text!="fixture-3"||u.OrderContext==nil||u.OrderContext.PublicNo!="CHAT-PLATFORM-401"||u.MemberRole!="worker" {t.Fatal("untrusted or missing preview/context",u)}
 boot:=readPage(t,h,HistoryRequest{ConversationID:r.ConversationID},"bootstrap")
 if boot.OrderContext==nil||boot.ViewerID!="10202"||boot.MemberRole!="worker"{t.Fatal("missing native page context",boot)}
 f.exec(`UPDATE chat_messages SET state='recalled',text_body='must-not-leak' WHERE conversation_id=? AND seq=3`, string(r.ConversationID))
	page, e := h.Digests(f.ctx, principal("10202"), "0", 20)
	if e != nil || len(page.Items) != 1 || page.Items[0].Unread.LastMessageAt == nil || !page.Items[0].Unread.LastMessageAt.Equal(*u.LastMessageAt) {
		t.Fatal("digest exposes recall activity without a body", page, e)
	}
	if page.Items[0].Unread.LastMessage==nil||page.Items[0].Unread.LastMessage.Text!=nil||page.Items[0].Unread.LastMessage.State!="recalled"{t.Fatal("recall original leaked in preview",page)}
 // A message outside every authorized membership interval cannot affect sorting.
	f.exec(`UPDATE chat_member_intervals SET end_version=1 WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	u, e = h.Unread(f.ctx, principal("10202"), r.ConversationID)
	if e != nil || u.LastMessageAt != nil || u.LastMessage != nil {
		t.Fatal("hidden activity leaked", u, e)
	}
	if f.text(`SELECT COUNT(*) FROM chat_member_reads`) != "0" {
		t.Fatal("inbox ordering wrote a read receipt")
	}
}
