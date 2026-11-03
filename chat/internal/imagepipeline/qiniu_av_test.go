package imagepipeline

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/message"
	"io"
	"net/http"
	"strings"
	"testing"
)

type avRoundTrip func(*http.Request) (*http.Response, error)

func (f avRoundTrip) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestAVPartGrantIsExactAndEnvironmentBound(t *testing.T) {
	q, e := NewQiniu(QiniuConfig{Bucket: "fixture", AccessKey: "fixture-ak", SecretKey: "fixture-sk", Private: true, DownloadOrigin: "https://cdn.fixture.invalid", UploadOrigin: "https://up.qiniup.com", Environment: "test"})
	if e != nil {
		t.Fatal(e)
	}
	key := "chatav/test/9/" + strings.Repeat("a", 64) + "/part-1"
	token, _, e := q.GrantAVPart(context.Background(), key, 2097152)
	if e != nil {
		t.Fatal(e)
	}
	parts := strings.Split(token, ":")
	raw, e := base64.URLEncoding.DecodeString(parts[2])
	if e != nil {
		t.Fatal(e)
	}
	var p map[string]any
	if json.Unmarshal(raw, &p) != nil || p["scope"] != "fixture:"+key || p["fsizeMin"] != float64(2097152) || p["fsizeLimit"] != float64(2097152) || p["insertOnly"] != float64(1) || p["deleteAfterDays"] != float64(1) {
		t.Fatal("unbounded or public grant", p)
	}
	if _, _, e = q.GrantAVPart(context.Background(), strings.Replace(key, "/test/", "/production/", 1), 1); e == nil {
		t.Fatal("cross environment")
	}
	if _, _, e = q.GrantAVPart(context.Background(), key, (4<<20)+1); e == nil {
		t.Fatal("part size")
	}
	if _, _, e = q.GrantAVPart(context.Background(), strings.Replace(key, "part-1", strings.Repeat("b", 64)+"/main", 1), 100); e == nil {
		t.Fatal("client granted formal object")
	}
}
func TestQiniuManagementSigningAndMissingObject(t *testing.T) {
	q, e := NewQiniu(QiniuConfig{Bucket: "fixture", AccessKey: "fixture-ak", SecretKey: "fixture-sk", Private: true, DownloadOrigin: "https://cdn.fixture.invalid", UploadOrigin: "https://up.qiniup.com", Environment: "test"})
	if e != nil {
		t.Fatal(e)
	}
	code, calls := 200, 0
	q.client = &http.Client{Transport: avRoundTrip(func(r *http.Request) (*http.Response, error) {
		calls++
		canonical := "POST " + r.URL.Path + "\nHost: rs.qiniuapi.com\nContent-Type: application/x-www-form-urlencoded\n\n"
		if r.URL.Host != "rs.qiniuapi.com" || r.Header.Get("Authorization") != "Qiniu fixture-ak:"+q.signature(canonical) {
			t.Fatal("management signing")
		}
		return &http.Response{StatusCode: code, Body: io.NopCloser(strings.NewReader("{}"))}, nil
	})}
	key := "chatav/test/9/" + strings.Repeat("a", 64) + "/" + strings.Repeat("b", 64) + "/main"
	if e = q.Keep(context.Background(), key); e != nil || calls != 1 {
		t.Fatal(e)
	}
	code = 612
	if e = q.Keep(context.Background(), key); e != message.ErrObjectMissing {
		t.Fatal("missing object not surfaced", e)
	}
	if e = q.LowFrequency(context.Background(), strings.Replace(key, "/main", "/check-0", 1)); e == nil || calls != 2 {
		t.Fatal("temporary ASR chunk tiered")
	}
	if e = q.LowFrequency(context.Background(), strings.Replace(key, "/main", "/part-1", 1)); e == nil {
		t.Fatal("part tiered")
	}
}
