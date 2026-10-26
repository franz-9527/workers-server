package calls

import(
 "context"
 "crypto/sha256"
 "encoding/hex"
 "database/sql"
 "encoding/json"
 "errors"
 "strconv"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
)

func(s *Service)Create(ctx context.Context,p ports.Principal,q Request)(View,error){
 var result View
 if !positive(q.ConversationID)||!positive(q.PeerID)||p.ActorID==q.PeerID||!opPattern.MatchString(q.Operation)||!instancePattern.MatchString(q.Instance)||(q.Media!=Audio&&q.Media!=Video){return result,ErrInvalid}
 hash:=operation("create",q)
 var replay *View
 e:=s.tx(ctx,func(tx *sql.Tx)error{prior,e:=s.prior(ctx,tx,p,q,hash);if e!=nil{return e};if prior!=nil{r,e:=s.load(ctx,tx,prior.CallID,false);if e!=nil{return e};if e=s.authorized(ctx,tx,p,r,false);e!=nil{return e};replay=prior};return nil});if e!=nil{return result,e};if replay!=nil{return *replay,nil}
 pool,settings,e:=s.selectPool(ctx,q.Media);if e!=nil{return result,e}
 e=s.tx(ctx,func(tx *sql.Tx)error{
  own,other,e:=s.guard.Pair(ctx,tx,p,q.ConversationID,q.PeerID,true);if e!=nil{return e}
  if prior,e:=s.prior(ctx,tx,p,q,hash);e!=nil{return e}else if prior!=nil{
   r,e:=s.load(ctx,tx,prior.CallID,true);if e!=nil{return e};if e=s.authorized(ctx,tx,p,r,false);e!=nil{return e};result=*prior;return nil
  }
  if e=s.accounts(ctx,tx,"0");e!=nil{return e}
  if e=s.accounts(ctx,tx,p.ActorID,q.PeerID);e!=nil{return e}
  var count int
  if e=tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_call_accounts WHERE actor_id<>0 AND active_call_id IS NOT NULL").Scan(&count);e!=nil{return e}
  if count>=settings.Config.MaxActive*2{return ErrBusy}
  now:=time.Now().Unix()
  res,e:=tx.ExecContext(ctx,"INSERT INTO chat_call_sessions(conversation_id,caller_id,callee_id,state,revision,attempt_epoch,model_json,resource_json,caller_member_key,callee_member_key,created_at,updated_at) VALUES(?,?,?,'ringing',1,1,'{}','{}',?,?,?,?)",
   string(q.ConversationID),string(p.ActorID),string(q.PeerID),own.MemberKey,other.MemberKey,now,now)
  if e!=nil{return e};id,e:=res.LastInsertId();if e!=nil||id<1{return ErrUnavailable}
  model,e:=NewSession(ports.ID(strconv.FormatInt(id,10)),q.ConversationID,p.ActorID,q.PeerID,pool.Decision.Provider,q.Media,now,now+30);if e!=nil{return e}
  r:=record{Session:model,Binding:Binding{Pool:pool},CallerKey:own.MemberKey,CalleeKey:other.MemberKey,Created:now}
  if e=s.save(ctx,tx,r,"created");e!=nil{return e}
  if _,e=tx.ExecContext(ctx,"UPDATE chat_call_accounts SET active_call_id=?,updated_at=UTC_TIMESTAMP(6) WHERE actor_id IN (?,?)",id,string(p.ActorID),string(q.PeerID));e!=nil{return e}
  principal,_:=json.Marshal(p)
  if _,e=tx.ExecContext(ctx,"INSERT INTO chat_call_legs(call_id,actor_id,instance_id,principal_json,last_seen) VALUES(?,?,?,?,?)",id,string(p.ActorID),q.Instance,principal,now);e!=nil{return e}
  result=r.view();return s.remember(ctx,tx,p,q,hash,result)
 });return result,e
}
func(s *Service)Action(ctx context.Context,p ports.Principal,action string,q Request)(View,error){
 var result View
 if !positive(q.CallID)||!positive(q.Epoch)||!positive(q.Revision)||!opPattern.MatchString(q.Operation)||!instancePattern.MatchString(q.Instance){return result,ErrInvalid}
 hash:=operation(action,q)
 e:=s.tx(ctx,func(tx *sql.Tx)error{
  initial,e:=s.load(ctx,tx,q.CallID,false);if e!=nil{return e}
  if e=s.authorized(ctx,tx,p,initial,action!="end"&&action!="reject");e!=nil{return e}
  r,e:=s.load(ctx,tx,q.CallID,true);if e!=nil{return e}
  if prior,e:=s.prior(ctx,tx,p,q,hash);e!=nil{return e}else if prior!=nil{result=*prior;return nil}
  epoch,_:=strconv.ParseUint(string(q.Epoch),10,64);revision,_:=strconv.ParseUint(string(q.Revision),10,64)
  if epoch!=r.Epoch{return ErrConflict}
  var instance string
  e=tx.QueryRowContext(ctx,"SELECT instance_id FROM chat_call_legs WHERE call_id=? AND actor_id=? FOR UPDATE",string(q.CallID),string(p.ActorID)).Scan(&instance)
  if action=="accept"&&e==sql.ErrNoRows&&p.ActorID==r.CalleeID&&r.State==Ringing{
   raw,_:=json.Marshal(p)
   _,e=tx.ExecContext(ctx,"INSERT INTO chat_call_legs(call_id,actor_id,instance_id,principal_json,last_seen) VALUES(?,?,?,?,?)",string(q.CallID),string(p.ActorID),q.Instance,raw,time.Now().Unix());instance=q.Instance
  }
  if e!=nil||instance!=q.Instance{return ErrConflict}
  now:=time.Now().Unix()
  switch action{
  case "pulse","ready","prepared":
   if r.State.Terminal()||r.State==Stopping{return ErrConflict}
   raw,_:=json.Marshal(p)
   if action=="prepared"{if _,e=tx.ExecContext(ctx,"UPDATE chat_call_legs SET prepared=1 WHERE call_id=? AND actor_id=?",string(q.CallID),string(p.ActorID));e!=nil{return e};if e=tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_call_legs WHERE call_id=? AND prepared=1",string(q.CallID)).Scan(&r.Prepared);e!=nil{return e}}
   ready:=0;if action=="ready"{ready=1}
   _,e=tx.ExecContext(ctx,"UPDATE chat_call_legs SET principal_json=?,last_seen=?,ready=GREATEST(ready,?) WHERE call_id=? AND actor_id=? AND instance_id=?",raw,now,ready,string(q.CallID),string(p.ActorID),q.Instance)
   if e!=nil{return e}
   if action=="ready"&&r.State==Connecting{
    var count int
    if e=tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_call_legs WHERE call_id=? AND ready=1",string(q.CallID)).Scan(&count);e!=nil{return e}
    if count==2{r.Session,e=r.Apply(Event{Name:"media_ready",Epoch:r.Epoch,ExpectedRevision:r.Revision,At:now});if e!=nil{return e};if e=s.save(ctx,tx,r,"active");e!=nil{return e}}
   }
  case "accept","reject","end":
   name:=action;if action=="end"&&r.State==Ringing{if p.ActorID==r.CallerID{name="cancel"}else{name="reject"}}
   r.Session,e=r.Apply(Event{Name:name,ActorID:p.ActorID,Epoch:epoch,ExpectedRevision:revision,At:now});if e!=nil{return e}
   if e=s.save(ctx,tx,r,name);e!=nil{return e}
  default:return ErrInvalid
  }
  result=r.view();return s.remember(ctx,tx,p,q,hash,result)
 })
 return result,e
}
func(s *Service)Status(ctx context.Context,p ports.Principal,q Request)(View,error){
 var out View;if !positive(q.CallID){return out,ErrInvalid}
 e:=s.tx(ctx,func(tx *sql.Tx)error{r,e:=s.load(ctx,tx,q.CallID,false);if e!=nil{return e};if e=s.authorized(ctx,tx,p,r,false);e!=nil{return e};out=r.view();return nil})
 return out,e
}
func(s *Service)Inbox(ctx context.Context,p ports.Principal)([]View,error){
 if e:=s.guard.Validate(ctx,p);e!=nil{return nil,e}
 rows,e:=s.db.QueryContext(ctx,"SELECT CAST(id AS CHAR) FROM chat_call_sessions WHERE (caller_id=? OR callee_id=?) AND state IN ('ringing','preparing','connecting','active','stopping') ORDER BY id DESC LIMIT 4",string(p.ActorID),string(p.ActorID))
 if e!=nil{return nil,e};ids:=[]ports.ID{}
 for rows.Next(){var id ports.ID;if e=rows.Scan(&id);e!=nil{rows.Close();return nil,e};ids=append(ids,id)};e=rows.Err();rows.Close();if e!=nil{return nil,e}
 out:=[]View{}
 for _,id:=range ids{v,e:=s.Status(ctx,p,Request{CallID:id});if e==nil{out=append(out,v)}else if errors.Is(e,ports.ErrUnavailable){return nil,e}}
 return out,nil
}
func(s *Service)History(ctx context.Context,p ports.Principal,q Request)([]View,error){
 if e:=s.guard.Validate(ctx,p);e!=nil{return nil,e}
 if !positive(q.ConversationID)||(q.Before!=""&&!positive(q.Before)){return nil,ErrInvalid}
 before:=q.Before;if before==""{before="18446744073709551615"}
 out:=[]View{}
 e:=s.tx(ctx,func(tx *sql.Tx)error{
  rows,e:=tx.QueryContext(ctx,"SELECT CAST(id AS CHAR) FROM chat_call_sessions WHERE conversation_id=? AND (caller_id=? OR callee_id=?) AND id<? ORDER BY id DESC LIMIT 20",string(q.ConversationID),string(p.ActorID),string(p.ActorID),string(before))
  if e!=nil{return e};ids:=[]ports.ID{};for rows.Next(){var id ports.ID;if e=rows.Scan(&id);e!=nil{rows.Close();return e};ids=append(ids,id)};e=rows.Err();rows.Close();if e!=nil{return e}
  for _,id:=range ids{r,e:=s.load(ctx,tx,id,false);if e!=nil{return e};if e=s.authorized(ctx,tx,p,r,false);e!=nil{continue};out=append(out,r.view())}
  return nil
 });return out,e
}
func(s *Service)Prepare(ctx context.Context,p ports.Principal,q Request)(any,error){
 if !positive(q.CallID)||!positive(q.Epoch)||!instancePattern.MatchString(q.Instance){return nil,ErrInvalid}
 var snapshot record
 e:=s.tx(ctx,func(tx *sql.Tx)error{
  r,e:=s.load(ctx,tx,q.CallID,false);if e!=nil{return e};if e=s.authorized(ctx,tx,p,r,true);e!=nil{return e}
  if string(q.Epoch)!=strconv.FormatUint(r.Epoch,10)||(r.State!=Preparing&&r.State!=Connecting&&r.State!=Active){return ErrConflict}
  var instance string
  if e=tx.QueryRowContext(ctx,"SELECT instance_id FROM chat_call_legs WHERE call_id=? AND actor_id=?",string(q.CallID),string(p.ActorID)).Scan(&instance);e!=nil||instance!=q.Instance{return ErrConflict}
  snapshot=r;return nil
 });if e!=nil{return nil,e}
 if snapshot.Binding.NextPool!=nil{if e=s.completeFallback(ctx,snapshot);e!=nil{return nil,e};return nil,ErrConflict}
 action:="reserve";if snapshot.Binding.Submitted{action="status"}
 permit,e:=s.broker.Resource(ctx,resourceRequest(snapshot,action,"initial"));if e!=nil{
  if action=="reserve"&&knownReserveFailure(e)&&!snapshot.FallbackUsed{
   if switchErr:=s.beginFallback(ctx,snapshot);switchErr==nil{return nil,ErrConflict}
  };return nil,e
 }
 if !validPermit(snapshot,permit)||permit.State!="active"||permit.Until<=time.Now().Unix()+2{return nil,ErrUnavailable}
 e=s.tx(ctx,func(tx *sql.Tx)error{
  if e:=s.authorized(ctx,tx,p,snapshot,true);e!=nil{return e}
  r,e:=s.load(ctx,tx,q.CallID,true);if e!=nil{return e};if r.Binding.NextPool!=nil||r.Epoch!=snapshot.Epoch||(r.State!=Preparing&&r.State!=Connecting&&r.State!=Active){return ErrConflict}
  event:="";if r.State==Preparing{event="media_start";r.Session,e=r.Apply(Event{Name:"media_start",Epoch:r.Epoch,ExpectedRevision:r.Revision,At:time.Now().Unix()});if e!=nil{return e}}
  r.Binding.Permit=permit;r.Binding.Submitted=true
  if e=s.save(ctx,tx,r,event);e!=nil{return e};snapshot=r;return nil
 });if e!=nil{return nil,e}
 // Persisted Connecting precedes all media credentials. A lost SDK response
 // can never be mistaken for "not submitted" or trigger cross-provider retry.
 credentials,e:=s.provider.Credentials(ctx,snapshot.Session,permit,p.ActorID)
 if e!=nil{return nil,e}
 return map[string]any{"call":snapshot.view(),"credentials":credentials},nil
}
func resourceRequest(r record,action,window string)ResourceRequest{
 op:=sha256String(string(r.CallID)+":"+strconv.FormatUint(r.Epoch,10)+":"+string(r.Binding.Pool.ID)+":"+action+":"+window)
 return ResourceRequest{action,op,r.CallID,ports.ID(strconv.FormatUint(r.Epoch,10)),r.Binding.Pool.ID,r.Media,r.CallerID,r.CalleeID}
}
func validPermit(r record,p Permit)bool{return p.CallID==r.CallID&&string(p.Epoch)==strconv.FormatUint(r.Epoch,10)&&p.PoolID==r.Binding.Pool.ID&&p.Provider==r.Provider&&positive(p.Revision)&&p.AppID!=""}
func sha256String(value string)string{sum:=sha256.Sum256([]byte(value));return hex.EncodeToString(sum[:])}

func knownReserveFailure(e error)bool{
 switch e.Error(){case "CALL_RESOURCE_exhausted","CALL_RESOURCE_busy","CALL_RESOURCE_unavailable","CALL_RESOURCE_safety_insufficient","CALL_RESOURCE_CYCLE_END","CALL_RESOURCE_UNAVAILABLE","CALL_RESOURCE_disabled","CALL_RESOURCE_waiting_verification":return true};return false
}
func(s *Service)beginFallback(ctx context.Context,r record)error{
 next,settings,e:=s.selectPool(ctx,r.Media,r.Provider);if e!=nil{return e};if !settings.Config.AllowFallback||next.Decision.Provider==r.Provider{return ErrUnavailable}
 e=s.tx(ctx,func(tx *sql.Tx)error{
  live,e:=s.load(ctx,tx,r.CallID,true);if e!=nil{return e}
  if live.Epoch!=r.Epoch||live.State!=Preparing||live.Binding.Submitted||live.FallbackUsed{return ErrConflict}
  live.Binding.NextPool=&next
  if e=s.save(ctx,tx,live,"");e!=nil{return e};r=live;return nil
 });if e!=nil{return e};return s.completeFallback(ctx,r)
}
func(s *Service)completeFallback(ctx context.Context,r record)error{
 if r.Binding.NextPool==nil||r.State!=Preparing||r.Binding.Submitted{return ErrConflict}
 // This durable tombstone fences an in-flight old reserve before either end
 // can get new credentials. A lost ACK is retried with the same operation.
 stopped,e:=s.broker.Resource(ctx,resourceRequest(r,"release_unsubmitted","fallback"))
 if e!=nil||!validPermit(r,stopped)||stopped.State!="closed"{return ErrUnavailable}
 return s.tx(ctx,func(tx *sql.Tx)error{
  live,e:=s.load(ctx,tx,r.CallID,true);if e!=nil{return e}
  if live.Epoch!=r.Epoch||live.State!=Preparing||live.Binding.Submitted||live.Binding.NextPool==nil{return ErrConflict}
  target:=*live.Binding.NextPool
  live.Session,e=live.Apply(Event{Name:"fallback",Epoch:live.Epoch,ExpectedRevision:live.Revision,At:time.Now().Unix(),NextProvider:target.Decision.Provider});if e!=nil{return e}
  live.Binding=Binding{Pool:target}
  _,e=tx.ExecContext(ctx,"UPDATE chat_call_legs SET prepared=0,ready=0 WHERE call_id=?",string(r.CallID));if e!=nil{return e}
  return s.save(ctx,tx,live,"fallback")
 })
}
