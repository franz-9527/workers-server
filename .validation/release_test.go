package bootstrap
import("crypto/hmac";"crypto/sha256";"encoding/base64";"encoding/hex";"encoding/json";"os";"path/filepath";"strings";"testing";"time")
func TestReleaseWithoutRecoveryEpoch(t *testing.T){
  rev:=strings.Repeat("a",40); key:=[]byte(strings.Repeat("k",32)); t.Setenv("CHAT_RELEASE_KEY",base64.StdEncoding.EncodeToString(key))
  path:=filepath.Join(t.TempDir(),"release.json"); cfg:=ReleaseConfig{File:path,KeyEnv:"CHAT_RELEASE_KEY"}
  write:=func(epoch string,expires int64,caps []string){t.Helper(); body,_:=json.Marshal(map[string]any{"schema":1,"environment":"production","epoch":epoch,"revision":rev,"expires_at":expires,"capabilities":caps}); mac:=hmac.New(sha256.New,key);mac.Write(body);raw,_:=json.Marshal(map[string]string{"payload":base64.StdEncoding.EncodeToString(body),"mac":hex.EncodeToString(mac.Sum(nil))});if err:=os.WriteFile(path,raw,0600);err!=nil{t.Fatal(err)}}
  write("",time.Now().Unix()+300,[]string{"text","governance"})
  if !cfg.Verify("production","",rev,"false","text","governance"){t.Fatal("no recovery epoch baseline should verify")}
  if cfg.Verify("production","",rev,"false","calls"){t.Fatal("unrequested calls accepted")}
  if cfg.Verify("production","",strings.Repeat("b",40),"false","text"){t.Fatal("wrong revision accepted")}
  if cfg.Verify("test","",rev,"false","text"){t.Fatal("wrong environment accepted")}
  if cfg.Verify("production","",rev,"true","text"){t.Fatal("dirty revision accepted")}
  if cfg.Verify("production",strings.Repeat("a",64),rev,"false","text"){t.Fatal("wrong epoch accepted")}
  write("",time.Now().Unix()-1,[]string{"text"});if cfg.Verify("production","",rev,"false","text"){t.Fatal("expired accepted")}
}
