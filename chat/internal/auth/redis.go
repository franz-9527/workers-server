package auth

import (
	"context"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/redis/go-redis/v9"
)

type RedisStore struct {
	client *redis.Client
	prefix string
}

func NewRedisStore(client *redis.Client, environment, prefix string) (*RedisStore, error) {
	if client == nil || !validEnvironment(environment) || prefix != "bxchat:"+environment+":" {
		return nil, ports.ErrInvalid
	}
	return &RedisStore{client, prefix + "auth:"}, nil
}
func (s *RedisStore) Put(ctx context.Context, key, value string, ttl time.Duration) (bool, error) {
	if ttl <= 0 || ttl > time.Minute+time.Second || len(value) > 4096 || !validStoreKey(key) {
		return false, ports.ErrInvalid
	}
	ok, e := s.client.SetNX(ctx, s.prefix+key, value, ttl).Result()
	if e != nil {
		return false, ports.ErrUnavailable
	}
	return ok, nil
}
func (s *RedisStore) Take(ctx context.Context, key string) (string, error) {
	if !validStoreKey(key) {
		return "", ports.ErrInvalid
	}
	v, e := s.client.GetDel(ctx, s.prefix+key).Result()
	if e == redis.Nil {
		return "", nil
	}
	if e != nil {
		return "", ports.ErrUnavailable
	}
	return v, nil
}
func validStoreKey(s string) bool {
	if len(s) == 71 && s[:7] == "ticket:" {
		return hex64.MatchString(s[7:])
	}
	return len(s) == 70 && s[:6] == "nonce:" && hex64.MatchString(s[6:])
}
