package message

import (
	"context"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/ports"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func (f *fixtureDB) s24Command(action string, args ...string) ([]byte, error) {
	script, _ := filepath.Abs("../../../server/tests/chat_collaboration_fixture.php")
	cmd := exec.CommandContext(f.ctx, os.Getenv("CHAT_AUTH_TEST_PHP"), append([]string{script, action}, args...)...)
	cmd.Env = append(os.Environ(), "CHAT_POLICY_FIXTURE_DATABASE="+f.name)
	return cmd.CombinedOutput()
}
func (f *fixtureDB) s24(action string, args ...string) {
	f.t.Helper()
	out, err := f.s24Command(action, args...)
	if err != nil || strings.TrimSpace(string(out)) != "committed" {
		f.t.Fatalf("S24 PHP %s failed: %s %v", action, out, err)
	}
}
func s24Fixture(t *testing.T) (*fixtureDB, *Service, *HistoryService, ports.ID) {
	f := newFixture(t)
	host := os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST")
	if host == "" {
		host = "127.0.0.1"
	}
	f.exec("GRANT SELECT ON `" + f.name + "`.chat_collaboration_groups TO '" + f.user + "'@'" + host + "'")
	f.s24("prepare")
	if _, err := f.store.Poll(f.ctx, 100); err != nil {
		t.Fatal(err)
	}
	cid, err := f.store.FindOrder(f.ctx, principal("10201"), "401", "401")
	if err != nil {
		t.Fatal(err)
	}
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	return f, s, h, cid
}
func s24Poll(t *testing.T, f *fixtureDB) {
	t.Helper()
	batch, err := f.store.Poll(f.ctx, 100)
	if err != nil || batch.Rejected != 0 || batch.Deferred != 0 {
		t.Fatalf("S24 projection %+v %v", batch, err)
	}
}
func s24Send(t *testing.T, s *Service, cid ports.ID, actor string, n int) Result {
	t.Helper()
	q := request(n)
	q.ConversationID = cid
	q.Text = fmt.Sprintf("群内施工沟通 %d", n)
	result, err := s.Send(context.Background(), principal(actor), q)
	if err != nil || result.State != "accepted" {
		t.Fatalf("S24 send %s %+v %v", actor, result, err)
	}
	return result
}
func s24Read(t *testing.T, h *HistoryService, cid ports.ID, actor string) HistoryPage {
	t.Helper()
	page, err := h.Read(context.Background(), principal(actor), HistoryRequest{ConversationID: cid}, "bootstrap")
	if err != nil {
		t.Fatal(actor, err)
	}
	return page
}
func TestMySQLS24GroupAuthorityHistoryRoleAndReadCounts(t *testing.T) {
	f, s, h, cid := s24Fixture(t)
	before := s24Send(t, s, cid, "10202", 1001)
	f.s24("invite", "10201")
	f.s24("join", "10203", "designer")
	s24Poll(t, f)
	f.s24("profiles")
	if page := s24Read(t, h, cid, "10203"); len(page.Messages) != 0 || page.Mode != "collaboration" {
		t.Fatal("new invitee saw prejoin history", page)
	}
	first := s24Send(t, s, cid, "10203", 1002)
	page := s24Read(t, h, cid, "10201")
	if len(page.Messages) != 2 || page.Messages[1].Sender == nil || page.Messages[1].Sender.DisplayRole != "designer" {
		t.Fatal("immutable sender identity missing", page)
	}
	counts, err := h.Readers(f.ctx, principal("10203"), cid, first.MessageID)
	if err != nil || counts.Read != 0 || counts.Unread != 3 {
		t.Fatal(counts, err)
	}
	if _, err = h.Acknowledge(f.ctx, principal("10201"), cid, page.SyncCursor); err != nil {
		t.Fatal(err)
	}
	counts, err = h.Readers(f.ctx, principal("10203"), cid, first.MessageID)
	if err != nil || counts.Read != 1 || counts.Unread != 2 {
		t.Fatal("explicit read counts", counts, err)
	}
	f.s24("role", "10203", "merchant")
	s24Poll(t, f)
	second := s24Send(t, s, cid, "10203", 1003)
	page = s24Read(t, h, cid, "10201")
	if len(page.Messages) != 3 || page.Messages[1].Sender.DisplayRole != "designer" || page.Messages[2].Sender.DisplayRole != "merchant" {
		t.Fatal("display edit rewrote old sender identity", page)
	}
	if f.text("SELECT COUNT(*) FROM chat_member_intervals WHERE conversation_id="+string(cid)+" AND actor_id=10203") != "1" {
		t.Fatal("display edit split history")
	}
	f.s24("remove", "10203")
	if _, err = h.Read(f.ctx, principal("10203"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("removed member retained access before Go caught up")
	}
	s24Poll(t, f)
	gap := s24Send(t, s, cid, "10202", 1004)
	f.s24("invite", "10201")
	f.s24("join", "10203", "designer")
	s24Poll(t, f)
	after := s24Send(t, s, cid, "10202", 1005)
	page = s24Read(t, h, cid, "10203")
	seen := map[ports.ID]bool{}
	for _, v := range page.Messages {
		seen[v.ID] = true
	}
	if seen[before.MessageID] || seen[gap.MessageID] || !seen[first.MessageID] || !seen[second.MessageID] || !seen[after.MessageID] {
		t.Fatal("rejoin filled unauthorized gaps", seen)
	}
	f.s24("source-change")
	if _, err = h.Read(f.ctx, principal("10201"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("stale source epoch accepted")
	}
	f.s24("sync")
	s24Poll(t, f)
	f.s24("phone-change")
	if _, err = h.Read(f.ctx, principal("10204"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("old owner read after authoritative address correction")
	}
	f.s24("sync")
	s24Poll(t, f)
	if _, err = h.Read(f.ctx, principal("10204"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("old owner restored")
	}
	if page := s24Read(t, h, cid, "10309"); len(page.Messages) != 0 {
		t.Fatal("new owner inherited old owner's history")
	}
	f.s24("invite", "10202")
	f.s24("join", "10300", "team_worker", "10202")
	f.s24("join", "10302", "coordinator", "10202")
	s24Poll(t, f)
	f.s24("reassign")
	s24Poll(t, f)
	next, err := f.store.FindOrder(f.ctx, principal("10301"), "401", "1401")
	if err != nil || next != cid {
		t.Fatal("reassignment changed group", next, cid, err)
	}
	if _, err = h.Read(f.ctx, principal("10202"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("old worker retained new group access")
	}
	if page := s24Read(t, h, cid, "10301"); len(page.Messages) != 0 {
		t.Fatal("new worker inherited old history")
	}
	s24Read(t, h, cid, "10203")
	s24Read(t, h, cid, "10302")
	if _, err = h.Read(f.ctx, principal("10300"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("old worker's dependent team helper retained access")
	}
	oldInvite, joinErr := f.s24Command("join", "10312", "other", "10202")
	if joinErr == nil || !strings.Contains(string(oldInvite), "CHAT_INVITE_INVALID") {
		t.Fatal("departed worker invitation remained active", string(oldInvite), joinErr)
	}
	f.s24("close")
	s24Poll(t, f)
	page = s24Read(t, h, cid, "10301")
	if page.CanSend {
		t.Fatal("closed group writable")
	}
	q := request(1006)
	q.ConversationID = cid
	if _, err = s.Send(f.ctx, principal("10301"), q); err == nil {
		t.Fatal("closed group accepted send")
	}
}
func TestMySQLS24ConcurrentAdmissionNeverExceedsCapacity(t *testing.T) {
	f, _, _, _ := s24Fixture(t)
	f.s24("invite", "10201")
	f.exec("UPDATE chat_collaboration_settings SET member_limit=4 WHERE id=1")
	var wg sync.WaitGroup
	out := make([][]byte, 2)
	errs := make([]error, 2)
	for i := 0; i < 2; i++ {
		wg.Add(1)
		go func(i int) { defer wg.Done(); out[i], errs[i] = f.s24Command("join", fmt.Sprint(10310+i), "other") }(i)
	}
	wg.Wait()
	success, full := 0, 0
	for i := range out {
		if errs[i] == nil && strings.TrimSpace(string(out[i])) == "committed" {
			success++
		} else if strings.Contains(string(out[i]), "CHAT_GROUP_FULL") {
			full++
		} else {
			t.Fatalf("unexpected join: %s %v", out[i], errs[i])
		}
	}
	if success != 1 || full != 1 || f.text("SELECT COUNT(*) FROM chat_collaboration_members WHERE order_id=401 AND active=1") != "4" {
		t.Fatal("capacity race", success, full)
	}
	if f.text("SELECT COUNT(*) FROM chat_collaboration_invites WHERE revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()") != "1" {
		t.Fatal("full group consumed invite")
	}
}

func s24Convert(t *testing.T, f *fixtureDB) ports.ID {
	t.Helper()
	host := os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST")
	if host == "" {
		host = "127.0.0.1"
	}
	f.exec("GRANT SELECT ON `" + f.name + "`.chat_collaboration_groups TO '" + f.user + "'@'" + host + "'")
	f.s24("prepare")
	s24Poll(t, f)
	cid, err := f.store.FindOrder(f.ctx, principal("10201"), "401", "401")
	if err != nil {
		t.Fatal(err)
	}
	f.s24("invite", "10201")
	f.s24("join", "10203", "designer")
	s24Poll(t, f)
	return cid
}
func TestMySQLS24GroupImageAttributionAndRevokedMediaAccess(t *testing.T) {
	f, s, m, storage, q := mediaFixture(t)
	q.ConversationID = s24Convert(t, f)
	q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 71, "group-construction")}
	mediaProcess(t, m, 1)
	if _, err := s.Send(f.ctx, principal("10202"), q); err != nil {
		t.Fatal(err)
	}
	if err := m.Advance(f.ctx); err != nil {
		t.Fatal(err)
	}
	mediaApprove(t, m)
	result, err := s.Query(f.ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if err != nil || result.State != "accepted" {
		t.Fatal(result, err)
	}
	h, _ := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
	page := s24Read(t, h, q.ConversationID, "10203")
	if len(page.Messages) != 1 || len(page.Messages[0].Images) != 1 || page.Messages[0].Sender == nil || page.Messages[0].Sender.RoleSource != "assigned_worker" {
		t.Fatal("group image identity", page)
	}
	if _, err = m.URL(f.ctx, principal("10203"), q.ConversationID, result.MessageID, q.Items[0].AttachmentID, false); err != nil {
		t.Fatal(err)
	}
	f.s24("remove", "10203")
	if _, err = m.URL(f.ctx, principal("10203"), q.ConversationID, result.MessageID, q.Items[0].AttachmentID, false); err == nil {
		t.Fatal("removed collaborator obtained new media URL")
	}
}
func TestMySQLS24GroupVoiceAndRecallUseSameCurrentAuthority(t *testing.T) {
	f, s, a, storage, inspector, q := avFixture(t, "audio")
	q.ConversationID = s24Convert(t, f)
	q = avSubmit(t, a, storage, q)
	avSteps(t, a, 5)
	if err := a.ReceiveAudio(f.ctx, ports.AudioReceipt{AppID: inspector.MediaAppID(), TraceID: "fixture-trace", Decision: "pass", Code: "official_pass", Fingerprint: strings.Repeat("e", 64)}); err != nil {
		t.Fatal(err)
	}
	f.exec("UPDATE chat_av_jobs SET next_attempt_at=UTC_TIMESTAMP(6)")
	avSteps(t, a, 2)
	result, err := s.Query(f.ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if err != nil || result.State != "accepted" {
		t.Fatal(result, err)
	}
	if _, err = a.URL(f.ctx, principal("10203"), q.ConversationID, result.MessageID, false); err != nil {
		t.Fatal(err)
	}
	if _, err = s.Recall(f.ctx, principal("10202"), q.ConversationID, result.MessageID); err != nil {
		t.Fatal(err)
	}
	if _, err = a.URL(f.ctx, principal("10203"), q.ConversationID, result.MessageID, false); err == nil {
		t.Fatal("recalled group audio accessible")
	}
}
func TestMySQLS24ReminderFanoutAndRemovedRecipientSuppression(t *testing.T) {
	f, s, _, cid := s24Fixture(t)
	f.s24("invite", "10201")
	f.s24("join", "10203", "designer")
	s24Poll(t, f)
	s24Send(t, s, cid, "10201", 1101)
	capture := &reminderCapture{}
	reminders, err := NewReminderService(s, capture)
	if err != nil {
		t.Fatal(err)
	}
	if err = reminders.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_clusters WHERE conversation_id="+string(cid)) != "3" || f.text("SELECT COUNT(*) FROM chat_reminder_clusters WHERE conversation_id="+string(cid)+" AND recipient_id=10201") != "0" {
		t.Fatal("group reminder recipients")
	}
	f.s24("remove", "10203")
	s24Poll(t, f)
	f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err = reminders.Prepare(f.ctx); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_intents WHERE recipient_id=10203") != "0" {
		t.Fatal("removed collaborator still received reminder intent")
	}
}

func TestMySQLS24TenMembersConcurrentSendAndIndependentUnread(t *testing.T) {
	f, s, h, cid := s24Fixture(t)
	f.s24("invite", "10201")
	actors := []string{"10201", "10202", "10204", "10203", "10310", "10311", "10312", "10313", "10314", "10315"}
	for _, actor := range actors[3:] {
		f.s24("join", actor, "other")
	}
	s24Poll(t, f)
	candidates, err := f.store.MemberCandidates(f.ctx, cid)
	if err != nil || len(candidates) != 10 {
		t.Fatal("ten-member candidates", candidates, err)
	}
	var wg sync.WaitGroup
	failures := make(chan error, 10)
	for i, actor := range actors {
		wg.Add(1)
		go func(i int, actor string) {
			defer wg.Done()
			q := request(1900 + i)
			q.ConversationID = cid
			q.Text = "多人同时沟通"
			result, e := s.Send(f.ctx, principal(actor), q)
			if e != nil {
				failures <- e
			} else if result.State != "accepted" {
				failures <- fmt.Errorf("not accepted: %s", result.State)
			}
		}(i, actor)
	}
	wg.Wait()
	close(failures)
	for e := range failures {
		t.Fatal(e)
	}
	if f.text("SELECT COUNT(*) FROM chat_messages WHERE conversation_id="+string(cid)) != "10" || f.text("SELECT MAX(seq) FROM chat_messages WHERE conversation_id="+string(cid)) != "10" {
		t.Fatal("group sequence lost a commit")
	}
	for _, actor := range actors {
		unread, e := h.Unread(f.ctx, principal(actor), cid)
		if e != nil || unread.Count != 9 {
			t.Fatal("per-member unread", actor, unread, e)
		}
	}
}
