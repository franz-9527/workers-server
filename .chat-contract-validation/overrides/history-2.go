package message

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

func historyFixture(t *testing.T, n int) (*fixtureDB, *Service, *HistoryService, Request) {
	t.Helper()
	f, s, q := setupMessage(t, safetyFunc(pass))
	h, e := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
	if e != nil {
		t.Fatal(e)
	}
	if n > 0 {
		digits := `(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)`
		from := []string{}
		terms := []string{}
		scale := 1
		for i := 0; i < 5; i++ {
			from = append(from, fmt.Sprintf("%s d%d", digits, i))
			terms = append(terms, fmt.Sprintf("d%d.n*%d", i, scale))
			scale *= 10
		}
		generator := `(SELECT ` + strings.Join(terms, "+") + `+1 seq FROM ` + strings.Join(from, " CROSS JOIN ") + `) nums`
		f.exec(`INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,text_body,state,state_version,safety_rule_version,created_at) SELECT ?,seq,10202,CONCAT('1700000000.',LPAD(seq,32,'0')),1,'text',CONCAT('fixture-',seq),'published',1,'s08-fixture',UTC_TIMESTAMP(6) FROM `+generator+` WHERE seq<=? ORDER BY seq`, string(q.ConversationID), n)
		f.exec(`INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) SELECT conversation_id,seq,id,1,'message.accepted',UTC_TIMESTAMP(6) FROM chat_messages WHERE conversation_id=?`, string(q.ConversationID))
		f.exec(`UPDATE chat_conversations SET last_seq=?,last_change_seq=? WHERE id=?`, n, n, string(q.ConversationID))
	}
	return f, s, h, q
}
func readPage(t *testing.T, h *HistoryService, q HistoryRequest, mode string) HistoryPage {
	t.Helper()
	p, e := h.Read(context.Background(), principal("10202"), q, mode)
	if e != nil {
		t.Fatalf("read %s: %v", mode, e)
	}
	return p
}
func TestMySQLS08FixedSnapshotsGapsAndChanges(t *testing.T) {
	f, s, h, r := historyFixture(t, 100)
	q := HistoryRequest{ConversationID: r.ConversationID}
	boot := readPage(t, h, q, "bootstrap")
	if len(boot.Messages) != 30 || boot.Messages[0].Seq != "71" || boot.NextSync != "100" || boot.NextChange != "100" || boot.HistoryCursor == "" {
		t.Fatal("bootstrap", boot)
	}
	for i := 1; i <= 3; i++ {
		r.ClientMessageID = client(i)
		accepted(t, s, r)
	} // A real-time observer may see 103 first; the signed confirmed cursor remains 100.
	q.Cursor = boot.SyncCursor
	q.Limit = 1
	p := readPage(t, h, q, "sync")
	if p.NextChange != "" {
		t.Fatal("sync certified unconsumed changes")
	}
	if p.NextSync != "101" || p.SnapshotMaxSeq != "103" || !p.HasMore {
		t.Fatal("skipped 101", p)
	}
	r.ClientMessageID = client(4)
	accepted(t, s, r)
	for _, seq := range []ports.ID{"102", "103"} {
		q.Cursor = p.SyncCursor
		p = readPage(t, h, q, "sync")
		if p.NextSync != seq || p.SnapshotMaxSeq != "103" {
			t.Fatal("snapshot moved", p)
		}
	}
	if p.HasMore {
		t.Fatal("snapshot did not finish")
	}
	q.Cursor = p.SyncCursor
	p = readPage(t, h, q, "sync")
	if p.NextSync != "104" || p.HasMore {
		t.Fatal("next round", p)
	}
	// S15-style writer fixture: same commit lock and atomic state/change; no product recall API is enabled here.
	tx, e := f.root.BeginTx(f.ctx, nil)
	if e != nil {
		t.Fatal(e)
	}
	defer tx.Rollback()
	var upper uint64
	tx.QueryRow(`SELECT last_change_seq FROM chat_conversations WHERE id=? FOR UPDATE`, string(r.ConversationID)).Scan(&upper)
	if _, e = tx.Exec(`UPDATE chat_messages SET state='recalled',state_version=2 WHERE conversation_id=? AND seq=20`, string(r.ConversationID)); e != nil {
		t.Fatal(e)
	}
	if _, e = tx.Exec(`INSERT INTO chat_message_changes SELECT conversation_id,?,id,2,'message.recalled',UTC_TIMESTAMP(6) FROM chat_messages WHERE conversation_id=? AND seq=20`, upper+1, string(r.ConversationID)); e != nil {
		t.Fatal(e)
	}
	tx.Exec(`UPDATE chat_conversations SET last_change_seq=? WHERE id=?`, upper+1, string(r.ConversationID))
	if tx.Commit() != nil {
		t.Fatal("recall commit")
	}
	q.Cursor = boot.ChangeCursor
	q.Limit = 2
	var changes []ChangeView
	for i := 0; i < 4; i++ {
		p = readPage(t, h, q, "changes")
		changes = append(changes, p.Changes...)
		q.Cursor = p.ChangeCursor
		if !p.HasMore {
			break
		}
	}
	if p.NextSync != "" {
		t.Fatal("change page certified messages")
	}
	last := changes[len(changes)-1]
	if len(changes) != 5 || last.Message.Seq != "20" || last.Message.Text != nil || last.Message.StateVersion != "2" || p.NextChange != "105" {
		t.Fatal("old-state recovery", p)
	}
	// The old history snapshot re-reads safe current states, never original evidence.
	q.Cursor = boot.HistoryCursor
	q.Limit = 50
	old := readPage(t, h, q, "history")
	if old.SyncCursor != "" || old.ChangeCursor != "" {
		t.Fatal("history replaced recovery cursors")
	}
	for _, m := range old.Messages {
		if m.Seq == "20" && (m.Text != nil || m.StateVersion != "2") {
			t.Fatal("old body resurrected")
		}
	}
	q.Cursor = old.HistoryCursor
	older := readPage(t, h, q, "history")
	seenRecall := false
	for _, m := range older.Messages {
		if m.Seq == "20" {
			seenRecall = true
			if m.Text != nil || m.StateVersion != "2" {
				t.Fatal("historical body resurrected")
			}
		}
	}
	if !seenRecall {
		t.Fatal("recall fixture not enumerated")
	}
	q.Cursor = boot.SyncCursor
	if _, e := h.Read(f.ctx, principal("10203"), q, "sync"); e != ErrResync {
		t.Fatal("cross-actor cursor reused", e)
	}
	// Closed ranges may contain a legal hole. Enumeration can certify the upper bound.
	f.exec(`UPDATE chat_conversations SET last_seq=106 WHERE id=?`, string(r.ConversationID))
	q.Cursor = p.ChangeCursor // deliberately a change cursor must be rejected by sync
	if _, e = h.Read(f.ctx, principal("10202"), q, "sync"); e != ErrResync {
		t.Fatal("stream reuse", e)
	}
	q.Cursor = boot.SyncCursor
	q.Limit = 50
	p = readPage(t, h, q, "sync")
	if p.NextSync != "106" || p.HasMore || len(p.Messages) != 4 {
		t.Fatal("legal hole", p)
	}
	if f.text("SELECT COUNT(*) FROM notifications") != "0" {
		t.Fatal("read created notification")
	}
}
func TestMySQLS08PermissionIntervalsLocateAndRetention(t *testing.T) {
	f, _, h, r := historyFixture(t, 90)
	q := HistoryRequest{ConversationID: r.ConversationID}
	boot := readPage(t, h, q, "bootstrap")
	f.php("close")
	f.store.Poll(f.ctx, 100)
	f.php("repair")
	f.store.Poll(f.ctx, 100)
	// Returning identity has two legal ranges; version 2 is an explicit gap.
	f.exec(`DELETE FROM chat_member_intervals WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	f.exec(`INSERT INTO chat_member_intervals(conversation_id,actor_id,member_key,start_version,end_version) SELECT conversation_id,actor_id,member_key,1,2 FROM chat_members WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	f.exec(`INSERT INTO chat_member_intervals(conversation_id,actor_id,member_key,start_version) SELECT conversation_id,actor_id,member_key,3 FROM chat_members WHERE conversation_id=? AND actor_id=10202`, string(r.ConversationID))
	f.exec(`UPDATE chat_messages SET policy_version=2 WHERE seq BETWEEN 40 AND 70 AND conversation_id=?`, string(r.ConversationID))
	q.Cursor = boot.SyncCursor
	if _, e := h.Read(f.ctx, principal("10202"), q, "sync"); e != ErrResync {
		t.Fatal("old policy cursor", e)
	}
	q.Cursor = ""
	fresh := readPage(t, h, q, "bootstrap")
	for _, m := range fresh.Messages {
		if m.PolicyVersion == "2" {
			t.Fatal("unauthorized gap")
		}
	}
	q.Cursor = ""
	q.TargetID = ports.ID(f.text(`SELECT id FROM chat_messages WHERE conversation_id=? AND seq=55`, string(r.ConversationID)))
	if _, e := h.Read(f.ctx, principal("10202"), q, "locate"); e != policy.ErrDenied {
		t.Fatal("gap target", e)
	}
	q.TargetID = ports.ID(f.text(`SELECT id FROM chat_messages WHERE conversation_id=? AND seq=30`, string(r.ConversationID)))
	located := readPage(t, h, q, "locate")
	if len(located.Messages) > 30 || located.SyncCursor != "" {
		t.Fatal("unbounded locate")
	}
	q.TargetID = ""
	q.Cursor = fresh.HistoryCursor
	f.exec(`UPDATE chat_conversations SET sync_floor_seq=80 WHERE id=?`, string(r.ConversationID))
	if _, e := h.Read(f.ctx, principal("10202"), q, "history"); e != ErrResync {
		t.Fatal("retained floor", e)
	}
	q.Cursor = fresh.ChangeCursor
	f.exec(`UPDATE chat_conversations SET change_floor_seq=90 WHERE id=?`, string(r.ConversationID))
	c, _ := h.parse(fresh.ChangeCursor, "changes")
	c.Position = "89"
	q.Cursor = h.sign(c)
	if _, e := h.Read(f.ctx, principal("10202"), q, "changes"); e != ErrResync {
		t.Fatal("change floor", e)
	}
	q.Cursor = ""
	f.exec(`UPDATE login_sessions SET status='revoked' WHERE id=10202`)
	if _, e := h.Read(f.ctx, principal("10202"), q, "bootstrap"); e == nil {
		t.Fatal("revoked login read")
	}
}
func TestMySQLS08LargeHistoryAndCommitSerialization(t *testing.T) {
	f, _, h, r := historyFixture(t, 100000)
	q := HistoryRequest{ConversationID: r.ConversationID, Limit: 50}
	p := readPage(t, h, q, "bootstrap")
	if len(p.Messages) != 50 || p.Messages[0].Seq != "99951" {
		t.Fatal("large bounded page")
	}
	encoded, _ := json.Marshal(p)
	if len(encoded) > 100000 {
		t.Fatal("unbounded response")
	}
	// A pending low-number change cannot fall behind a certified watermark.
	tx, e := f.root.BeginTx(f.ctx, nil)
	if e != nil {
		t.Fatal(e)
	}
	defer tx.Rollback()
	tx.Exec(`UPDATE chat_conversations SET last_change_seq=100001 WHERE id=?`, string(r.ConversationID))
	done := make(chan error, 1)
	go func() { _, e := h.Read(f.ctx, principal("10202"), q, "bootstrap"); done <- e }()
	select {
	case e := <-done:
		t.Fatal("snapshot crossed pending commit", e)
	case <-time.After(100 * time.Millisecond):
	}
	tx.Rollback()
	if e = <-done; e != nil {
		t.Fatal(e)
	}
	committing, e := f.root.BeginTx(f.ctx, nil)
	if e != nil {
		t.Fatal(e)
	}
	defer committing.Rollback()
	if _, e = committing.Exec(`UPDATE chat_conversations SET last_change_seq=100001 WHERE id=?`, string(r.ConversationID)); e != nil {
		t.Fatal(e)
	}
	if _, e = committing.Exec(`UPDATE chat_messages SET state='recalled',state_version=2 WHERE conversation_id=? AND seq=1`, string(r.ConversationID)); e != nil {
		t.Fatal(e)
	}
	if _, e = committing.Exec(`INSERT INTO chat_message_changes SELECT conversation_id,100001,id,2,'message.recalled',UTC_TIMESTAMP(6) FROM chat_messages WHERE conversation_id=? AND seq=1`, string(r.ConversationID)); e != nil {
		t.Fatal(e)
	}
	result := make(chan HistoryPage, 1)
	go func() {
		page, e := h.Read(f.ctx, principal("10202"), HistoryRequest{ConversationID: r.ConversationID, Cursor: p.ChangeCursor}, "changes")
		if e != nil {
			done <- e
			return
		}
		result <- page
	}()
	select {
	case page := <-result:
		t.Fatal("change crossed uncommitted state", page)
	case e := <-done:
		t.Fatal(e)
	case <-time.After(100 * time.Millisecond):
	}
	if e = committing.Commit(); e != nil {
		t.Fatal(e)
	}
	select {
	case page := <-result:
		if page.SnapshotMaxChange != "100001" || len(page.Changes) != 1 || page.Changes[0].Message.Text != nil || page.Changes[0].Message.StateVersion != "2" {
			t.Fatal("snapshot missed committed safety state", page)
		}
	case e := <-done:
		t.Fatal(e)
	case <-time.After(4 * time.Second):
		t.Fatal("change reader did not resume")
	}
	// Reads are allowed under a send-only ban, but never as a different actor.
	f.exec(`INSERT INTO chat_restrictions(actor_id,permanent,version,updated_at) VALUES(10202,1,1,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE permanent=1`)
	readPage(t, h, q, "bootstrap")
	if _, e = h.Read(f.ctx, principal("10201"), q, "bootstrap"); e == nil {
		t.Fatal("outsider read")
	}
}
