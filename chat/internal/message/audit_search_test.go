package message

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"testing"
)

func TestAuditSearchFilterValidation(t *testing.T) {
	for _, f := range []AuditFilter{{Conversation: "0"}, {Actor: "-1"}, {Order: "18446744073709551616"}, {Query: strings.Repeat("字", 81)}, {Query: "a\x00b"}, {Kind: "unsafe"}, {From: "yesterday"}, {From: "2026-01-01T00:00:00Z", To: "2026-12-01T00:00:00Z"}} {
		if _, err := f.validated(); err == nil {
			t.Fatal("invalid audit filter accepted", f)
		}
	}
	f, err := (AuditFilter{Query: "  施工%_  ", Kind: "text"}).validated()
	if err != nil || f.Query != "施工%_" {
		t.Fatal("literal query trim", f, err)
	}
	sql, args := f.messageSQL("c")
	if strings.Contains(sql, f.Query) || !strings.Contains(sql, "LOCATE(?,m.text_body)") || !strings.Contains(sql, "m.state='published'") || len(args) != 4 {
		t.Fatal("unsafe or unbounded content search", sql, args)
	}
	if f.fingerprint() == (AuditFilter{Query: "另一条件"}).fingerprint() {
		t.Fatal("filters not bound")
	}
}
func TestMySQLUnifiedAuditSearchAndSnapshotReplacement(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	g, admin := governanceFixture(t, f, s, nil)
	q.Text = "统一审核施工100%_"
	message := accepted(t, s, q)
	// Populate two extra conversations/messages using the real schema, not a copied fake schema.
	for i := 0; i < 2; i++ {
		result, err := f.root.ExecContext(f.ctx, "INSERT INTO chat_conversations(conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,last_seq,last_message_at,created_at,updated_at) VALUES('order',CONCAT('unified-audit:',?),?,?,1,REPEAT('a',64),'fixture','readonly',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", i, 800+i, 800+i)
		if err != nil {
			t.Fatal(err)
		}
		cid, err := result.LastInsertId()
		if err != nil {
			t.Fatal(err)
		}
		f.exec("INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,text_body,state,state_version,safety_rule_version,created_at) SELECT ?,1,sender_id,client_message_id,policy_version,message_type,text_body,'published',1,safety_rule_version,created_at FROM chat_messages WHERE id=?", cid, string(message.MessageID))
	}
	before := f.text("SELECT COUNT(*) FROM chat_member_reads")
	query := AuditFilter{Query: "100%_"}
	result, err := g.AuditSearch(f.ctx, admin, query, "", "", 1)
	if err != nil || len(result.Items) != 1 || !result.More || result.Snapshot == "" || result.Items[0].MatchMessage == "" {
		t.Fatal("search", result, err)
	}
	seen := map[ports.ID]bool{result.Items[0].ID: true}
	snapshot := result.Snapshot
	if _, err = g.AuditSearch(f.ctx, admin, AuditFilter{Query: "changed"}, result.Cursor, "", 1); err == nil {
		t.Fatal("cursor crossed query scope")
	}
	for result.More {
		result, err = g.AuditSearch(f.ctx, admin, query, result.Cursor, "", 1)
		if err != nil {
			t.Fatal(err)
		}
		for _, item := range result.Items {
			if seen[item.ID] {
				t.Fatal("duplicate search row")
			}
			seen[item.ID] = true
		}
	}
	if len(seen) != 3 {
		t.Fatal("missing matched conversation", seen)
	}
	for i := 0; i < 6; i++ {
		result, err = g.AuditSearch(f.ctx, admin, AuditFilter{Conversation: q.ConversationID}, "", snapshot, 20)
		if err != nil || len(result.Items) != 1 {
			t.Fatal("repeated search exhausted snapshot quota", i, result, err)
		}
		snapshot = result.Snapshot
	}
	result, err = g.AuditSearch(f.ctx, admin, AuditFilter{Order: "401", Actor: "10202", Query: "统一审核"}, "", snapshot, 20)
	if err != nil || len(result.Items) != 1 || result.Items[0].MatchMessage != message.MessageID {
		t.Fatal("combined filters / anchor", result, err)
	}
	snapshot = result.Snapshot
	result, err = g.AuditSearch(f.ctx, admin, AuditFilter{OrderNo: "CHAT-PLATFORM-401"}, "", snapshot, 20)
	if err != nil || len(result.Items) != 1 || result.Items[0].OrderNo != "CHAT-PLATFORM-401" {
		t.Fatal("human readable order number search", result, err)
	}
	snapshot = result.Snapshot
	if _, err = g.AuditSearch(context.Background(), principal("10203"), AuditFilter{}, "", "", 20); err == nil {
		t.Fatal("ordinary user received administrative search")
	}
	if _, err = s.Recall(f.ctx, principal("10202"), q.ConversationID, message.MessageID); err != nil {
		t.Fatal(err)
	}
	result, err = g.AuditSearch(f.ctx, admin, AuditFilter{Conversation: q.ConversationID, Query: "统一审核"}, "", snapshot, 20)
	if err != nil || len(result.Items) != 0 {
		t.Fatal("recalled content searchable", result, err)
	}
	detail, err := g.AuditHistory(f.ctx, admin, HistoryRequest{ConversationID: q.ConversationID})
	if err != nil || len(detail.Messages) != 1 || detail.Messages[0].Text != nil {
		t.Fatal("recalled text leaked", detail, err)
	}
	if f.text("SELECT COUNT(*) FROM chat_member_reads") != before {
		t.Fatal("audit changed user read state")
	}
	f.exec("UPDATE admin_users SET role='viewer' WHERE id=91001")
	if _, err = g.AuditSearch(f.ctx, admin, AuditFilter{}, "", "", 20); err == nil {
		t.Fatal("revoked administrator retained search")
	}
}
