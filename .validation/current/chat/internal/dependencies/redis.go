package dependencies

import (
	"context"
	"encoding/json"
	"strings"
	"sync/atomic"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/redis/go-redis/v9"
)

type silentRedisLog struct{}

func (silentRedisLog) Printf(context.Context, string, ...interface{}) {}
func init()                                                           { redis.SetLogger(silentRedisLog{}) }

// Count real short-lived business operations, not periodic PING health probes.
// Never record Redis keys, ticket values, credentials or command arguments.
type redisUsageCounters struct {
	writes, consumes, failures atomic.Uint64
	wakeWrites, wakeChecks, wakeHits, presenceWrites, presenceReads atomic.Uint64
}

func (u *redisUsageCounters) DialHook(next redis.DialHook) redis.DialHook { return next }
func (u *redisUsageCounters) ProcessPipelineHook(next redis.ProcessPipelineHook) redis.ProcessPipelineHook {
	return next
}
func (u *redisUsageCounters) ProcessHook(next redis.ProcessHook) redis.ProcessHook {
	return func(ctx context.Context, cmd redis.Cmder) error {
		name := cmd.Name()
		key := ""
		if args := cmd.Args(); len(args) > 1 {
			key, _ = args[1].(string)
		}
		err := next(ctx, cmd)
		tracked := true
		switch {
		case strings.Contains(key, ":auth:") && (name == "set" || name == "setnx"):
			if err == nil {
				if result, ok := cmd.(*redis.BoolCmd); !ok || result.Val() { u.writes.Add(1) }
			}
		case strings.Contains(key, ":auth:") && name == "getdel":
			if err == nil { u.consumes.Add(1) }
		case strings.Contains(key, ":wake:") && name == "set":
			if err == nil { u.wakeWrites.Add(1) }
		case strings.Contains(key, ":wake:") && name == "getdel":
			u.wakeChecks.Add(1)
			if err == nil { u.wakeHits.Add(1) }
		case strings.Contains(key, ":presence:") && name == "set":
			if err == nil { u.presenceWrites.Add(1) }
		case strings.Contains(key, ":presence:") && name == "get":
			u.presenceReads.Add(1)
		default:
			tracked = false
		}
		if tracked && err != nil && err != redis.Nil {
			u.failures.Add(1)
		}
		return err
	}
}

// Only a listening serve process owns live sockets. A snapshot is a hint,
// not authorization. Require two fresh samples before inferring offline;
// include the prior sample to dampen transient reconnect/background races.
type gatewayPresence struct {
	At int64 `json:"at"`
	Actors []ports.ID `json:"actors"`
	Previous []ports.ID `json:"previous"`
}

func (r *Runtime) publishPresence(ctx context.Context) {
	if r.role != "serve" || r.redis == nil || r.Socket == nil || !r.serving.Load() || !r.OperationsReady() || !r.Snapshot().InfrastructureReady {
		r.presencePrevious = nil
		r.presenceSampleAt = time.Time{}
		return
	}
	actors, ok := r.Socket.OnlineActors()
	if !ok || len(actors) > 10000 {
		r.presencePrevious = nil
		r.presenceSampleAt = time.Time{}
		return
	}
	now := time.Now()
	previous := r.presencePrevious
	if now.Sub(r.presenceSampleAt) > 8*time.Second { previous = nil }
	body, err := json.Marshal(gatewayPresence{At:now.Unix(), Actors:actors, Previous:previous})
	if err != nil { return }
	pass, cancel := context.WithTimeout(ctx, 200*time.Millisecond)
	defer cancel()
	if r.redis.Set(pass, r.settings.Redis.KeyPrefix+"presence:gateway", body, 15*time.Second).Err() != nil {
		r.presencePrevious = nil
		r.presenceSampleAt = time.Time{}
		return
	}
	r.presencePrevious, r.presenceSampleAt = actors, now
}

