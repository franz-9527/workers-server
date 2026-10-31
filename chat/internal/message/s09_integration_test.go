package message

import (
 "context"
 "testing"

 "github.com/franz-9527/workers/chat/internal/ports"
)

func TestMySQLS09ReadProgressAndUnread(t *testing.T){
 f,_,h,r:=historyFixture(t,6)
 // Fixture actor authored all rows; incoming sender is a different actor.
 f.exec(`UPDATE chat_messages SET sender_id=10203 WHERE conversation_id=? AND seq IN(2,3,5)`,string(r.ConversationID))
 p:=principal("10202")
 initial,e:=h.Unread(f.ctx,p,r.ConversationID)
 if e!=nil||initial.Count!=3||initial.FirstSeq!="2"||initial.ReadSeq!="0"{t.Fatalf("initial %+v %v",initial,e)}
 if f.text(`SELECT COUNT(*) FROM chat_member_reads WHERE conversation_id=?`,string(r.ConversationID))!="0"{t.Fatal("read-only unread query mutated state")}
 boot:=readPage(t,h,HistoryRequest{ConversationID:r.ConversationID},"bootstrap")
 future,err:=h.parse(boot.SyncCursor,"sync")
 if err!=nil{t.Fatal(err)}
 future.Seq="7";future.Position="7"
 if _,e=h.Acknowledge(f.ctx,p,r.ConversationID,h.sign(future));e!=ErrResync{t.Fatal("future advanced",e)}
 if _,e=h.Acknowledge(f.ctx,principal("10201"),r.ConversationID,boot.SyncCursor);e==nil{t.Fatal("outsider acknowledged")}
 if _,e=h.Acknowledge(f.ctx,p,r.ConversationID,"invalid");e!=ErrResync{t.Fatal("unsigned acknowledgement",e)}
 if got,e:=h.Acknowledge(f.ctx,p,r.ConversationID,boot.SyncCursor);e!=nil||got!="6"{t.Fatal("ack",got,e)}
 if got,e:=h.Acknowledge(f.ctx,p,r.ConversationID,boot.SyncCursor);e!=nil||got!="6"{t.Fatal("ack duplicate",got,e)}
 if f.text(`SELECT COUNT(*) FROM chat_member_reads WHERE conversation_id=?`,string(r.ConversationID))!="1"{t.Fatal("ack idempotence created extra read rows")}
 older,err:=h.parse(boot.SyncCursor,"sync")
 if err!=nil{t.Fatal(err)}
 older.Seq="2";older.Position="2"
 if got,e:=h.Acknowledge(f.ctx,p,r.ConversationID,h.sign(older));e!=nil||got!="6"{t.Fatal("watermark decreased",got,e)}
 now,e:=h.Unread(f.ctx,p,r.ConversationID)
 if e!=nil||now.Count!=0||now.ReadSeq!="6"{t.Fatal("post-ack",now,e)}
 if _,e=h.Acknowledge(context.Background(),principal("10203"),r.ConversationID,boot.SyncCursor);e==nil{t.Fatal("cross identity cursor accepted")}
 // Changing the membership generation resets persisted progress.
 f.exec(`UPDATE chat_members SET member_key=CONCAT(member_key,'-new') WHERE conversation_id=? AND actor_id=10202`,string(r.ConversationID))
 if _,e=h.Unread(f.ctx,p,r.ConversationID);e==nil{t.Fatal("stale authority member accepted")}
}

func TestMySQLS09DigestPageAndIsolation(t *testing.T){
 f,_,h,r:=historyFixture(t,3)
 p:=principal("10202")
 out,e:=h.Digests(f.ctx,p,"0",10)
 if e!=nil||len(out.Items)!=1||out.Items[0].ConversationID!=r.ConversationID||out.HasMore{t.Fatal(out,e)}
 if _,e=h.Digests(f.ctx,p,"0",21);e!=ports.ErrInvalid{t.Fatal("limit",e)}
 if _,e=h.Digests(f.ctx,principal("10201"),"0",10);e!=nil{t.Fatal("outsider index unavailable",e)}
}

