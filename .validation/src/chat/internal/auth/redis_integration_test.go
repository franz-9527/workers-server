package auth

import (
	"context"
	"net"
	"os"
	"sync"
	"sync/atomic"
	"testing"
	"time"

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
