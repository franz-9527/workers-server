package conversation

import (
 "context"
 "database/sql"
 "errors"
 "strconv"
 "github.com/franz-9527/workers/chat/internal/policy"
 "github.com/franz-9527/workers/chat/internal/ports"
)

// Go still owns projection writes. PHP supplies only test membership facts.
// Normal auto-increment IDs are retained; virtual business bindings never
// advance the real conversation ID sequence into the reserved test namespace.
func (s *Store) discoverSandboxLocked(ctx context.Context,tx *sql.Tx,p ports.Principal) error {
 if !ports.SandboxPrincipal(p){return policy.ErrDenied}
 rows,err:=tx.QueryContext(ctx,"SELECT CAST(r.id AS CHAR) FROM chat_sandbox_rooms r JOIN chat_sandbox_members m ON m.room_id=r.id WHERE m.wire_id=? ORDER BY r.id DESC LIMIT 100",string(p.ActorID))
 if err!=nil{return ports.ErrUnavailable}
 ids:=[]string{}
 for rows.Next(){var id string;if rows.Scan(&id)!=nil{rows.Close();return ports.ErrUnavailable};ids=append(ids,id)}
 err=rows.Err();rows.Close();if err!=nil{return ports.ErrUnavailable}
 for _,id:=range ids {
  projection,e:=s.reader.CurrentLocked(ctx,tx,"sandbox:"+id)
  if errors.Is(e,policy.ErrDenied){continue};if e!=nil{return e}
  if e=s.apply(ctx,tx,projection);e!=nil{return e}
 }
 return nil
}
func SandboxBinding(id ports.ID) string {
 n,e:=strconv.ParseUint(string(id),10,64);if e!=nil||n<=ports.SandboxBindingBase{return ""}
 return strconv.FormatUint(n-ports.SandboxBindingBase,10)
}
