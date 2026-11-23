package message

import (
	"context"
	"errors"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"sync"
	"testing"
	"time"
)

func TestMySQLUnifiedDirectNoApplicationThreeMessageLimitAndBlock(t *testing.T) {
	f := newFixture(t)
	f.php("direct-request")
	poll := func() {
		t.Helper()
		if stats, err := f.store.Poll(f.ctx, 100); err != nil || stats.Applied < 1 {
			t.Fatalf("project direct: %+v %v", stats, err)
		}
	}
	poll()
	cid := ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='direct'"))
	ctx := policy.WithDirect(f.ctx)
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	send := func(actor string, number int) (Result, error) {
		q := request(number)
		q.ConversationID = cid
		return s.Send(ctx, principal(actor), q)
	}
	first, err := send("10202", 200)
	if err != nil || first.State != "accepted" {
		t.Fatalf("first direct send: %+v %v", first, err)
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_events") != "1" {
		t.Fatal("first stranger message did not create its normal reminder event")
	}
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte("12345678901234567890123456789012"), s.timeout)
	if err != nil {
		t.Fatal(err)
	}
	recipient, err := h.DirectSummaries(ctx, principal("10203"), []ports.ID{cid})
	if err != nil || len(recipient.Items) != 1 || recipient.Items[0].Unread.Count != 1 || recipient.Items[0].Unread.LastMessage == nil ||
		recipient.Items[0].Unread.LastMessage.ID != first.MessageID || recipient.Items[0].Unread.LastMessageAt == nil {
		t.Fatalf("direct recipient summary: %+v %v", recipient, err)
	}
	if recipient.Items[0].Unread.LastMessage.Display == nil || recipient.Items[0].Unread.LastMessage.Display.Name != "测试师傅" {
		t.Fatalf("approved worker nickname did not come from the restricted worker display projection: %+v", recipient.Items[0].Unread.LastMessage.Display)
	}
	sender, err := h.DirectSummaries(ctx, principal("10202"), []ports.ID{cid})
	if err != nil || len(sender.Items) != 1 || sender.Items[0].Unread.Count != 0 || sender.Items[0].Unread.LastMessage == nil {
		t.Fatalf("direct sender summary: %+v %v", sender, err)
	}
	if _, err = h.DirectSummaries(ctx, principal("10203"), []ports.ID{cid, cid}); !errors.Is(err, ports.ErrInvalid) {
		t.Fatalf("duplicate direct summaries accepted: %v", err)
	}
	for _, number := range []int{201, 202} {
		if out, e := send("10202", number); e != nil || out.State != "accepted" {
			t.Fatalf("stranger message %d rejected: %+v %v", number, out, e)
		}
	}
	if _, err := send("10202", 203); !errors.Is(err, ErrDirectStrangerLimit) {
		t.Fatalf("fourth stranger message bypassed limit: %v", err)
	}
	if f.text("SELECT initiator_message_count FROM chat_direct_contacts WHERE scope_key='direct:10202:10203'") != "3" {
		t.Fatal("stranger message count is not durable at three")
	}
	if out, e := send("10203", 204); e != nil || out.State != "accepted" {
		t.Fatalf("recipient reply did not establish Direct: %+v %v", out, e)
	}
	if f.text("SELECT established_at IS NOT NULL FROM chat_direct_contacts WHERE scope_key='direct:10202:10203'") != "1" {
		t.Fatal("recipient reply did not persist established relationship")
	}
	if out, e := send("10202", 205); e != nil || out.State != "accepted" {
		t.Fatalf("established Direct still enforced stranger limit: %+v %v", out, e)
	}

	reminders, _ := NewReminderService(s, &reminderCapture{})
	reminders = reminders.WithPresence(func(ports.ID) (bool, bool) { return true, false })
	if err = reminders.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err = reminders.Prepare(f.ctx); err != nil {
		t.Fatal(err)
	}
	op := f.text("SELECT operation_id FROM chat_reminder_intents WHERE recipient_id=10203 ORDER BY id LIMIT 1")
	if allowed, err := reminders.Eligible(f.ctx, op); err != nil || !allowed {
		t.Fatalf("direct reminder ineligible before block: %t %v", allowed, err)
	}
	f.php("direct-transition", "401", "block")
	if allowed, err := reminders.Eligible(f.ctx, op); err != nil || allowed {
		t.Fatalf("block failed before projection: %t %v", allowed, err)
	}
	poll()
	if _, err := send("10202", 206); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("blocked direct still accepts sends: %v", err)
	}
}

