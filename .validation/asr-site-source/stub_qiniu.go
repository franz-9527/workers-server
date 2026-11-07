package imagepipeline
import(
 "context"
 "errors"
 "net/http"
 "time"
)
type Qiniu struct{client *http.Client; data map[string][]byte}
func(q *Qiniu)Keep(context.Context,string)error{return nil}
func(q *Qiniu)Read(_ context.Context,key string,limit int64)([]byte,error){b:=q.data[key];if len(b)==0||int64(len(b))>limit{return nil,errors.New("missing")};return append([]byte(nil),b...),nil}
func(q *Qiniu)Sign(context.Context,string,time.Duration)(string,error){return "",nil}
func(q *Qiniu)InspectionURL(context.Context,string)(string,error){return "https://qiniu.example/delegated",nil}
func(q *Qiniu)Delete(context.Context,string)error{return nil}
func(q *Qiniu)GrantAVPart(context.Context,string,int64)(string,string,error){return "","",nil}
func(q *Qiniu)PutAV(context.Context,string,[]byte,string)error{return nil}
func(q *Qiniu)StatAV(context.Context,string)(int64,error){return 1,nil}
