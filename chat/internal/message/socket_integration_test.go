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
	"os"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/socket"
	"github.com/gorilla/websocket"
	"github.com/redis/go-redis/v9"
)

func TestMySQLRedisS10HTTPAndWSSUseTheSameCommands(t *testing.T) {
	f, s, h, q := historyFixture(t, 0)
	address := os.Getenv("CHAT_AUTH_TEST_REDIS_ADDRESS")
	if address == "" {
		t.Fatal("explicit isolated Redis required")
	}
	redisClient := redis.NewClient(&redis.Options{Addr: address, PoolSize: 2, MaxActiveConns: 2, MaxRetries: -1, DisableIdentity: true, Protocol: 2})
	defer redisClient.Close()
	ticketStore, e := auth.NewRedisStore(redisClient, "test", "bxchat:test:")
	if e != nil {
		t.Fatal(e)
	}
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	verifier, _ := auth.NewVerifier("test", map[string]ed25519.PublicKey{"s10": pub})
	source, _ := auth.NewMySQLSource(f.goDB)
	identity, e := auth.NewService(verifier, source, ticketStore, 4, time.Second)
	if e != nil {
		t.Fatal(e)
	}
	p := principal("10202")
	now := time.Now().Unix()
	claims := auth.Claims{Issuer: "bixuan-php:test", Audience: "bixuan-chat", ActorID: p.ActorID, Kind: p.Kind, SourceID: p.SourceID, SessionID: p.SessionID, Version: p.SessionVersion, Environment: p.Environment, Scopes: p.Scopes, IssuedAt: now, NotBefore: now, ExpiresAt: now + 300, JTI: strings.Repeat("c", 64)}
	raw, _ := json.Marshal(claims)
	unsigned := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"EdDSA","typ":"JWT","kid":"s10"}`)) + "." + base64.RawURLEncoding.EncodeToString(raw)
	token := unsigned + "." + base64.RawURLEncoding.EncodeToString(ed25519.Sign(priv, []byte(unsigned)))
	gateway, e := socket.New(identity, SocketCommands{Sender: s, History: h})
	if e != nil {
		t.Fatal(e)
	}
	sendHTTP, _ := NewHandler(s, identity, 4)
	readHTTP, _ := NewHistoryHandler(h, identity, 4)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/chat/ws" {
			gateway.ServeHTTP(w, r)
		} else if strings.HasSuffix(r.URL.Path, "/send") {
			sendHTTP.ServeHTTP(w, r)
		} else {
			readHTTP.ServeHTTP(w, r)
		}
	}))
	defer server.Close()
	defer func() {
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		defer cancel()
		if e := gateway.Shutdown(ctx); e != nil {
			t.Error(e)
		}
	}()
	ws, _, e := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/chat/ws", nil)
	if e != nil {
		t.Fatal(e)
	}
	defer ws.Close()
	call := func(typ string, payload any) (string, json.RawMessage) {
		t.Helper()
		if e := ws.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "s10", "type": typ, "payload": payload}); e != nil {
			t.Fatal(e)
		}
		ws.SetReadDeadline(time.Now().Add(5 * time.Second))
		var out struct {
			Type    string          `json:"type"`
			Payload json.RawMessage `json:"payload"`
		}
		if e := ws.ReadJSON(&out); e != nil {
			t.Fatal(e)
		}
		return out.Type, out.Payload
	}
	ticket, _, e := identity.IssueTicket(f.ctx, token)
	if e != nil {
		t.Fatal(e)
	}
	typ, _ := call("auth", map[string]string{"ticket": ticket})
	if typ != "auth.ok" {
		t.Fatal(typ)
	}
	typ, body := call("message.send", q)
	var first Result
	if typ != "message.accepted" || json.Unmarshal(body, &first) != nil || first.Seq != "1" {
		t.Fatalf("send %s %s", typ, body)
	}
	httpCall := func(path string, payload any) json.RawMessage {
		t.Helper()
		b, _ := json.Marshal(payload)
		r, _ := http.NewRequest("POST", server.URL+path, strings.NewReader(string(b)))
		r.Header.Set("Authorization", "Bearer "+token)
		r.Header.Set("Content-Type", "application/json")
		res, e := server.Client().Do(r)
		if e != nil {
			t.Fatal(e)
		}
		defer res.Body.Close()
		b, _ = io.ReadAll(res.Body)
		if res.StatusCode != 200 {
			t.Fatalf("HTTP %d %s", res.StatusCode, b)
		}
		var out struct {
			Data json.RawMessage `json:"data"`
		}
		if json.Unmarshal(b, &out) != nil {
			t.Fatal("HTTP data")
		}
		return out.Data
	}
	var repeated Result
	json.Unmarshal(httpCall("/chat/api/v1/messages/send", q), &repeated)
	if repeated.MessageID != first.MessageID || f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("HTTP retry duplicated WSS send")
	}
	typ, body = call("message.status", map[string]string{"conversation_id": string(q.ConversationID), "client_message_id": q.ClientMessageID})
	json.Unmarshal(body, &repeated)
	if typ != "message.status.result" || repeated.MessageID != first.MessageID {
		t.Fatal("WSS result lookup")
	}
	typ, body = call("conversation.bootstrap", HistoryRequest{ConversationID: q.ConversationID})
	var boot HistoryPage
	if typ != "conversation.bootstrap.result" || json.Unmarshal(body, &boot) != nil || len(boot.Messages) != 1 {
		t.Fatalf("bootstrap %s %s", typ, body)
	}
	// Cross-transport opaque cursors are identical contracts, not numeric seqs.
	var syncPage HistoryPage
	json.Unmarshal(httpCall("/chat/api/v1/messages/sync", HistoryRequest{ConversationID: q.ConversationID, Cursor: boot.SyncCursor}), &syncPage)
	typ, body = call("receipt.read", map[string]string{"conversation_id": string(q.ConversationID), "cursor": syncPage.SyncCursor})
	if typ != "receipt.read.result" {
		t.Fatalf("read %s %s", typ, body)
	}
	if f.text("SELECT COUNT(*) FROM chat_member_reads") != "1" {
		t.Fatal("explicit read did not commit")
	}
	// Scope/policy checks are shared too. Revoking the PHP guard cannot be
	// bypassed through an already authenticated socket.
	f.exec("UPDATE chat_policy_guards SET policy_version=policy_version+1 WHERE order_id=401")
	typ, body = call("conversation.bootstrap", HistoryRequest{ConversationID: q.ConversationID})
	if typ != "error" || !strings.Contains(string(body), "CHAT_PERMISSION_DENIED") {
		t.Fatalf("revoked policy %s %s", typ, body)
	}
	// PHP logout invalidates an existing WSS identity before the next command;
	// there is no wait for an advisory Redis event or periodic idle check.
	f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
	if e = ws.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "logout", "type": "conversation.bootstrap", "payload": HistoryRequest{ConversationID: q.ConversationID}}); e != nil {
		t.Fatal(e)
	}
	ws.SetReadDeadline(time.Now().Add(5 * time.Second))
	if _, _, e = ws.ReadMessage(); !websocket.IsCloseError(e, websocket.ClosePolicyViolation) {
		t.Fatalf("revoked PHP login kept socket usable: %v", e)
	}
}

func TestMySQLS15SocketRecallUsesSameDecoderAndUseCase(t *testing.T){
 f,s,h,q:=historyFixture(t,0);m:=accepted(t,s,q)
 commands:=SocketCommands{Sender:s,History:h}
 raw,_:=json.Marshal(map[string]any{"conversation_id":q.ConversationID,"message_id":m.MessageID})
 typ,out,e:=commands.Execute(f.ctx,principal("10202"),"message.recall",raw)
 if e!=nil||typ!="message.recall.result"||out.(MessageView).Text!=nil||out.(MessageView).State!="recalled"{t.Fatal(typ,out,e)}
 if _,_,e:=commands.Execute(f.ctx,principal("10202"),"message.recall",append(raw[:len(raw)-1],[]byte(`,"read_seq":"1"}`)...));e==nil{t.Fatal("recall extra field")}
}
