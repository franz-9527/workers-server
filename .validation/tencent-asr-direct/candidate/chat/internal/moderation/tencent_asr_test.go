package moderation

import (
	"io"
	"net/http"
	"strings"
	"testing"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func TestTencentSentenceRecognitionUsesTC3AndDialectEngine(t *testing.T) {
	var seen bool
	a := &AV{
		c: AVConfig{
			Provider: "tencent_cloud", APIName: "SentenceRecognition", DataEncoding: "json",
			Engine: "16k_zh_dialect", SecretID: "AKID1234567890ABCDEFGHIJ", SecretKey: "0123456789abcdef0123456789abcdef",
		},
		client: httpFunc(func(req *http.Request) (*http.Response, error) {
			seen = true
			if req.URL.String() != "https://asr.tencentcloudapi.com/" || req.Header.Get("X-TC-Action") != "SentenceRecognition" ||
			req.Header.Get("X-TC-Version") != "2019-06-14" || !strings.Contains(req.Header.Get("Authorization"), "Credential=AKID1234567890ABCDEFGHIJ/") {
				t.Fatal("tencent request headers are not the documented v3 contract")
			}
			body, _ := io.ReadAll(req.Body)
			text := string(body)
			for _, needle := range []string{`"EngSerViceType":"16k_zh_dialect"`, `"SourceType":0`, `"VoiceFormat":"mp3"`, `"Url":"https://test.20m.cc/uploads/chat-asr/`} {
				if !strings.Contains(text, needle) { t.Fatalf("payload missing %s: %s", needle, text) }
			}
			return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"Response":{"RequestId":"41ed9283-0c09-46fb-917b-0b83fa95f0be","Result":"施工墙面十二米"}}`))}, nil
		}),
	}
	result, err := a.recognizeTencent(t.Context(), ports.AudioInspectionRequest{URL:"https://test.20m.cc/uploads/chat-asr/"+strings.Repeat("a",64)+".mp3"}, binding{proof:strings.Repeat("a",64)})
	if err != nil || !seen || result.Text != "施工墙面十二米" || result.RequestID == "" || result.Engine != "16k_zh_dialect" || result.ServiceID != "tencent_cloud_asr" {
		t.Fatalf("unexpected direct ASR result: %#v err=%v", result, err)
	}
}

func TestTencentSentenceRecognitionKeepsProviderRequestIDOnError(t *testing.T) {
	a := &AV{
		c: AVConfig{Provider:"tencent_cloud",APIName:"SentenceRecognition",DataEncoding:"json",Engine:"16k_zh",SecretID:"AKID1234567890ABCDEFGHIJ",SecretKey:"0123456789abcdef0123456789abcdef"},
		client: httpFunc(func(*http.Request) (*http.Response,error){
			return &http.Response{StatusCode:200,Body:io.NopCloser(strings.NewReader(`{"Response":{"RequestId":"41ed9283-0c09-46fb-917b-0b83fa95f0be","Error":{"Code":"AuthFailure.SecretIdNotFound","Message":"secret id not found"}}}`))},nil
		}),
	}
	result, err := a.recognizeTencent(t.Context(), ports.AudioInspectionRequest{URL:"https://test.20m.cc/uploads/chat-asr/"+strings.Repeat("b",64)+".mp3"}, binding{proof:strings.Repeat("a",64)})
	if err == nil || result.Known != true || result.RequestID == "" || result.ProviderCode != "AuthFailure.SecretIdNotFound" || result.Retryable {
		t.Fatalf("provider error diagnostic lost: %#v err=%v", result, err)
	}
}
