package dependencies

import (
 "context"
 "os"
 "sync/atomic"
 "testing"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
 "github.com/redis/go-redis/v9"
)

// Test-only composition boundary. Production Redis implementation is unmodified.
type gatewayFixture struct { actors []ports.ID }
func (g *gatewayFixture) OnlineActors() ([]ports.ID,bool) {return g.actors,true}
func (g *gatewayFixture) AccountPresence(ports.ID)(bool,bool){return false,false}
type fixtureSnapshot struct{InfrastructureReady bool}
type Runtime struct{
 role string
 redis *redis.Client
 settings Settings
 redisUsage redisUsageCounters
 Socket *gatewayFixture
 serving atomic.Bool
 presencePrevious []ports.ID
 presenceSampleAt time.Time
}
func(r *Runtime) OperationsReady()bool{return true}
func(r *Runtime) Snapshot()fixtureSnapshot{return fixtureSnapshot{true}}

func TestRealRedisWakeAndPresence(t *testing.T){
 addr:=os.Getenv("CHAT_INTEGRATION_REDIS_ADDR");if !loopback(addr){t.Fatal("isolated Redis required")}
 s:=Defaults();s.Redis.Enabled=true;s.Redis.Address=addr;s.Redis.KeyPrefix="bxchat:test:"
 ctx,cancel:=context.WithTimeout(context.Background(),10*time.Second);defer cancel()
 a,e:=openRedis(s,"serve");if e!=nil{t.Fatal(e)};defer a.Close()
 b,e:=openRedis(s,"worker");if e!=nil{t.Fatal(e)};defer b.Close()
 serve:=&Runtime{role:"serve",redis:a,settings:s,Socket:&gatewayFixture{actors:[]ports.ID{"12"}}}
 worker:=&Runtime{role:"worker",redis:b,settings:s}
 a.AddHook(&serve.redisUsage);b.AddHook(&worker.redisUsage)
 if e=a.Ping(ctx).Err();e!=nil{t.Fatal(e)}
 serve.publishPresence(ctx)
 if known,_:=worker.accountPresence("12");known{t.Fatal("not listening published")}
 serve.serving.Store(true);serve.publishPresence(ctx)
 if known,on:=worker.accountPresence("12");!known||!on{t.Fatal("online not shared")}
 if known,_:=worker.accountPresence("13");known{t.Fatal("first snapshot inferred offline")}
 serve.publishPresence(ctx)
 if known,on:=worker.accountPresence("13");!known||on{t.Fatal("two snapshots did not infer offline")}
 if worker.mediaWake(ctx,"image"){t.Fatal("empty wake")}
 serve.signalMedia("image");serve.signalMedia("image")
 if !worker.mediaWake(ctx,"image")||worker.mediaWake(ctx,"image"){t.Fatal("coalescing or atomic consume failed")}
 if worker.redisUsage.wakeChecks.Load()!=3||worker.redisUsage.wakeHits.Load()!=1{t.Fatal("wake counters")}
 serve.signalMedia("av")
 prod:=&Runtime{role:"worker",redis:b,settings:s};prod.settings.Redis.KeyPrefix="bxchat:production:"
 if prod.mediaWake(ctx,"av"){t.Fatal("cross environment wake")}
 if !worker.mediaWake(ctx,"av"){t.Fatal("test wake lost")}
 worker.publishWorkerMetrics(ctx)
 if _,e=b.Get(ctx,"bxchat:test:worker:metrics").Result();e!=nil{t.Fatal(e)}
 if e=b.Del(ctx,"bxchat:test:presence:gateway").Err();e!=nil{t.Fatal(e)}
 if known,_:=worker.accountPresence("12");known{t.Fatal("missing presence treated as known")}
 down:=s;down.Redis.Address="127.0.0.1:1"
 c,e:=openRedis(down,"worker");if e!=nil{t.Fatal(e)};defer c.Close()
 broken:=&Runtime{role:"worker",redis:c,settings:down}
 broken.signalMedia("image")
 if broken.mediaWake(ctx,"image"){t.Fatal("outage fabricated wake")}
 if known,_:=broken.accountPresence("12");known{t.Fatal("outage fabricated presence")}
 t.Log("actual Redis: presence, environment isolation, coalescing, loss and outage, counters")
}

func TestSnapshotDecoder(t *testing.T){
 now:=time.Unix(1000,0)
 cases:=[]struct{raw string;k,o bool}{
 {`["12"]`,false,false},
 {`{"at":1000,"actors":[],"previous":null}`,false,false},
 {`{"at":1000,"actors":["12"],"previous":null}`,true,true},
 {`{"at":999,"actors":[],"previous":["12"]}`,true,true},
 {`{"at":999,"actors":[],"previous":[]}`,true,false},
 {`{"at":991,"actors":[],"previous":[]}`,false,false},
 {`{"at":1001,"actors":[],"previous":[]}`,false,false},
 {`{"at":1000,"actors":["12","invalid"],"previous":[]}`,false,false},
 }
 for _,c:=range cases{ k,o:=decodeGatewayPresence([]byte(c.raw),"12",now);if k!=c.k||o!=c.o{t.Fatalf("decode %s got %v %v",c.raw,k,o)}}
}
