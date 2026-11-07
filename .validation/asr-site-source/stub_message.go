package message
import (
 "context"
 "errors"
 "time"
)
var ErrMedia=errors.New("media")
const MaxVoiceBytes int64=1<<20
type ObjectKeeper interface{Keep(context.Context,string)error}
type AVStorage interface{
 ObjectKeeper
 Read(context.Context,string,int64)([]byte,error)
 Sign(context.Context,string,time.Duration)(string,error)
 InspectionURL(context.Context,string)(string,error)
 Delete(context.Context,string)error
 GrantAVPart(context.Context,string,int64)(string,string,error)
 PutAV(context.Context,string,[]byte,string)error
 StatAV(context.Context,string)(int64,error)
}
