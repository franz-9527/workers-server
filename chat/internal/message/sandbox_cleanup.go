package message

import (
 "context"
 "database/sql"
 "github.com/franz-9527/workers/chat/internal/ports"
)

// Called only by the testing runtime. An orphaned PHP test scope can never
// match an ordinary conversation. Remote-object ledgers survive failed deletes.
// Provider call accounting/stop proofs remain available for reconciliation.
func (s *Service) CleanSandboxOne(ctx context.Context) error {
 var cid ports.ID
 err:=s.db.QueryRowContext(ctx,"SELECT CAST(c.id AS CHAR) FROM chat_conversations c WHERE c.scope_key REGEXP '^sandbox:[1-9][0-9]*$' AND c.state<>'sandbox_cleared' AND NOT EXISTS(SELECT 1 FROM chat_sandbox_rooms r WHERE CONCAT('sandbox:',r.id)=c.scope_key) ORDER BY c.id LIMIT 1").Scan(&cid)
 if err==sql.ErrNoRows{return nil};if err!=nil{return ports.ErrUnavailable}
 tx,err:=s.begin(ctx);if err!=nil{return err};defer tx.Rollback()
 var scope string
 if err=tx.QueryRowContext(ctx,"SELECT scope_key FROM chat_conversations WHERE id=? FOR UPDATE",string(cid)).Scan(&scope);err!=nil{return ports.ErrUnavailable}
 var exists,active,held int
 if err=tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_sandbox_rooms WHERE CONCAT('sandbox:',id)=?",scope).Scan(&exists);err!=nil||exists!=0{return err}
 if err=tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_call_sessions WHERE conversation_id=? AND state NOT IN ('ended','failed','cancelled','rejected','missed')",string(cid)).Scan(&active);err!=nil{return ports.ErrUnavailable}
 if active>0{return nil}
 if err=tx.QueryRowContext(ctx,"SELECT (SELECT COUNT(*) FROM chat_image_uploads WHERE conversation_id=? AND legal_hold=1)+(SELECT COUNT(*) FROM chat_av_uploads WHERE conversation_id=? AND legal_hold=1)",string(cid),string(cid)).Scan(&held);err!=nil{return ports.ErrUnavailable}
 if held>0{return nil}
 // Do not race a worker already writing cloud bytes. Its failed authority check
 // will release the bounded lease; the next maintenance pass retries cleanup.
 var leased int
 if err=tx.QueryRowContext(ctx,"SELECT (SELECT COUNT(*) FROM chat_image_uploads WHERE conversation_id=? AND lease_until>UTC_TIMESTAMP(6))+(SELECT COUNT(*) FROM chat_av_jobs j JOIN chat_av_uploads u ON u.id=j.upload_id WHERE u.conversation_id=? AND j.lease_until>UTC_TIMESTAMP(6))",string(cid),string(cid)).Scan(&leased);err!=nil{return ports.ErrUnavailable}
 if leased>0{return nil}
 statements:=[]string{
  "UPDATE chat_members SET can_read=0,can_send=0 WHERE conversation_id=?",
  "UPDATE chat_send_intents SET state='rejected',error_code='CHAT_ACTION_EXPIRED',lease_owner=NULL,lease_until=NULL WHERE conversation_id=? AND state IN ('checking','review_pending','retryable')",
  "DELETE i FROM chat_image_items i JOIN chat_messages m ON m.id=i.message_id WHERE m.conversation_id=?",
  "DELETE i FROM chat_av_items i JOIN chat_messages m ON m.id=i.message_id WHERE m.conversation_id=?",
  "DELETE FROM chat_text_checks WHERE conversation_id=?",
  "UPDATE chat_av_asr_requests a JOIN chat_send_intents i ON i.id=a.intent_id SET a.text_result=NULL,a.metadata_json=NULL WHERE i.conversation_id=?",
  "UPDATE chat_messages SET state='expired',text_body=NULL WHERE conversation_id=?",
  "UPDATE chat_image_bundles b JOIN chat_send_intents i ON i.id=b.intent_id SET b.principal_json='{}',b.review_state='rejected',b.expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE i.conversation_id=?",
  "UPDATE chat_image_uploads SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND,state='blocked',lease_owner=NULL,lease_until=NULL WHERE conversation_id=? AND state<>'cleaned'",
  "UPDATE chat_av_uploads SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND,state='canceled' WHERE conversation_id=? AND state<>'cleaned'",
  "UPDATE chat_image_objects o JOIN chat_image_uploads u ON u.id=o.upload_id SET o.created_at=LEAST(o.created_at,UTC_TIMESTAMP(6)-INTERVAL 2 DAY) WHERE u.conversation_id=?",
  "UPDATE chat_av_objects o JOIN chat_av_uploads u ON u.id=o.upload_id SET o.created_at=LEAST(o.created_at,UTC_TIMESTAMP(6)-INTERVAL 2 DAY) WHERE u.conversation_id=?",
  "UPDATE chat_av_jobs j JOIN chat_av_uploads u ON u.id=j.upload_id SET j.phase='failed',j.expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND,j.transcript=NULL,j.principal_json='{}',j.segments_json=NULL WHERE u.conversation_id=?",
 }
 for _,q:=range statements{if _,err=tx.ExecContext(ctx,q,string(cid));err!=nil{return ports.ErrUnavailable}}
 var remaining int
 err=tx.QueryRowContext(ctx,"SELECT (SELECT COUNT(*) FROM chat_image_objects o JOIN chat_image_uploads u ON u.id=o.upload_id WHERE u.conversation_id=?)+(SELECT COUNT(*) FROM chat_image_uploads WHERE conversation_id=? AND source_cleaned=0)+(SELECT COUNT(*) FROM chat_av_objects o JOIN chat_av_uploads u ON u.id=o.upload_id WHERE u.conversation_id=?)+(SELECT COUNT(*) FROM chat_av_parts p JOIN chat_av_uploads u ON u.id=p.upload_id WHERE u.conversation_id=? AND p.state<>'cleaned')",string(cid),string(cid),string(cid),string(cid)).Scan(&remaining)
 if err!=nil{return ports.ErrUnavailable}
 if remaining==0 {
  // Preserve the tiny conversation binding (and financial call evidence), but
  // remove payloads, receipts, cursors and local test message history.
  for _,q:=range []string{
   "DELETE FROM chat_message_changes WHERE conversation_id=?",
   "DELETE l FROM chat_av_listens l JOIN chat_messages m ON m.id=l.message_id WHERE m.conversation_id=?",
   "DELETE r FROM chat_image_reports r JOIN chat_messages m ON m.id=r.message_id WHERE m.conversation_id=?",
   "DELETE FROM chat_messages WHERE conversation_id=?",
   "UPDATE chat_send_intents SET message_id=NULL WHERE conversation_id=?",
   "DELETE FROM chat_user_preferences WHERE conversation_id=?",
   "DELETE FROM chat_member_reads WHERE conversation_id=?",
  }{if _,err=tx.ExecContext(ctx,q,string(cid));err!=nil{return ports.ErrUnavailable}}
  if _,err=tx.ExecContext(ctx,"UPDATE chat_conversations SET state='sandbox_cleared',last_message_id=NULL,last_message_at=NULL WHERE id=?",string(cid));err!=nil{return ports.ErrUnavailable}
 }
 return tx.Commit()
}