func TestMySQLS09MembershipGenerationInvalidatesRead(t *testing.T){
 f,_,h,r:=historyFixture(t,6)
 actor:=principal("10202")
 boot:=readPage(t,h,HistoryRequest{ConversationID:r.ConversationID},"bootstrap")
 if n,e:=h.Acknowledge(f.ctx,actor,r.ConversationID,boot.SyncCursor);e!=nil||n!="6"{t.Fatal(n,e)}
 f.php("close")
 if _,e:=f.store.Poll(f.ctx,100);e!=nil{t.Fatal(e)}
 f.php("repair")
 if _,e:=f.store.Poll(f.ctx,100);e!=nil{t.Fatal(e)}
 if _,e:=h.Acknowledge(f.ctx,actor,r.ConversationID,boot.SyncCursor);e!=ErrResync{t.Fatal("prior authority generation accepted",e)}
 u,e:=h.Unread(f.ctx,actor,r.ConversationID)
 if e!=nil||u.ReadSeq!="0"{t.Fatal("stale membership watermark reused",u,e)}
}

func TestMySQLS09ConcurrentReadHighWatermarkAndLaterMessage(t *testing.T) {
 f,s,h,r:=historyFixture(t,6)
 actor:=principal("10202")
 boot:=readPage(t,h,HistoryRequest{ConversationID:r.ConversationID},"bootstrap")
 old,err:=h.parse(boot.SyncCursor,"sync")
 if err!=nil{t.Fatal(err)}
 old.Seq="2";old.Position="2"
 tokens:=[]string{h.sign(old),boot.SyncCursor}
 results:=make(chan error,len(tokens))
 for _,token:=range tokens{
  tok:=token
  go func(){_,e:=h.Acknowledge(f.ctx,actor,r.ConversationID,tok);results<-e}()
 }
 for range tokens{
  if e:=<-results;e!=nil{t.Fatal("concurrent ack",e)}
 }
 u,e:=h.Unread(f.ctx,actor,r.ConversationID)
 if e!=nil||u.ReadSeq!="6"{t.Fatal("regressed waterline",u,e)}
 // A message committed after the signed cursor must not be marked read.
 r.ClientMessageID=client(991)
 msg:=accepted(t,s,r)
 if msg.Seq!="7"{t.Fatal("expected next committed sequence",msg)}
 f.exec(`UPDATE chat_messages SET sender_id=10203 WHERE conversation_id=? AND seq=7`,string(r.ConversationID))
 u,e=h.Unread(f.ctx,actor,r.ConversationID)
 if e!=nil||u.ReadSeq!="6"||u.Count!=1||u.FirstSeq!="7"{
  t.Fatal("post-ack message lost",u,e)
 }
}

func TestMySQLS09AccountPaginationExcludesLaterConversations(t *testing.T) {
 f,_,h,_:=historyFixture(t,0)
 f.php("sync","402")
 if outcome,e:=f.store.Poll(f.ctx,100);e!=nil||outcome.Applied!=1{t.Fatal("second projection",outcome,e)}
 actor:=principal("10202")
 first,e:=h.DigestsCursor(f.ctx,actor,"",1)
 if e!=nil||len(first.Items)!=1||!first.HasMore||first.NextCursor==""{t.Fatal("first",first,e)}
 // A new conversation after the snapshot is not inserted into its later pages.
 f.php("sync","403")
 if outcome,e:=f.store.Poll(f.ctx,100);e!=nil||outcome.Applied!=1{t.Fatal("third projection",outcome,e)}
 second,e:=h.DigestsCursor(f.ctx,actor,first.NextCursor,1)
 if e!=nil||len(second.Items)!=1||second.HasMore||second.NextCursor!=""{t.Fatal("later candidate drift",second,e)}
 if first.Items[0].ConversationID==second.Items[0].ConversationID{t.Fatal("duplicate candidate")}
 fresh,e:=h.DigestsCursor(f.ctx,actor,"",20)
 if e!=nil||len(fresh.Items)!=3{t.Fatal("new snapshot lost new conversation",fresh,e)}
}

