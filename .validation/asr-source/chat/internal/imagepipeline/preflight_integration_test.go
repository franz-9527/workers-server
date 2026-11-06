package imagepipeline

import (
	"context"
	"errors"
	"io"
	"net/http"
	"strings"
	"testing"

	"github.com/franz-9527/workers/chat/internal/sourceprobe"
)

type fakeAVTransport func(*http.Request) (*http.Response, error)
func (f fakeAVTransport) RoundTrip(req *http.Request) (*http.Response, error) { return f(req) }

func TestInspectionURLVerifiesProviderDownloadBeforeReturning(t *testing.T) {
	key := "chatav/test/9/" + strings.Repeat("a", 64) + "/" + strings.Repeat("b", 64) + "/main.mp3"
	for _, tc := range []struct {
		name string
		private bool
		status int
		mime string
		body string
		want string
	}{
		{"public-ok",false,200,"audio/mpeg","ID3\x04\x00\x00\x00\x00\x00\x00",""},
		{"private-ok",true,206,"application/octet-stream","ID3\x04\x00\x00\x00\x00\x00\x00",""},
		{"private-denied",true,403,"text/html","forbidden",sourceprobe.CodeUnreachable},
		{"public-html",false,200,"text/html","<html>WAF</html>",sourceprobe.CodeInvalidMP3},
		{"public-bad-bytes",false,200,"audio/mpeg","<html>WAF</html>",sourceprobe.CodeInvalidMP3},
	}{
		t.Run(tc.name,func(t *testing.T){
			var statCalls, downloadCalls int
			q := &Qiniu{config:QiniuConfig{
				Bucket:"fixture", AccessKey:"fixture-ak", SecretKey:"fixture-sk",
				DownloadOrigin:"https://cdn.fixture.invalid", Environment:"test", Private:tc.private,
			}}
			q.client = &http.Client{
				CheckRedirect:func(_ *http.Request,_ []*http.Request)error{return http.ErrUseLastResponse},
				Transport:fakeAVTransport(func(req *http.Request)(*http.Response,error){
					switch req.URL.Host {
					case "rs.qiniuapi.com":
						statCalls++
						return &http.Response{StatusCode:200,Body:io.NopCloser(strings.NewReader(`{"fsize":1024,"mimeType":"audio/mpeg"}`))},nil
					case "cdn.fixture.invalid":
						downloadCalls++
						if req.Header.Get("Range")!="bytes=0-4095" {t.Fatal("missing bounded range")}
						if tc.private && !strings.Contains(req.URL.RawQuery,"token=") {t.Fatal("missing signed URL")}
						if !tc.private && req.URL.RawQuery!="" {t.Fatal("unexpected public token")}
						h:=http.Header{}
						h.Set("Content-Type",tc.mime)
						if tc.status==206 {h.Set("Content-Range","bytes 0-9/1024")}
						return &http.Response{StatusCode:tc.status,Header:h,Body:io.NopCloser(strings.NewReader(tc.body)),Request:req},nil
					default:
						t.Fatalf("unexpected request host %s",req.URL.Host)
						return nil,errors.New("unreachable")
					}
				}),
			}
			u,e:=q.InspectionURL(context.Background(),key)
			if statCalls!=1||downloadCalls!=1{t.Fatalf("stat=%d download=%d",statCalls,downloadCalls)}
			if tc.want=="" {
				if e!=nil||u==""{t.Fatalf("unexpected source validation failure: %v",e)}
				return
			}
			var failure sourceprobe.Failure
			if !errors.As(e,&failure)||failure.FailureCode()!=tc.want||u!="" {
				t.Fatalf("unsafe InspectionURL result: url=%q error=%v",u,e)
			}
			if strings.Contains(e.Error(),"fixture-ak")||strings.Contains(e.Error(),"token="){
				t.Fatal("signed URL leaked in failure")
			}
		})
	}
}
