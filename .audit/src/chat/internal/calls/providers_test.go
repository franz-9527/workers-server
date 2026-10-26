package calls

import(
 "context"
 "net/http"
 "io"
 "strings"
 "testing"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
 token "github.com/franz-9527/workers/chat/internal/agoratoken/accesstoken2"
)
func TestCredentialsEnforceMediaPrivilegesAndIdentity(t *testing.T){
 p:=&Providers{Environment:"test",AppID:strings.Repeat("a",32),Certificate:strings.Repeat("b",32),CustomerID:"synthetic",CustomerSecret:"synthetic"}
 session,_:=NewSession("1","2","11","12",Shengwang,Audio,time.Now().Unix(),time.Now().Unix()+30)
 permit:=Permit{CallID:"1",Epoch:"1",PoolID:"1",AppID:p.AppID,Until:time.Now().Unix()+40}
 if p.Ready(Shengwang){t.Fatal("unverified project publish privileges were enabled")}
 if _,e:=p.Credentials(context.Background(),session,permit,"11");e==nil{t.Fatal("unverified privilege fence issued a token")}
 p.PublishPrivilegesVerified=true
 for _,media:=range []Media{Audio,Video}{
  session.Media=media
  for _,actor:=range []string{"11","12"}{
   raw,e:=p.Credentials(context.Background(),session,permit,ports.ID(actor));if e!=nil{t.Fatal(e)}
   data:=raw.(map[string]any);parsed:=token.NewAccessToken("","",0)
   ok,e:=parsed.Parse(data["token"].(string));if e!=nil||!ok{t.Fatal("token parse",e)}
   ok,e=parsed.VerifySignature(p.Certificate);if e!=nil||!ok{t.Fatal("token signature",e)}
   if len(parsed.Services)!=1{t.Fatal("unexpected service scope")}
   rtc:=parsed.Services[0].(*token.ServiceRtc)
   expectedUID:="1";if actor=="12"{expectedUID="2"}
   if rtc.ChannelName!=p.channel(session,p.AppID)||rtc.Uid!=expectedUID{t.Fatal("wrong call/channel/UID binding")}
   if rtc.Privileges[token.PrivilegeJoinChannel]==0||rtc.Privileges[token.PrivilegePublishAudioStream]==0{t.Fatal("required media privilege missing")}
   _,video:=rtc.Privileges[token.PrivilegePublishVideoStream];if video!=(media==Video){t.Fatal("video privilege crossed media authorization")}
   if _,data:=rtc.Privileges[token.PrivilegePublishDataStream];data{t.Fatal("unneeded data stream privilege")}
  }
 }
 if _,e:=p.Credentials(context.Background(),session,permit,"13");e==nil{t.Fatal("another actor got credentials")}
}
type providerRoundTrip func(*http.Request)(*http.Response,error)
func(f providerRoundTrip)RoundTrip(r *http.Request)(*http.Response,error){return f(r)}
func TestSupplierStopRequiresBanAndAuthoritativeAbsence(t *testing.T){
 for _,test:=range []struct{name,ban,query string;expected bool}{
  {"confirmed",`{"status":"success"}`,`{"success":true,"data":{"channel_exist":false}}`,true},
  {"occupied",`{"status":"success"}`,`{"success":true,"data":{"channel_exist":true}}`,false},
  {"missing-proof",`{"status":"success"}`,`{"success":true,"data":{}}`,false},
  {"ban-refused",`{"status":"failed"}`,`{"success":true,"data":{"channel_exist":false}}`,false},
 }{t.Run(test.name,func(t *testing.T){
  p:=&Providers{PublishPrivilegesVerified:true,Environment:"test",AppID:strings.Repeat("a",32),Certificate:strings.Repeat("b",32),CustomerID:"synthetic",CustomerSecret:"synthetic"}
  seen:=[]string{}
  p.Client=&http.Client{Transport:providerRoundTrip(func(r *http.Request)(*http.Response,error){seen=append(seen,r.Method);body:=test.query;if r.Method=="POST"{body=test.ban};return &http.Response{StatusCode:200,Body:io.NopCloser(strings.NewReader(body)),Header:make(http.Header)},nil})}
  session,_:=NewSession("1","2","11","12",Shengwang,Audio,time.Now().Unix(),time.Now().Unix()+30)
  stopped,_:=p.Stop(context.Background(),session,Permit{AppID:p.AppID,Until:time.Now().Unix()+30})
  if stopped!=test.expected{t.Fatal("unsafe supplier stop proof")}
  if stopped&&(len(seen)!=2||seen[0]!="POST"||seen[1]!="GET"){t.Fatal("stop proof skipped supplier steps")}
  session.Provider=Wechat;if stopped,_=p.Stop(context.Background(),session,Permit{});stopped{t.Fatal("client-only native stop was trusted")}
 })}
}
