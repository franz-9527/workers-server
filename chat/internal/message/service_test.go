package message

import (
	"fmt"
	"strings"
	"testing"
	"time"
)

func client(n int) string { return fmt.Sprintf("%d.%032x", time.Now().Unix(), n) }
func request(n int) Request {
	return Request{ConversationID: "1", ClientMessageID: client(n), MessageType: "text", Text: "施工尺寸 3.5m × 2m\n明天见 🛠️"}
}
func TestActionBoundaryAndFingerprint(t *testing.T) {
	now := time.Now().Truncate(time.Second)
	id := fmt.Sprintf("%d.%032x", now.Unix(), 1)
	if !active(id, now.Add(ActionWindow-time.Nanosecond)) || active(id, now.Add(ActionWindow)) || active(id, now.Add(-31*time.Second)) {
		t.Fatal("action window boundary")
	}
	r := request(1)
	fp, e := fingerprint(r)
	if e != nil {
		t.Fatal(e)
	}
	r.Text += " "
	other, _ := fingerprint(r)
	if fp == other {
		t.Fatal("whitespace was normalized")
	}
	r.Text = strings.Repeat("a", 2001)
	if _, e = fingerprint(r); e == nil {
		t.Fatal("character limit")
	}
	r.Text = strings.Repeat("中", 1334)
	if _, e = fingerprint(r); e == nil {
		t.Fatal("UTF8 byte limit")
	}
	for _, id := range []string{"1", client(1) + "0", "0000000000." + strings.Repeat("a", 32), strings.ToUpper(client(255))} {
		r = request(1)
		r.ClientMessageID = id
		if _, e = fingerprint(r); e == nil {
			t.Fatalf("invalid client ID %s", id)
		}
	}
}
func TestStrictJSON(t *testing.T) {
	for _, v := range []string{`"\ud83d\udee0"`, `"�"`, `"\\uD800"`} {
		if !validSurrogates([]byte(v)) {
			t.Fatal("valid Unicode rejected", v)
		}
	}
	for _, v := range []string{`"\ud800"`, `"\udc00"`, `"\ud800\u0041"`} {
		if validSurrogates([]byte(v)) {
			t.Fatal("lossy Unicode accepted", v)
		}
	}

	good := fmt.Sprintf(`{"conversation_id":"18446744073709551615","client_message_id":%q,"message_type":"text","text":"换行\n🛠"}`, client(1))
	if _, e := decode([]byte(good), false); e != nil {
		t.Fatal(e)
	}
	for _, body := range []string{strings.Replace(good, `"text":`, `"text":"x","text":`, 1), strings.Replace(good, `"text":`, `"Text":`, 1), strings.Replace(good, `"message_type":"text"`, `"message_type":null`, 1), strings.Replace(good, `"18446744073709551615"`, `18446744073709551615`, 1), good + `{}`, strings.Replace(good, `换行\n🛠`, `\ud800`, 1), `[]`, strings.Replace(good, `"text":`, `"sender_id":"42","text":`, 1)} {
		if _, e := decode([]byte(body), false); e == nil {
			t.Fatalf("accepted invalid body %s", body)
		}
	}
	if _, e := decode([]byte(good), true); e == nil {
		t.Fatal("query accepted text")
	}
}

func TestS15ReferenceFingerprintAndClosedPayloads(t *testing.T){
 q:=request(1501);base,_:=fingerprint(q);q.ReplyTo="1";reply,_:=fingerprint(q)
 q.ReplyTo="";q.EditedFrom="1";edited,_:=fingerprint(q)
 if base==reply||reply==edited||base==edited{t.Fatal("source fingerprint collision")}
 good:=fmt.Sprintf(`{"conversation_id":"1","client_message_id":%q,"message_type":"text","text":"回复","reply_to_message_id":"2"}`,client(1502))
 if _,e:=decode([]byte(good),false);e!=nil{t.Fatal(e)}
 for _,bad:=range []string{strings.Replace(good,`"2"`,`2`,1),strings.Replace(good,`"2"`,`"0"`,1),strings.Replace(good,`"2"`,`null`,1),strings.Replace(good,`"text":`,`"quote_text":"fake","text":`,1),strings.Replace(good,`"reply_to_message_id":"2"`,`"reply_to_message_id":"2","reply_to_message_id":"2"`,1)}{if _,e:=decode([]byte(bad),false);e==nil{t.Fatal("malformed source",bad)}}
 for _,bad:=range []string{`{"conversation_id":"1","message_id":"2","cursor":""}`,`{"conversation_id":"1","message_id":2}`,`{"conversation_id":"1","message_id":"2","message_id":"2"}`,`{"conversation_id":"1","message_id":"2","sender_id":"1"}`}{if _,e:=decodeRecall([]byte(bad));e==nil{t.Fatal("malformed recall",bad)}}
 if _,e:=decodeRecall([]byte(`{"conversation_id":"1","message_id":"2"}`));e!=nil{t.Fatal(e)}
}
