package asrcontract

import (
	"encoding/json"
	"net/url"
)

const SentenceAPI = "SentenceASR"

// BuildData builds the provider-specific data value placed inside WeChat's
// /wxa/servicemarket envelope. The Open API parameter table historically calls
// data a string, while the HTTPS example sends a JSON object. SentenceASR uses
// that object envelope; custom services can explicitly opt into JSON text.
func BuildData(api, configuredEncoding, rawURL, key string) (any, string, int) {
	encodedURL := url.QueryEscape(rawURL)
	data := any(map[string]any{
		"Action":         "SentenceRecognitionWX",
		"EngSerViceType": "16k_zh",
		"SourceType":     0,
		"VoiceFormat":    "mp3",
		"Url":            encodedURL,
		"UsrAudioKey":    key,
	})
	encoding := configuredEncoding
	if api == SentenceAPI {
		encoding = "object"
	}
	if encoding == "json_string" {
		raw, _ := json.Marshal(data)
		data = string(raw)
	}
	return data, encoding, len(encodedURL)
}
