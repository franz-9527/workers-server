package moderation

import (
	"context"
	"errors"
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
				if !strings.Contains(text, needle) {
					t.Fatalf("payload missing %s: %s", needle, text)
				}
			}
			return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"Response":{"RequestId":"41ed9283-0c09-46fb-917b-0b83fa95f0be","Result":"施工墙面十二米"}}`))}, nil
		}),
	}
	result, err := a.recognizeTencent(context.Background(), ports.AudioInspectionRequest{URL: "https://test.20m.cc/uploads/chat-asr/" + strings.Repeat("a", 64) + ".mp3"}, binding{proof: strings.Repeat("a", 64)})
	if err != nil || !seen || result.Text != "施工墙面十二米" || result.RequestID == "" || result.Engine != "16k_zh_dialect" || result.ServiceID != "tencent_cloud_asr" {
		t.Fatalf("unexpected direct ASR result: %#v err=%v", result, err)
	}
}

func TestTencentSentenceRecognitionUncertainResponsesNeverBecomeSuccess(t *testing.T) {
	for _, tc := range []struct {
		name, body     string
		status         int
		transportError bool
	}{
		{"missing request id", `{"Response":{"Result":"墙面"}}`, 200, false},
		{"oversized error request id", `{"Response":{"RequestId":"` + strings.Repeat("a", 129) + `","Error":{"Code":"InternalError","Message":"fixture"}}}`, 200, false},
		{"missing result", `{"Response":{"RequestId":"request-1"}}`, 200, false},
		{"duplicate result", `{"Response":{"RequestId":"request-1","Result":"a","Result":"b"}}`, 200, false},
		{"empty error", `{"Response":{"RequestId":"request-1","Result":"a","Error":{}}}`, 200, false},
		{"nul text", `{"Response":{"RequestId":"request-1","Result":"a\u0000b"}}`, 200, false},
		{"invalid json", `{`, 200, false},
		{"oversized body", strings.Repeat(" ", 65537), 200, false},
		{"http error", `{}`, 503, false},
		{"network timeout", ``, 0, true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			calls := 0
			a := &AV{c: AVConfig{Provider: "tencent_cloud", Engine: "16k_zh_dialect", SecretID: "AKIDFixture123456789", SecretKey: "fixture-secret-0123456789abcdef"}, client: httpFunc(func(*http.Request) (*http.Response, error) {
				calls++
				if tc.transportError {
					return nil, errors.New("fixture timeout")
				}
				return &http.Response{StatusCode: tc.status, Body: io.NopCloser(strings.NewReader(tc.body))}, nil
			})}
			result, e := a.recognizeTencent(context.Background(), ports.AudioInspectionRequest{URL: "https://fixture.invalid/main.mp3"}, binding{proof: strings.Repeat("a", 64)})
			if e == nil || result.Known || !result.Dispatched || calls != 1 {
				t.Fatalf("uncertain response was accepted or repeated: %+v %v calls=%d", result, e, calls)
			}
		})
	}
}

func TestTencentSentenceRecognitionQuotaFailureIsKnownAndNotRetried(t *testing.T) {
	calls := 0
	a := &AV{c: AVConfig{Provider: "tencent_cloud", Engine: "16k_zh", SecretID: "AKIDFixture123456789", SecretKey: "fixture-secret-0123456789abcdef"}, client: httpFunc(func(*http.Request) (*http.Response, error) {
		calls++
		return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"Response":{"RequestId":"quota-1","Error":{"Code":"FailedOperation.UserHasNoFreeAmount","Message":"no free amount"}}}`))}, nil
	})}
	result, e := a.recognizeTencent(context.Background(), ports.AudioInspectionRequest{URL: "https://fixture.invalid/main.mp3"}, binding{proof: strings.Repeat("a", 64)})
	if e == nil || !result.Known || !result.Dispatched || result.Retryable || result.RequestID != "quota-1" || calls != 1 {
		t.Fatalf("quota failure mishandled: %+v %v", result, e)
	}
}

func TestTencentSentenceRecognitionKeepsProviderRequestIDOnError(t *testing.T) {
	a := &AV{
		c: AVConfig{Provider: "tencent_cloud", APIName: "SentenceRecognition", DataEncoding: "json", Engine: "16k_zh", SecretID: "AKID1234567890ABCDEFGHIJ", SecretKey: "0123456789abcdef0123456789abcdef"},
		client: httpFunc(func(*http.Request) (*http.Response, error) {
			return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"Response":{"RequestId":"41ed9283-0c09-46fb-917b-0b83fa95f0be","Error":{"Code":"AuthFailure.SecretIdNotFound","Message":"secret id not found"}}}`))}, nil
		}),
	}
	result, err := a.recognizeTencent(context.Background(), ports.AudioInspectionRequest{URL: "https://test.20m.cc/uploads/chat-asr/" + strings.Repeat("b", 64) + ".mp3"}, binding{proof: strings.Repeat("a", 64)})
	if err == nil || result.Known != true || result.RequestID == "" || result.ProviderCode != "AuthFailure.SecretIdNotFound" || result.Retryable {
		t.Fatalf("provider error diagnostic lost: %#v err=%v", result, err)
	}
}

func TestTencentSentenceRecognitionMissingCredentialsDoesNotDispatch(t *testing.T) {
	called := false
	a := &AV{
		c:      AVConfig{Provider: "tencent_cloud", APIName: "SentenceRecognition", DataEncoding: "json", Engine: "16k_zh_dialect"},
		client: httpFunc(func(*http.Request) (*http.Response, error) { called = true; return nil, nil }),
	}
	result, err := a.recognizeTencent(context.Background(), ports.AudioInspectionRequest{URL: "https://test.20m.cc/uploads/chat-asr/" + strings.Repeat("c", 64) + ".mp3"}, binding{proof: strings.Repeat("a", 64)})
	if err == nil || called || result.Dispatched || result.ProviderCode != "NOT_CONFIGURED" || result.Retryable {
		t.Fatalf("missing credentials must be a non-dispatched enhancement failure: %#v err=%v", result, err)
	}
}
