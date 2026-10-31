package message

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/socket"
	"github.com/gorilla/websocket"
	"github.com/redis/go-redis/v9"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestMySQLRedisS23MixedLoad(t *testing.T) {
	for _, profile := range []struct{ connections, history int }{{30, 1000}, {100, 10000}, {300, 100000}} {
		t.Run(fmt.Sprintf("%d_connections_%d_history", profile.connections, profile.history), func(t *testing.T) {
			f, s, m, storage, q := mediaFixture(t)
			ctx, cancel := context.WithTimeout(context.Background(), 150*time.Second)
			defer cancel()
			f.ctx = ctx
			seedHistory(t, f, q, profile.history)
			h, err := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("h", 32)), 3*time.Second)
			if err != nil {
				t.Fatal(err)
			}
			address := os.Getenv("CHAT_AUTH_TEST_REDIS_ADDRESS")
			if address == "" {
				t.Fatal("isolated Redis required")
			}
			rc := redis.NewClient(&redis.Options{Addr: address, PoolSize: 2, MaxActiveConns: 2, MaxRetries: -1, DisableIdentity: true, Protocol: 2})
			defer rc.Close()
			tickets, err := auth.NewRedisStore(rc, "test", "bxchat:test:")
			if err != nil {
				t.Fatal(err)
			}
			pub, priv, _ := ed25519.GenerateKey(rand.Reader)
			verifier, _ := auth.NewVerifier("test", map[string]ed25519.PublicKey{"load": pub})
			source, _ := auth.NewMySQLSource(f.goDB)
			identity, err := auth.NewService(verifier, source, tickets, 8, time.Second)
			if err != nil {
				t.Fatal(err)
			}
			p := principal("10202")
			now := time.Now().Unix()
			claims := auth.Claims{Issuer: "bixuan-php:test", Audience: "bixuan-chat", ActorID: p.ActorID, Kind: p.Kind, SourceID: p.SourceID, SessionID: p.SessionID, Version: p.SessionVersion, Environment: "test", Scopes: p.Scopes, IssuedAt: now, NotBefore: now, ExpiresAt: now + 300, JTI: strings.Repeat("c", 64)}
			body, _ := json.Marshal(claims)
			unsigned := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"EdDSA","typ":"JWT","kid":"load"}`)) + "." + base64.RawURLEncoding.EncodeToString(body)
			token := unsigned + "." + base64.RawURLEncoding.EncodeToString(ed25519.Sign(priv, []byte(unsigned)))
			gateway, err := socket.NewWithPolicy(identity, SocketCommands{Sender: s, History: h}, f.store)
			if err != nil {
				t.Fatal(err)
			}
			defer func() {
				shutdown, stop := context.WithTimeout(context.Background(), 3*time.Second)
				defer stop()
				if gateway.Shutdown(shutdown) != nil {
					t.Error("drain")
				}
			}()
			readHTTP, _ := NewHistoryHandler(h, identity, 16)
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/chat/ws" {
					gateway.ServeHTTP(w, r)
					return
				}
				if r.URL.Path == "/fixture/order" {
					var state string
					err := f.root.QueryRowContext(r.Context(), "SELECT lifecycle_status FROM orders WHERE id=401").Scan(&state)
					if err != nil {
						w.WriteHeader(503)
						return
					}
					_, _ = io.WriteString(w, state)
					return
				}
				readHTTP.ServeHTTP(w, r)
			}))
			defer server.Close()
			clients := []*websocket.Conn{}
			defer func() {
				for _, ws := range clients {
					ws.Close()
				}
			}()
			for i := 0; i < profile.connections; i++ {
				ticket, _, err := identity.IssueTicket(ctx, token)
				if err != nil {
					t.Fatal("ticket", err)
				}
				ws, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/chat/ws", nil)
				if err != nil {
					t.Fatal(err)
				}
				clients = append(clients, ws)
				ws.SetReadDeadline(time.Now().Add(5 * time.Second))
				if ws.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "load-auth", "type": "auth", "payload": map[string]string{"ticket": ticket}}) != nil {
					t.Fatal("auth write")
				}
				var response struct {
					Type string `json:"type"`
				}
				if ws.ReadJSON(&response) != nil || response.Type != "auth.ok" {
					t.Fatal("authenticated connection", response)
				}
			}
			// Verify the hard connection cap rather than relying on unbounded goroutines.
			if profile.connections == 300 {
				extra, _, e := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/chat/ws", nil)
				if e == nil {
					extra.Close()
					t.Fatal("connection 301 admitted")
				}
			}
			var mu sync.Mutex
			orderTimes, chatTimes := []int64{}, []int64{}
			failures := []string{}
			record := func(kind string, start time.Time, err error) {
				mu.Lock()
				defer mu.Unlock()
				if err != nil {
					if len(failures) < 20 {
						failures = append(failures, kind+":"+err.Error())
					}
					return
				}
				ms := time.Since(start).Microseconds()
				if kind == "order" {
					orderTimes = append(orderTimes, ms)
				} else {
					chatTimes = append(chatTimes, ms)
				}
			}
			orderProbe := func() {
				start := time.Now()
				response, err := server.Client().Get(server.URL + "/fixture/order")
				if err == nil {
					io.Copy(io.Discard, response.Body)
					response.Body.Close()
					if response.StatusCode != 200 {
						err = fmt.Errorf("order status")
					}
				}
				record("order", start, err)
			}
			for i := 0; i < 20; i++ {
				orderProbe()
			}
			baseline := append([]int64(nil), orderTimes...)
			orderTimes = nil
			var wg sync.WaitGroup
			jobs := make(chan *websocket.Conn, profile.connections)
			for _, ws := range clients {
				jobs <- ws
			}
			close(jobs)
			for i := 0; i < 8; i++ {
				wg.Add(1)
				go func() {
					defer wg.Done()
					for ws := range jobs {
						start := time.Now()
						ws.SetReadDeadline(time.Now().Add(5 * time.Second))
						err := ws.WriteJSON(map[string]any{"protocol_version": 1, "request_id": "load-history", "type": "conversation.bootstrap", "payload": map[string]string{"conversation_id": string(q.ConversationID)}})
						var response struct {
							Type    string      `json:"type"`
							Payload HistoryPage `json:"payload"`
						}
						if err == nil {
							err = ws.ReadJSON(&response)
						}
						if err == nil && (response.Type != "conversation.bootstrap.result" || len(response.Payload.Messages) > 30) {
							err = fmt.Errorf("bounded page")
						}
						record("chat", start, err)
						orderProbe()
					}
				}()
			}
			wg.Add(1)
			go func() {
				defer wg.Done()
				for i := 0; i < 10; i++ {
					text := request(2300 + i)
					text.ConversationID = q.ConversationID
					_, err := s.Send(ctx, principal("10202"), text)
					record("send", time.Now(), err)
				}
			}()
			// Real media state machine with simulated private storage / local processor.
			q.MessageType = "image_album"
			q.Items = nil
			for i := 0; i < 9; i++ {
				v := mediaAdd(t, m, storage, q.ConversationID, 100+i, "load-construction")
				v.Ordinal = i
				q.Items = append(q.Items, v)
			}
			mediaProcess(t, m, 9)
			if _, err := s.Send(ctx, principal("10202"), q); err != nil {
				t.Fatal("mixed album", err)
			}
			if err := m.Advance(ctx); err != nil {
				t.Fatal(err)
			}
			mediaApprove(t, m)
			reminders, _ := NewReminderService(s, &reminderCapture{})
			if err := reminders.DrainEvents(ctx); err != nil {
				t.Fatal("mixed reminder", err)
			}
			f.exec("UPDATE chat_reminder_clusters SET next_due=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
			if err := reminders.Prepare(ctx); err != nil {
				t.Fatal(err)
			}
			if err := reminders.SubmitPending(ctx); err != nil {
				t.Fatal(err)
			}
			wg.Wait()
			if len(failures) > 0 {
				t.Fatal("mixed isolated failures", failures)
			}
			p95 := func(v []int64) int64 {
				sort.Slice(v, func(i, j int) bool { return v[i] < v[j] })
				if len(v) == 0 {
					return 0
				}
				return v[(len(v)-1)*95/100]
			}
			t.Logf("S23_CAPACITY {\"environment\":\"isolated_runner\",\"connections\":%d,\"history\":%d,\"order_probe_baseline_p95_us\":%d,\"order_probe_mixed_p95_us\":%d,\"chat_p95_us\":%d,\"real_order_api\":false,\"real_media_channel\":false}", profile.connections, profile.history, p95(baseline), p95(orderTimes), p95(chatTimes))
		})
	}
}
