package message

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestAVLimitsAndImmutableIdentity(t *testing.T) {
	for _, v := range []struct {
		source   string
		size     int64
		duration int
	}{{"voice", 1 << 20, 60000}, {"camera", 8 << 20, 30000}, {"album", 20 << 20, 120000}, {"other", 0, 0}} {
		size, d := avLimits(v.source)
		if size != v.size || d != v.duration {
			t.Fatal(v.source)
		}
	}
	for _, size := range []int64{1, 4 << 20, (4 << 20) + 1, 20 << 20} {
		var sum int64
		for i := 1; i <= avParts(size); i++ {
			n := avPartSize(size, i)
			if n < 1 || n > 4<<20 {
				t.Fatal(size, i, n)
			}
			sum += n
		}
		if sum != size {
			t.Fatal("part sum")
		}
	}
	q := Request{ConversationID: "3", ClientMessageID: client(400), MessageType: "video", Revision: 1, Items: []MediaItem{{AttachmentID: "8", ObjectVersion: strings.Repeat("a", 64)}}}
	fp, e := avFingerprint(q)
	if e != nil {
		t.Fatal(e)
	}
	q.ReplyTo = "7"
	changed, _ := avFingerprint(q)
	if changed == fp {
		t.Fatal("reply not bound")
	}
	q.Items[0].ObjectVersion = strings.Repeat("b", 64)
	next, _ := avFingerprint(q)
	if next == changed {
		t.Fatal("version not bound")
	}
	q.Items[0].Ordinal = 1
	if _, e = avFingerprint(q); e == nil {
		t.Fatal("invalid ordinal")
	}
	q.Items[0].Ordinal = 0
	q.Text = "client transcript"
	if _, e = avFingerprint(q); e == nil {
		t.Fatal("untrusted transcript")
	}
}
func TestAVWireDecode(t *testing.T) {
	q := Request{ConversationID: "3", ClientMessageID: client(401), MessageType: "audio", Revision: 1, Items: []MediaItem{{AttachmentID: "8", ObjectVersion: strings.Repeat("a", 64)}}}
	raw, _ := json.Marshal(q)
	if _, e := decode(raw, false); e != nil {
		t.Fatal(e)
	}
	bad := strings.Replace(string(raw), `"ordinal":0`, `"ordinal":0,"ordinal":0`, 1)
	if _, e := decode([]byte(bad), false); e == nil {
		t.Fatal("duplicate ordinal accepted")
	}
	if _, e := decode([]byte(strings.Replace(string(raw), `"revision":1`, `"revision":0`, 1)), false); e == nil {
		t.Fatal("zero revision")
	}
}

var _ = time.Second