func (r *Runtime) accountPresence(actor ports.ID) (bool, bool) {
	if r.role != "worker" { return r.Socket.AccountPresence(actor) }
	if r.redis == nil { return false, false }
	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	raw, err := r.redis.Get(ctx, r.settings.Redis.KeyPrefix+"presence:gateway").Bytes()
	if err != nil { return false, false }
	return decodeGatewayPresence(raw,actor,time.Now())
}

func decodeGatewayPresence(raw []byte, actor ports.ID, now time.Time) (bool,bool) {
	if len(raw)>500000 { return false,false }
	var snapshot gatewayPresence
	if json.Unmarshal(raw,&snapshot)!=nil || snapshot.Actors==nil || len(snapshot.Actors)>10000 || len(snapshot.Previous)>10000 { return false,false }
	age:=now.Unix()-snapshot.At
	if age<0 || age>8 { return false,false }
	if !actor.Valid() || actor=="0" { return false,false }
	found := false
	for _, list:=range [][]ports.ID{snapshot.Actors,snapshot.Previous} {
		for _,id:=range list {
			if !id.Valid() || id=="0" { return false,false }
			if id==actor { found=true }
		}
	}
	if found { return true,true }
	if snapshot.Previous==nil { return false,false }
	return true,false
}

// A coalescing, short-lived wake hint, never a job queue. Only committed work
// signals it. Lost hints, Redis outages and restarts fall back to DB polling.
func (r *Runtime) signalMedia(kind string) {
	if r.redis==nil || (kind!="image" && kind!="av") { return }
	ctx,cancel:=context.WithTimeout(context.Background(),100*time.Millisecond)
	defer cancel()
	_ = r.redis.Set(ctx,r.settings.Redis.KeyPrefix+"wake:"+kind,"1",30*time.Second).Err()
}

func (r *Runtime) mediaWake(ctx context.Context, kind string) bool {
	if r.redis==nil { return false }
	pass,cancel:=context.WithTimeout(ctx,100*time.Millisecond)
	defer cancel()
	v,err:=r.redis.GetDel(pass,r.settings.Redis.KeyPrefix+"wake:"+kind).Result()
	return err==nil && v=="1"
}

// Worker has no public metrics listener. Publish only bounded aggregate
// counters with a short TTL; an expired snapshot is reported as unavailable.
func (r *Runtime) publishWorkerMetrics(ctx context.Context) {
	if r.role != "worker" || r.redis == nil { return }
	u := &r.redisUsage
	body, err := json.Marshal(map[string]uint64{
		"wake_checks":u.wakeChecks.Load(), "wake_hits":u.wakeHits.Load(),
		"wake_writes":u.wakeWrites.Load(), "presence_reads":u.presenceReads.Load(),
		"errors":u.failures.Load(),
	})
	if err != nil { return }
	pass,cancel:=context.WithTimeout(ctx,200*time.Millisecond)
	defer cancel()
	_ = r.redis.Set(pass,r.settings.Redis.KeyPrefix+"worker:metrics",body,30*time.Second).Err()
}

func openRedis(s Settings, role string) (*redis.Client, error) {
	pw, err := password(s.Redis.PasswordEnv, false)
	if err != nil {
		return nil, err
	}
	max := s.Redis.PoolServe
	if role == "worker" {
		max = s.Redis.PoolWorker
	}
	return redis.NewClient(&redis.Options{Addr: s.Redis.Address, Password: pw, Protocol: 2, ClientName: s.Redis.KeyPrefix + role,
		PoolSize: max, MaxActiveConns: max, MaxConcurrentDials: max, MaxIdleConns: max, MinIdleConns: 0,
		PipelinePoolSize: -1, MaxRetries: -1, DialerRetries: 1, DisableIdentity: true,
		DialTimeout: s.Timeout(), ReadTimeout: s.Timeout(), WriteTimeout: s.Timeout(), PoolTimeout: s.Timeout(),
		ContextTimeoutEnabled: true}), nil
}
