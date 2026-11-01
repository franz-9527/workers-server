package message

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
"fmt"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
	"github.com/gorilla/websocket"
	"github.com/redis/go-redis/v9"
)

func TestMySQLRedisS11PostCommitPushAndMissedHintRecovery(t *testing.T) {
	f, s, h, q := historyFixture(t, 0)
	rdb := redis.NewClient(&redis.Options{Addr: os.Getenv("CHAT_AUTH_TEST_REDIS_ADDRESS"), PoolSize: 2, MaxActiveConns: 2, MaxRetries: -1, DisableIdentity: true, Protocol: 2})
	defer rdb.Close()
	store, e := auth.NewRedisStore(rdb, "test", "bxchat:test:")
	if e != nil {
		t.Fatal(e)
	}
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	v, _ := auth.NewVerifier("test", map[string]ed25519.PublicKey{"s11": pub})
	src, _ := auth.NewMySQLSource(f.goDB)
	identity, _ := auth.NewService(v, src, store, 4, time.Second)
	commands := &SocketCommands{History: h}
	g, e := socket.NewWithPolicy(identity, commands, f.store)
	if e != nil {
		t.Fatal(e)
	}
	commands.Sender = s.WithPublisher(g)
	server := httptest.NewServer(g)
	defer server.Close()
	defer func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		if e := g.Shutdown(ctx); e != nil {
			t.Error(e)
		}
	}()
	login := func(actor string) *websocket.Conn {
		t.Helper()
		p := principal(actor)
		now := time.Now().Unix()
		c := auth.Claims{Issuer: "bixuan-php:test", Audience: "bixuan-chat", ActorID: p.ActorID, Kind: p.Kind, SourceID: p.SourceID, SessionID: p.SessionID, Version: p.SessionVersion, Environment: p.Environment, Scopes: p.Scopes, IssuedAt: now, NotBefore: now, ExpiresAt: now + 300, JTI: strings.Repeat("c", 64)}
		raw, _ := json.Marshal(c)
		unsigned := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"EdDSA","typ":"JWT","kid":"s11"}`)) + "." + base64.RawURLEncoding.EncodeToString(raw)
		token := unsigned + "." + base64.RawURLEncoding.EncodeToString(ed25519.Sign(priv, []byte(unsigned)))
		ticket, _, e := identity.IssueTicket(f.ctx, token)
		if e != nil {
			t.Fatal(e)
		}
		ws, _, e := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/chat/ws", nil)
		if e != nil {
			t.Fatal(e)
		}
		t.Cleanup(func() { ws.Close() })
		ws.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "auth", "type": "auth", "payload": map[string]string{"ticket": ticket}})
		ws.SetReadDeadline(time.Now().Add(5 * time.Second))
		var out map[string]any
		if e := ws.ReadJSON(&out); e != nil || out["type"] != "auth.ok" {
			t.Fatalf("auth %v %v", out, e)
		}
		return ws
	}
	recipient := login("10203")
	boot, e := h.Read(f.ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if e != nil {
		t.Fatal(e)
	}
	result, e := commands.Sender.Send(f.ctx, principal("10202"), q)
	if e != nil || result.State != "accepted" {
		t.Fatal(result, e)
	}
	var hint struct {
		Type    string `json:"type"`
		Payload struct {
			ConversationID ports.ID `json:"conversation_id"`
			Seq            ports.ID `json:"highest_seq"`
		} `json:"payload"`
	}
	recipient.SetReadDeadline(time.Now().Add(5 * time.Second))
	if e := recipient.ReadJSON(&hint); e != nil || hint.Type != "message.new" || hint.Payload.Seq != "1" {
		t.Fatalf("postcommit %v %+v", e, hint)
	}
	// The row is already visible when the hint arrives; hints never invent seq.
	if f.text("SELECT COUNT(*) FROM chat_messages WHERE id=?", string(result.MessageID)) != "1" {
		t.Fatal("hint before commit")
	}
	recipient.Close()
	q.ClientMessageID = client(2)
	second := accepted(t, commands.Sender, q)
	// No live connection observed the second hint. The original signed cursor
	// still recovers both messages and the independent changes stream.
	page, e := h.Read(f.ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID, Cursor: boot.SyncCursor}, "sync")
	if e != nil || len(page.Messages) != 2 || page.Messages[1].ID != second.MessageID {
		t.Fatal("lost hint lost data", e)
	}
	changes, e := h.Read(f.ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID, Cursor: boot.ChangeCursor}, "changes")
	if e != nil || len(changes.Changes) != 2 {
		t.Fatal("change recovery", e)
	}
	if f.text("SELECT COUNT(*) FROM chat_member_reads") != "0" {
		t.Fatal("delivery/sync automatically marked read")
	}
	// Retries query/return the committed result and do not publish another row.
	accepted(t, commands.Sender, q)
	if f.text("SELECT COUNT(*) FROM chat_messages") != "2" {
		t.Fatal("retry duplicated publication")
	}
	recipient = login("10203")
	recipient.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "focus", "type": "conversation.focus", "payload": map[string]any{"conversation_id": q.ConversationID}})
	var frame map[string]any
	for {
		if e := recipient.ReadJSON(&frame); e != nil {
			t.Fatal(e)
		}
		if frame["request_id"] == "focus" {
			break
		}
	}
	// A recall emits a body-free state wakeup and recovers through the same
    // original change cursor even if this device misses that wakeup.
    if _,e:=commands.Sender.Recall(f.ctx,principal("10202"),q.ConversationID,result.MessageID);e!=nil{t.Fatal(e)}
    for{
        if e:=recipient.ReadJSON(&frame);e!=nil{t.Fatal(e)}
        if frame["type"]=="message.changed"{if strings.Contains(fmt.Sprint(frame),"text-"){t.Fatal("recall hint body")};break}
    }
    recovered,e:=h.Read(f.ctx,principal("10203"),HistoryRequest{ConversationID:q.ConversationID,Cursor:changes.ChangeCursor},"changes")
    if e!=nil||len(recovered.Changes)!=1||recovered.Changes[0].Message.State!="recalled"||recovered.Changes[0].Message.Text!=nil{t.Fatal("recall reconnect recovery",recovered,e)}
    // Drain the paired account wakeup before the stale-authorization barrier.
    for{if e:=recipient.ReadJSON(&frame);e!=nil{t.Fatal(e)};if frame["type"]=="account.summary.changed"{break}}
	// A stale projected membership is only a candidate, never permission.
	f.exec("UPDATE chat_policy_guards SET policy_version=policy_version+1 WHERE order_id=401")
	g.Committed(ports.CommittedMessage{ConversationID: q.ConversationID, Seq: "2", PolicyVersion: "1"})
	recipient.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "barrier", "type": "ping", "payload": map[string]string{"nonce": "done"}})
	recipient.SetReadDeadline(time.Now().Add(5 * time.Second))
	for {
		if e := recipient.ReadJSON(&frame); e != nil {
			t.Fatal(e)
		}
		if frame["request_id"] == "server" && frame["type"] == "presence.changed" {
			continue
		}
		if frame["type"] != "pong" {
			t.Fatal("stale membership leaked", frame)
		}
		break
	}
}
