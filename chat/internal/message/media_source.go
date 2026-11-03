package message
import (
 "context"
 "database/sql"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
)
// An asynchronous upload keeps the authority generation captured at send time.
// Its access deadline may refresh only from that same persisted login/delegation;
// publication still revalidates the parent and current conversation under locks.
func mediaPrincipalExpiry(ctx context.Context,db *sql.DB,p ports.Principal)(time.Time,error){
 var expiry int64
 var err error
 if id,ok:=ports.SandboxSource(p.SourceID);ok{
  if !ports.SandboxPrincipal(p){return time.Time{},ErrRestricted}
  err=db.QueryRowContext(ctx,"SELECT UNIX_TIMESTAMP(expires_at) FROM chat_sandbox_sessions WHERE id=? AND wire_id=? AND session_version=? AND expires_at>UTC_TIMESTAMP()",id,string(p.ActorID),p.SessionVersion).Scan(&expiry)
 }else{
  err=db.QueryRowContext(ctx,"SELECT UNIX_TIMESTAMP(expires_at) FROM login_sessions WHERE id=? AND user_id=? AND status='active'",string(p.SourceID),string(p.ActorID)).Scan(&expiry)
 }
 if err==sql.ErrNoRows{return time.Time{},ErrRestricted};if err!=nil{return time.Time{},ports.ErrUnavailable}
 return time.Unix(expiry,0),nil
}
