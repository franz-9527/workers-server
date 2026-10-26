package message

import (
 "context"
 "errors"
 "crypto/aes"
 "crypto/cipher"
 "crypto/rand"
 "crypto/sha256"
 "encoding/base64"
 "encoding/json"
 "strconv"
  "time"

 "github.com/franz-9527/workers/chat/internal/policy"
 "github.com/franz-9527/workers/chat/internal/ports"
)

type ConversationDigest struct {
 ConversationID ports.ID `json:"conversation_id"`
 Unread UnreadWindow `json:"unread"`
}
type DigestPage struct {
 BadgeCount *uint64 `json:"badge_count,omitempty"` // C only, emitted after the complete server scan
 PreferenceRevision ports.ID `json:"preference_revision"`
 Items []ConversationDigest `json:"items"`
 NextAfter ports.ID `json:"-"` // internal resume key; no unauthorized candidate IDs on the wire
 NextCursor string `json:"next_cursor,omitempty"`
 HasMore bool `json:"has_more"`
}
type digestCursor struct {
 Version int `json:"v"`
 Attention uint64 `json:"attention"`
 PreferenceRevision ports.ID `json:"preference_revision"`
 Binding string `json:"binding"`
 After ports.ID `json:"after"`
 Upper ports.ID `json:"upper"`
 Expires int64 `json:"expires"`
}

