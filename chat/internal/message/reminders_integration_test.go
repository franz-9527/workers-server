package message

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"sync"
	"testing"
)

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
