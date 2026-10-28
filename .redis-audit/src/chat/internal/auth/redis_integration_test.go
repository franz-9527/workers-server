package auth

import (
	"context"
	"net"
	"os"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/redis/go-redis/v9"
)

// Explicit isolated endpoint only; never infer a production/default Redis.
func TestRedisAtomicTicketAndNonce(t *testing.T) {
	address := os.Getenv("CHAT_AUTH_TEST_REDIS_ADDRESS")
	if address == "" {
		t.Skip("isolated Redis endpoint not provided")
	}
	h, _, e := net.SplitHostPort(address)
	if e != nil || net.ParseIP(h) == nil || !net.ParseIP(h).IsLoopback() {
		t.Fatal("isolated loopback required")
	}
	client := redis.NewClient(&redis.Options{Addr: address, PoolSize: 2, MaxActiveConns: 2, MaxRetries: -1, PipelinePoolSize: -1, DisableIdentity: true, Protocol: 2})
	defer client.Close()
	store, e := NewRedisStore(client, "test", "bxchat:test:")
	if e != nil {
		t.Fatal(e)
	}
	s, priv, c, _ := fixture(t)
	s.store = store
	ctx := context.Background()
	ticket, _, e := s.IssueTicket(ctx, token(priv, c))
	if e != nil {
		t.Fatal(e)
	}
	key := "bxchat:test:auth:ticket:" + digest(ticket)
	ttl := client.PTTL(ctx, key).Val()
	if ttl <= 0 || ttl > 30*time.Second {
		t.Fatal(ttl)
	}
	var wg sync.WaitGroup
	var count atomic.Int32
	for range 32 {
		wg.Go(func() {
			if _, e := s.ConsumeTicket(ctx, ticket); e == nil {
				count.Add(1)
			}
		})
	}
	wg.Wait()
	if count.Load() != 1 {
		t.Fatal(count.Load())
	}
	if client.Exists(ctx, key).Val() != 0 {
		t.Fatal("consumed ticket survived")
	}
	nonce, _ := randomID()
	nonceKey := "nonce:" + digest(nonce)
	defer client.Del(ctx, "bxchat:test:auth:"+nonceKey)
	count.Store(0)
	for range 24 {
		wg.Go(func() {
			if ok, e := store.Put(ctx, nonceKey, "1", 61*time.Second); e == nil && ok {
				count.Add(1)
			}
		})
	}
	wg.Wait()
	if count.Load() != 1 {
		t.Fatal("nonce reservation", count.Load())
	}
	ttl = client.PTTL(ctx, "bxchat:test:auth:"+nonceKey).Val()
	if ttl < 60*time.Second || ttl > 61*time.Second {
		t.Fatal(ttl)
	}
	if _, e := NewRedisStore(client, "production", "bxchat:test:"); e == nil {
		t.Fatal("cross environment prefix")
	}
}

func TestRedisExpiryAndFailureClosed(t *testing.T) {
	address := os.Getenv("CHAT_AUTH_TEST_REDIS_ADDRESS")
	if address == "" {
		t.Skip("isolated Redis endpoint not provided")
	}
	host, _, err := net.SplitHostPort(address)
	if err != nil || net.ParseIP(host) == nil || !net.ParseIP(host).IsLoopback() {
		t.Fatal("isolated loopback required")
	}
	client := redis.NewClient(&redis.Options{Addr: address, Protocol: 2, DisableIdentity: true, MaxRetries: -1, PoolSize: 2, MaxActiveConns: 2, PipelinePoolSize: -1})
	defer client.Close()
	store, err := NewRedisStore(client, "test", "bxchat:test:")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	id, err := randomID()
	if err != nil {
		t.Fatal(err)
	}
	key := "nonce:" + digest(id)
	full := "bxchat:test:auth:" + key
	defer client.Del(context.Background(), full)
	if ok, err := store.Put(ctx, key, "expiry", 40*time.Millisecond); err != nil || !ok {
		t.Fatal("millisecond SET NX failed")
	}
	deadline := time.Now().Add(time.Second)
	for client.Exists(ctx, full).Val() != 0 && time.Now().Before(deadline) {
		time.Sleep(5*time.Millisecond)
	}
	if client.Exists(ctx, full).Val() != 0 {
		t.Fatal("Redis did not expire the nonce")
	}
	if value, err := store.Take(ctx, key); err != nil || value != "" {
		t.Fatal("expired nonce became usable")
	}
	for _, ttl := range []time.Duration{0, 62*time.Second} {
		if _, err := store.Put(ctx, key, "invalid", ttl); err != ports.ErrInvalid {
			t.Fatal("out-of-contract TTL accepted")
		}
	}
	if client.HSet(ctx, full, "field", "value").Err() != nil {
		t.Fatal("wrong-type fixture failed")
	}
	if _, err := store.Take(ctx, key); err != ports.ErrUnavailable || client.Exists(ctx, full).Val() != 1 {
		t.Fatal("GETDEL type error must fail closed without accepting a nonce")
	}
	client.Del(ctx, full)
	username, password := "bxchat_test_"+digest(id)[:16], digest(id+":password")
	if client.Do(ctx, "ACL", "SETUSER", username, "reset", "on", ">"+password, "~bxchat:test:auth:*", "+ping", "+hello", "+set").Err() != nil {
		t.Fatal("isolated ACL fixture failed")
	}
	defer client.Do(context.Background(), "ACL", "DELUSER", username)
	restricted := redis.NewClient(&redis.Options{Addr: address, Username: username, Password: password, Protocol: 2, DisableIdentity: true, MaxRetries: -1, PoolSize: 1, MaxActiveConns: 1, PipelinePoolSize: -1})
	defer restricted.Close()
	limited, err := NewRedisStore(restricted, "test", "bxchat:test:")
	if err != nil {
		t.Fatal(err)
	}
	if ok, err := limited.Put(ctx, key, "acl", time.Second); err != nil || !ok {
		t.Fatal("allowed SET NX failed under ACL")
	}
	if _, err := limited.Take(ctx, key); err != ports.ErrUnavailable || client.Exists(ctx, full).Val() != 1 {
		t.Fatal("GETDEL ACL denial bypassed fail-closed authentication")
	}
	client.Del(ctx, full)
	if err := restricted.Close(); err != nil {
		t.Fatal("close isolated client")
	}
	if _, err := limited.Put(ctx, key, "closed", time.Second); err != ports.ErrUnavailable {
		t.Fatal("closed Redis client accepted a nonce")
	}
	if _, err := limited.Take(ctx, key); err != ports.ErrUnavailable {
		t.Fatal("closed Redis client consumed a nonce")
	}
}
