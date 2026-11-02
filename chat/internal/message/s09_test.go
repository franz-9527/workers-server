package message

import (
	"encoding/json"
	"testing"
 "strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func TestS09StrictRequestDecoder(t *testing.T) {
	for _, bad := range []string{
		`{"cursor":null}`,
		`{"cursor":"\ud800"}`,
		"{\"cursor\":\"\xff\"}",
		`{"conversation_id":"1","conversation_id":"2"}`,
		`{"limit":5,"limit":6}`,
		`{"conversation_id":1}`,
		`{"conversation_id":"01"}`,
		`{"limit":2.5}`,
		`{"limit":-1}`,
		`{"actor_id":"10203"}`,
		`[]`,
		`{"limit":1} {}`,
	} {
		if _, err := decodeS09([]byte(bad)); err == nil {
			t.Fatalf("accepted %s", bad)
		}
	}
	for _, good := range []string{`{"conversation_id":"1"}`, `{"conversation_id":"1","cursor":"abc"}`, `{"limit":20}`} {
		if _, err := decodeS09([]byte(good)); err != nil {
			t.Fatalf("rejected %s: %v", good, err)
		}
	}
}

func TestS09DigestCursorBindingAndExpiry(t *testing.T) {
	s := &HistoryService{environment: "test", key: []byte("12345678901234567890123456789012")}
	p := principal("10202")
	c := digestCursor{Version: 2, PreferenceRevision: "0", Binding: bindingFor("test", p), After: "2", Upper: "99", Expires: time.Now().Add(time.Minute).Unix()}
	token, e := s.encodeDigestCursor(c)
	if e != nil {
		t.Fatal(e)
	}
	got, e := s.decodeDigestCursor(token, p)
	if e != nil || got.After != "2" {
		t.Fatal("round trip", got, e)
	}
	if _, e = s.decodeDigestCursor(token, principal("10203")); e != ErrResync {
		t.Fatal("cross account", e)
	}
	if _, e = s.decodeDigestCursor(token+"x", p); e != ErrResync {
		t.Fatal("tampered token", e)
	}
	c.Expires = time.Now().Add(-time.Second).Unix()
	expired, e := s.encodeDigestCursor(c)
	if e != nil {
		t.Fatal(e)
	}
	if _, e = s.decodeDigestCursor(expired, p); e != ErrResync {
		t.Fatal("expired", e)
	}
}

func TestS09ReadIDJSON(t *testing.T) {
	v := UnreadWindow{ReadSeq: "0", SnapshotSeq: "12", SnapshotChange: "12", PolicyVersion: "1", Count: 2}
	raw, e := json.Marshal(v)
	if e != nil {
		t.Fatal(e)
	}
	var decoded struct {
		ReadSeq ports.ID `json:"read_seq"`
	}
	if e = json.Unmarshal(raw, &decoded); e != nil || decoded.ReadSeq != "0" {
		t.Fatal(decoded, e)
	}
}

func TestS14PreferenceClosedSchema(t *testing.T){
 hash:="0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
 good:=`{"conversation_id":"9","member_version":"`+hash+`","field":"pinned","value":true,"expected_version":"0"`+`}`
 q,e:=decodePreference([]byte(good),true);if e!=nil||q.Field!="pinned"||!q.Value||q.Expected!="0"{t.Fatal(q,e)}
 for _,raw:=range []string{
  `{"conversation_id":"9","muted":true}`, `{"conversation_id":"9","hidden":true}`, `{"conversation_id":"9","actor_id":"1"}`,
  `{"conversation_id":"9","conversation_id":"10"}`, `{"conversation_id":9}`, `{"conversation_id":"09"}`,
  `{"conversation_id":"9","read_seq":"999"}`, `{"conversation_id":"9","field":"pinned","value":null}`, `{"conversation_id":"9"} {}`,
 }{if _,e:=decodePreference([]byte(raw),true);e==nil{t.Fatal("accepted write",raw)};if _,e:=decodePreference([]byte(raw),false);e==nil{t.Fatal("accepted read",raw)}}
 if _,e:=decodePreference([]byte(`{"conversation_id":"9"}`),false);e!=nil{t.Fatal(e)}
 for _,change:=range []struct{old,new string}{{"true","null"},{"true",`"true"`},{`"pinned"`,`"muted"`},{`"0"`,`"00"`},{hash,"BAD"},{`"expected_version":"0"`,`"expected_version":0`}}{
  raw:=strings.Replace(good,change.old,change.new,1);if _,e:=decodePreference([]byte(raw),true);e==nil{t.Fatal("accepted",raw)}
 }
}