func TestMySQLS09RevokedAccountDigestFailsClosed(t *testing.T) {
 f,_,h,_:=historyFixture(t,1)
 p:=principal("10202")
 if out,e:=h.DigestsCursor(f.ctx,p,"",10);e!=nil||len(out.Items)!=1 {
  t.Fatalf("pre-revocation digest: %+v %v",out,e)
 }
 f.exec(`UPDATE login_sessions SET status='revoked' WHERE id=10202`)
 if out,e:=h.DigestsCursor(f.ctx,p,"",10);e==nil||len(out.Items)!=0 {
  t.Fatalf("revoked login returned partial or empty success: %+v %v",out,e)
 }
}

func TestMySQLS09EmptyDigestStillRechecksLogin(t *testing.T) {
 f,_,h,_:=historyFixture(t,0)
 p:=principal("10201") // No projected membership in the initial unbound order.
 before,e:=h.DigestsCursor(f.ctx,p,"",10)
 if e!=nil||len(before.Items)!=0{t.Fatalf("valid empty account: %+v %v",before,e)}
 f.exec(`UPDATE login_sessions SET status='revoked' WHERE id=10201`)
 after,e:=h.DigestsCursor(f.ctx,p,"",10)
 if e==nil||len(after.Items)!=0{t.Fatalf("revoked empty account falsely succeeded: %+v %v",after,e)}
}

func TestMySQLS14FullAccountAttentionBeyondClientWindow(t *testing.T){
 f,_,h,r:=historyFixture(t,3)
 f.php("attention-seed")
 for {batch,e:=f.store.Poll(f.ctx,100);if e!=nil{t.Fatal(e)};if batch.Applied<100{break}}
 f.exec(`INSERT INTO chat_user_preferences(conversation_id,actor_id,member_key_hash,pinned,pinned_version,later,later_version,updated_at) SELECT conversation_id,actor_id,SHA2(member_key,256),0,0,1,1,UTC_TIMESTAMP(6) FROM chat_members WHERE actor_id=10202`)
 f.exec("UPDATE chat_messages SET sender_id=10203 WHERE conversation_id=?",string(r.ConversationID))
 cursor:="";pages:=0
 for{
  page,e:=h.DigestsCursor(f.ctx,principal("10202"),cursor,20);if e!=nil{t.Fatal(e)};pages++
  if page.HasMore {if page.BadgeCount!=nil{t.Fatal("partial page claims a global count")};cursor=page.NextCursor;continue}
  if page.BadgeCount==nil||*page.BadgeCount!=126||pages<7{t.Fatal("client-window truncated or unread/later double counted",page.BadgeCount,pages)}
  break
 }
 f.exec("UPDATE chat_user_preferences SET later=0 WHERE conversation_id=?",string(r.ConversationID))
 page,e:=h.DigestsCursor(f.ctx,principal("10202"),"",20);if e!=nil||!page.HasMore{t.Fatal(page,e)}
 // The caller cannot alter an authenticated partial attention accumulator.
 parsed,e:=h.decodeDigestCursor(page.NextCursor,principal("10202"));if e!=nil||parsed.Attention!=20{t.Fatal(parsed,e)}
 if _,e=h.DigestsCursor(f.ctx,principal("10202"),page.NextCursor+"x",20);e!=ErrResync{t.Fatal("tampered total accepted",e)}
 boot:=readPage(t,h,HistoryRequest{ConversationID:r.ConversationID},"bootstrap")
 if _,e=h.Acknowledge(f.ctx,principal("10202"),r.ConversationID,boot.SyncCursor);e!=nil{t.Fatal(e)}
 cursor=""
 for{page,e=h.DigestsCursor(f.ctx,principal("10202"),cursor,20);if e!=nil{t.Fatal(e)};if !page.HasMore{if page.BadgeCount==nil||*page.BadgeCount!=125{t.Fatal("read did not preserve other later items",page.BadgeCount)};break};cursor=page.NextCursor}
}
