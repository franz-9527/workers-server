package message

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"testing"
)

func TestS22RetentionBounds(t *testing.T) {
	c := DefaultRetentionConfig()
	c.Enabled = true
	if c.Validate() != nil {
		t.Fatal("default retention")
	}
	for _, bad := range []RetentionConfig{{true, 89, 30, 90, 20}, {true, 365, 29, 90, 20}, {true, 365, 30, 0, 20}, {true, 365, 30, 90, 51}} {
		if bad.Validate() == nil {
			t.Fatal("unsafe retention", bad)
		}
	}
}
func TestMySQLS22RetentionFloorsEvidenceAndMinimalFacts(t *testing.T) {
	f, s, h, q := historyFixture(t, 4)
	f.exec(`UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 400 DAY,retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE conversation_id=? AND seq<=2`, string(q.ConversationID))
	f.exec(`UPDATE chat_message_changes SET created_at=UTC_TIMESTAMP(6)-INTERVAL 100 DAY WHERE conversation_id=? AND change_seq<=2`, string(q.ConversationID))
	before := readPage(t, h, HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	c := DefaultRetentionConfig()
	c.Enabled = true
	r, err := NewRetention(f.goDB, "test", c)
	if err != nil {
		t.Fatal(err)
	}
	result, err := r.Pass(f.ctx, "0")
	if err != nil || result.Messages != 2 || result.Changes != 2 {
		t.Fatal(result, err)
	}
	if f.text("SELECT sync_floor_seq FROM chat_conversations WHERE id=?", string(q.ConversationID)) != "0" || f.text("SELECT change_floor_seq FROM chat_conversations WHERE id=?", string(q.ConversationID)) != "2" {
		t.Fatal("noncontiguous floors")
	}
	if f.text("SELECT COUNT(*) FROM chat_messages WHERE seq<=2 AND text_body IS NOT NULL") != "0" || f.text("SELECT COUNT(*) FROM chat_messages") != "4" {
		t.Fatal("body or minimal fact loss")
	}
	after := readPage(t, h, HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if len(after.Messages) != 4 || after.Messages[0].Text != nil || after.Messages[0].State != "published" {
		t.Fatal("expired body visible", after)
	}
	changed, err := h.Read(f.ctx, principal("10202"), HistoryRequest{ConversationID: q.ConversationID, Cursor: before.ChangeCursor}, "changes")
	if err != nil || len(changed.Changes) != 0 {
		t.Fatal("cache invalidation", changed, err)
	}

	// Current signed sync snapshot still resumes, but a signed old floor must resync.
	old, err := h.parse(before.SyncCursor, "sync")
	if err != nil {
		t.Fatal(err)
	}
	old.Position = "0"
	if _, err = h.Read(f.ctx, principal("10202"), HistoryRequest{ConversationID: q.ConversationID, Cursor: h.sign(old)}, "sync"); err != nil {
		t.Fatal("old cursor did not resync", err)
	}
	_ = s
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT last_seq FROM chat_conversations WHERE id=?", string(q.ConversationID)) != "4" {
		t.Fatal("cleanup reset seq")
	}
}
func TestMySQLS22LegalCaseProtectsBodyAndReleasedEvidenceExpires(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	out := accepted(t, s, q)
	g, _ := governanceFixture(t, f, s, nil)
	_, err := g.Report(f.ctx, principal("10203"), q.ConversationID, out.MessageID, "", "证据保留验证")
	if err != nil {
		t.Fatal(err)
	}
	f.exec(`UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 400 DAY,retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE id=?`, string(out.MessageID))
	c := DefaultRetentionConfig()
	c.Enabled = true
	r, _ := NewRetention(f.goDB, "test", c)
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT sync_floor_seq FROM chat_conversations WHERE id=?", string(q.ConversationID)) != "0" || f.text("SELECT text_body IS NOT NULL FROM chat_messages WHERE id=?", string(out.MessageID)) != "1" {
		t.Fatal("case must preserve cloud evidence and stable history")
	}
	f.exec(`UPDATE chat_cases SET retain_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND`)
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT state FROM chat_messages WHERE id=?", string(out.MessageID)) != "published" || f.text("SELECT text_body IS NULL FROM chat_messages WHERE id=?", string(out.MessageID)) != "1" {
		t.Fatal("expired hold retained body")
	}
	if f.text("SELECT COUNT(*) FROM chat_send_intents WHERE message_id=?", string(out.MessageID)) != "1" {
		t.Fatal("minimum idempotency fact deleted")
	}
}
func TestMySQLS22RecalledEvidenceExpiresWithoutRevivingOrSending(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	out := accepted(t, s, q)
	if _, err := s.Recall(f.ctx, principal("10202"), q.ConversationID, out.MessageID); err != nil {
		t.Fatal(err)
	}
	f.exec(`UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 40 DAY,recalled_at=UTC_TIMESTAMP(6)-INTERVAL 35 DAY WHERE id=?`, string(out.MessageID))
	f.exec(`UPDATE chat_message_changes SET created_at=UTC_TIMESTAMP(6)-INTERVAL 35 DAY WHERE message_id=?`, string(out.MessageID))
	c := DefaultRetentionConfig()
	c.Enabled = true
	r, _ := NewRetention(f.goDB, "test", c)
	if _, err := r.Pass(context.Background(), "0"); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT state FROM chat_messages WHERE id=?", string(out.MessageID)) != "recalled" || f.text("SELECT COUNT(*) FROM chat_messages WHERE text_body IS NOT NULL") != "0" {
		t.Fatal("evidence body retained")
	}
	if f.text("SELECT COUNT(*) FROM chat_reminder_events") != "1" {
		t.Fatal("cleanup re-enqueued reminder")
	}
}

