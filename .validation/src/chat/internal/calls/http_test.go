package calls
import("testing";"strings")
func TestCallPublicProtocolRejectsAmbiguity(t *testing.T){
 good:=`{"conversation_id":"1","peer_id":"2","media":"audio","operation_id":"`+strings.Repeat("a",64)+`","instance_id":"`+strings.Repeat("b",32)+`"}`
 if _,e:=Decode("create",[]byte(good));e!=nil{t.Fatal(e)}
 for _,raw:=range []string{`{"call_id":1}`,`{"call_id":null}`,`{"call_id":"1","call_id":"2"}`,`{"call_id":"1","provider":"wechat"}`,`{"call_id":"1"}{}`,`{"call_id":"1","media_stopped":"true"}`,`[]`,`{}`,`{"call_id":"01"}`,`{"call_id":"18446744073709551616"}`}{
  if _,e:=Decode("status",[]byte(raw));e==nil{t.Errorf("accepted %s",raw)}
 }
 for _,action:=range []string{"media_stopped","fallback","resource_stop","identities","renew","release","confirmStopped"}{if _,e:=Decode(action,[]byte(`{}`));e==nil{t.Errorf("accepted private action %s",action)}}
 if knownReserveFailure(ErrUnavailable){t.Fatal("transport uncertainty must never prove no media")}
}