func TestMySQLUnifiedDirectConcurrentStrangerLimit(t *testing.T) {
	f := newFixture(t)
	f.php("direct-request")
	if stats, err := f.store.Poll(f.ctx, 100); err != nil || stats.Applied < 1 {
		t.Fatalf("project direct: %+v %v", stats, err)
	}
	cid := ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='direct'"))
	ctx := policy.WithDirect(f.ctx)
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	type outcome struct{ result Result; err error }
	results := make(chan outcome, 4)
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func(number int) {
			defer wg.Done()
			q := request(300 + number)
			q.ConversationID = cid
			out, e := s.Send(ctx, principal("10202"), q)
			results <- outcome{result: out, err: e}
		}(i)
	}
	wg.Wait()
	close(results)
	accepted, limited := 0, 0
	for row := range results {
		if row.err == nil && row.result.State == "accepted" {
			accepted++
			continue
		}
		if errors.Is(row.err, ErrDirectStrangerLimit) {
			limited++
			continue
		}
		t.Fatalf("unexpected concurrent result: %+v %v", row.result, row.err)
	}
	if accepted != 3 || limited != 1 {
		t.Fatalf("concurrent stranger cap mismatch: accepted=%d limited=%d", accepted, limited)
	}
	if f.text("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=?", string(cid)) != "3" ||
		f.text("SELECT initiator_message_count FROM chat_direct_contacts WHERE scope_key='direct:10202:10203'") != "3" {
		t.Fatal("concurrent sends escaped the durable three-message cap")
	}
}

func TestMySQLUnifiedDirectLegacyApplicationCompatibility(t *testing.T) {
	f := newFixture(t)
	f.php("direct-legacy", "401", "request")
	if stats, err := f.store.Poll(f.ctx, 100); err != nil || stats.Applied < 1 {
		t.Fatalf("project legacy request: %+v %v", stats, err)
	}
	if f.text("SELECT application_state FROM chat_direct_guards WHERE scope_key='direct:10202:10203'") != "accepted" {
		t.Fatal("legacy request did not normalize to the accepted direct relationship")
	}
	before := f.text("SELECT COUNT(*) FROM chat_direct_blocks WHERE blocker_id=10203 AND blocked_id=10202")
	f.php("direct-legacy", "401", "accept")
	if f.text("SELECT COUNT(*) FROM chat_direct_blocks WHERE blocker_id=10203 AND blocked_id=10202") != before {
		t.Fatal("legacy accept unexpectedly changed block state")
	}
	f.php("direct-legacy", "401", "reject")
	if f.text("SELECT COUNT(*) FROM chat_direct_blocks WHERE blocker_id=10203 AND blocked_id=10202") != "1" {
		t.Fatal("legacy reject was not preserved as an explicit block")
	}
	if stats, err := f.store.Poll(f.ctx, 100); err != nil || stats.Applied < 1 {
		t.Fatalf("project legacy reject block: %+v %v", stats, err)
	}
	cid := ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='direct'"))
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	q := request(360)
	q.ConversationID = cid
	if _, err = s.Send(policy.WithDirect(f.ctx), principal("10202"), q); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("legacy reject block did not revoke send authority: %v", err)
	}
}

func TestMySQLUnifiedDirectConcurrentReportLimit(t *testing.T) {
	f := newFixture(t)
	f.php("direct-request")
	if stats, err := f.store.Poll(f.ctx, 100); err != nil || stats.Applied < 1 {
		t.Fatalf("project direct: %+v %v", stats, err)
	}
	cid := ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='direct'"))
	ctx := policy.WithDirect(f.ctx)
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	send := func(actor string, number int) Result {
		t.Helper()
		q := request(number)
		q.ConversationID = cid
		out, e := s.Send(ctx, principal(actor), q)
		if e != nil || out.State != "accepted" {
			t.Fatalf("direct send %d: %+v %v", number, out, e)
		}
		return out
	}
	peerMessages := []ports.ID{send("10203", 370).MessageID}
	send("10202", 371) // establish the relationship before the remaining peer messages.
	for _, number := range []int{372, 373, 374, 375, 376} {
		peerMessages = append(peerMessages, send("10203", number).MessageID)
	}
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte("12345678901234567890123456789012"), s.timeout)
	if err != nil {
		t.Fatal(err)
	}
	type reportOutcome struct{ err error }
	results := make(chan reportOutcome, len(peerMessages))
	var wg sync.WaitGroup
	for _, mid := range peerMessages {
		wg.Add(1)
		go func(messageID ports.ID) {
			defer wg.Done()
			_, e := h.directReport(ctx, principal("10202"), cid, messageID, "spam")
			results <- reportOutcome{err: e}
		}(mid)
	}
	wg.Wait()
	close(results)
	received, limited := 0, 0
	for row := range results {
		if row.err == nil {
			received++
			continue
		}
		if errors.Is(row.err, ports.ErrBusy) {
			limited++
			continue
		}
		t.Fatalf("unexpected concurrent report result: %v", row.err)
	}
	if received != 5 || limited != 1 {
		t.Fatalf("concurrent report cap mismatch: received=%d limited=%d", received, limited)
	}
	if f.text("SELECT COUNT(*) FROM chat_direct_reports WHERE reporter_id=10202") != "5" {
		t.Fatal("concurrent reports escaped the durable 24-hour cap")
	}
}

