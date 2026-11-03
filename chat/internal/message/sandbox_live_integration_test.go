package message

import (
 "bytes"
 "context"
 "fmt"
 "os"
 "path/filepath"
 "strconv"
 "strings"
 "testing"
 "time"
 "github.com/franz-9527/workers/chat/internal/calls"
 "github.com/franz-9527/workers/chat/internal/moderation"
 "github.com/franz-9527/workers/chat/internal/ports"
)

func sandboxSeed(t *testing.T,f *fixtureDB)(ports.Principal,ports.Principal,ports.ID){
 t.Helper()
 for _,version:=range []string{"2.7.2","2.7.3"} {
  data,e:=os.ReadFile(filepath.Join("..","..","..","server","database","upgrades",version+".sql"));if e!=nil{t.Fatal(e)}
  for _,statement:=range strings.Split(string(data),";"){if strings.TrimSpace(statement)!=""{f.exec(statement)}}
 }
 f.exec("CREATE TABLE IF NOT EXISTS chat_call_sessions(id BIGINT UNSIGNED PRIMARY KEY,conversation_id BIGINT UNSIGNED,state VARCHAR(16))")
 f.exec("INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(91001,'sandbox-owner','fixture','super_admin','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
 f.exec("INSERT INTO chat_admin_sessions(id,admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at) VALUES(91001,91001,REPEAT('d',64),REPEAT('b',64),REPEAT('c',64),'[\"chat:audit\"]',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())")
 f.exec("INSERT INTO login_sessions(id,user_id,token_hash,status,expires_at,created_at,demo_actor_user_id) VALUES(81001,10201,?,'active',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP(),10202)",digest("sandbox-parent"))
 f.exec("INSERT INTO chat_sandbox_rooms(id,title,created_by,request_key,request_hash,created_at) VALUES(51,'完整联调',91001,REPEAT('a',32),REPEAT('a',64),UTC_TIMESTAMP())")
 f.exec("INSERT INTO chat_sandbox_members(room_id,actor_key,wire_id,display_name,display_role) VALUES(51,'admin:91001','7000000000000091001','测试管理员','admin'),(51,'user:10202','10202','演示师傅','worker')")
 peers:=[]struct{id int;actor,wire,kind,origin,version string}{{101,"admin:91001","7000000000000091001","admin","91001",strings.Repeat("b",64)},{102,"user:10202","10202","mini","81001",digest("sandbox-parent")}}
 principals:=[]ports.Principal{}
 for _,peer:=range peers {
  key:=digest("sandbox-key:"+peer.actor);version:=digest("sandbox-version:"+peer.actor)
  f.exec("INSERT INTO chat_sandbox_sessions(id,session_key,actor_key,wire_id,origin_kind,origin_id,origin_version,moderation_user_id,session_version,expires_at,created_at) VALUES(?,?,?,?,?,?,?,10203,?,UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())",peer.id,key,peer.actor,peer.wire,peer.kind,peer.origin,peer.version,version)
  source:=strconv.FormatUint(ports.SandboxSourceBase+uint64(peer.id),10)
  principals=append(principals,ports.Principal{ActorID:ports.ID(peer.wire),SourceID:ports.ID(source),Kind:"mini",Environment:"test",Scopes:[]string{"chat:use"},SessionID:digest("sandbox\n"+source+"\n"+key),SessionVersion:version,ExpiresAt:time.Now().Add(time.Minute)})
 }
 host:=os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST");if host==""{host="127.0.0.1"}
 for _,table:=range []string{"system_settings","chat_sandbox_rooms","chat_sandbox_members","chat_sandbox_sessions","admin_users","chat_call_sessions"} {
  f.exec("GRANT SELECT ON "+fmt.Sprintf("%s.%s",f.name,table)+" TO '"+f.user+"'@'"+host+"'")
 }
 if err:=f.store.ValidatePrincipal(f.ctx,principals[0]);err!=nil{t.Fatal("sandbox delegation",err)}
 cid:=ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE scope_key='sandbox:51'"))
 if len(cid)>15{t.Fatal("virtual test binding advanced real auto increment",cid)}
 return principals[0],principals[1],cid
}
func TestMySQLSandboxLiveTextAuthorityAndIsolation(t *testing.T){
 f,s,normal:=setupMessage(t,safetyFunc(pass));admin,mini,cid:=sandboxSeed(t,f)
 q:=normal;q.ConversationID=cid;q.ClientMessageID=client(9100);q.Text="真实Go测试消息"
 result,e:=s.Send(f.ctx,admin,q);if e!=nil||result.State!="accepted"{t.Fatal(result,e)}
 h,e:=NewHistoryService(f.goDB,f.store,"test",bytes.Repeat([]byte{7},32),3*time.Second);if e!=nil{t.Fatal(e)}
 page,e:=h.Read(f.ctx,mini,HistoryRequest{ConversationID:cid,Limit:50},"bootstrap")
 if e!=nil||len(page.Messages)!=1||page.Messages[0].Text==nil||*page.Messages[0].Text!=q.Text||page.OrderContext==nil||!page.OrderContext.Simulation||page.OrderContext.SandboxRoomID!="51"{t.Fatal("history",page,e)}
 if f.text("SELECT COUNT(*) FROM chat_reminder_events WHERE conversation_id=?",string(cid))!="0"{t.Fatal("sandbox generated real reminders")}
 if _,e=f.store.Authorize(f.ctx,ports.PolicyRequest{Actor:principal("10202"),ConversationID:cid,Action:"read"});e==nil{t.Fatal("ordinary login entered test scope")}
 if _,e=f.store.Authorize(f.ctx,ports.PolicyRequest{Actor:mini,ConversationID:normal.ConversationID,Action:"read"});e==nil{t.Fatal("test delegation entered real scope")}
 bad:=mini;bad.Environment="production";if e=f.store.ValidatePrincipal(f.ctx,bad);e==nil{t.Fatal("production accepted reserved test source")}
 f.exec("UPDATE login_sessions SET demo_actor_user_id=10203 WHERE id=81001")
 if e=f.store.ValidatePrincipal(f.ctx,mini);e==nil{t.Fatal("old demo switch survived")}
 f.exec("UPDATE login_sessions SET demo_actor_user_id=10202 WHERE id=81001")
 f.exec("UPDATE admin_users SET role='operations' WHERE id=91001")
 if e=f.store.ValidatePrincipal(f.ctx,admin);e==nil{t.Fatal("administrator downgrade survived")}
}
func TestMySQLSandboxLiveImageUsesUploadInspectionReviewAndSharedHistory(t *testing.T){
 f,s,m,storage,q:=mediaFixture(t);admin,mini,cid:=sandboxSeed(t,f)
 ctx:=context.Background();body:=[]byte("sandbox-image")
 grant,e:=m.Upload(ctx,admin,cid,client(9201),int64(len(body)));if e!=nil{t.Fatal(e)}
 if e=storage.Put(ctx,grant.Key,body,"image/jpeg");e!=nil{t.Fatal(e)}
 if _,e=m.Uploaded(ctx,admin,cid,grant.AttachmentID);e!=nil{t.Fatal(e)}
 q.ConversationID=cid;q.ClientMessageID=client(9202);q.Items=[]MediaItem{{AttachmentID:grant.AttachmentID,ObjectVersion:grant.ObjectVersion}}
 result,e:=s.Send(ctx,admin,q);if e!=nil{t.Fatal(e)}
 if result.State=="accepted"{t.Fatal("image bypassed processing/review")}
 mediaProcess(t,m,1);mediaApprove(t,m)
 result,e=s.Query(ctx,admin,cid,q.ClientMessageID);if e!=nil||result.State!="accepted"{t.Fatal(result,e)}
 if _,e=m.URL(ctx,mini,cid,result.MessageID,grant.AttachmentID,true);e!=nil{t.Fatal("peer image",e)}
 if _,e=s.Recall(ctx,admin,cid,result.MessageID);e!=nil{t.Fatal(e)}
 if _,e=m.URL(ctx,mini,cid,result.MessageID,grant.AttachmentID,true);e==nil{t.Fatal("recalled image stayed visible")}
}
func TestMySQLSandboxLiveAudioUsesASRReceiptPublicationAndPlaybackAuthorization(t *testing.T){
 f,s,a,storage,inspector,q:=avFixture(t,"audio");admin,mini,cid:=sandboxSeed(t,f)
 ctx:=context.Background();body:=[]byte("sandbox-voice")
 u,e:=a.Upload(ctx,mini,cid,client(9301),"voice",int64(len(body)),fileMD5(body));if e!=nil{t.Fatal(e)}
 q.ConversationID=cid;q.ClientMessageID=client(9301);q.Items=[]MediaItem{{AttachmentID:u.AttachmentID,ObjectVersion:u.ObjectVersion}}
 if _,e=a.Send(ctx,mini,q);e!=nil{t.Fatal(e)}
 part,e:=a.Part(ctx,mini,cid,u.AttachmentID,1,false);if e!=nil{t.Fatal(e)}
 if e=storage.Put(ctx,part.Key,body,"application/octet-stream");e!=nil{t.Fatal(e)}
 if _,e=a.Part(ctx,mini,cid,u.AttachmentID,1,true);e!=nil{t.Fatal(e)}
 if e=a.Complete(ctx,mini,cid,u.AttachmentID);e!=nil{t.Fatal(e)}
 avSteps(t,a,5)
 if inspector.asrCalls.Load()!=1||inspector.rawCalls.Load()!=1{t.Fatal("audio checks skipped")}
 if e=a.ReceiveAudio(ctx,ports.AudioReceipt{AppID:inspector.MediaAppID(),TraceID:"fixture-trace",Decision:"pass",Code:"official_pass",Fingerprint:strings.Repeat("e",64)});e!=nil{t.Fatal(e)}
 f.exec("UPDATE chat_av_jobs SET next_attempt_at=UTC_TIMESTAMP(6)")
 avSteps(t,a,2)
 result,e:=s.Query(ctx,mini,cid,q.ClientMessageID);if e!=nil||result.State!="accepted"{t.Fatal(result,e)}
 if _,e=a.URL(ctx,admin,cid,result.MessageID,false);e!=nil{t.Fatal(e)}
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51");f.exec("DELETE FROM chat_sandbox_rooms WHERE id=51")
 if _,e=a.URL(ctx,admin,cid,result.MessageID,false);e==nil{t.Fatal("cleared scope still authorized playback")}
 if _,e=f.store.Authorize(ctx,ports.PolicyRequest{Actor:mini,ConversationID:cid,Action:"send"});e==nil{t.Fatal("deleted room still writable")}
}

func TestMySQLSandboxLiveCallAuthorityUsesSameMembersAndFailsAfterClear(t *testing.T){
 f,_,_:=setupMessage(t,safetyFunc(pass));admin,mini,cid:=sandboxSeed(t,f)
 authority,e:=calls.NewOrderAuthority(f.goDB,f.store,"test");if e!=nil{t.Fatal(e)}
 tx,e:=f.goDB.BeginTx(f.ctx,nil);if e!=nil{t.Fatal(e)}
 own,peer,e:=authority.Pair(f.ctx,tx,admin,cid,mini.ActorID,true)
 tx.Rollback()
 if e!=nil||!own.CanSend||!peer.CanSend||!strings.HasPrefix(peer.MemberKey,"sandbox:51:"){t.Fatal("shared call authority",own,peer,e)}
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51");f.exec("DELETE FROM chat_sandbox_rooms WHERE id=51")
 tx,e=f.goDB.BeginTx(f.ctx,nil);if e!=nil{t.Fatal(e)}
 _,_,e=authority.Pair(f.ctx,tx,admin,cid,mini.ActorID,true);tx.Rollback()
 if e==nil{t.Fatal("cleared test call remained authorized")}
}
func TestMySQLSandboxCleanupDeletesRemoteMediaBeforePayloads(t *testing.T){
 f,s,m,storage,_:=mediaFixture(t);admin,_,cid:=sandboxSeed(t,f)
 body:=[]byte("sandbox-cleanup")
 u,e:=m.Upload(f.ctx,admin,cid,client(9401),int64(len(body)));if e!=nil{t.Fatal(e)}
 storage.Put(f.ctx,u.Key,body,"image/jpeg")
 if _,e=m.Uploaded(f.ctx,admin,cid,u.AttachmentID);e!=nil{t.Fatal(e)}
 mediaProcess(t,m,1)
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51");f.exec("DELETE FROM chat_sandbox_rooms WHERE id=51")
 for i:=0;i<6;i++{
  if e=s.CleanSandboxOne(f.ctx);e!=nil{t.Fatal("scope cleanup",e)}
  if e=m.CleanOne(f.ctx);e!=nil{t.Fatal("cloud delete",e)}
 }
 if f.text("SELECT state FROM chat_conversations WHERE id=?",string(cid))!="sandbox_cleared"{t.Fatal("cleanup never completed")}
 if f.text("SELECT COUNT(*) FROM chat_image_objects o JOIN chat_image_uploads u ON u.id=o.upload_id WHERE u.conversation_id=?",string(cid))!="0"{t.Fatal("objects retained")}
 if len(storage.objects)!=0{t.Fatal("remote bytes retained",len(storage.objects))}
}

func TestMySQLSandboxLiveVideoRequiresRealPrereview(t *testing.T){
 f,s,a,storage,inspector,q:=avFixture(t,"video");admin,mini,cid:=sandboxSeed(t,f)
 ctx:=context.Background();body:=[]byte("sandbox-video")
 u,e:=a.Upload(ctx,mini,cid,client(9351),"album",int64(len(body)),fileMD5(body));if e!=nil{t.Fatal(e)}
 q.ConversationID=cid;q.ClientMessageID=client(9351);q.Items=[]MediaItem{{AttachmentID:u.AttachmentID,ObjectVersion:u.ObjectVersion}}
 if _,e=a.Send(ctx,mini,q);e!=nil{t.Fatal(e)}
 part,e:=a.Part(ctx,mini,cid,u.AttachmentID,1,false);if e!=nil{t.Fatal(e)}
 if e=storage.Put(ctx,part.Key,body,"application/octet-stream");e!=nil{t.Fatal(e)}
 if _,e=a.Part(ctx,mini,cid,u.AttachmentID,1,true);e!=nil{t.Fatal(e)}
 if e=a.Complete(ctx,mini,cid,u.AttachmentID);e!=nil{t.Fatal(e)}
 avSteps(t,a,4)
 if f.text("SELECT phase FROM chat_av_jobs")!="review_pending"{t.Fatal("video skipped full prereview")}
 if inspector.asrCalls.Load()!=1{t.Fatal("video speech skipped recognition")}
 reviewer:=mediaAdmin();reviewer.Scopes=[]string{"chat:audit","chat:moderate","chat:media","chat:evidence"}
 f.exec("INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(90001,'sandbox-video-review','fixture','operations','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
 f.exec("INSERT INTO chat_admin_sessions(id,admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at) VALUES(90001,90001,REPEAT('a',64),REPEAT('b',64),REPEAT('c',64),'[\"chat:audit\",\"chat:moderate\",\"chat:media\",\"chat:evidence\"]',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())")
 list,e:=a.Reviews(ctx,reviewer,"0");if e!=nil||len(list)!=1{t.Fatal(list,e)}
 review,e:=a.Review(ctx,reviewer,list[0].IntentID,list[0].Version,"claim","完整测试视频",false,false);if e!=nil{t.Fatal(e)}
 if _,e=a.ReviewURL(ctx,reviewer,review.IntentID,review.Version);e!=nil{t.Fatal(e)}
 if _,e=a.Review(ctx,reviewer,review.IntentID,review.Version,"approve","已完整检查画面和原声",true,true);e!=nil{t.Fatal(e)}
 avSteps(t,a,1)
 result,e:=s.Query(ctx,mini,cid,q.ClientMessageID);if e!=nil||result.State!="accepted"{t.Fatal(result,e)}
 if _,e=a.URL(ctx,admin,cid,result.MessageID,false);e!=nil{t.Fatal(e)}
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51");f.exec("DELETE FROM chat_sandbox_rooms WHERE id=51")
 if _,e=a.URL(ctx,admin,cid,result.MessageID,false);e==nil{t.Fatal("cleared scope still authorized playback")}
 if _,e=f.store.Authorize(ctx,ports.PolicyRequest{Actor:mini,ConversationID:cid,Action:"send"});e==nil{t.Fatal("deleted room still writable")}
}

func TestMySQLSandboxMultiMemberUsesRealSharedHistory(t *testing.T){
 f,s,q:=setupMessage(t,safetyFunc(pass));admin,mini,_:=sandboxSeed(t,f)
 f.exec("INSERT INTO chat_sandbox_rooms(id,title,created_by,request_key,request_hash,created_at) VALUES(52,'三方联调',91001,REPEAT('b',32),REPEAT('b',64),UTC_TIMESTAMP())")
 f.exec("INSERT INTO chat_sandbox_members(room_id,actor_key,wire_id,display_name,display_role) SELECT 52,actor_key,wire_id,display_name,display_role FROM chat_sandbox_members WHERE room_id=51")
 f.exec("INSERT INTO chat_sandbox_members(room_id,actor_key,wire_id,display_name,display_role) VALUES(52,'user:10203','10203','演示客户','customer')")
 if e:=f.store.ValidatePrincipal(f.ctx,admin);e!=nil{t.Fatal(e)}
 cid:=ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE scope_key='sandbox:52'"))
 q.ConversationID=cid;q.ClientMessageID=client(9501);q.Text="多人会话共用真实消息"
 if result,e:=s.Send(f.ctx,admin,q);e!=nil||result.State!="accepted"{t.Fatal(result,e)}
 h,e:=NewHistoryService(f.goDB,f.store,"test",bytes.Repeat([]byte{7},32),3*time.Second);if e!=nil{t.Fatal(e)}
 page,e:=h.Read(f.ctx,mini,HistoryRequest{ConversationID:cid,Limit:50},"bootstrap")
 if e!=nil||len(page.Messages)!=1||page.Mode!="collaboration"{t.Fatal(page,e)}
 if f.text("SELECT COUNT(*) FROM chat_members WHERE conversation_id=? AND can_read=1",string(cid))!="3"{t.Fatal("missing group participant")}
}

type sandboxCallBroker struct{}
func(sandboxCallBroker)Availability(context.Context)(calls.Availability,error){
 a:=calls.Availability{};a.Settings.Revision="1";c:=&a.Settings.Config
 c.Enabled=true;c.AudioEnabled=true;c.VideoEnabled=true;c.AudioPriority=[2]calls.Provider{calls.Wechat,calls.Shengwang};c.VideoPriority=c.AudioPriority;c.AllowFallback=false;c.Window=45;c.Margin=15;c.Heartbeat=15;c.MaxActive=10
 for i,p:=range []calls.Provider{calls.Wechat,calls.Shengwang}{unit:="wechat_equivalent";if p==calls.Shengwang{unit="shengwang_standard"}
 a.Pools=append(a.Pools,calls.Pool{ID:ports.ID(strconv.Itoa(i+1)),Decision:calls.ResourceDecision{SchemaVersion:1,Provider:p,ScopeID:"fixture",CycleID:"fixture",ConfigRevision:"1",Unit:unit,Status:"available",AvailableUnits:"99999999",ExpiresAt:time.Now().Unix()+300},Audio:calls.Meter{1,1,1},Video:calls.Meter{1,1,1}})}
 return a,nil
}
func(sandboxCallBroker)Resource(_ context.Context,q calls.ResourceRequest)(calls.Permit,error){
 state:="active";if strings.HasPrefix(q.Action,"release"){state="closed"}
 return calls.Permit{CallID:q.CallID,Epoch:q.Epoch,PoolID:q.PoolID,State:state,Provider:calls.Shengwang,AppID:strings.Repeat("a",32),Until:time.Now().Unix()+60,Revision:"1"},nil
}
func(sandboxCallBroker)Identities(context.Context,calls.ResourceRequest)(map[string]calls.NativePerson,error){return nil,calls.ErrUnavailable}
type sandboxCallMedia struct{p *calls.Providers}
func(m sandboxCallMedia)Ready(p calls.Provider)bool{return m.p.Ready(p)}
func(m sandboxCallMedia)Credentials(ctx context.Context,s calls.Session,p calls.Permit,id ports.ID)(any,error){return m.p.Credentials(ctx,s,p,id)}
func(sandboxCallMedia)Stop(context.Context,calls.Session,calls.Permit)(bool,error){return true,nil}
func TestMySQLSandboxRealCallLifecycleCredentialsAndClearStopsBothAccounts(t *testing.T){
 f,_,_:=setupMessage(t,safetyFunc(pass));admin,mini,cid:=sandboxSeed(t,f)
 host:=os.Getenv("CHAT_AUTH_TEST_MYSQL_USER_HOST");if host==""{host="127.0.0.1"}
 for _,table:=range []string{"chat_call_accounts","chat_call_sessions","chat_call_legs","chat_call_operations","chat_call_events"}{f.exec("GRANT SELECT,INSERT,UPDATE,DELETE ON "+f.name+"."+table+" TO '"+f.user+"'@'"+host+"'")}
 authority,e:=calls.NewOrderAuthority(f.goDB,f.store,"test");if e!=nil{t.Fatal(e)}
 media:=sandboxCallMedia{&calls.Providers{Environment:"test",AppID:strings.Repeat("a",32),Certificate:strings.Repeat("b",32),CustomerID:"fixture",CustomerSecret:"fixture",PublishPrivilegesVerified:true}}
 service,e:=calls.NewService(f.goDB,authority,sandboxCallBroker{},media);if e!=nil{t.Fatal(e)}
 ready,e:=service.Available(f.ctx,mini,calls.Request{ConversationID:cid});if e!=nil||ready.(map[string]any)["video"]!=true{t.Fatal("mini availability diverged from Web routing",ready,e)}
 view,e:=service.Create(f.ctx,admin,calls.Request{ConversationID:cid,PeerID:mini.ActorID,Media:calls.Video,Instance:strings.Repeat("a",32),Operation:digest("sandbox-live-call")});if e!=nil||view.Provider!=calls.Shengwang{t.Fatal(view,e)}
 action:=func(p ports.Principal,name,instance string)calls.View{
 t.Helper();current,e:=service.Status(f.ctx,p,calls.Request{CallID:view.CallID});if e!=nil{t.Fatal(e)}
 out,e:=service.Action(f.ctx,p,name,calls.Request{CallID:view.CallID,Epoch:ports.ID(current.Epoch),Revision:ports.ID(current.Revision),Instance:instance,Operation:digest(string(p.ActorID)+":"+name+":"+current.Revision)});if e!=nil{t.Fatal(name,e)};return out
 }
 action(mini,"accept",strings.Repeat("b",32))
 grants:=[]map[string]any{}
 for i,p:=range []ports.Principal{admin,mini}{instance:=strings.Repeat("a",32);if i==1{instance=strings.Repeat("b",32)}
 prepared,e:=service.Prepare(f.ctx,p,calls.Request{CallID:view.CallID,Epoch:"1",Instance:instance});if e!=nil{t.Fatal(e)}
 grant:=prepared.(map[string]any)["credentials"].(map[string]any);grants=append(grants,grant)
 if !strings.HasPrefix(grant["token"].(string),"007"){t.Fatal("real RTC token generation was bypassed")}
 action(p,"prepared",instance)
 }
 if grants[0]["channel"]!=grants[1]["channel"]||grants[0]["uid"]==grants[1]["uid"]||grants[0]["peer_uid"]!=grants[1]["uid"]{t.Fatal("cross-client RTC identities mismatched",grants)}
 action(admin,"ready",strings.Repeat("a",32));active:=action(mini,"ready",strings.Repeat("b",32));if active.State!=calls.Active{t.Fatal(active)}
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51");f.exec("DELETE FROM chat_sandbox_rooms WHERE id=51")
 if _,e=service.Status(f.ctx,admin,calls.Request{CallID:view.CallID});e==nil{t.Fatal("clear retained call authorization")}
 if e=service.Tick(f.ctx);e!=nil{t.Fatal(e)}
 if f.text("SELECT state FROM chat_call_sessions WHERE id=?",string(view.CallID))!="ended"{t.Fatal("cleared active call was not stopped")}
 if f.text("SELECT COUNT(*) FROM chat_call_accounts WHERE active_call_id IS NOT NULL")!="0"{t.Fatal("cleared call retained account occupancy")}
}

func TestMySQLSandboxCleanupRemovesPublishedTextAndInspectionText(t *testing.T){
 f,s,q:=setupMessage(t,safetyFunc(pass));admin,_,cid:=sandboxSeed(t,f)
 q.ConversationID=cid;q.ClientMessageID=client(9601);q.Text="清理正文"
 if r,e:=s.Send(f.ctx,admin,q);e!=nil||r.State!="accepted"{t.Fatal(r,e)}
 f.exec("INSERT INTO chat_text_checks(intent_id,sender_id,conversation_id,fingerprint,normalized_text,created_at) SELECT id,sender_id,conversation_id,fingerprint,'清理正文',UTC_TIMESTAMP(6) FROM chat_send_intents WHERE conversation_id=?",string(cid))
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51");f.exec("DELETE FROM chat_sandbox_rooms WHERE id=51")
 if e:=s.CleanSandboxOne(f.ctx);e!=nil{t.Fatal(e)}
 if f.text("SELECT state FROM chat_conversations WHERE id=?",string(cid))!="sandbox_cleared"||f.text("SELECT COUNT(*) FROM chat_messages WHERE conversation_id=?",string(cid))!="0"||f.text("SELECT COUNT(*) FROM chat_text_checks WHERE conversation_id=?",string(cid))!="0"{t.Fatal("clear retained text payload")}
}

func TestMySQLSandboxLiveDirectReadReceiptsAndMemberRevision(t *testing.T){
 f,s,q:=setupMessage(t,safetyFunc(pass));admin,mini,cid:=sandboxSeed(t,f)
 q.ConversationID=cid;q.ClientMessageID=client(9701);q.Text="订单与已读测试"
 result,e:=s.Send(f.ctx,admin,q);if e!=nil||result.State!="accepted"{t.Fatal(result,e)}
 h,e:=NewHistoryService(f.goDB,f.store,"test",bytes.Repeat([]byte{7},32),3*time.Second);if e!=nil{t.Fatal(e)}
 counts,e:=h.Readers(f.ctx,admin,cid,result.MessageID);if e!=nil||counts.Read!=0||counts.Unread!=1{t.Fatal("initial direct receipt",counts,e)}
 page,e:=h.Read(f.ctx,mini,HistoryRequest{ConversationID:cid,Limit:50},"bootstrap");if e!=nil{t.Fatal(e)}
 if _,e=h.Acknowledge(f.ctx,mini,cid,page.SyncCursor);e!=nil{t.Fatal(e)}
 counts,e=h.Readers(f.ctx,admin,cid,result.MessageID);if e!=nil||counts.Read!=1||counts.Unread!=0{t.Fatal("confirmed direct receipt",counts,e)}
 f.exec("UPDATE chat_sandbox_rooms SET revision=revision+1,title='重命名测试' WHERE id=51")
 if e=f.store.ValidatePrincipal(f.ctx,mini);e!=nil{t.Fatal("updated projection",e)}
 updated,e:=h.Read(f.ctx,mini,HistoryRequest{ConversationID:cid,Limit:50},"bootstrap");if e!=nil||updated.PolicyVersion==page.PolicyVersion{t.Fatal("policy did not advance",updated,e)}
 f.exec("DELETE FROM chat_sandbox_members WHERE room_id=51 AND wire_id='10202'")
 f.exec("UPDATE chat_sandbox_rooms SET revision=revision+1 WHERE id=51")
 if _,e=h.Read(f.ctx,mini,HistoryRequest{ConversationID:cid,Limit:50},"bootstrap");e==nil{t.Fatal("removed member retained access")}
}

func TestMySQLSandboxRealLocalModerationForDemoWithoutWechatBinding(t *testing.T){
 f,s,q:=setupMessage(t,safetyFunc(pass));admin,mini,cid:=sandboxSeed(t,f)
 f.exec("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10203,'chat-platform-app','oS07Parent_abcdefghijklmnopqrstuv','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
 safety,e:=moderation.New(f.goDB,"test",moderation.Config{Enabled:true,Mode:"local",AppID:"chat-platform-app"},4,2*time.Second,nil);if e!=nil{t.Fatal(e)};t.Cleanup(safety.Close);s.safety=safety
 for i,p:=range []ports.Principal{admin,mini}{
  q.ConversationID=cid;q.ClientMessageID=client(9800+i);q.Text="确认明天下午施工"
  result,e:=s.Send(f.ctx,p,q);if e!=nil||result.State!="accepted"{t.Fatal("real moderation path",result,e)}
 }
 q.ClientMessageID=client(9890);q.Text="【平台订单】\n{\"publicNo\":\"TEST_ORDER_123\",\"title\":\"客厅墙纸施工\"}"
 result,e:=s.Send(f.ctx,mini,q);if e!=nil||result.State!="accepted"{t.Fatal("order text attachment moderation",result,e)}
 if f.text("SELECT COUNT(*) FROM chat_text_checks WHERE conversation_id=?",string(cid))!="3"{t.Fatal("actual moderation context not recorded")}
}
