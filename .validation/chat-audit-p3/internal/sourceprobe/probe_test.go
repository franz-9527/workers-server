package sourceprobe

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestVerifyMP3Fixtures(t *testing.T) {
	mp3 := append([]byte("ID3\x04\x00\x00\x00\x00\x00\x00"), make([]byte, 1024)...)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.Header.Get("Range") != "bytes=0-4095" {
			t.Errorf("expected bounded GET, got %s / %s", r.Method, r.Header.Get("Range"))
		}
		switch r.URL.Path {
		case "/ok":
			w.Header().Set("Content-Type", "audio/mpeg")
			w.Write(mp3)
		case "/partial":
			w.Header().Set("Content-Type", "application/octet-stream")
			w.Header().Set("Content-Range", "bytes 0-1023/4000")
			w.WriteHeader(http.StatusPartialContent)
			w.Write([]byte{0xff, 0xfb, 0x90, 0x64, 1, 2, 3, 4})
		case "/forbidden":
			w.WriteHeader(http.StatusForbidden)
		case "/redirect":
			http.Redirect(w, r, "/ok", http.StatusFound)
		case "/html":
			w.Header().Set("Content-Type", "text/html")
			w.Write([]byte("<html>not an audio file</html>"))
		case "/fake":
			w.Header().Set("Content-Type", "audio/mpeg")
			w.Write([]byte("<html>blocked</html>"))
		case "/short":
			w.Header().Set("Content-Type", "audio/mpeg")
			w.Write([]byte("ID"))
		case "/wrongrange":
			w.Header().Set("Content-Range", "bytes 4096-8000/8001")
			w.WriteHeader(http.StatusPartialContent)
			w.Write(mp3)
		case "/huge":
			w.Header().Set("Content-Type", "audio/mpeg")
			w.Write(append(mp3, make([]byte, 1<<20)...))
		case "/mp3-frame":
			w.Write([]byte{0xff, 0xfb, 0x90, 0x64, 1, 2, 3, 4})
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()
	client := &http.Client{CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}
	for _, tc := range []struct {
		path, want string
	}{
		{"/ok", ""}, {"/partial", ""}, {"/mp3-frame", ""}, {"/huge", ""},
		{"/forbidden", CodeUnreachable}, {"/redirect", CodeUnreachable},
		{"/html", CodeInvalidMP3}, {"/fake", CodeInvalidMP3},
		{"/short", CodeInvalidMP3}, {"/wrongrange", CodeInvalidMP3},
	} {
		t.Run(tc.path, func(t *testing.T) {
			err := VerifyMP3(context.Background(), client, srv.URL+tc.path)
			if tc.want == "" {
				if err != nil { t.Fatalf("unexpected failure: %v", err) }
				return
			}
			var f Failure
			if !errors.As(err, &f) || f.FailureCode() != tc.want {
				t.Fatalf("failure code = %v; want %s", err, tc.want)
			}
			if strings.Contains(err.Error(), srv.URL) {
				t.Fatal("signed URL leaked into failure text")
			}
		})
	}
}

func TestVerifyMP3RejectsTransportAndCancellation(t *testing.T) {
	if err := VerifyMP3(context.Background(), nil, "https://unused.invalid/audio.mp3"); err == nil {
		t.Fatal("nil client should fail")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = io.WriteString(w, "ID3") }))
	defer srv.Close()
	err := VerifyMP3(ctx, srv.Client(), srv.URL+"/sound.mp3?token=private")
	var f Failure
	if !errors.As(err, &f) || f.FailureCode() != CodeUnreachable {
		t.Fatalf("canceled context failure: %v", err)
	}
	if strings.Contains(err.Error(), "token=") {
		t.Fatal("signed token leaked")
	}
}