func TestMySQLUnifiedFeedbackSingleHandlerAndOfflineEligibility(t *testing.T) {
	f := newFixture(t)
	f.php("support-init")
	poll := func() {
		t.Helper()
		if stats, err := f.store.Poll(f.ctx, 100); err != nil || stats.Applied < 1 {
			t.Fatalf("project support: %+v %v", stats, err)
		}
	}
	poll()
	cid := ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='support'"))
	ctx := policy.WithFeedback(f.ctx)
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte("12345678901234567890123456789012"), s.timeout)
	if err != nil {
		t.Fatal(err)
	}
	send := func(actor string, number int, reply ports.ID) Result {
		t.Helper()
		q := request(number)
		q.ConversationID, q.ReplyTo = cid, reply
		result, err := s.Send(ctx, principal(actor), q)
		if err != nil || result.State != "accepted" {
			t.Fatalf("support send: %+v %v", result, err)
		}
		return result
	}
	page := func(actor string) HistoryPage {
		t.Helper()
		result, err := h.Read(ctx, principal(actor), HistoryRequest{ConversationID: cid}, "bootstrap")
		if err != nil {
			t.Fatal(err)
		}
		return result
	}
	f.exec(`INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,created_at,updated_at) VALUES
		('chat.support.auto_reply_enabled','1','bool','chat',0,'fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
		('chat.support.auto_reply_text','已收到，我们会尽快查看。','string','chat',0,'fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP())
		ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()`)
	first := send("10202", 190, "")
	ownerFirst := page("10202")
	if len(ownerFirst.Messages) != 2 || ownerFirst.Messages[0].ID != first.MessageID || ownerFirst.Messages[1].Text == nil || *ownerFirst.Messages[1].Text != "已收到，我们会尽快查看。" {
		t.Fatalf("support first-message auto reply missing: %+v", ownerFirst.Messages)
	}
	if f.text("SELECT COUNT(*) FROM chat_support_message_labels WHERE message_id=?", string(ownerFirst.Messages[1].ID)) != "1" {
		t.Fatal("support auto reply missing service label")
	}
	ownerUnread, err := h.Unread(ctx, principal("10202"), cid)
	if err != nil || ownerUnread.LastMessage == nil || ownerUnread.LastMessage.Text == nil ||
		*ownerUnread.LastMessage.Text != "已收到，我们会尽快查看。" || ownerUnread.LastMessageAt == nil {
		t.Fatalf("support fixed entry lost its real last-message preview: %+v %v", ownerUnread, err)
	}
	if _, err = h.Read(ctx, principal("10201"), HistoryRequest{ConversationID: cid}, "bootstrap"); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("unselected staff can read feedback: %v", err)
	}
	f.php("support-handler", "401", "10201")
	if _, err = h.Read(ctx, principal("10203"), HistoryRequest{ConversationID: cid}, "bootstrap"); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("previous staff still authorized before projection: %v", err)
	}
	poll()
	if got := page("10201"); len(got.Messages) != 2 || got.Messages[0].ID != first.MessageID || got.Messages[0].Display == nil ||
		got.Messages[0].Display.Name != "测试师傅" || got.Messages[1].Text == nil {
		t.Fatalf("replacement did not receive feedback with approved worker identity plus auto reply: %+v", got.Messages)
	}
	inboxRaw, err := h.supportInbox(ctx, principal("10201"), "", "pending", 20)
	if err != nil {
		t.Fatal(err)
	}
	inbox := inboxRaw.(map[string]any)
	rows := inbox["items"].([]supportRow)
	if len(rows) != 1 || rows[0].OwnerID != "10202" || rows[0].OwnerRole != "worker" || rows[0].OwnerName == "" ||
		rows[0].LastUserMessage == nil || rows[0].LastUserMessage.ID != first.MessageID || rows[0].LastUserMessageAt == nil || rows[0].Unread == 0 {
		t.Fatalf("support inbox missing owner/feedback preview or bounded unread projection: %+v", rows)
	}
	second := send("10202", 191, first.MessageID)
	if got := page("10201"); len(got.Messages) != 3 || got.Messages[2].ID != second.MessageID || got.Messages[2].Reply == nil || got.Messages[2].Reply.Text == nil {
		t.Fatalf("replacement cannot read the quoted history: %+v", got.Messages)
	}
	if len(page("10202").Messages) != 3 {
		t.Fatal("owner history changed when assigning staff or auto reply repeated")
	}

	online := true
	capture := &reminderCapture{}
	reminders, _ := NewReminderService(s, capture)
	reminders = reminders.WithPresence(func(ports.ID) (bool, bool) { return true, online })
	if err = reminders.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err = reminders.Prepare(f.ctx); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_intents") != "0" {
		t.Fatal("online staff received offline intents")
	}
	online = false
	f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err = reminders.Prepare(f.ctx); err != nil {
		t.Fatal(err)
	}
	op := f.text("SELECT operation_id FROM chat_reminder_intents WHERE recipient_id=10201")
	if allowed, err := reminders.Eligible(f.ctx, op); err != nil || !allowed {
		t.Fatalf("offline staff ineligible: %t %v", allowed, err)
	}
	f.exec(`INSERT INTO chat_scene_preferences(conversation_id,actor_id,member_key_hash,muted,updated_at)
		SELECT conversation_id,actor_id,SHA2(member_key,256),1,UTC_TIMESTAMP(6) FROM chat_members
		WHERE conversation_id=? AND actor_id=10201`, string(cid))
	if allowed, err := reminders.Eligible(f.ctx, op); err != nil || allowed {
		t.Fatalf("muted staff eligible: %t %v", allowed, err)
	}

	f.php("support-handler", "401", "10203")
	if allowed, err := reminders.Eligible(f.ctx, op); err != nil || allowed {
		t.Fatalf("old queued reminder survived reassignment: %t %v", allowed, err)
	}
	poll()
	if len(page("10203").Messages) != 3 {
		t.Fatal("replacement did not receive existing feedback")
	}
	f.php("support-disable")
	if _, err = h.Read(ctx, principal("10203"), HistoryRequest{ConversationID: cid}, "bootstrap"); !errors.Is(err, policy.ErrDenied) {
		t.Fatalf("pause did not revoke staff access: %v", err)
	}
}

