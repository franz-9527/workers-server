package message

import (
	"context"
	"encoding/json"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/ports"
	"sync"
	"testing"
)

func prefRequest(id ports.ID, p Preferences, field string, value bool) PreferenceRequest {
	version := p.PinnedVersion
	if field == "later" {
		version = p.LaterVersion
	}
	return PreferenceRequest{id, p.MemberVersion, field, value, version}
}
func TestMySQLS14IndependentFieldsRetryAndReadIsolation(t *testing.T) {
	f, _, h, r := historyFixture(t, 4)
	p := principal("10202")
	initial, e := h.Preferences(f.ctx, p, r.ConversationID)
	if e != nil || initial.Pinned || initial.Later || initial.AccountRevision != "0" {
		t.Fatal(initial, e)
	}
	if f.text("SELECT COUNT(*) FROM chat_user_preferences") != "0" || f.text("SELECT COUNT(*) FROM chat_preference_accounts") != "0" {
		t.Fatal("read creates preferences")
	}
	start := make(chan struct{})
	errs := make(chan error, 2)
	for _, field := range []string{"pinned", "later"} {
		go func(field string) {
			<-start
			_, err := h.SetPreference(f.ctx, p, prefRequest(r.ConversationID, initial, field, true))
			errs <- err
		}(field)
	}
	close(start)
	for n := 0; n < 2; n++ {
		if err := <-errs; err != nil {
			t.Fatal(err)
		}
	}
	current, e := h.Preferences(f.ctx, p, r.ConversationID)
	if e != nil || !current.Pinned || !current.Later || current.PinnedVersion != "1" || current.LaterVersion != "1" || current.AccountRevision != "2" {
		t.Fatal(current, e)
	}
	repeated, e := h.SetPreference(f.ctx, p, prefRequest(r.ConversationID, initial, "pinned", true))
	if e != nil || repeated.AccountRevision != "2" {
		t.Fatal("lost-response retry", repeated, e)
	}
	conflict := prefRequest(r.ConversationID, initial, "pinned", false)
	if _, e = h.SetPreference(f.ctx, p, conflict); e != ErrPreferenceConflict {
		t.Fatal("stale overwrite", e)
	}
	_, e = h.SetPreference(f.ctx, p, prefRequest(r.ConversationID, current, "pinned", false))
	if e != nil {
		t.Fatal(e)
	}
	if _, e = h.SetPreference(f.ctx, p, prefRequest(r.ConversationID, initial, "pinned", true)); e != ErrPreferenceConflict {
		t.Fatal("ABA retry", e)
	}
	u, e := h.Unread(f.ctx, p, r.ConversationID)
	if e != nil || !u.Preferences.Later || u.Preferences.Pinned || u.Preferences.AccountRevision != "3" {
		t.Fatal(u, e)
	}
	if f.text("SELECT COUNT(*) FROM chat_member_reads") != "0" {
		t.Fatal("preference writes read seq")
	}
	boot := readPage(t, h, HistoryRequest{ConversationID: r.ConversationID}, "bootstrap")
	if _, e = h.Acknowledge(f.ctx, p, r.ConversationID, boot.SyncCursor); e != nil {
		t.Fatal(e)
	}
	current, e = h.Preferences(f.ctx, p, r.ConversationID)
	if e != nil || !current.Later {
		t.Fatal("read cleared later", current, e)
	}
	other, e := h.Preferences(f.ctx, principal("10203"), r.ConversationID)
	if e != nil || other.Pinned || other.Later {
		t.Fatal("other actor inherited preference", other, e)
	}
	if _, e = h.SetPreference(f.ctx, principal("10201"), prefRequest(r.ConversationID, current, "later", false)); e == nil {
		t.Fatal("outsider mutated")
	}
	f.php("close")
	if _, e = f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	// Readonly policy still permits changing one's own organizational preference.
	current, e = h.Preferences(f.ctx, p, r.ConversationID)
	if e != nil {
		t.Fatal(e)
	}
	if _, e = h.SetPreference(f.ctx, p, prefRequest(r.ConversationID, current, "pinned", true)); e != nil {
		t.Fatal("readonly blocked preference", e)
	}
	f.php("repair")
	if _, e = f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	next, e := h.Preferences(f.ctx, p, r.ConversationID)
	if e != nil || next.Pinned || next.Later || next.MemberVersion == initial.MemberVersion {
		t.Fatal("generation inherited preference", next, e)
	}
	if _, e = h.SetPreference(f.ctx, p, prefRequest(r.ConversationID, current, "later", false)); e != ErrResync {
		t.Fatal("old generation updated new preference", e)
	}
}
func TestMySQLS14DigestRevisionAndTransports(t *testing.T) {
	f, _, h, r := historyFixture(t, 1)
	p := principal("10202")
	f.php("sync", "402")
	if _, e := f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	page, e := h.DigestsCursor(f.ctx, p, "", 1)
	if e != nil || !page.HasMore || page.PreferenceRevision != "0" {
		t.Fatal(page, e)
	}
	initial, e := h.Preferences(f.ctx, p, r.ConversationID)
	if e != nil {
		t.Fatal(e)
	}
	body := fmt.Sprintf(`{"conversation_id":"%s","member_version":"%s","field":"later","value":true,"expected_version":"0"}`, r.ConversationID, initial.MemberVersion)
	commands := SocketCommands{History: h}
	_, out, e := commands.Execute(f.ctx, p, "conversation.preference", json.RawMessage(body))
	if e != nil || !out.(Preferences).Later {
		t.Fatal(out, e)
	}
	if _, e = h.DigestsCursor(f.ctx, p, page.NextCursor, 1); e != ErrResync {
		t.Fatal("mixed preference cursor", e)
	}
	page, e = h.DigestsCursor(f.ctx, p, "", 20)
	if e != nil || page.PreferenceRevision != "1" {
		t.Fatal(page, e)
	}
	found := false
	for _, row := range page.Items {
		if row.ConversationID == r.ConversationID {
			found = row.Unread.Preferences.Later && row.Unread.Preferences.AccountRevision == "1"
		}
	}
	if !found {
		t.Fatal("digest lost preference")
	}
	for _, key := range []string{"muted", "hidden", "read_seq", "actor_id"} {
		raw := fmt.Sprintf(`{"conversation_id":"%s","%s":true}`, r.ConversationID, key)
		if _, _, e = commands.Execute(f.ctx, p, "conversation.preference", json.RawMessage(raw)); e == nil {
			t.Fatal("forged socket field", key)
		}
	}
	f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
	if _, e = h.Preferences(f.ctx, p, r.ConversationID); e == nil {
		t.Fatal("revoked session read preferences")
	}
	if _, _, e = commands.Execute(context.Background(), p, "conversation.preference", json.RawMessage(body)); e == nil {
		t.Fatal("revoked session updated")
	}
}
func TestMySQLS14PinBudgetAcrossConversations(t *testing.T) {
	f, _, h, r := historyFixture(t, 0)
	p := principal("10202")
	f.php("sync", "402")
	if _, e := f.store.Poll(f.ctx, 100); e != nil {
		t.Fatal(e)
	}
	second, e := f.store.FindOrder(f.ctx, p, "402", "402")
	if e != nil {
		t.Fatal(e)
	}
	for n := 1; n <= 9; n++ {
		id := 1000 + n
		key := fmt.Sprintf("budget-member-%d", n)
		f.exec(`INSERT INTO chat_conversations(id,conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,created_at,updated_at) VALUES(?,'order',?,?,?,1,REPEAT('a',64),'budget','open',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, id, fmt.Sprintf("budget:%d", n), id, id)
		f.exec(`INSERT INTO chat_members(conversation_id,actor_id,actor_kind,role,member_key,can_read,can_send) VALUES(?,10202,'mini','worker',?,1,1)`, id, key)
		f.exec(`INSERT INTO chat_user_preferences(conversation_id,actor_id,member_key_hash,pinned,pinned_version,later,later_version,updated_at) VALUES(?,10202,?,1,1,0,0,UTC_TIMESTAMP(6))`, id, memberHash(key))
	}
	start := make(chan struct{})
	errs := make(chan error, 2)
	var wg sync.WaitGroup
	for _, id := range []ports.ID{r.ConversationID, second} {
		initial, e := h.Preferences(f.ctx, p, id)
		if e != nil {
			t.Fatal(e)
		}
		wg.Add(1)
		go func(id ports.ID, initial Preferences) {
			defer wg.Done()
			<-start
			_, err := h.SetPreference(f.ctx, p, prefRequest(id, initial, "pinned", true))
			errs <- err
		}(id, initial)
	}
	close(start)
	wg.Wait()
	close(errs)
	accepted, limited := 0, 0
	for err := range errs {
		if err == nil {
			accepted++
		} else if err == ErrPinLimit {
			limited++
		} else {
			t.Fatal(err)
		}
	}
	if accepted != 1 || limited != 1 || f.text("SELECT COUNT(*) FROM chat_user_preferences WHERE pinned=1") != "10" {
		t.Fatal("pin budget race", accepted, limited)
	}
	// Removed projection generations no longer occupy usable pin capacity.
	f.exec("UPDATE chat_members SET can_read=0 WHERE conversation_id=1001")
	for _, id := range []ports.ID{r.ConversationID, second} {
		initial, e := h.Preferences(f.ctx, p, id)
		if e != nil {
			t.Fatal(e)
		}
		if !initial.Pinned {
			if _, e = h.SetPreference(f.ctx, p, prefRequest(id, initial, "pinned", true)); e != nil {
				t.Fatal("stale pin consumed capacity", e)
			}
		}
	}
}
