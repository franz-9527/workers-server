package storagepolicy

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestPolicyDefaultsAndStrictWireContract(t *testing.T) {
	p := Defaults()
	b, _ := json.Marshal(p)
	got, e := Decode(b)
	if e != nil || got.Retention.Text.Mode != "permanent" || got.Retention.Image.Mode != "permanent" || got.Retention.Audio.Mode != "permanent" || got.Retention.Video.Days != 60 || got.Tiering.Enabled {
		t.Fatal(got, e)
	}
	for _, raw := range []string{
		strings.Replace(string(b), "\"schema\":1", "\"schema\":1,\"schema\":1", 1),
		strings.Replace(string(b), "\"schema\":1", "\"Schema\":1", 1),
		strings.Replace(string(b), "\"enabled\":false", "\"enabled\":null", 1),
		strings.Replace(string(b), "\"videos\":false,", "", 1),
		strings.Replace(string(b), "\"days\":0", "\"days\":1", 1),
		strings.Replace(string(b), "\"days\":60", "\"days\":0", 1),
		string(b) + "{}",
	} {
		if _, e := Decode([]byte(raw)); e == nil {
			t.Fatal("invalid policy accepted", raw)
		}
	}
}
func TestRuleDeadlineAndModeIsolation(t *testing.T) {
	p := Defaults()
	now := time.Date(2026, 1, 1, 12, 0, 0, 0, time.UTC)
	for _, kind := range []string{"text", "image", "image_album", "audio"} {
		r, e := p.Rule(kind)
		if e != nil || r.Deadline(now) != nil {
			t.Fatal(kind, r, e)
		}
	}
	video, _ := p.Rule("video")
	if v := video.Deadline(now); v == nil || !v.Equal(now.Add(60*24*time.Hour)) {
		t.Fatal(v)
	}
	if _, e := p.Rule("file"); e == nil {
		t.Fatal("unknown media")
	}
	p.Tiering.Enabled = true
	p.Tiering.Images = false
	p.Tiering.Audio = false
	if p.Valid() {
		t.Fatal("enabled empty type set")
	}
	p = Defaults()
	p.Retention.Text = Rule{Mode: "days", Days: 7}
	if !p.Valid() || p.Retention.Audio.Mode != "permanent" {
		t.Fatal("type settings coupled")
	}
}