type reminderCapture struct {
	mu    sync.Mutex
	calls []ports.Reminder
	lose  bool
}

func (c *reminderCapture) Submit(_ context.Context, q ports.Reminder) (ports.Receipt, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.calls = append(c.calls, q)
	if c.lose {
		c.lose = false
		return ports.Receipt{}, ports.ErrUnavailable
	}
	return ports.Receipt{OperationID: q.OperationID, State: "accepted"}, nil
}
func (c *reminderCapture) Status(_ context.Context, op string) (ports.Receipt, error) {
	return ports.Receipt{OperationID: op, State: "sent", Diagnostic: "sent"}, nil
}
func TestMySQLS20DurableEventsFixedDueRetryAndReadSuppression(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	capture := &reminderCapture{lose: true}
	r, err := NewReminderService(s, capture)
	if err != nil {
		t.Fatal(err)
	}
	first := accepted(t, s, q)
	if f.text("SELECT COUNT(*) FROM chat_reminder_events WHERE processed_at IS NULL") != "1" {
		t.Fatal("publication lost durable event")
	}
	// A new instance represents process death after message commit.
	r, _ = NewReminderService(s, capture)
	if err = r.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	due := f.text("SELECT next_due FROM chat_reminder_clusters WHERE recipient_id=10203")
	q.ClientMessageID = client(42)
	accepted(t, s, q)
	if err = r.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT next_due FROM chat_reminder_clusters WHERE recipient_id=10203") != due {
		t.Fatal("new message postponed first reminder")
	}
	f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err = r.Prepare(f.ctx); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_intents") != "1" {
		t.Fatal("not one aggregate round")
	}
	if err = r.SubmitPending(f.ctx); err != nil {
		t.Fatal(err)
	}
	f.exec("UPDATE chat_reminder_intents SET next_attempt_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err = r.SubmitPending(f.ctx); err != nil {
		t.Fatal(err)
	}
	if len(capture.calls) != 2 || capture.calls[0].OperationID != capture.calls[1].OperationID {
		t.Fatal("ambiguous PHP result changed operation")
	}
	op := capture.calls[1].OperationID
	if allowed, err := r.Eligible(f.ctx, op); err != nil || !allowed {
		t.Fatal("online is not read", allowed, err)
	}
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte("12345678901234567890123456789012"), s.timeout)
	if err != nil {
		t.Fatal(err)
	}
	page, err := h.Read(f.ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = h.Acknowledge(f.ctx, principal("10203"), q.ConversationID, page.SyncCursor); err != nil {
		t.Fatal(err)
	}
	if allowed, err := r.Eligible(f.ctx, op); err != nil || allowed {
		t.Fatal("read did not suppress queued reminder", allowed, err)
	}
	if first.MessageID == "" || f.text("SELECT COUNT(*) FROM notifications") != "0" {
		t.Fatal("per-message business notices created")
	}
}
func TestMySQLS20RoundsBudgetRecallAndPermissionRevocation(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	capture := &reminderCapture{}
	r, _ := NewReminderService(s, capture)
	msg := accepted(t, s, q)
	if err := r.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 5; i++ {
		f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
		if err := r.Prepare(f.ctx); err != nil {
			t.Fatal(err)
		}
		if err := r.SubmitPending(f.ctx); err != nil {
			t.Fatal(err)
		}
		if err := r.Reconcile(f.ctx); err != nil {
			t.Fatal(err)
		}
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_intents") != "3" {
		t.Fatal("continued unread exceeded three rounds")
	}
	if f.text("SELECT hour_count FROM chat_reminder_budgets WHERE recipient_id=10203") != "3" {
		t.Fatal("chat-only budget mismatch")
	}
	if err := r.SubmitPending(f.ctx); err != nil {
		t.Fatal(err)
	}
	if len(capture.calls) != 3 {
		t.Fatal(capture.calls)
	}
	if _, err := s.Recall(f.ctx, principal("10202"), q.ConversationID, msg.MessageID); err != nil {
		t.Fatal(err)
	}
	if ok, err := r.Eligible(f.ctx, capture.calls[0].OperationID); err != nil || ok {
		t.Fatal("recall still reminded", ok, err)
	}
	q.ClientMessageID = client(49)
	accepted(t, s, q)
	if err := r.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	// The original member epoch is revoked in PHP before the worker projects it.
	f.exec("UPDATE system_settings SET setting_value='[]' WHERE setting_key='wechat.admin_openids'")
	if ok, err := r.Eligible(f.ctx, capture.calls[1].OperationID); err != nil || ok {
		t.Fatal("revoked recipient reminded", ok, err)
	}
}

