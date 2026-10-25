package moderation

import (
	"bytes"
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/sha1"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestMediaCallbackAuthenticatedEnvelope(t *testing.T) {
	h := &MediaCallback{AppID: "wx0123456789abcdef", Token: strings.Repeat("t", 24), Key: bytes.Repeat([]byte("k"), 32)}
	now := time.Unix(1800000000, 0)
	body := []byte(`{"Event":"wxa_media_check"}`)
	plain := append(bytes.Repeat([]byte("r"), 16), 0, 0, 0, 0)
	binary.BigEndian.PutUint32(plain[16:20], uint32(len(body)))
	plain = append(plain, body...)
	plain = append(plain, []byte(h.AppID)...)
	padding := 32 - len(plain)%32
	plain = append(plain, bytes.Repeat([]byte{byte(padding)}, padding)...)
	block, _ := aes.NewCipher(h.Key)
	encrypted := make([]byte, len(plain))
	cipher.NewCBCEncrypter(block, h.Key[:16]).CryptBlocks(encrypted, plain)
	encoded := base64.StdEncoding.EncodeToString(encrypted)
	parts := []string{h.Token, "1800000000", "nonce", encoded}
	sort.Strings(parts)
	sum := sha1.Sum([]byte(strings.Join(parts, "")))
	sig := hex.EncodeToString(sum[:])
	out, e := h.decrypt(sig, "1800000000", "nonce", encoded, now)
	if e != nil || !bytes.Equal(out, body) {
		t.Fatal(e)
	}
	if _, e = h.decrypt(strings.Repeat("0", 40), "1800000000", "nonce", encoded, now); e == nil {
		t.Fatal("unsigned callback")
	}
	if _, e = h.decrypt(sig, "1800000000", "nonce", encoded, now.Add(301*time.Second)); e == nil {
		t.Fatal("replay window")
	}
	h.AppID = "wx1111111111111111"
	if _, e = h.decrypt(sig, "1800000000", "nonce", encoded, now); e == nil {
		t.Fatal("other app accepted")
	}
}
func TestAVProviderJSONRejectsDuplicatesAllowsDocumentedCase(t *testing.T) {
	var out any
	if !avJSON([]byte(`{"Response":{"Result":"","RequestId":"id"}}`), &out) {
		t.Fatal("provider case rejected")
	}
	for _, raw := range []string{`{"errcode":0,"errcode":1}`, `{"data":{"Result":"a","Result":"b"}}`, `{}{}`} {
		if avJSON([]byte(raw), &out) {
			t.Fatal(raw)
		}
	}
}

type callbackReceiver struct {
	receipts []ports.AudioReceipt
	fail     bool
}

