package message

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
)

func TestMySQLHTTPUsesVerifiedIdentityAndSharedSend(t *testing.T) {
	f, s, r := setupMessage(t, safetyFunc(pass))
	public, private, e := ed25519.GenerateKey(rand.Reader)
	if e != nil {
		t.Fatal(e)
	}
	verifier, e := auth.NewVerifier("test", map[string]ed25519.PublicKey{"fixture": public})
	if e != nil {
		t.Fatal(e)
	}
	source, e := auth.NewMySQLSource(f.goDB)
	if e != nil {
		t.Fatal(e)
	}
	identity, e := auth.NewService(verifier, source, nil, 4, time.Second)
	if e != nil {
		t.Fatal(e)
	}
	handler, e := NewHandler(s, identity, 4)
	if e != nil {
		t.Fatal(e)
	}
	server := httptest.NewServer(handler)
	defer server.Close()
	token := func(actor string) string {
		p := principal(actor)
		now := time.Now().Unix()
		claims := auth.Claims{Issuer: "bixuan-php:test", Audience: "bixuan-chat", ActorID: p.ActorID, Kind: p.Kind, SourceID: p.SourceID, SessionID: p.SessionID, Version: p.SessionVersion, Environment: "test", Scopes: p.Scopes, IssuedAt: now, NotBefore: now, ExpiresAt: now + 60, JTI: digest("fixture-jti")}
		payload, _ := json.Marshal(claims)
		header := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"EdDSA","typ":"JWT","kid":"fixture"}`))
		data := header + "." + base64.RawURLEncoding.EncodeToString(payload)
		return data + "." + base64.RawURLEncoding.EncodeToString(ed25519.Sign(private, []byte(data)))
	}
	credential := token("10202")
	call := func(method, path, body, credential string, status int) []byte {
		t.Helper()
		req, _ := http.NewRequest(method, server.URL+path, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		if credential != "" {
			req.Header.Set("Authorization", "Bearer "+credential)
		}
		res, e := server.Client().Do(req)
		if e != nil {
			t.Fatal(e)
		}
		defer res.Body.Close()
		out, _ := io.ReadAll(res.Body)
		if res.StatusCode != status {
			t.Fatalf("HTTP %d expected %d: %s", res.StatusCode, status, out)
		}
		if res.Header.Get("Cache-Control") != "no-store" || res.Header.Get("X-Request-ID") == "" {
			t.Fatal("response privacy headers")
		}
		return out
	}
	body, _ := json.Marshal(r)
	call("GET", "/chat/api/v1/messages/send", string(body), credential, 405)
	call("POST", "/chat/api/v1/messages/send", string(body), "", 401)
	call("POST", "/chat/api/v1/messages/send?sender=10203", string(body), credential, 400)
	call("POST", "/chat/api/v1/messages/send", strings.Repeat("a", (32<<10)+1), credential, 413)
	bad := strings.Replace(string(body), `"text":`, `"sender_id":"10203","text":`, 1)
	call("POST", "/chat/api/v1/messages/send", bad, credential, 400)
	out := call("POST", "/chat/api/v1/messages/send", string(body), credential, 200)
	if strings.Contains(string(out), r.Text) || !strings.Contains(string(out), `"state":"accepted"`) {
		t.Fatal("unsafe result body", string(out))
	}
	if again, e := s.Send(context.Background(), principal("10202"), r); e != nil || again.Seq != "1" {
		t.Fatalf("transport idempotency %+v %v", again, e)
	}
	r.Text += " changed"
	changed, _ := json.Marshal(r)
	call("POST", "/chat/api/v1/messages/send", string(changed), credential, 409)
	query, _ := json.Marshal(map[string]any{"conversation_id": r.ConversationID, "client_message_id": r.ClientMessageID})
	call("POST", "/chat/api/v1/messages/result", string(query), token("10203"), 409)
	f.exec("UPDATE chat_restrictions SET permanent=1 WHERE actor_id=10202")
	call("POST", "/chat/api/v1/messages/result", string(query), credential, 200)
	f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
	call("POST", "/chat/api/v1/messages/result", string(query), credential, 401)
	if f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("HTTP retries duplicated")
	}
}