type retryDeleteStorage struct {
	*imageMemory
	fail bool
}

func (s *retryDeleteStorage) Delete(ctx context.Context, key string) error {
	if s.fail {
		return ErrMedia
	}
	return s.imageMemory.Delete(ctx, key)
}
func TestMySQLS22SharedMediaSurvivesFirstExpiryAndDeletionRetries(t *testing.T) {
	f, s, m, storage, q := mediaFixture(t)
	q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 301, "shared-construction")}
	mediaProcess(t, m, 1)
	if _, err := s.Send(f.ctx, principal("10202"), q); err != nil {
		t.Fatal(err)
	}
	m.Advance(f.ctx)
	mediaApprove(t, m)
	first, err := s.Query(f.ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if err != nil {
		t.Fatal(err)
	}
	q.ClientMessageID = client(223)
	if _, err = s.Send(f.ctx, principal("10202"), q); err != nil {
		t.Fatal(err)
	}
	m.Advance(f.ctx)
	mediaApprove(t, m)
	second, err := s.Query(f.ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if err != nil {
		t.Fatal(err)
	}
	main := f.text("SELECT main_key FROM chat_image_uploads WHERE id=?", string(q.Items[0].AttachmentID))
	f.exec(`UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 400 DAY,retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE id=?`, string(first.MessageID))
	f.exec(`UPDATE chat_image_uploads SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY`)
	f.exec(`UPDATE chat_image_objects SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY`)
	c := DefaultRetentionConfig()
	c.Enabled = true
	r, _ := NewRetention(f.goDB, "test", c)
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}
	if err = m.CleanOne(f.ctx); err != nil {
		t.Fatal(err)
	}
	storage.mu.Lock()
	_, kept := storage.objects[main]
	storage.mu.Unlock()
	if !kept || f.text("SELECT COUNT(*) FROM chat_image_items") != "2" {
		t.Fatal("shared delivery object deleted")
	}
	if _, err = m.URL(f.ctx, principal("10202"), q.ConversationID, second.MessageID, q.Items[0].AttachmentID, false); err != nil {
		t.Fatal("remaining reference denied", err)
	}
	f.exec(`UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 400 DAY,retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE id=?`, string(second.MessageID))
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}

	access, err := m.URLInfo(f.ctx, principal("10202"), q.ConversationID, second.MessageID, q.Items[0].AttachmentID, false)
	if err != nil || access.CloudAvailable || access.URL != "" {
		t.Fatal("cloud expiry should preserve cache authorization", access, err)
	}
	failed := &retryDeleteStorage{storage, true}
	m.storage = failed
	if err = s.CleanCloudOne(f.ctx); err != ErrMedia || f.text("SELECT COUNT(*) FROM chat_image_objects WHERE deleted_at IS NULL") != "2" {
		t.Fatal("failed delete marked object gone", err)
	}
	failed.fail = false
	for i := 0; i < 3; i++ {
		if err = s.CleanCloudOne(f.ctx); err != nil {
			t.Fatal(err)
		}
	}
	if f.text("SELECT COUNT(*) FROM chat_image_objects WHERE deleted_at IS NULL") != "0" || f.text("SELECT COUNT(*) FROM chat_image_items") != "2" {
		t.Fatal("cloud cleanup lost stable references")
	}

}
func TestMySQLS22DueRiskRevisitedAfterReviewAlreadyExpired(t *testing.T) {
	safety := safetyFunc(func(context.Context, ports.TextRequest) (ports.SafetyResult, error) {
		return ports.SafetyResult{Decision: "blocked", RuleVersion: "s22-fixture", Finding: &ports.SafetyFinding{Provider: "local.anti_diversion", Code: "fixture_risk", Level: 2, Confirmed: true}}, nil
	})
	f, s, q := setupMessage(t, safety)
	out, err := s.Send(f.ctx, principal("10202"), q)
	if err != nil || out.RiskEventID == "" {
		t.Fatal(out, err)
	}
	f.exec(`UPDATE chat_cases SET retain_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND`)
	c := DefaultRetentionConfig()
	c.Enabled = true
	r, _ := NewRetention(f.goDB, "test", c)
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_cases WHERE state='expired' AND reason=''") != "1" || f.text("SELECT COUNT(*) FROM chat_risk_events WHERE text_evidence<>''") != "1" {
		t.Fatal("separate case/risk TTL")
	}
	f.exec(`UPDATE chat_risk_events SET retain_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND`)
	if _, err = r.Pass(f.ctx, "0"); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_risk_events WHERE text_evidence<>'' OR context_evidence<>''") != "0" || f.text("SELECT COUNT(*) FROM chat_risk_events WHERE confirmed=1") != "1" {
		t.Fatal("expired review stranded evidence or lost minimal count")
	}
}