func (r *callbackReceiver) ReceiveAudio(_ context.Context, v ports.AudioReceipt) error {
	if r.fail {
		return ports.ErrUnavailable
	}
	r.receipts = append(r.receipts, v)
	return nil
}
func callbackRequest(t *testing.T, h *MediaCallback, body string, xmlBody bool) *http.Request {
	t.Helper()
	plain := append(bytes.Repeat([]byte("r"), 16), 0, 0, 0, 0)
	binary.BigEndian.PutUint32(plain[16:20], uint32(len(body)))
	plain = append(plain, []byte(body)...)
	plain = append(plain, []byte(h.AppID)...)
	padding := 32 - len(plain)%32
	plain = append(plain, bytes.Repeat([]byte{byte(padding)}, padding)...)
	block, _ := aes.NewCipher(h.Key)
	encrypted := make([]byte, len(plain))
	cipher.NewCBCEncrypter(block, h.Key[:16]).CryptBlocks(encrypted, plain)
	encoded := base64.StdEncoding.EncodeToString(encrypted)
	stamp := strconv.FormatInt(time.Now().Unix(), 10)
	parts := []string{h.Token, stamp, "nonce", encoded}
	sort.Strings(parts)
	sum := sha1.Sum([]byte(strings.Join(parts, "")))
	q := url.Values{"timestamp": {stamp}, "nonce": {"nonce"}, "msg_signature": {hex.EncodeToString(sum[:])}, "encrypt_type": {"aes"}}
	raw, _ := json.Marshal(map[string]string{"Encrypt": encoded})
	if xmlBody {
		raw = []byte("<xml><Encrypt><![CDATA[" + encoded + "]]></Encrypt></xml>")
	}
	return httptest.NewRequest("POST", "/chat/api/v1/av/callback?"+q.Encode(), bytes.NewReader(raw))
}
func TestMediaCallbackHTTPAndProviderErrors(t *testing.T) {
	for _, format := range []string{"json", "xml"} {
		t.Run(format, func(t *testing.T) {
			receiver := &callbackReceiver{}
			h, _ := NewMediaCallback("wx0123456789abcdef", strings.Repeat("t", 24), base64.RawStdEncoding.EncodeToString(bytes.Repeat([]byte("k"), 32)), receiver)
			for _, v := range []struct {
				code          int
				suggest, want string
			}{{0, "pass", "pass"}, {0, "risky", "risky"}, {0, "review", "review"}, {-1, "pass", "error"}, {0, "other", "error"}} {
				body := `{"appid":"` + h.AppID + `","Event":"wxa_media_check","version":2,"trace_id":"fixture-trace","errcode":` + strconv.Itoa(v.code) + `,"result":{"suggest":"` + v.suggest + `"}}`
				if format == "xml" {
					body = "<xml><appid>" + h.AppID + "</appid><Event>wxa_media_check</Event><version>2</version><trace_id>fixture-trace</trace_id><errcode>" + strconv.Itoa(v.code) + "</errcode><result><suggest>" + v.suggest + "</suggest></result></xml>"
				}
				req := callbackRequest(t, h, body, format == "xml")
				w := httptest.NewRecorder()
				h.ServeHTTP(w, req)
				if w.Code != 200 || w.Body.String() != "success" || receiver.receipts[len(receiver.receipts)-1].Decision != v.want {
					t.Fatalf("callback %s: %d %s", v.want, w.Code, w.Body.String())
				}
				// Exact redelivery reaches the durable receiver with the same fingerprint.
				again := httptest.NewRecorder()
				h.ServeHTTP(again, callbackRequest(t, h, body, format == "xml"))
				n := len(receiver.receipts)
				if again.Code != 200 || receiver.receipts[n-1].Fingerprint != receiver.receipts[n-2].Fingerprint {
					t.Fatal("unstable redelivery")
				}
			}
			receiver.fail = true
			w := httptest.NewRecorder()
			h.ServeHTTP(w, callbackRequest(t, h, `{"appid":"`+h.AppID+`","Event":"wxa_media_check","version":2,"trace_id":"fixture-trace","errcode":0,"result":{"suggest":"pass"}}`, false))
			if w.Code != 503 {
				t.Fatal("unpersisted callback acknowledged")
			}
		})
	}
}
func TestMediaCallbackHandshakeAndStrictParsing(t *testing.T) {
	receiver := &callbackReceiver{}
	h, _ := NewMediaCallback("wx0123456789abcdef", strings.Repeat("t", 24), base64.RawStdEncoding.EncodeToString(bytes.Repeat([]byte("k"), 32)), receiver)
	stamp := strconv.FormatInt(time.Now().Unix(), 10)
	parts := []string{h.Token, stamp, "nonce"}
	sort.Strings(parts)
	sum := sha1.Sum([]byte(strings.Join(parts, "")))
	q := url.Values{"timestamp": {stamp}, "nonce": {"nonce"}, "signature": {hex.EncodeToString(sum[:])}, "echostr": {"ownership-check"}}
	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("GET", "/?"+q.Encode(), nil))
	if w.Code != 200 || w.Body.String() != "ownership-check" || len(receiver.receipts) != 0 {
		t.Fatal("ownership handshake")
	}
	w = httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest("POST", "/?"+q.Encode(), strings.NewReader(`{"result":{"suggest":"pass"}}`)))
	if w.Code != 403 {
		t.Fatal("plain event accepted")
	}
	for _, raw := range []string{`{"Encrypt":"one","Encrypt":"two"}`, `{"Encrypt":"one","encrypt":"two"}`, `<xml><Encrypt>one</Encrypt><Encrypt>two</Encrypt></xml>`, `<xml><result><suggest>pass</suggest><suggest>risky</suggest></result></xml>`, `<xml></xml><xml></xml>`, `<!DOCTYPE xml><xml></xml>`} {
		var out any
		if callbackDecode([]byte(raw), &out) {
			t.Fatal("ambiguous envelope:", raw)
		}
	}
	body := `{"appid":"` + h.AppID + `","Event":"wxa_media_check","version":2,"trace_id":"fixture-trace","result":{"suggest":"pass"}}`
	w = httptest.NewRecorder()
	h.ServeHTTP(w, callbackRequest(t, h, body, false))
	if w.Code != 400 {
		t.Fatal("missing result code")
	}
	req := callbackRequest(t, h, strings.Replace(body, `"version":2`, `"version":2,"errcode":0`, 1), false)
	req.URL.RawQuery += "&nonce=other"
	w = httptest.NewRecorder()
	h.ServeHTTP(w, req)
	if w.Code != 400 {
		t.Fatal("ambiguous query")
	}
}
func avFixture(t *testing.T, body string, status int) (*AV, Credential, *Credentials) {
	t.Helper()
	c := credential("av_fixture_official_token")
	path := filepath.Join(t.TempDir(), "credential.json")
	putCredential(t, path, c)
	credentials, _ := NewCredentials(path, "test", c.AppID)
	a, _ := NewAV(&Service{credentials: credentials}, AVConfig{"fixture_api", "object"}, httpFunc(func(*http.Request) (*http.Response, error) {
		return &http.Response{StatusCode: status, Body: io.NopCloser(strings.NewReader(body))}, nil
	}))
	return a, c, credentials
}
func TestAVRecognitionProtocolAndDefinitiveErrors(t *testing.T) {
	for _, encoding := range []string{"object", "json_string"} {
		t.Run(encoding, func(t *testing.T) {
			a, c, _ := avFixture(t, `{"errcode":0,"data":{"Response":{"Result":"尺寸12米","RequestId":"fixture-id"}}}`, 200)
			a.c.DataEncoding = encoding
			a.client = httpFunc(func(req *http.Request) (*http.Response, error) {
				var body map[string]any
				if json.NewDecoder(req.Body).Decode(&body) != nil || req.URL.Path != "/wxa/servicemarket" || body["service"] != ASRServiceID || body["api"] != "fixture_api" || body["client_msg_id"] != strings.Repeat("a", 64) {
					t.Fatal("market request")
				}
				data := body["data"]
				if encoding == "json_string" {
					s, ok := data.(string)
					if !ok {
						t.Fatal("encoding")
					}
					if json.Unmarshal([]byte(s), &data) != nil {
						t.Fatal("data JSON")
					}
				}
				m, ok := data.(map[string]any)
				if !ok || m["Action"] != "SentenceRecognitionWX" || m["Url"] != "https://private.invalid/audio" || m["UsrAudioKey"] != body["client_msg_id"] {
					t.Fatal("audio binding")
				}
				return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"errcode":0,"data":"{\"Response\":{\"Result\":\"尺寸12米\",\"RequestId\":\"fixture-id\"}}"}`))}, nil
			})
			out, e := a.recognize(context.Background(), ports.AudioInspectionRequest{URL: "https://private.invalid/audio", UserAudioKey: strings.Repeat("a", 64)}, binding{proof: "proof"}, c)
			if e != nil || !out.Known || !out.Dispatched || out.Text != "尺寸12米" || out.RequestID != "fixture-id" {
				t.Fatalf("recognition: %+v %v", out, e)
			}
		})
	}
	for _, v := range []struct {
		body         string
		known, retry bool
	}{
		{`{"errcode":0,"data":{"Response":{"Error":{"Code":"AuthFailure","Message":"private"},"RequestId":"fixture-id"}}}`, true, false},
		{`{"errcode":45009}`, true, false}, {`{"errcode":-1}`, true, true},
		{`{"errcode":0,"data":{}}`, false, true}, {`{"errcode":0,"ERRCODE":0}`, false, true},
	} {
		a, c, credentials := avFixture(t, v.body, 200)
		out, e := a.recognize(context.Background(), ports.AudioInspectionRequest{}, binding{}, c)
		if e == nil || out.Known != v.known || out.Retryable != v.retry {
			t.Fatalf("failure %+v %v", out, e)
		}
		if _, e = credentials.Get(); e != nil {
			t.Fatal("non-token failure quarantined credentials")
		}
	}
	for _, code := range []string{"40001", "40014", "42001"} {
		a, c, credentials := avFixture(t, `{"errcode":`+code+`}`, 200)
		out, e := a.submitAudio(context.Background(), ports.AudioInspectionRequest{}, binding{}, c)
		if e == nil || !out.Known || !out.Retryable {
			t.Fatal("media token response")
		}
		if _, e = credentials.Get(); e == nil {
			t.Fatal("media rejected credential reused")
		}
	}
}
