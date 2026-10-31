package message

import (
 "encoding/json"
 "io"
 "net/http"
 "net/http/httptest"
 "strings"
 "testing"
)

func TestMySQLS09HTTPAuthorizationAndRead(t *testing.T){
 f,_,h,r:=historyFixture(t,8)
 f.exec(`UPDATE chat_messages SET sender_id=10203 WHERE conversation_id=? AND seq IN(2,6)`,string(r.ConversationID))
 handler,e:=NewHistoryHandler(h,historyFixtureIdentity{},4);if e!=nil{t.Fatal(e)}
 server:=httptest.NewServer(handler);defer server.Close()
 call:=func(path,body,credential string,status int) []byte {
  t.Helper()
  req,e:=http.NewRequest("POST",server.URL+path,strings.NewReader(body));if e!=nil{t.Fatal(e)}
  req.Header.Set("Content-Type","application/json")
  if credential!=""{req.Header.Set("Authorization","Bearer "+credential)}
  response,e:=server.Client().Do(req);if e!=nil{t.Fatal(e)};defer response.Body.Close()
  raw,_:=io.ReadAll(response.Body)
  if response.StatusCode!=status{t.Fatalf("%s: got %d want %d: %s",path,response.StatusCode,status,raw)}
  if response.Header.Get("Cache-Control")!="no-store"||response.Header.Get("X-Request-ID")==""{t.Fatal("missing safe headers")}
  return raw
 }
 base:=`{"conversation_id":"`+string(r.ConversationID)+`"}`
 call("/chat/api/v1/conversations/unread",base,"",401)
 call("/chat/api/v1/conversations/unread",base,"bad",401)
 call("/chat/api/v1/conversations/unread",`{"conversation_id":"1","conversation_id":"2"}`,"fixture-only",400)
 data:=call("/chat/api/v1/conversations/unread",base,"fixture-only",200)
 var unread struct{Data UnreadWindow `json:"data"`}
 if json.Unmarshal(data,&unread)!=nil||unread.Data.Count!=2||unread.Data.FirstSeq!="2"{t.Fatal("unread",string(data))}
 call("/chat/api/v1/conversations/read",`{"conversation_id":"`+string(r.ConversationID)+`","cursor":"forged"}`,"fixture-only",409)
 signed:=readPage(t,h,HistoryRequest{ConversationID:r.ConversationID},"bootstrap").SyncCursor
 call("/chat/api/v1/conversations/read",`{"conversation_id":"`+string(r.ConversationID)+`","cursor":"`+signed+`"}`,"fixture-only",200)
 data=call("/chat/api/v1/conversations/unread",base,"fixture-only",200)
 if json.Unmarshal(data,&unread)!=nil||unread.Data.Count!=0||unread.Data.ReadSeq!="8"{t.Fatal("not acknowledged",string(data))}
 data=call("/chat/api/v1/conversations/digests",`{"limit":20}`,"fixture-only",200)
 var dig struct{Data DigestPage `json:"data"`}
 if json.Unmarshal(data,&dig)!=nil||len(dig.Data.Items)==0{t.Fatal("digest",string(data))}
 call("/chat/api/v1/conversations/digests",`{"limit":20,"cursor":"bad"}`,"fixture-only",409)
 data=call("/chat/api/v1/conversations/preferences",base,"fixture-only",200)
 var pref struct{Data Preferences `json:"data"`};if json.Unmarshal(data,&pref)!=nil||pref.Data.Later{t.Fatal("default preference",string(data))}
 update:=`{"conversation_id":"`+string(r.ConversationID)+`","member_version":"`+pref.Data.MemberVersion+`","field":"later","value":true,"expected_version":"0"}`
 call("/chat/api/v1/conversations/preference",update,"fixture-only",200)
 call("/chat/api/v1/conversations/preference",update,"fixture-only",200)
 call("/chat/api/v1/conversations/preference",strings.Replace(update,`"value":true`,`"value":false`,1),"fixture-only",409)
 call("/chat/api/v1/conversations/preferences",`{"conversation_id":"1","hidden":true}`,"fixture-only",400)
 data=call("/chat/api/v1/conversations/unread",base,"fixture-only",200)
 if json.Unmarshal(data,&unread)!=nil||unread.Data.Count!=0||!unread.Data.Preferences.Later{t.Fatal("preference/read isolation",string(data))}
 f.exec(`UPDATE login_sessions SET status='revoked' WHERE id=10202`)
 call("/chat/api/v1/conversations/unread",base,"fixture-only",403)
}
