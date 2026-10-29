package calls

import (
 "context"
 "errors"
 "encoding/json"
 "strings"
 "testing"
 "time"
 token "github.com/franz-9527/workers/chat/internal/agoratoken/accesstoken2"
)

func TestAdministratorCredentialsResolveAndFailClosed(t *testing.T) {
 value := ProviderCredentials{Schema:1, Managed:true, AppID:strings.Repeat("a",32),Certificate:strings.Repeat("b",32), CustomerID:"fixture-rest-id",CustomerSecret:"fixture-rest-secret",PublishPrivilegesVerified:true}
 unavailable := false
 p := &Providers{Environment:"test",AppID:strings.Repeat("c",32),Certificate:strings.Repeat("d",32), CustomerID:"legacy", CustomerSecret:"legacy",PublishPrivilegesVerified:true}
 p.Resolve = func(context.Context)(ProviderCredentials,error){
  if unavailable {return ProviderCredentials{},errors.New("fixture transport failure")}
  return value,nil
 }
 session,_ := NewSession("1","2","11","12",Shengwang,Audio,time.Now().Unix(),time.Now().Unix()+30)
 permit := Permit{CallID:"1",Epoch:"1",PoolID:"1",AppID:value.AppID,Until:time.Now().Unix()+40}
 verify := func(certificate string) {
  t.Helper()
  if !p.Ready(Shengwang){t.Fatal("saved credentials did not enable verified provider")}
  raw,err:=p.Credentials(context.Background(),session,permit,"11");if err!=nil{t.Fatal(err)}
  output,_:=json.Marshal(raw)
  for _,secret:=range []string{value.Certificate,value.CustomerID,value.CustomerSecret}{
   if strings.Contains(string(output),secret){t.Fatal("provider secret leaked into client credentials")}
  }
  parsed:=token.NewAccessToken("","",0)
  ok,err:=parsed.Parse(raw.(map[string]any)["token"].(string));if err!=nil||!ok{t.Fatal("token parse")}
  ok,err=parsed.VerifySignature(certificate);if err!=nil||!ok{t.Fatal("saved certificate not used")}
 }
 verify(value.Certificate)
 value.Certificate=strings.Repeat("e",32)
 verify(value.Certificate)
 value.PublishPrivilegesVerified=false
 if p.Ready(Shengwang){t.Fatal("verification fence bypassed")}
 if _,err:=p.Credentials(context.Background(),session,permit,"11");err==nil{t.Fatal("unverified credentials issued")}
 value.PublishPrivilegesVerified=true
 unavailable=true
 if p.Ready(Shengwang){t.Fatal("transport error fell back to stale environment credentials")}
 if _,err:=p.Credentials(context.Background(),session,permit,"11");err==nil{t.Fatal("transport failure issued credentials")}
 if stopped,err:=p.Stop(context.Background(),session,permit);stopped||err==nil{t.Fatal("transport failure released exposure")}
 if !p.Ready(Wechat){t.Fatal("unrelated WeChat provider blocked")}
 unavailable=false
 value=ProviderCredentials{Schema:1,Managed:true}
 if p.Ready(Shengwang){t.Fatal("explicit clear restored legacy credentials")}
 value=ProviderCredentials{Schema:1,Managed:false}
 if !p.Ready(Shengwang){t.Fatal("pre-migration environment compatibility lost")}
 value.Schema=0
 if p.Ready(Shengwang){t.Fatal("malformed internal response accepted")}
}
