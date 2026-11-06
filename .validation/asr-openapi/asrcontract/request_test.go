package asrcontract

import (
	"encoding/json"
	"net/url"
	"strings"
	"testing"
)

func TestSentenceASRUsesOpenAPIObjectEnvelope(t *testing.T) {
	rawURL := "https://cdn.example.invalid/main.mp3?e=1&token=a:b"
	key := strings.Repeat("a", 32)
	data, encoding, encodedLen := BuildData(SentenceAPI, "json_string", rawURL, key)
	if encoding != "object" {
		t.Fatalf("SentenceASR encoding = %q, want object", encoding)
	}
	inner, ok := data.(map[string]any)
	if !ok {
		t.Fatalf("SentenceASR data must be an object, got %T", data)
	}
	if len(inner) != 6 ||
		inner["Action"] != "SentenceRecognitionWX" ||
		inner["EngSerViceType"] != "16k_zh" ||
		inner["SourceType"] != 0 ||
		inner["VoiceFormat"] != "mp3" ||
		inner["Url"] != url.QueryEscape(rawURL) ||
		inner["UsrAudioKey"] != key {
		t.Fatalf("unexpected SentenceASR data: %#v", inner)
	}
	if encodedLen != len(url.QueryEscape(rawURL)) {
		t.Fatalf("encoded URL length = %d", encodedLen)
	}

	outer := map[string]any{
		"service":       "wxa8386175898e12c9",
		"api":           SentenceAPI,
		"client_msg_id": key,
		"data":          data,
	}
	raw, err := json.Marshal(outer)
	if err != nil {
		t.Fatal(err)
	}
	var decoded map[string]any
	if err = json.Unmarshal(raw, &decoded); err != nil {
		t.Fatal(err)
	}
	if _, ok = decoded["data"].(map[string]any); !ok {
		t.Fatalf("wire data must remain a JSON object: %s", raw)
	}
}

func TestCustomServiceMayUseJSONString(t *testing.T) {
	data, encoding, _ := BuildData("CustomASR", "json_string", "https://example.invalid/a.mp3", strings.Repeat("b", 32))
	if encoding != "json_string" {
		t.Fatalf("custom encoding = %q", encoding)
	}
	if _, ok := data.(string); !ok {
		t.Fatalf("custom JSON-string mode returned %T", data)
	}
}