func TestS20ReminderConfigBounds(t *testing.T) {
	c := DefaultReminderConfig()
	if c.FirstDelaySeconds != 10 || c.Validate() != nil {
		t.Fatal("default reminder policy")
	}
	c.FirstDelaySeconds = 31
	if c.Validate() == nil {
		t.Fatal("first due exceeded 30-second policy")
	}
	c = DefaultReminderConfig()
	c.PlatformHourly = 0
	if c.Validate() == nil {
		t.Fatal("unbounded platform budget")
	}
}
func TestMySQLS20ChannelLimitsReleaseReservationsWithoutCountingSuccess(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	capture := &reminderCapture{}
	r, _ := NewReminderService(s, capture)
	accepted(t, s, q)
	if err := r.DrainEvents(f.ctx); err != nil {
		t.Fatal(err)
	}
	f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
	if err := r.Prepare(f.ctx); err != nil {
		t.Fatal(err)
	}
	op := f.text("SELECT operation_id FROM chat_reminder_intents LIMIT 1")
	if err := r.reconcileOne(f.ctx, op, ports.Receipt{OperationID: op, State: "channel_limited", Diagnostic: "template_unavailable"}); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT rounds FROM chat_reminder_clusters") != "0" || f.text("SELECT hour_count FROM chat_reminder_budgets WHERE recipient_id=10203") != "0" || f.text("SELECT hour_count FROM chat_reminder_budgets WHERE recipient_id=0") != "0" {
		t.Fatal("channel failure counted as successful reminder")
	}
	if f.text("SELECT state FROM chat_reminder_clusters") != "channel_limited" {
		t.Fatal("channel diagnostic lost")
	}
	for i := 0; i < 3; i++ {
		if err := r.Prepare(f.ctx); err != nil {
			t.Fatal(err)
		}
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_intents") != "1" {
		t.Fatal("channel limit created endless new rounds")
	}
}
