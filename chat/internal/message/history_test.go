package message

import (
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"testing"
	"time"
)

func TestS08CursorBindingTamperingAndExpiry(t *testing.T) {
	s := &HistoryService{key: []byte(strings.Repeat("k", 32)), environment: "test"}
	now := time.Now().Unix()
	c := historyCursor{Schema: 1, Mode: "sync", Binding: bindingFor("test", principal("10202")), Conversation: "1", Policy: "1", Member: "fixture", Seq: "18446744073709551615", Change: "0", Position: "100", Issued: now, Expiry: now + 600}
	token := s.sign(c)
	if got, e := s.parse(token, "sync"); e != nil || got.Seq != c.Seq {
		t.Fatal("valid large cursor", e)
	}
	for _, v := range []string{"", token + "a", strings.Repeat("x", 2049), strings.Replace(token, ".", "..", 1)} {
		if _, e := s.parse(v, "sync"); e != ErrResync {
			t.Fatal("forged cursor")
		}
	}
	if _, e := s.parse(token, "changes"); e != ErrResync {
		t.Fatal("cross-stream cursor")
	}
	c.Issued -= 601
	c.Expiry -= 601
	if _, e := s.parse(s.sign(c), "sync"); e != ErrResync {
		t.Fatal("expired snapshot")
	}
	if bindingFor("production", principal("10202")) == c.Binding || bindingFor("test", principal("10203")) == c.Binding {
		t.Fatal("identity collision")
	}
}
func TestS08StrictReadRequest(t *testing.T) {
	for _, body := range []string{`{"conversation_id":1}`, `{"conversation_id":"1","conversation_id":"1"}`, `{"Conversation_ID":"1"}`, `{"conversation_id":"1","limit":null}`, `{"conversation_id":"1","limit":51}`, `{"conversation_id":"1","limit":1.5}`, `{"conversation_id":"1","after_seq":"103"}`, `{"conversation_id":"1","cursor":"\ud800"}`, `{"conversation_id":"01"}`, `{"conversation_id":"1"}{}`} {
		if _, e := decodeHistory([]byte(body)); e != ports.ErrInvalid {
			t.Fatal("accepted malformed request", body)
		}
	}
	if q, e := decodeHistory([]byte(`{"conversation_id":"1","limit":50}`)); e != nil || q.Limit != 50 {
		t.Fatal(e)
	}
}
