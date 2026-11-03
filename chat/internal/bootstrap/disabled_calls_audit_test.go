package bootstrap
import("bytes";"context";"crypto/ed25519";"encoding/base64";"encoding/json";"net/http/httptest";"strings";"testing";"time";"github.com/franz-9527/workers/chat/internal/auth";"github.com/franz-9527/workers/chat/internal/dependencies")
type auditSessionSource struct{denied bool}
func(s *auditSessionSource)Validate(context.Context,auth.Claims)error{if s.denied{return auth.ErrUnauthorized};return nil}
func TestDisabledCallsAuthenticateAndRemainQueryable(t *testing.T){
 key:=ed25519.NewKeyFromSeed(bytes.Repeat([]byte{9},32));v,e:=auth.NewVerifier("test",map[string]ed25519.PublicKey{"audit":key.Public().(ed25519.PublicKey)});if e!=nil{t.Fatal(e)}
 source:=&auditSessionSource{};identity,e:=auth.NewService(v,source,nil,2,time.Second);if e!=nil{t.Fatal(e)}
 encode:=func(v any)string{b,_:=json.Marshal(v);return base64.RawURLEncoding.EncodeToString(b)}
 now:=time.Now().Unix();claims:=auth.Claims{Issuer:"bixuan-php:test",Audience:"bixuan-chat",ActorID:"11",Kind:"mini",SourceID:"1",SessionID:strings.Repeat("a",64),Version:strings.Repeat("b",64),Environment:"test",Scopes:[]string{"chat:use"},IssuedAt:now,NotBefore:now,ExpiresAt:now+60,JTI:strings.Repeat("c",64)}
 unsigned:=encode(map[string]string{"alg":"EdDSA","typ":"JWT","kid":"audit"})+"."+encode(claims);token:=unsigned+"."+base64.RawURLEncoding.EncodeToString(ed25519.Sign(key,[]byte(unsigned)))
 deps:=&dependencies.Runtime{Identity:identity}
 for _,test:=range []struct{path,body string;status int}{{"availability",`{"conversation_id":"1"}`,200},{"inbox","{}",200},{"create",`{"conversation_id":"1","peer_id":"22","media":"audio","operation_id":"x","instance_id":"y"}`,503}}{
 r:=httptest.NewRequest("POST","/chat/api/v1/calls/"+test.path,strings.NewReader(test.body));r.Header.Set("Content-Type","application/json");r.Header.Set("Authorization","Bearer "+token);w:=httptest.NewRecorder();disabledCalls(w,r,deps)
 if w.Code!=test.status{t.Fatalf("%s: %d %s",test.path,w.Code,w.Body.String())}
 if test.path=="availability"&&!strings.Contains(w.Body.String(),`"enabled":false`){t.Fatal(w.Body.String())}
 }
 source.denied=true;r:=httptest.NewRequest("POST","/chat/api/v1/calls/inbox",strings.NewReader("{}"));r.Header.Set("Content-Type","application/json");r.Header.Set("Authorization","Bearer "+token);w:=httptest.NewRecorder();disabledCalls(w,r,deps);if w.Code!=401{t.Fatal("revoked identity accepted",w.Code)}
}
