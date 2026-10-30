package dependencies

import (
	"context"
	"github.com/redis/go-redis/v9"
)

type silentRedisLog struct{}

func (silentRedisLog) Printf(context.Context, string, ...interface{}) {}
func init()                                                           { redis.SetLogger(silentRedisLog{}) }

func openRedis(s Settings, role string) (*redis.Client, error) {
	pw, err := password(s.Redis.PasswordEnv, false)
	if err != nil {
		return nil, err
	}
	max := s.Redis.PoolServe
	if role == "worker" {
		max = s.Redis.PoolWorker
	}
	return redis.NewClient(&redis.Options{Addr: s.Redis.Address, Password: pw, Protocol: 2,
		PoolSize: max, MaxActiveConns: max, MaxConcurrentDials: max, MaxIdleConns: max, MinIdleConns: 0,
		PipelinePoolSize: -1, MaxRetries: -1, DialerRetries: 1, DisableIdentity: true,
		DialTimeout: s.Timeout(), ReadTimeout: s.Timeout(), WriteTimeout: s.Timeout(), PoolTimeout: s.Timeout(),
		ContextTimeoutEnabled: true}), nil
}
