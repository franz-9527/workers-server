package message
import(
 "testing"
 "context"
 "sync"
 "time"
 "strings"
 "net/http/httptest"
 "encoding/json"
 "github.com/franz-9527/workers/chat/internal/auth"
 "github.com/franz-9527/workers/chat/internal/ports"
)
func TestMySQLPaidSMSWaitReadRecallAndAuthority(t *testing.T){
 f,s,q:=setupMessage(t,safetyFunc(pass));msg:=accepted(t,s,q)
 check:=func(want int)[]SMSCandidate{t.Helper();v,e:=s.PaidSMSCandidates(f.ctx,"10202",q.ConversationID,"10203","");if e!=nil||len(v)!=want{t.Fatalf("eligible %v %v want %d",v,e,want)};return v}
 check(0)
 f.exec("UPDATE chat_messages SET created_at=UTC_TIMESTAMP()-INTERVAL 6 MINUTE WHERE id=?",string(msg.MessageID))
 first:=check(1)[0];if first.MessageID!=msg.MessageID||len(first.Wave)!=64{t.Fatal("wrong bounded nudge target")}
 if f.text("SELECT COUNT(*) FROM chat_member_reads")!="0"{t.Fatal("eligibility marked recipient read")}
 again:=check(1)[0];if again.Wave!=first.Wave{t.Fatal("same unread wave changed")}
 if v,e:=s.PaidSMSCandidates(f.ctx,"10203",q.ConversationID,"10202",msg.MessageID);e!=nil||len(v)!=0{t.Fatal("cannot nudge another author's message",v,e)}
 f.exec("UPDATE chat_restrictions SET permanent=1 WHERE actor_id=10202");check(0)
 f.exec("UPDATE chat_restrictions SET permanent=0 WHERE actor_id=10202")
 f.exec("UPDATE chat_messages SET state='recalled' WHERE id=?",string(msg.MessageID));check(0)
 f.exec("UPDATE chat_messages SET state='published' WHERE id=?",string(msg.MessageID))
 h,e:=NewHistoryService(f.goDB,f.store,"test",[]byte("12345678901234567890123456789012"),s.timeout);if e!=nil{t.Fatal(e)}
 page,e:=h.Read(f.ctx,principal("10203"),HistoryRequest{ConversationID:q.ConversationID},"bootstrap");if e!=nil{t.Fatal(e)}
 if _,e=h.Acknowledge(f.ctx,principal("10203"),q.ConversationID,page.SyncCursor);e!=nil{t.Fatal(e)};check(0)
 f.exec("DELETE FROM chat_member_reads WHERE actor_id=10203");check(1)
 f.php("close");if _,e:=f.store.Poll(f.ctx,100);e!=nil{t.Fatal(e)};check(0)
 if _,e:=s.PaidSMSCandidates(f.ctx,ports.ID("0"),q.ConversationID,"","");e==nil{t.Fatal("invalid payer accepted")}
}

type smsNonceStore struct{mu sync.Mutex;values map[string]string}
func(s *smsNonceStore)Put(_ context.Context,k,v string,_ time.Duration)(bool,error){s.mu.Lock();defer s.mu.Unlock();if _,ok:=s.values[k];ok{return false,nil};s.values[k]=v;return true,nil}
func(s *smsNonceStore)Take(_ context.Context,k string)(string,error){return "",nil}
func TestMySQLPaidSMSPrivateHMACAndReplayFence(t *testing.T){
 f,s,q:=setupMessage(t,safetyFunc(pass));accepted(t,s,q);f.exec("UPDATE chat_messages SET created_at=UTC_TIMESTAMP()-INTERVAL 6 MINUTE")
 a,e:=auth.NewInternal("test",map[string][]byte{"fixture":[]byte(strings.Repeat("k",32))},&smsNonceStore{values:map[string]string{}},time.Second);if e!=nil{t.Fatal(e)}
 h,e:=NewPaidSMSInternalHandler(s,a);if e!=nil{t.Fatal(e)}
 raw,_:=json.Marshal(map[string]string{"payer_id":"10202","conversation_id":string(q.ConversationID),"recipient_id":"10203"})
 makeRequest:=func()*httptest.ResponseRecorder{r:=httptest.NewRequest("POST","/chat/internal/v1/sms/eligible",strings.NewReader(string(raw)));w:=httptest.NewRecorder();h.ServeHTTP(w,r);return w}
 if w:=makeRequest();w.Code!=401{t.Fatal("unsigned private query",w.Code)}
 r:=httptest.NewRequest("POST","/chat/internal/v1/sms/eligible",strings.NewReader(string(raw)));if a.Sign(r,raw,"fixture",time.Now())!=nil{t.Fatal("sign")}
 headers:=r.Header.Clone();w:=httptest.NewRecorder();h.ServeHTTP(w,r);if w.Code!=200||strings.Contains(w.Body.String(),q.Text){t.Fatal("private query leaked body",w.Code)}
 replay:=httptest.NewRequest("POST","/chat/internal/v1/sms/eligible",strings.NewReader(string(raw)));replay.Header=headers;w=httptest.NewRecorder();h.ServeHTTP(w,replay);if w.Code!=401{t.Fatal("replayed paid eligibility accepted")}
}
