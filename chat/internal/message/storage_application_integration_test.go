package message

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
	"testing"
)

func TestMySQLStorageApplicationDoesNotReplayActivityOrReviveExpiry(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	r := accepted(t, s, q)
	f.exec("UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE id=?", string(r.MessageID))
	policy := storagepolicy.Defaults()
	policy.Retention.Text = storagepolicy.Rule{Mode: "days", Days: 1}
	raw, _ := json.Marshal(policy)
	f.exec("INSERT INTO chat_storage_policy_audit(revision,admin_id,policy_json,apply_through_id,reason,created_at) VALUES(2,1,?,?,'缩短保存',UTC_TIMESTAMP(6))", string(raw), string(r.MessageID))
	if e := s.ApplyStorageOne(context.Background()); e != nil {
		t.Fatal(e)
	}
	if f.text("SELECT retention_mode FROM chat_messages") != "days" || f.text("SELECT CAST(state_version AS CHAR) FROM chat_messages") != "1" {
		t.Fatal("policy not applied")
	}
	if f.text("SELECT CAST(last_seq AS CHAR) FROM chat_conversations WHERE id=?", string(q.ConversationID)) != "1" {
		t.Fatal("application republished activity")
	}
	if e := s.ApplyStorageOne(context.Background()); e != nil {
		t.Fatal(e)
	}
	if f.text("SELECT state FROM chat_storage_applications WHERE revision=2") != "completed" {
		t.Fatal("application did not complete")
	}
	f.exec("UPDATE chat_messages SET text_body=NULL WHERE id=?", string(r.MessageID))
	permanent := storagepolicy.Defaults()
	raw, _ = json.Marshal(permanent)
	f.exec("INSERT INTO chat_storage_policy_audit(revision,admin_id,policy_json,apply_through_id,reason,created_at) VALUES(3,1,?,?,'恢复默认',UTC_TIMESTAMP(6))", string(raw), string(r.MessageID))
	if e := s.ApplyStorageOne(context.Background()); e != nil {
		t.Fatal(e)
	}
	if f.text("SELECT retention_mode FROM chat_messages") != "days" || f.text("SELECT code FROM chat_storage_application_errors WHERE revision=3") != ErrObjectMissing.Error() {
		t.Fatal("expired media was revived")
	}
	if f.text("SELECT COUNT(*) FROM chat_send_intents WHERE state='accepted'") != "1" {
		t.Fatal("new send intent created")
	}
}
