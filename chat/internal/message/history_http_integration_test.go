package message

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type historyFixtureIdentity struct{}

func (historyFixtureIdentity) Authenticate(ctx context.Context, token string) (ports.Principal, error) {
	if token != "fixture-only" {
		return ports.Principal{}, auth.ErrUnauthorized
	}
	return principal("10202"), nil
}
func TestMySQLS08HTTPReadBoundary(t *testing.T) {
	f, _, h, r := historyFixture(t, 31)
	handler, e := NewHistoryHandler(h, historyFixtureIdentity{}, 4)
	if e != nil {
		t.Fatal(e)
	}
	server := httptest.NewServer(handler)
	defer server.Close()
	call := func(path, body, credential string, status int) []byte {
		t.Helper()
		req, _ := http.NewRequest("POST", server.URL+path, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		if credential != "" {
			req.Header.Set("Authorization", "Bearer "+credential)
		}
		res, e := server.Client().Do(req)
		if e != nil {
			t.Fatal(e)
		}
		defer res.Body.Close()
		raw, _ := io.ReadAll(res.Body)
		if res.StatusCode != status {
			t.Fatalf("HTTP %d want %d: %s", res.StatusCode, status, raw)
		}
		if res.Header.Get("Cache-Control") != "no-store" || res.Header.Get("X-Request-ID") == "" {
			t.Fatal("privacy headers")
		}
		return raw
	}
	body := `{"conversation_id":"` + string(r.ConversationID) + `"}`
	call("/chat/api/v1/conversations/bootstrap", body, "", 401)
	call("/chat/api/v1/conversations/bootstrap", body, "bad", 401)
	raw := call("/chat/api/v1/conversations/bootstrap", body, "fixture-only", 200)
	var out struct {
		Data HistoryPage `json:"data"`
	}
	if json.Unmarshal(raw, &out) != nil || len(out.Data.Messages) != 30 {
		t.Fatal("bootstrap HTTP")
	}
	call("/chat/api/v1/messages/history", `{"conversation_id":"`+string(r.ConversationID)+`","cursor":"`+out.Data.HistoryCursor+`"}`, "fixture-only", 200)
	call("/chat/api/v1/messages/sync", `{"conversation_id":"`+string(r.ConversationID)+`","cursor":"invalid"}`, "fixture-only", 409)
	call("/chat/api/v1/messages/sync", `{"conversation_id":"`+string(r.ConversationID)+`","after_seq":"103"}`, "fixture-only", 400)
	call("/chat/api/v1/conversations/bootstrap?actor=10203", body, "fixture-only", 400)
	call("/chat/api/v1/conversations/bootstrap", strings.Repeat("a", 4097), "fixture-only", 413)
	f.exec(`UPDATE login_sessions SET status='revoked' WHERE id=10202`)
	call("/chat/api/v1/conversations/bootstrap", body, "fixture-only", 403)
}
