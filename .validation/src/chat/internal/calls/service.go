package calls

import(
 "context"
 "crypto/sha256"
 "database/sql"
 "encoding/hex"
 "encoding/json"
 "errors"
 "fmt"
 "regexp"
 "strconv"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
)

var ErrBusy=errors.New("CALL_BUSY")
var ErrUnavailable=errors.New("CALL_UNAVAILABLE")
type Meter struct { Numerator int64 `json:"rate_numerator"`;Denominator int64 `json:"rate_denominator"`;Quantum int64 `json:"quantum_seconds"` }
type Pool struct { ID ports.ID `json:"pool_id,omitempty"`;Decision ResourceDecision `json:"decision"`;Audio Meter `json:"audio"`;Video Meter `json:"video"` }
type Settings struct{
 Revision ports.ID `json:"revision"`
 Config struct{
  Enabled bool `json:"enabled"`;AudioEnabled bool `json:"audio_enabled"`;VideoEnabled bool `json:"video_enabled"`
  AllowFallback bool `json:"allow_fallback"`;AudioPriority [2]Provider `json:"audio_priority"`;VideoPriority [2]Provider `json:"video_priority"`
  Window int64 `json:"window_seconds"`;Margin int64 `json:"stop_margin_seconds"`;Heartbeat int64 `json:"heartbeat_seconds"`;MaxActive int `json:"max_active_calls"`
 } `json:"config"`
}
type Availability struct{Settings Settings `json:"settings"`;Pools []Pool `json:"pools"`}
type ResourceRequest struct{
 Action string `json:"action"`;Operation string `json:"operation_id"`;CallID ports.ID `json:"call_id,omitempty"`;Epoch ports.ID `json:"attempt_epoch,omitempty"`
 PoolID ports.ID `json:"pool_id"`;Media Media `json:"media"`;CallerID ports.ID `json:"caller_id"`;CalleeID ports.ID `json:"callee_id"`
}
type Permit struct{CallID ports.ID `json:"call_id"`;Epoch ports.ID `json:"attempt_epoch"`;PoolID ports.ID `json:"pool_id"`;State string `json:"state"`;Provider Provider `json:"provider"`;AppID string `json:"app_id"`;Until int64 `json:"authorized_until"`;Revision ports.ID `json:"config_revision,omitempty"`}
type Broker interface{Availability(context.Context)(Availability,error);Resource(context.Context,ResourceRequest)(Permit,error);Identities(context.Context,ResourceRequest)(map[string]NativePerson,error)}
type NativePerson struct{OpenID string `json:"openid"`;Nickname string `json:"nickname"`}
type MediaProvider interface{Ready(Provider)bool;Credentials(context.Context,Session,Permit,ports.ID)(any,error);Stop(context.Context,Session,Permit)(bool,error)}
type Binding struct {Pool Pool `json:"pool"`;Permit Permit `json:"permit"`;Submitted bool `json:"submitted"`}
type record struct{Session;Binding Binding;CallerKey,CalleeKey string;Prepared int;Created int64}
type View struct{
 CallID ports.ID `json:"call_id"`;ConversationID ports.ID `json:"conversation_id"`;CallerID ports.ID `json:"caller_id"`;CalleeID ports.ID `json:"callee_id"`
 Provider Provider `json:"provider"`;Media Media `json:"media"`;State State `json:"state"`
 Epoch string `json:"attempt_epoch"`;Revision string `json:"revision"`;RingExpires int64 `json:"ring_expires_at"`;Until int64 `json:"authorized_until"`;ServerTime int64 `json:"server_time"`;Prepared int `json:"prepared_count"`;Created int64 `json:"created_at"`
}
func(r record)view()View{return View{r.CallID,r.ConversationID,r.CallerID,r.CalleeID,r.Provider,r.Media,r.State,strconv.FormatUint(r.Epoch,10),strconv.FormatUint(r.Revision,10),r.RingExpiresAt,r.Binding.Permit.Until,time.Now().Unix(),r.Prepared,r.Created}}
type Request struct{
 ConversationID ports.ID `json:"conversation_id,omitempty"`;PeerID ports.ID `json:"peer_id,omitempty"`;CallID ports.ID `json:"call_id,omitempty"`
 Operation string `json:"operation_id,omitempty"`;Instance string `json:"instance_id,omitempty"`;Epoch ports.ID `json:"attempt_epoch,omitempty"`;Revision ports.ID `json:"revision,omitempty"`
 Media Media `json:"media,omitempty"`;Before ports.ID `json:"before_id,omitempty"`
}
type Service struct{db *sql.DB;guard Authority;broker Broker;provider MediaProvider}
func NewService(db *sql.DB,g Authority,b Broker,p MediaProvider)(*Service,error){
 if db==nil||g==nil||b==nil||p==nil{return nil,ErrInvalid};return &Service{db,g,b,p},nil
}
func(s *Service)tx(ctx context.Context,fn func(*sql.Tx)error)error{
 tx,e:=s.db.BeginTx(ctx,&sql.TxOptions{Isolation:sql.LevelReadCommitted});if e!=nil{return ports.ErrUnavailable};defer tx.Rollback()
 if e=fn(tx);e!=nil{return e};if tx.Commit()!=nil{return ports.ErrUnavailable};return nil
}
func(s *Service)load(ctx context.Context,tx *sql.Tx,id ports.ID,lock bool)(record,error){
 var r record;var model,binding []byte
 query:="SELECT model_json,resource_json,caller_member_key,callee_member_key,created_at FROM chat_call_sessions WHERE id=?";if lock{query+=" FOR UPDATE"}
 e:=tx.QueryRowContext(ctx,query,string(id)).Scan(&model,&binding,&r.CallerKey,&r.CalleeKey,&r.Created)
 if e!=nil{return r,e};if json.Unmarshal(model,&r.Session)!=nil||json.Unmarshal(binding,&r.Binding)!=nil||!r.Session.valid(){return r,ErrInvalid}
 if e=tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_call_legs WHERE call_id=? AND prepared=1",string(id)).Scan(&r.Prepared);e!=nil{return r,e}
 return r,nil
}
func(s *Service)authorized(ctx context.Context,tx *sql.Tx,p ports.Principal,r record,send bool)error{
 peer:=r.CalleeID;ownKey,otherKey:=r.CallerKey,r.CalleeKey
 if p.ActorID==r.CalleeID{peer=r.CallerID;ownKey,otherKey=r.CalleeKey,r.CallerKey}else if p.ActorID!=r.CallerID{return ErrInvalid}
 own,other,e:=s.guard.Pair(ctx,tx,p,r.ConversationID,peer,send);if e!=nil{return e}
 if own.MemberKey!=ownKey||other.MemberKey!=otherKey{return ErrConflict};return nil
}
func(s *Service)save(ctx context.Context,tx *sql.Tx,r record,event string,evidence ...string)error{
 model,_:=json.Marshal(r.Session);binding,_:=json.Marshal(r.Binding)
 _,e:=tx.ExecContext(ctx,"UPDATE chat_call_sessions SET state=?,revision=?,attempt_epoch=?,model_json=?,resource_json=?,updated_at=? WHERE id=?",r.State,r.Revision,r.Epoch,model,binding,time.Now().Unix(),string(r.CallID))
 if e!=nil{return e}
 if event!=""{var proof any;if len(evidence)==1{proof=evidence[0]};_,e=tx.ExecContext(ctx,"INSERT INTO chat_call_events(call_id,revision,attempt_epoch,event_type,evidence_hash,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))",string(r.CallID),r.Revision,r.Epoch,event,proof)}
 return e
}
func(s *Service)accounts(ctx context.Context,tx *sql.Tx,ids ...ports.ID)error{
 if len(ids)==2&&lessID(ids[1],ids[0]){ids[0],ids[1]=ids[1],ids[0]}
 for _,id:=range ids{
  if _,e:=tx.ExecContext(ctx,"INSERT INTO chat_call_accounts(actor_id,updated_at) VALUES(?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=actor_id",string(id));e!=nil{return e}
  var active sql.NullString
  if e:=tx.QueryRowContext(ctx,"SELECT CAST(active_call_id AS CHAR) FROM chat_call_accounts WHERE actor_id=? FOR UPDATE",string(id)).Scan(&active);e!=nil{return e}
  if id!="0"&&active.Valid{return ErrBusy}
 };return nil
}
var opPattern=regexp.MustCompile("^[a-f0-9]{64}$")
var instancePattern=regexp.MustCompile("^[a-f0-9]{32}$")
func operation(action string,q Request)string{b,_:=json.Marshal(q);v:=sha256.Sum256(append([]byte(action+"\n"),b...));return hex.EncodeToString(v[:])}
func(s *Service)prior(ctx context.Context,tx *sql.Tx,p ports.Principal,q Request,hash string)(*View,error){
 var fingerprint string;var raw []byte
 e:=tx.QueryRowContext(ctx,"SELECT fingerprint,response_json FROM chat_call_operations WHERE actor_id=? AND operation_id=?",string(p.ActorID),q.Operation).Scan(&fingerprint,&raw)
 if e==sql.ErrNoRows{return nil,nil};if e!=nil{return nil,e};if fingerprint!=hash{return nil,ErrConflict}
 var v View;if json.Unmarshal(raw,&v)!=nil{return nil,ErrInvalid};return &v,nil
}
func(s *Service)remember(ctx context.Context,tx *sql.Tx,p ports.Principal,q Request,hash string,v View)error{
 b,_:=json.Marshal(v);_,e:=tx.ExecContext(ctx,"INSERT INTO chat_call_operations(actor_id,operation_id,fingerprint,call_id,response_json,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))",string(p.ActorID),q.Operation,hash,string(v.CallID),b);return e
}
func(s *Service)selectPool(ctx context.Context,media Media)(Pool,Settings,error){
 a,e:=s.broker.Availability(ctx);if e!=nil{return Pool{},a.Settings,e}
 chosen:=map[Provider]Pool{}
 for _,p:=range a.Pools{if !positive(p.ID)||!p.Decision.valid(){return Pool{},a.Settings,ErrInvalid};if _,ok:=chosen[p.Decision.Provider];!ok{chosen[p.Decision.Provider]=p}}
 decisions:=[]ResourceDecision{};costs:=map[Provider]int64{Wechat:1,Shengwang:1}
 for _,provider:=range []Provider{Wechat,Shengwang}{if p,ok:=chosen[provider];ok{
  if !s.provider.Ready(provider){p.Decision.Status="disabled";p.Decision.AvailableUnits="0"}
  meter:=p.Audio;if media==Video{meter=p.Video};cost,e:=windowCost(meter,a.Settings.Config.Window+a.Settings.Config.Margin)
  if e!=nil{return Pool{},a.Settings,e};costs[provider]=cost;decisions=append(decisions,p.Decision)
 }}
 c:=a.Settings.Config
 if c.Window<30||c.Margin<15||c.MaxActive<1{return Pool{},a.Settings,ErrInvalid}
 route,e:=SelectProvider(RoutingSettings{c.Enabled,c.AudioEnabled,c.VideoEnabled,c.AllowFallback,c.AudioPriority,c.VideoPriority},media,decisions,costs,time.Now().Unix())
 if e!=nil{return Pool{},a.Settings,e};if route.Provider==""{return Pool{},a.Settings,fmt.Errorf("CALL_%s",route.Reason)}
 return chosen[route.Provider],a.Settings,nil
}
func windowCost(m Meter,seconds int64)(int64,error){
 if m.Numerator<1||m.Denominator<1||m.Quantum<1||m.Numerator>1000000||m.Denominator>1000000||m.Quantum>3600||seconds<1||seconds>10000000{return 0,ErrInvalid}
 rounded:=((seconds+m.Quantum-1)/m.Quantum)*m.Quantum
 if rounded>9223372036854775807/m.Numerator/200{return 0,ErrInvalid}
 per:=((rounded*m.Numerator*100)+m.Denominator-1)/m.Denominator
 return per*2,nil
}
