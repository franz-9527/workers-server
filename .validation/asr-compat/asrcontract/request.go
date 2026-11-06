package asrcontract

import (
	"encoding/json"
	"net/url"
)

const SentenceAPI = "SentenceASR"

// BuildData builds the provider-specific data value placed inside WeChat's
// /wxa/servicemarket envelope. SentenceASR keeps the compatibility fields
// defined by the bound WeChat service-market product; Tencent Cloud direct-API
// compatibility fields are deliberately not injected into this product payload.
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
