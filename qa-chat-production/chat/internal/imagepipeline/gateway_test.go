package imagepipeline

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

func publicFixture(t *testing.T) *Qiniu {
	t.Helper()
	q, err := NewQiniu(QiniuConfig{Bucket: "fixture", AccessKey: "fixture-ak", SecretKey: "fixture-sk", Private: false, DownloadOrigin: "http://cdn.example.com", UploadOrigin: "https://up-z2.qiniup.com", Environment: "test", Namespace: "test/chat", ProxyOrigin: "https://test.example.com", ProxyKey: strings.Repeat("k", 32)})
	if err != nil {
		t.Fatal(err)
	}
	return q
}
func TestPublicMediaNamespaceGrantAndHTTPSGateway(t *testing.T) {
	q := publicFixture(t)
	hash := strings.Repeat("a", 64)
	key := "test/chat/images/test/9/" + hash + "/source"
	token, host, err := q.Grant(context.Background(), key, 12)
	if err != nil || host != "https://up-z2.qiniup.com" {
		t.Fatal(err)
	}
	parts := strings.Split(token, ":")
	raw, _ := base64.URLEncoding.DecodeString(parts[2])
	var policy map[string]any
	json.Unmarshal(raw, &policy)
	if policy["scope"] != "fixture:"+key || q.Namespace() != "test/chat" {
		t.Fatal("namespace not preserved", policy)
	}
	var upstream string
	q.client = &http.Client{Transport: avRoundTrip(func(r *http.Request) (*http.Response, error) {
		upstream = r.URL.String()
		return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader("fixture-body")), ContentLength: 12}, nil
	})}
	signed, err := q.Sign(context.Background(), key, time.Minute)
	if err != nil || !strings.HasPrefix(signed, "https://test.example.com/chat/api/v1/media/object?") {
		t.Fatal("client received HTTP origin", signed, err)
	}
	req := httptest.NewRequest("GET", signed, nil)
	req.Header.Set("Range", "bytes=0-6")
	w := httptest.NewRecorder()
	q.Gateway().ServeHTTP(w, req)
	if w.Code != 206 || w.Body.String() != "fixture" || upstream != "http://cdn.example.com/"+key {
		t.Fatal("public HTTP origin or range response", w.Code, w.Body.String(), upstream)
	}
	// Provider inspection uses the actual configured object origin, while client
	// playback continues to use the authenticated HTTPS gateway.
	q.client = &http.Client{Transport: avRoundTrip(func(r *http.Request) (*http.Response, error) {
		return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(`{"fsize":12,"mimeType":"video/mp4"}`))}, nil
	})}
	av := "test/chat/av/test/9/" + hash + "/" + hash + "/main.mp4"
	signed, err = q.InspectionURL(context.Background(), av)
	if err != nil || signed != "http://cdn.example.com/"+av {
		t.Fatal("provider object URL", signed, err)
	}
	if _, err = q.InspectionURL(context.Background(), strings.Replace(av, "main.mp4", "frame-0.jpg", 1)); err == nil {
		t.Fatal("image accepted for ASR")
	}

}
func TestMediaGatewayRejectsUnsignedExpiredAndCrossEnvironment(t *testing.T) {
	q := publicFixture(t)
	key := "test/chat/av/test/9/" + strings.Repeat("a", 64) + "/" + strings.Repeat("b", 64) + "/main.mp4"
	signed, err := q.Sign(context.Background(), key, time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	u, err := url.Parse(signed)
	if err != nil {
		t.Fatal(err)
	}
	cases := []url.Values{}
	for _, mutate := range []func(url.Values){
		func(v url.Values) { v.Set("signature", strings.Repeat("0", 64)) },
		func(v url.Values) { v.Set("expires", "1") },
		func(v url.Values) { v.Set("key", strings.Replace(key, "/test/9/", "/production/9/", 1)) },
		func(v url.Values) { v.Add("key", key) },
		func(v url.Values) { v.Set("url", "http://127.0.0.1/") },
	} {
		v := u.Query()
		mutate(v)
		cases = append(cases, v)
	}
	for _, values := range cases {
		w := httptest.NewRecorder()
		q.Gateway().ServeHTTP(w, httptest.NewRequest("GET", u.Path+"?"+values.Encode(), nil))
		if w.Code != 403 {
			t.Fatal("invalid gateway request", w.Code)
		}
	}
	for _, name := range []string{"../orders", "test/../orders", "/absolute", strings.Repeat("a", 61)} {
		if validNamespace(name) {
			t.Fatal("invalid namespace", name)
		}
	}
	c := q.config
	c.Environment = "production"
	if _, err := NewQiniu(c); err != nil {
		t.Fatal("public HTTP origin with HTTPS site proxy must remain valid in production", err)
	}
	c.ProxyOrigin = ""
	if _, err := NewQiniu(c); err == nil {
		t.Fatal("public HTTP origin must not bypass the HTTPS site proxy in production")
	}
}