func TestMySQLStoragePermanentMessagesDoNotExpireWithLaterFiniteMessages(t *testing.T) {
	f, _, h, q := historyFixture(t, 4)
	f.exec(`UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 800 DAY WHERE conversation_id=?`, string(q.ConversationID))
	f.exec(`UPDATE chat_messages SET retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE conversation_id=? AND seq=2`, string(q.ConversationID))
	before := readPage(t, h, HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if len(before.Messages) != 4 || before.Messages[1].State != "published" || before.Messages[1].Text == nil || before.Messages[0].Text == nil {
		t.Fatal("expiry projection before cleanup", before)
	}
	c := DefaultRetentionConfig()
	c.Enabled = true
	r, e := NewRetention(f.goDB, "test", c)
	if e != nil {
		t.Fatal(e)
	}
	result, e := r.Pass(f.ctx, "0")
	if e != nil || result.Messages != 1 {
		t.Fatal(result, e)
	}
	if f.text("SELECT sync_floor_seq FROM chat_conversations WHERE id=?", string(q.ConversationID)) != "0" {
		t.Fatal("floor crossed permanent message")
	}
	if f.text("SELECT COUNT(*) FROM chat_messages WHERE retention_mode='permanent' AND text_body IS NOT NULL") != "3" {
		t.Fatal("permanent body deleted")
	}
	after := readPage(t, h, HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if len(after.Messages) != 4 || after.Messages[0].RetentionMode != "permanent" || after.Messages[0].ExpiresAt != nil {
		t.Fatal(after)
	}
}
