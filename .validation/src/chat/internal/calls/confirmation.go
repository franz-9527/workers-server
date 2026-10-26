package calls
import(
 "context"
 "database/sql"
 "strconv"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
)
// ConfirmStopped is internal-only: PHP's current quota administrator must
// attest supplier evidence. Public SDK events cannot reach this operation.
func(s *Service)ConfirmStopped(ctx context.Context,id,epoch ports.ID,evidence string)error{
 if !positive(id)||!positive(epoch)||!opPattern.MatchString(evidence){return ErrInvalid}
 return s.tx(ctx,func(tx *sql.Tx)error{
  r,e:=s.load(ctx,tx,id,true);if e!=nil{return e}
  if strconv.FormatUint(r.Epoch,10)!=string(epoch){return ErrConflict}
  if r.State==Ended{return nil};if r.State!=Stopping{return ErrConflict}
  r.Session,e=r.Apply(Event{Name:"media_stopped",Epoch:r.Epoch,ExpectedRevision:r.Revision,At:time.Now().Unix()});if e!=nil{return e}
  return s.save(ctx,tx,r,"evidence_stopped",evidence)
 })
}
