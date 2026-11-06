package asrcontract

import (
	"encoding/json"
	"net/url"
	"strings"
	"testing"
)

func TestSentenceASRCompatibilityEnvelope(t *testing.T) {
	rawURL := "https://qn.example.invalid/test/chat/av/test/1/a/main.mp3?e=1&token=a:b"
	key := strings.Repeat("a", 32)
	data, encoding, encodedLen := BuildData(SentenceAPI, "json_string", rawURL, key)
	if encoding != "object" {
		t.Fatalf("encoding=%q", encoding)
	}
	inner, ok := data.(map[string]any)
	if !ok || len(inner) != 6 {
		t.Fatalf("data=%#v", data)
	}
	if inner["Action"] != "SentenceRecognitionWX" || inner["EngSerViceType"] != "16k_zh" ||
		inner["SourceType"] != 0 || inner["VoiceFormat"] != "mp3" ||
		inner["Url"] != url.QueryEscape(rawURL) || inner["UsrAudioKey"] != key {
		t.Fatalf("inner=%#v", inner)
	}
	if encodedLen != len(url.QueryEscape(rawURL)) {
		t.Fatalf("encoded length=%d", encodedLen)
	}
	wire, err := json.Marshal(map[string]any{"service":"wxa8386175898e12c9","api":SentenceAPI,"client_msg_id":key,"data":data})
	if err != nil { t.Fatal(err) }
	var decoded map[string]any
	if json.Unmarshal(wire, &decoded) != nil { t.Fatal("wire") }
	if _, ok := decoded["data"].(map[string]any); !ok { t.Fatalf("wire data=%T",decoded["data"]) }
}
