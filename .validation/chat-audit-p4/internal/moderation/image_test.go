package moderation

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"
)

type imageHTTPFunc func(*http.Request) (*http.Response, error)

func (fn imageHTTPFunc) Do(r *http.Request) (*http.Response, error) { return fn(r) }
func TestActualImageContentResponses(t *testing.T) {
	for _, v := range []struct {
		body, want string
		ok         bool
	}{{`{"errcode":0}`, "passed", true}, {`{"errcode":87014}`, "review", true}, {`{}`, "", false}, {`{"errcode":999}`, "", false}, {"", "", false}} {
		t.Run(v.body, func(t *testing.T) {
			s := &Service{client: imageHTTPFunc(func(r *http.Request) (*http.Response, error) {
				if r.URL.Host != "api.weixin.qq.com" || r.URL.Path != "/wxa/img_sec_check" {
					t.Fatal(r.URL)
				}
				if e := r.ParseMultipartForm(2 << 20); e != nil {
					t.Fatal(e)
				}
				file, _, e := r.FormFile("media")
				if e != nil {
					t.Fatal(e)
				}
				defer file.Close()
				b, _ := io.ReadAll(file)
				if string(b) != "complete-frame" {
					t.Fatal(string(b))
				}
				return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(v.body))}, nil
			})}
			got, e := s.inspectImage(context.Background(), Credential{Token: "fixture-token"}, []byte("complete-frame"))
			if got != v.want || (e == nil) != v.ok {
				t.Fatal(got, e)
			}
		})
	}
}
