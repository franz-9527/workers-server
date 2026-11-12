package auth

import (
	"bufio"
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func TestAuthHTTPBoundary(t *testing.T) {
	s, priv, c, m := fixture(t)
	h, e := NewHandler(s, 2)
	if e != nil {
		t.Fatal(e)
	}
	credential := token(priv, c)
	for _, tc := range []struct {
		method, path, body, credential string
		status                         int
	}{
		{"GET", "/chat/api/v1/auth/session", "", credential, 200},
		{"POST", "/chat/api/v1/auth/socket-ticket", "{}", credential, 200},
		{"GET", "/chat/api/v1/auth/socket-ticket", "", credential, 405},
		{"GET", "/chat/api/v1/auth/session?token=secret-input", "", credential, 400},
		{"GET", "/chat/api/v1/auth/session", "", "", 401},
		{"GET", "/chat/api/v1/auth/session", "", "bad", 401},
		{"POST", "/chat/api/v1/auth/socket-ticket", `{"actor_id":"3"}`, credential, 400},
		{"POST", "/chat/api/v1/auth/socket-ticket", strings.Repeat("x", 257), credential, 413},
		{"GET", "/chat/api/v1/messages", "", credential, 404},
	} {
		r := httptest.NewRequest(tc.method, tc.path, strings.NewReader(tc.body))
		if tc.credential != "" {
			r.Header.Set("Authorization", "Bearer "+tc.credential)
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		if w.Code != tc.status || w.Header().Get("Cache-Control") != "no-store" || strings.Contains(w.Body.String(), credential) || strings.Contains(w.Body.String(), "secret-input") {
			t.Fatalf("%s expected %d got %d", tc.path, tc.status, w.Code)
		}
	}
	m.fail = true
	r := httptest.NewRequest("POST", "/chat/api/v1/auth/socket-ticket", nil)
	r.Header.Set("Authorization", "Bearer "+credential)
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 503 {
		t.Fatal(w.Code)
	}
	s.source = sourceFunc(func(context.Context, Claims) error { return ports.ErrUnavailable })
	r = httptest.NewRequest("GET", "/chat/api/v1/auth/session", nil)
	r.Header.Set("Authorization", "Bearer "+credential)
	w = httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 503 {
		t.Fatal(w.Code)
	}
}

func TestAuthHTTPBodyDeadline(t *testing.T) {
	s, priv, c, _ := fixture(t)
	h, _ := NewHandler(s, 2)
	server := httptest.NewServer(h)
	defer server.Close()
	connection, e := net.DialTimeout("tcp", strings.TrimPrefix(server.URL, "http://"), time.Second)
	if e != nil {
		t.Fatal(e)
	}
	defer connection.Close()
	connection.SetDeadline(time.Now().Add(time.Second))
	request := "POST /chat/api/v1/auth/socket-ticket HTTP/1.1\r\nHost: fixture\r\nAuthorization: Bearer " + token(priv, c) + "\r\nContent-Length: 2\r\n\r\n{"
	if _, e = connection.Write([]byte(request)); e != nil {
		t.Fatal(e)
	}
	response, e := http.ReadResponse(bufio.NewReader(connection), nil)
	if e != nil {
		t.Fatal(e)
	}
	defer response.Body.Close()
	if response.StatusCode != 408 {
		t.Fatal(response.StatusCode)
	}
}
