package sourceprobe
import(
 "context"
 "io"
 "net/http"
 "strings"
)
func VerifyMP3(ctx context.Context,client *http.Client,u string) error{
 req,_:=http.NewRequestWithContext(ctx,http.MethodGet,u,nil)
 req.Header.Set("Range","bytes=0-4095")
 res,e:=client.Do(req);if e!=nil{return e};defer res.Body.Close()
 if res.StatusCode!=200&&res.StatusCode!=206{return io.ErrUnexpectedEOF}
 b,e:=io.ReadAll(io.LimitReader(res.Body,4096));if e!=nil{return e}
 if len(b)<3||string(b[:3])!="ID3"{return io.ErrUnexpectedEOF}
 if ct:=res.Header.Get("Content-Type");ct!=""&&!strings.HasPrefix(ct,"audio/"){return io.ErrUnexpectedEOF}
 return nil
}
