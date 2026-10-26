package message

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"sync"
	"testing"
	"time"
)

func governanceFixture(t *testing.T, f *fixtureDB, s *Service, m *MediaService) (*GovernanceService, ports.Principal) {
	t.Helper()
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("k", 32)), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	g, err := NewGovernanceService(s, h, m, "test")
	if err != nil {
		t.Fatal(err)
	}
	f.exec(`INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(91001,'governance-fixture','fixture','super_admin','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())`)
	f.exec(`INSERT INTO chat_admin_sessions(id,admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at) VALUES(91001,91001,REPEAT('d',64),REPEAT('e',64),REPEAT('f',64),'["chat:audit","chat:moderate","chat:images","chat:evidence"]',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())`)
	p := ports.Principal{ActorID: "91001", Kind: "admin", SourceID: "91001", SessionID: strings.Repeat("d", 64), SessionVersion: strings.Repeat("e", 64), Environment: "test", Scopes: []string{"chat:audit", "chat:moderate", "chat:images", "chat:evidence"}, ExpiresAt: time.Now().Add(time.Minute)}
	return g, p
}
func TestMySQLS18AuditSnapshotIsolationAndRevocation(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	g, admin := governanceFixture(t, f, s, nil)
	ctx := f.ctx
	first := accepted(t, s, q)
	for i := 0; i < 5; i++ {
		f.exec(`INSERT INTO chat_conversations(conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,created_at,updated_at) VALUES('order',CONCAT('fixture-audit:',?),?, ?,1,REPEAT('a',64),'fixture','readonly',UTC_TIMESTAMP(6)-INTERVAL 1 DAY,UTC_TIMESTAMP(6))`, i, 600+i, 600+i)
	}
	page, err := g.AuditList(ctx, admin, "", 2)
	if err != nil || len(page.Items) != 2 || !page.More {
		t.Fatal(page, err)
	}
	total := f.text("SELECT COUNT(*) FROM chat_conversations")
	seen := map[ports.ID]bool{}
	for _, item := range page.Items {
		seen[item.ID] = true
	}
	// Move an unseen row to the top after the snapshot was created.
	f.exec(`UPDATE chat_conversations SET last_message_at=UTC_TIMESTAMP(6)+INTERVAL 1 DAY WHERE scope_key='fixture-audit:4'`)
	for page.More {
		page, err = g.AuditList(ctx, admin, page.Cursor, 2)
		if err != nil {
			t.Fatal(err)
		}
		for _, item := range page.Items {
			if seen[item.ID] {
				t.Fatal("activity change duplicated snapshot row")
			}
			seen[item.ID] = true
		}
	}
	if decimal(uint64(len(seen))) != ports.ID(total) {
		t.Fatal("snapshot lost conversations", len(seen), total)
	}
	if _, err = g.AuditList(ctx, principal("10203"), "", 20); err == nil {
		t.Fatal("platform staff inherited audit")
	}
	mini := principal("10203")
	mini.Kind = "mini_audit"
	mini.Scopes = []string{"chat:audit"}
	if _, err = g.AuditList(ctx, mini, "", 20); err == nil {
		t.Fatal("ungranted mini audit")
	}
	f.exec(`INSERT INTO chat_mini_auditors(user_id,enabled,authority_version,granted_by,reason,updated_at) VALUES(10203,1,REPEAT('f',64),91001,'独立抽检测试',UTC_TIMESTAMP(6))`)
	mini.SessionVersion = digest("mini-audit-v1\n10203\n" + digest("login:10203") + "\n10203\n" + strings.Repeat("f", 64))
	if _, err = g.AuditList(ctx, mini, "", 20); err != nil {
		t.Fatal("independent grant", err)
	}
	if _, err = f.store.Authorize(ctx, ports.PolicyRequest{Actor: mini, ConversationID: q.ConversationID, Action: "send"}); err == nil {
		t.Fatal("audit identity used ordinary member path")
	}
	before := f.text("SELECT COUNT(*) FROM chat_member_reads")
	detail, err := g.AuditHistory(ctx, admin, HistoryRequest{ConversationID: q.ConversationID})
	if err != nil || len(detail.Messages) != 1 || detail.Messages[0].Text == nil {
		t.Fatal(detail, err)
	}
	if _, err = s.Recall(ctx, principal("10202"), q.ConversationID, first.MessageID); err != nil {
		t.Fatal(err)
	}
	detail, err = g.AuditHistory(ctx, admin, HistoryRequest{ConversationID: q.ConversationID, TargetID: first.MessageID})
	if err != nil || detail.Messages[0].Text != nil || detail.Messages[0].State != "recalled" {
		t.Fatal("audit leaked recall", detail, err)
	}
	if f.text("SELECT COUNT(*) FROM chat_member_reads") != before {
		t.Fatal("audit marked read")
	}
	f.exec("UPDATE chat_mini_auditors SET enabled=0 WHERE user_id=10203")
	if _, err = g.AuditHistory(ctx, mini, HistoryRequest{ConversationID: q.ConversationID}); err == nil {
		t.Fatal("revoked mini audit")
	}
	f.exec("UPDATE chat_mini_auditors SET enabled=1,authority_version=REPEAT('a',64) WHERE user_id=10203")
	if _, err = g.AuditHistory(ctx, mini, HistoryRequest{ConversationID: q.ConversationID}); err == nil {
		t.Fatal("old revoked grant revived after regrant")
	}
	f.exec("UPDATE admin_users SET role='viewer' WHERE id=91001")
	if _, err = g.AuditList(ctx, admin, "", 20); err == nil {
		t.Fatal("revoked admin")
	}
	if _, err = f.goDB.ExecContext(ctx, "UPDATE chat_governance_audit SET reason='tamper'"); err == nil {
		t.Fatal("audit table mutable by Go")
	}
}
func TestMySQLS19BanSerializesPendingSendAndKeepsReadRecallAppeal(t *testing.T) {
	started, resume := make(chan struct{}), make(chan struct{})
	var once sync.Once
	f, s, q := setupMessage(t, safetyFunc(pass))
	g, admin := governanceFixture(t, f, s, nil)
	ctx := f.ctx
	first := accepted(t, s, q)
	s.safety = safetyFunc(func(context.Context, ports.TextRequest) (ports.SafetyResult, error) {
		once.Do(func() { close(started) })
		<-resume
		return ports.SafetyResult{Decision: "passed", RuleVersion: "s19-fixture"}, nil
	})
	q.ClientMessageID = client(22)
	done := make(chan error, 1)
	go func() { _, err := s.Send(ctx, principal("10202"), q); done <- err }()
	<-started
	blocked, err := g.Restrict(ctx, admin, RestrictionRequest{Actor: "10202", Expected: "0", Action: "permanent", Reason: "并发禁聊测试"})
	if err != nil || !blocked.Blocked {
		t.Fatal(blocked, err)
	}
	close(resume)
	if err = <-done; err != ErrRestricted {
		t.Fatal("in-flight send bypassed restriction", err)
	}
	if f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("late publish")
	}
	if _, err = g.history.Read(ctx, principal("10202"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap"); err != nil {
		t.Fatal("ban blocked legal read", err)
	}
	if _, err = s.Recall(ctx, principal("10202"), q.ConversationID, first.MessageID); err != nil {
		t.Fatal("ban blocked recall", err)
	}
	id, err := g.Appeal(ctx, principal("10202"), "restriction", "10202", blocked.Version, "对此次禁聊提出申诉")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = g.Evidence(ctx, admin, id, "1", "", "核对禁聊依据"); err != nil {
		t.Fatal(err)
	}
	if _, err = g.Decide(ctx, admin, CaseDecision{ID: id, Expected: "1", Action: "correct", Reason: "核查后解除限制"}); err != nil {
		t.Fatal(err)
	}
	status, err := g.Restriction(ctx, principal("10202"), "10202")
	if err != nil || status.Blocked || status.Version != "2" {
		t.Fatal(status, err)
	}
	if f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("appeal resent old action")
	}
	timed, err := g.Restrict(ctx, admin, RestrictionRequest{Actor: "10202", Expected: "2", Action: "timed", Seconds: 60, Reason: "限时到期测试"})
	if err != nil || !timed.Blocked {
		t.Fatal(timed, err)
	}
	f.exec("UPDATE chat_restrictions SET blocked_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE actor_id=10202")
	if err = g.ExpireRestrictions(ctx); err != nil {
		t.Fatal(err)
	}
	status, err = g.Restriction(ctx, principal("10202"), "10202")
	if err != nil || status.Blocked || status.Version != "4" {
		t.Fatal(status, err)
	}
	bannedAgain, err := g.Restrict(ctx, admin, RestrictionRequest{Actor: "10202", Expected: "4", Action: "permanent", Reason: "独立第二次禁聊"})
	if err != nil {
		t.Fatal(err)
	}
	second, err := g.Appeal(ctx, principal("10202"), "restriction", "10202", bannedAgain.Version, "第二次禁聊独立申诉")
	if err != nil || second == id {
		t.Fatal("new restriction generation reused old case", second, err)
	}
	again, err := g.Appeal(ctx, principal("10202"), "restriction", "10202", bannedAgain.Version, "重复提交同一申诉")
	if err != nil || again != second {
		t.Fatal("same generation duplicated appeal", again, err)
	}
}
func TestMySQLS19ReportsScopeEvidenceAndFalsePositiveCorrection(t *testing.T) {
	safety := safetyFunc(func(context.Context, ports.TextRequest) (ports.SafetyResult, error) {
		return ports.SafetyResult{Decision: "blocked", RuleVersion: "s19-fixture", Finding: &ports.SafetyFinding{Provider: "local.anti_diversion", Code: "fixture_risk", Level: 2, Confirmed: true}}, nil
	})
	f, s, q := setupMessage(t, safety)
	g, admin := governanceFixture(t, f, s, nil)
	ctx := f.ctx
	result, err := s.Send(ctx, principal("10202"), q)
	if err != nil || result.RiskEventID == "" {
		t.Fatal(result, err)
	}
	id, err := g.Appeal(ctx, principal("10202"), "risk", result.RiskEventID, "1", "认为此内容存在误判")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = g.Appeal(ctx, principal("10203"), "risk", result.RiskEventID, "1", "不能申诉他人内容"); err == nil {
		t.Fatal("foreign evidence appeal")
	}
	weak := admin
	weak.Scopes = []string{"chat:audit", "chat:moderate"}
	if _, err = g.Evidence(ctx, weak, id, "1", "", "普通权限不能读原文"); err == nil {
		t.Fatal("missing distinct evidence scope")
	}
	evidence, err := g.Evidence(ctx, admin, id, "1", "", "核对原文及上下文")
	if err != nil || evidence.Text == nil || *evidence.Text != q.Text {
		t.Fatal(evidence, err)
	}
	if _, err = g.Decide(ctx, admin, CaseDecision{ID: id, Expected: "1", Action: "correct", Reason: "原内容确认为误判"}); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT COUNT(*) FROM chat_risk_events WHERE confirmed=1") != "0" {
		t.Fatal("false positive still counted")
	}
	again, err := s.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if err != nil || again.State != "rejected" {
		t.Fatal("corrected old intent resurrected", again, err)
	}
	noMessages(t, f)
	s.safety = safetyFunc(pass)
	q.ClientMessageID = client(29)
	message := accepted(t, s, q)
	report, err := g.Report(ctx, principal("10203"), q.ConversationID, message.MessageID, "", "此消息需要平台核查")
	if err != nil {
		t.Fatal(err)
	}
	duplicate, err := g.Report(ctx, principal("10203"), q.ConversationID, message.MessageID, "", "重复举报合并")
	if err != nil || duplicate != report {
		t.Fatal("duplicate case", duplicate, err)
	}
	if _, err = g.Decide(ctx, admin, CaseDecision{ID: report, Expected: "1", Action: "block", Reason: "尚未查看证据"}); err == nil {
		t.Fatal("decision without evidence")
	}
	if _, err = g.Evidence(ctx, admin, report, "1", "", "查看被举报原消息"); err != nil {
		t.Fatal(err)
	}
	if _, err = g.Decide(ctx, admin, CaseDecision{ID: report, Expected: "1", Action: "block", Reason: "确认需要屏蔽"}); err != nil {
		t.Fatal(err)
	}
	page, err := g.history.Read(ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if err != nil || len(page.Messages) != 1 || page.Messages[0].Text != nil || page.Messages[0].State != "blocked" {
		t.Fatal("block projection", page, err)
	}
	if _, err = g.Decide(ctx, admin, CaseDecision{ID: report, Expected: "1", Action: "dismiss", Reason: "旧版本不能覆盖"}); err == nil {
		t.Fatal("stale decision")
	}
}