// Account cursors are authenticated AND encrypted: an otherwise valid member
// projection is only a candidate, so even its upper conversation ID is private.
func(s *HistoryService) digestAEAD() (cipher.AEAD,error){
 h:=sha256.New()
 h.Write([]byte("chat/digests/aes256gcm/v1"))
 h.Write(s.key)
 key:=h.Sum(nil)
 block,e:=aes.NewCipher(key)
 if e!=nil{return nil,e}
 return cipher.NewGCM(block)
}
func (s *HistoryService) encodeDigestCursor(c digestCursor) (string,error) {
 raw,e:=json.Marshal(c);if e!=nil{return "",ports.ErrUnavailable}
 aead,e:=s.digestAEAD();if e!=nil{return "",ports.ErrUnavailable}
 nonce:=make([]byte,aead.NonceSize())
 if _,e=rand.Read(nonce);e!=nil{return "",ports.ErrUnavailable}
 sealed:=aead.Seal(nil,nonce,raw,[]byte("chat/digests/v1"))
 return base64.RawURLEncoding.EncodeToString(append(nonce,sealed...)),nil
}
func (s *HistoryService) decodeDigestCursor(token string,p ports.Principal)(digestCursor,error){
 var c digestCursor
 if len(token)>1024{return c,ErrResync}
 payload,e:=base64.RawURLEncoding.Strict().DecodeString(token)
 if e!=nil{return c,ErrResync}
 aead,e:=s.digestAEAD();if e!=nil{return c,ports.ErrUnavailable}
 if len(payload)<aead.NonceSize()+aead.Overhead(){return c,ErrResync}
 raw,e:=aead.Open(nil,payload[:aead.NonceSize()],payload[aead.NonceSize():],[]byte("chat/digests/v1"))
 if e!=nil{return c,ErrResync}
 if json.Unmarshal(raw,&c)!=nil||c.Version!=2||!c.PreferenceRevision.Valid()||c.Binding!=bindingFor(s.environment,p)||!c.After.Valid()||!c.Upper.Valid()||number(c.After)>number(c.Upper)||c.Expires<=time.Now().Unix()||c.Expires>time.Now().Add(cursorTTL).Unix()+5{return digestCursor{},ErrResync}
 return c,nil
}
func (s *HistoryService) Digests(ctx context.Context,p ports.Principal,after ports.ID,limit int)(DigestPage,error){
 if !after.Valid()||after!="0"{return DigestPage{},ports.ErrInvalid}
 return s.DigestsCursor(ctx,p,"",limit)
}
// DigestsCursor fixes the account candidate upper ID across pages and signs
// progression for the authenticated session. Reauthorization remains live.
func (s *HistoryService) DigestsCursor(ctx context.Context,p ports.Principal,token string,limit int)(DigestPage,error){
 if !p.ActorID.Valid()||p.Environment!=s.environment||limit<1||limit>20{return DigestPage{},ports.ErrInvalid}
 if !time.Now().Before(p.ExpiresAt){return DigestPage{},policy.ErrDenied}
 ctx,cancel:=context.WithTimeout(ctx,3*s.timeout);defer cancel()
 if err:=s.conversations.ValidatePrincipal(ctx,p);err!=nil{return DigestPage{},err}
 revision,err:=accountRevision(ctx,s.db,p.ActorID);if err!=nil{return DigestPage{},err}
 var c digestCursor
 if token!=""{
  var e error;c,e=s.decodeDigestCursor(token,p);if e!=nil{return DigestPage{},e};if c.PreferenceRevision!=revision{return DigestPage{},ErrResync}
 }else{
  var upper uint64
  if e:=s.db.QueryRowContext(ctx,`SELECT COALESCE(MAX(conversation_id),0) FROM chat_members WHERE actor_id=? AND can_read=1`,string(p.ActorID)).Scan(&upper);e!=nil{return DigestPage{},ports.ErrUnavailable}
  c=digestCursor{Version:2,PreferenceRevision:revision,Binding:bindingFor(s.environment,p),After:"0",Upper:ports.ID(strconv.FormatUint(upper,10)),Expires:time.Now().Add(cursorTTL).Unix()}
 }
 page:=DigestPage{PreferenceRevision:revision,Items:[]ConversationDigest{},NextAfter:c.After}
 const maxCandidates=100
 rows,e:=s.db.QueryContext(ctx,`SELECT CAST(conversation_id AS CHAR) FROM chat_members WHERE actor_id=? AND can_read=1 AND conversation_id>? AND conversation_id<=? ORDER BY conversation_id LIMIT ?`,string(p.ActorID),string(c.After),string(c.Upper),maxCandidates+1)
 if e!=nil{return DigestPage{},ports.ErrUnavailable}
 ids:=make([]ports.ID,0,maxCandidates+1)
 for rows.Next(){var id ports.ID;if rows.Scan(&id)!=nil||!validID(id){rows.Close();return DigestPage{},ports.ErrUnavailable};ids=append(ids,id)}
 e=rows.Err();rows.Close();if e!=nil{return DigestPage{},ports.ErrUnavailable}
 for i,id:=range ids{
  if len(page.Items)==limit||i==maxCandidates{page.HasMore=true;break}
  page.NextAfter=id
  u,err:=s.Unread(ctx,p,id)
  if err != nil {
   // Only loss of this conversation's current membership may be skipped.
   // Revoked login, identity mismatch, policy errors, SQL errors or timeout
   // must fail the entire response rather than returning a partial snapshot.
   if errors.Is(err, policy.ErrDenied) {
    continue
   }
   return DigestPage{}, err
  }
  u.Preferences.AccountRevision=revision
  if u.Count>0 || u.Preferences!=nil && u.Preferences.Later { c.Attention++ }
  page.Items=append(page.Items,ConversationDigest{ConversationID:id,Unread:u})
 }
 if !page.HasMore { total:=c.Attention;page.BadgeCount=&total }
 if page.HasMore{
  c.After=page.NextAfter
  next,e:=s.encodeDigestCursor(c)
  if e!=nil{return DigestPage{},e}
  page.NextCursor=next
 }
 // Recheck at the response boundary, including empty/fully-filtered pages.
 // A logout that committed while scanning is not a successful empty list.
 if err:=s.conversations.ValidatePrincipal(ctx,p);err!=nil{return DigestPage{},err}
 latest,err:=accountRevision(ctx,s.db,p.ActorID);if err!=nil{return DigestPage{},err};if latest!=revision{return DigestPage{},ErrResync}
 if !time.Now().Before(p.ExpiresAt){return DigestPage{},policy.ErrDenied}
 return page,nil
}