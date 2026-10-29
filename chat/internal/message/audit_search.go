package message

import (
 "context"
 "crypto/rand"
 "crypto/sha256"
 "database/sql"
 "encoding/hex"
 "encoding/json"
 "strings"
 "time"
 "unicode/utf8"

 "github.com/franz-9527/workers/chat/internal/ports"
)

type AuditFilter struct {
 Conversation ports.ID `json:"conversation_id,omitempty"`
 Order ports.ID `json:"order_id,omitempty"`
 Actor ports.ID `json:"actor_id,omitempty"`
 Query string `json:"query,omitempty"`
 Kind string `json:"kind,omitempty"`
 From string `json:"from,omitempty"`
 To string `json:"to,omitempty"`
}
func (f AuditFilter) validated() (AuditFilter,error) {
 f.Query=strings.TrimSpace(f.Query)
 for _,id:=range []ports.ID{f.Conversation,f.Order,f.Actor}{if id!=""&&!validID(id){return f,ports.ErrInvalid}}
 if !utf8.ValidString(f.Query)||utf8.RuneCountInString(f.Query)>80||strings.ContainsRune(f.Query,0){return f,ports.ErrInvalid}
 if f.Kind!=""&&f.Kind!="text"&&f.Kind!="image"&&f.Kind!="audio"&&f.Kind!="video"{return f,ports.ErrInvalid}
 if f.messageFilter(){if _,_,e:=f.window();e!=nil{return f,e}}
 return f,nil
}
func (f AuditFilter) messageFilter() bool{return f.Query!=""||f.Kind!=""||f.From!=""||f.To!=""}
func (f AuditFilter) window()(time.Time,time.Time,error){
 until:=time.Now().UTC()
 var e error
 if f.To!=""{until,e=time.Parse(time.RFC3339,f.To);if e!=nil{return time.Time{},time.Time{},ports.ErrInvalid}}
 from:=until.Add(-30*24*time.Hour)
 if f.From!=""{from,e=time.Parse(time.RFC3339,f.From);if e!=nil{return time.Time{},time.Time{},ports.ErrInvalid}}
 if !from.Before(until)||until.Sub(from)>90*24*time.Hour{return from,until,ports.ErrInvalid}
 return from,until,nil
}
func (f AuditFilter) fingerprint()string{b,_:=json.Marshal(f);sum:=sha256.Sum256(b);return hex.EncodeToString(sum[:])}
func (f AuditFilter) messageSQL(alias string)(string,[]any){
 from,to,_:=f.window()
 where:="m.conversation_id="+alias+".id AND m.seq>"+alias+".sync_floor_seq AND m.seq<="+alias+".last_seq AND m.state='published' AND (m.retention_mode='permanent' OR m.expires_at>UTC_TIMESTAMP(6)) AND m.created_at>=? AND m.created_at<?"
 args:=[]any{from,to}
 if f.Query!=""{where+=" AND m.message_type='text' AND LOCATE(?,m.text_body)>0";args=append(args,f.Query)}
 if f.Kind=="image"{where+=" AND m.message_type IN ('image','image_album')"}else if f.Kind!=""{where+=" AND m.message_type=?";args=append(args,f.Kind)}
 return where,args
}
// Search materializes the same bounded, encrypted-cursor snapshot as ordinary
// audit listing. It never reads recalled/blocked text or marks member reads.
func (g *GovernanceService) AuditSearch(ctx context.Context,p ports.Principal,f AuditFilter,token,replace string,limit int)(AuditList,error){
 var err error
 f,err=f.validated();if err!=nil{return AuditList{},err}
 if limit==0{limit=20};if limit<1||limit>50||token!=""&&replace!=""{return AuditList{},ports.ErrInvalid}
 ctx,cancel:=context.WithTimeout(ctx,3*g.base.timeout);defer cancel()
 tx,err:=g.base.begin(ctx);if err!=nil{return AuditList{},err};defer tx.Rollback()
 if err=g.admin(ctx,tx,p,"chat:audit");err!=nil{return AuditList{},err}
 var c auditCursor
 binding:=bindingFor(g.environment,p)
 if token==""{
  if _,err=tx.ExecContext(ctx,"INSERT INTO chat_audit_accounts(binding,updated_at) VALUES(?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at)",binding);err!=nil{return AuditList{},ports.ErrUnavailable}
  if replace!=""{
   var previous auditCursor
   if err=g.decodeAuditCursor(replace,p,&previous);err!=nil{return AuditList{},err}
   if _,err=tx.ExecContext(ctx,"DELETE FROM chat_audit_snapshots WHERE id=? AND binding=?",previous.Snapshot,binding);err!=nil{return AuditList{},ports.ErrUnavailable}
  }
  var n int
  if tx.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_audit_snapshots WHERE binding=? AND expires_at>UTC_TIMESTAMP(6)",binding).Scan(&n)!=nil{return AuditList{},ports.ErrUnavailable}
  if n>=3{return AuditList{},ports.ErrBusy}
  var bytes [32]byte;if _,err=rand.Read(bytes[:]);err!=nil{return AuditList{},ports.ErrUnavailable}
  c.Snapshot=hex.EncodeToString(bytes[:]);c.Filter=f.fingerprint()
  if _,err=tx.ExecContext(ctx,"INSERT INTO chat_audit_snapshots(id,binding,expires_at) VALUES(?,?,UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE)",c.Snapshot,binding);err!=nil{return AuditList{},ports.ErrUnavailable}
  query:="INSERT INTO chat_audit_snapshot_items(snapshot_id,conversation_id,order_id,activity_at) SELECT ?,c.id,c.order_id,COALESCE(c.last_message_at,c.created_at) FROM chat_conversations c WHERE c.conversation_type='order'"
  args:=[]any{c.Snapshot}
  if f.Conversation!=""{query+=" AND c.id=?";args=append(args,string(f.Conversation))}
  if f.Order!=""{query+=" AND c.order_id=?";args=append(args,string(f.Order))}
  if f.Actor!=""{query+=" AND EXISTS(SELECT 1 FROM chat_members a WHERE a.conversation_id=c.id AND a.actor_id=?)";args=append(args,string(f.Actor))}
  if f.messageFilter(){where,values:=f.messageSQL("c");query+=" AND EXISTS(SELECT 1 FROM chat_messages m WHERE "+where+")";args=append(args,values...)}
  if _,err=tx.ExecContext(ctx,query,args...);err!=nil{return AuditList{},ports.ErrUnavailable}
 }else{
  if err=g.decodeAuditCursor(token,p,&c);err!=nil||c.Filter!=f.fingerprint(){return AuditList{},ErrResync}
 }
 return g.auditListPage(ctx,tx,p,token,limit,c,f)
}
func (g *GovernanceService) auditMatch(ctx context.Context,tx *sql.Tx,f AuditFilter,id ports.ID)ports.ID{
 if !f.messageFilter(){return ""}
 where,args:=f.messageSQL("c")
 query:="SELECT CAST(m.id AS CHAR) FROM chat_conversations c JOIN chat_messages m ON m.conversation_id=c.id WHERE c.id=? AND "+where+" ORDER BY m.seq DESC LIMIT 1"
 args=append([]any{string(id)},args...)
 var result ports.ID
 if tx.QueryRowContext(ctx,query,args...).Scan(&result)!=nil{return ""}
 return result
}
