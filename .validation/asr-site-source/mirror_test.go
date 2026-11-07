package imagepipeline
import(
 "context"
 "crypto/sha256"
 "encoding/hex"
 "io"
 "net/http"
 "os"
 "path/filepath"
 "strings"
 "testing"
 "time"
)
type rt func(*http.Request)(*http.Response,error)
func(f rt)RoundTrip(r *http.Request)(*http.Response,error){return f(r)}
func TestASRMirrorWritesVerifiedHTTPSAndCleans(t *testing.T){
 root:=t.TempDir()
 if err:=os.Chmod(root,0755);err!=nil{t.Fatal(err)}
 client:=&http.Client{Transport:rt(func(r *http.Request)(*http.Response,error){
  if r.URL.Scheme!="https"||r.URL.Host!="test.example.com"||!strings.HasPrefix(r.URL.Path,"/uploads/chat-asr/"){t.Fatalf("unexpected url %s",r.URL.String())}
  b,err:=os.ReadFile(filepath.Join(root,filepath.Base(r.URL.Path)));if err!=nil{return nil,err}
  return &http.Response{StatusCode:206,Header:http.Header{"Content-Type":[]string{"audio/mpeg"}},Body:io.NopCloser(strings.NewReader(string(b))),Request:r},nil
 })}
 body:=append([]byte("ID3"),make([]byte,200)...)
 sum:=sha256.Sum256(body);hash:=hex.EncodeToString(sum[:])
 key:="test/chat/av/test/9/version/"+hash+"/main.mp3"
 base:=&Qiniu{client:client,data:map[string][]byte{key:body}}
 storage,err:=NewASRMirrorStorage(base,root,"https://test.example.com/uploads/chat-asr",10*time.Minute)
 if err!=nil{t.Fatal(err)}
 u,err:=storage.InspectionURL(context.Background(),key);if err!=nil{t.Fatal(err)}
 if !strings.HasPrefix(u,"https://test.example.com/uploads/chat-asr/"){t.Fatal(u)}
 name:=filepath.Base(u)
 if !asrMirrorName.MatchString(name){t.Fatal(name)}
 got,err:=os.ReadFile(filepath.Join(root,name));if err!=nil||string(got)!=string(body){t.Fatal("mirror bytes",err)}
 delegated,err:=storage.InspectionURL(context.Background(),"video.mp4");if err!=nil||delegated!="https://qiniu.example/delegated"{t.Fatal("delegate",delegated,err)}
 old:=filepath.Join(root,strings.Repeat("b",64)+".mp3");if err=os.WriteFile(old,body,0644);err!=nil{t.Fatal(err)}
 past:=time.Now().Add(-11*time.Minute);if err=os.Chtimes(old,past,past);err!=nil{t.Fatal(err)}
 keep:=filepath.Join(root,"keep.txt");if err=os.WriteFile(keep,[]byte("x"),0644);err!=nil{t.Fatal(err)}
 cleaner:=storage.(interface{CleanInspection(context.Context)error})
 if err=cleaner.CleanInspection(context.Background());err!=nil{t.Fatal(err)}
 if _,err=os.Stat(old);!os.IsNotExist(err){t.Fatal("old mirror retained")}
 if _,err=os.Stat(keep);err!=nil{t.Fatal("unrelated file removed")}
}
func TestASRMirrorRejectsBadOriginAndHash(t *testing.T){
 root:=t.TempDir();_ = os.Chmod(root,0755)
 client:=&http.Client{Transport:rt(func(r *http.Request)(*http.Response,error){return nil,io.ErrUnexpectedEOF})}
 base:=&Qiniu{client:client,data:map[string][]byte{}}
 if _,err:=NewASRMirrorStorage(base,root,"http://test.example.com/uploads/chat-asr",10*time.Minute);err==nil{t.Fatal("http accepted")}
 body:=append([]byte("ID3"),make([]byte,20)...)
 key:="test/chat/av/test/9/version/"+strings.Repeat("a",64)+"/main.mp3";base.data[key]=body
 storage,err:=NewASRMirrorStorage(base,root,"https://test.example.com/uploads/chat-asr",10*time.Minute);if err!=nil{t.Fatal(err)}
 if _,err=storage.InspectionURL(context.Background(),key);err==nil{t.Fatal("hash mismatch accepted")}
 entries,_:=os.ReadDir(root);if len(entries)!=0{t.Fatal("bad file leaked")}
}
