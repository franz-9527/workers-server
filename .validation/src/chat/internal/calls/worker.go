package calls
import(
 "context"
 "database/sql"
 "encoding/json"
 "strconv"
 "time"
 "sync"
 "github.com/franz-9527/workers/chat/internal/ports"
)
func(s *Service)Available(ctx context.Context,p ports.Principal,q Request)(any,error){
 if !positive(q.ConversationID){return nil,ErrInvalid}
 rows,e:=s.db.QueryContext(ctx,"SELECT CAST(actor_id AS CHAR) FROM chat_members WHERE conversation_id=? AND actor_id<>? AND can_read=1 AND can_send=1 ORDER BY actor_id LIMIT 50",string(q.ConversationID),string(p.ActorID))
 if e!=nil{return nil,e};peers:=[]ports.ID{};for rows.Next(){var id ports.ID;if e=rows.Scan(&id);e!=nil{rows.Close();return nil,e};peers=append(peers,id)};e=rows.Err();rows.Close();if e!=nil{return nil,e}
 if len(peers)==0{return map[string]any{"audio":false,"video":false,"reason":"no_peer","peers":[]ports.ID{}},s.guard.Validate(ctx,p)}
 e=s.tx(ctx,func(tx *sql.Tx)error{own,other,e:=s.guard.Pair(ctx,tx,p,q.ConversationID,peers[0],false);if e!=nil{return e};if !own.CanSend||!other.CanSend{return ErrUnavailable};return nil});if e!=nil{return nil,e}
 _,_,audio:=s.selectPool(ctx,Audio);_,_,video:=s.selectPool(ctx,Video)
 reason:="available";if audio!=nil&&video!=nil{reason="unavailable";if audio.Error()==video.Error(){reason=audio.Error()}}
 return map[string]any{"audio":audio==nil,"video":video==nil,"reason":reason,"peers":peers},nil
}
// Tick is crash-recoverable. Terminal rows keep account occupancy until PHP
// settlement ACK. Media uncertainty retains both occupancy and resource R.
func(s *Service)Tick(ctx context.Context)error{
 rows,e:=s.db.QueryContext(ctx,"SELECT CAST(s.id AS CHAR) FROM chat_call_sessions s WHERE s.state IN ('ringing','preparing','connecting','active','stopping') OR EXISTS(SELECT 1 FROM chat_call_accounts a WHERE a.active_call_id=s.id) ORDER BY CASE WHEN s.state IN ('active','connecting') THEN 0 WHEN s.state IN ('ringing','preparing') THEN 1 ELSE 2 END,s.updated_at,s.id LIMIT 100")
 if e!=nil{return e};ids:=[]ports.ID{};for rows.Next(){var id ports.ID;if e=rows.Scan(&id);e!=nil{rows.Close();return e};ids=append(ids,id)};e=rows.Err();rows.Close();if e!=nil{return e}
 availability,availabilityErr:=s.broker.Availability(ctx);emergency:=availabilityErr==nil&&!availability.Settings.Config.Enabled
 var group sync.WaitGroup;queue:=make(chan ports.ID)
 for i:=0;i<8;i++{group.Add(1);go func(){defer group.Done();for id:=range queue{if ctx.Err()==nil{_ = s.maintain(ctx,id,emergency)}}}()}
 for _,id:=range ids{if ctx.Err()!=nil{break};queue<-id};close(queue);group.Wait()
 return ctx.Err()
}
func(s *Service)maintain(ctx context.Context,id ports.ID,emergency bool)error{
 var r record
 e:=s.tx(ctx,func(tx *sql.Tx)error{var e error;r,e=s.load(ctx,tx,id,false);return e});if e!=nil{return e}
 now:=time.Now().Unix();name:=""
 // Failed stop probes rotate behind other pending calls instead of starving leases.
 _,_=s.db.ExecContext(ctx,"UPDATE chat_call_sessions SET updated_at=? WHERE id=?",now,string(id))
 if r.State==Ringing&&now>=r.RingExpiresAt{name="ring_timeout"}
 if r.State==Preparing&&now-r.LastEventAt>=30{name="media_failure"}
 if r.State==Connecting&&now-r.LastEventAt>=30{name="media_failure"}
 if r.State==Active||r.State==Connecting{
  e=s.tx(ctx,func(tx *sql.Tx)error{
   legs,e:=tx.QueryContext(ctx,"SELECT principal_json,last_seen FROM chat_call_legs WHERE call_id=? ORDER BY actor_id",string(id));if e!=nil{return e}
   type leg struct{p ports.Principal;seen int64};all:=[]leg{}
   for legs.Next(){var raw []byte;var l leg;if e=legs.Scan(&raw,&l.seen);e!=nil{legs.Close();return e};if json.Unmarshal(raw,&l.p)!=nil{legs.Close();return ErrInvalid};all=append(all,l)}
   e=legs.Err();legs.Close();if e!=nil{return e};if len(all)!=2{return ErrUnavailable}
   for _,l:=range all{if now-l.seen>30{return ErrUnavailable};if e=s.authorized(ctx,tx,l.p,r,true);e!=nil{return e}}
   return nil
  });if e!=nil{name="revoke"}
  if name==""&&r.Binding.Permit.Until<=now+20{
   permit,e:=s.broker.Resource(ctx,resourceRequest(r,"renew",strconv.FormatInt(now/15,10)))
   if e!=nil||!validPermit(r,permit)||permit.State!="active"||permit.Until<=now+5{name="resource_stop"}else{
    _=s.tx(ctx,func(tx *sql.Tx)error{
     live,e:=s.load(ctx,tx,id,true);if e!=nil{return e};if live.Epoch!=r.Epoch||live.State.Terminal()||live.State==Stopping{return ErrConflict}
     live.Binding.Permit=permit;r=live;return s.save(ctx,tx,live,"")
    })
   }
  }
 }
 if emergency&&!r.State.Terminal()&&r.State!=Stopping{name="resource_stop"}
 if name!=""{
  e=s.tx(ctx,func(tx *sql.Tx)error{
   live,e:=s.load(ctx,tx,id,true);if e!=nil{return e};if live.Revision!=r.Revision{return ErrConflict}
   live.Session,e=live.Apply(Event{Name:name,Epoch:live.Epoch,ExpectedRevision:live.Revision,At:now});if e!=nil{return e}
   if e=s.save(ctx,tx,live,name);e!=nil{return e};r=live;return nil
  });if e!=nil{return e}
 }
 if r.State==Stopping{
  stopped,e:=s.provider.Stop(ctx,r.Session,r.Binding.Permit);if e!=nil||!stopped{return ErrUnavailable}
  e=s.tx(ctx,func(tx *sql.Tx)error{
   live,e:=s.load(ctx,tx,id,true);if e!=nil{return e};if live.State!=Stopping||live.Epoch!=r.Epoch{return ErrConflict}
   live.Session,e=live.Apply(Event{Name:"media_stopped",Epoch:live.Epoch,ExpectedRevision:live.Revision,At:time.Now().Unix()});if e!=nil{return e}
   if e=s.save(ctx,tx,live,"stopped");e!=nil{return e};r=live;return nil
  });if e!=nil{return e}
 }
 if r.State.Terminal(){
  action:="release_unsubmitted";if r.Binding.Submitted{action="release"}
  permit,e:=s.broker.Resource(ctx,resourceRequest(r,action,"final"));if e!=nil||!validPermit(r,permit)||permit.State!="closed"{return ErrUnavailable}
  _,e=s.db.ExecContext(ctx,"UPDATE chat_call_accounts SET active_call_id=NULL,updated_at=UTC_TIMESTAMP(6) WHERE active_call_id=?",string(id));return e
 }
 return nil
}
