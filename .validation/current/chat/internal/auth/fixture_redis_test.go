package auth

import (
 "context"
 "os"
 "regexp"
 "testing"
 "time"
 "strings"
 "github.com/redis/go-redis/v9"
)
var hex64=regexp.MustCompile(`^[a-f0-9]{64}$`)
func validEnvironment(s string)bool{return s=="development"||s=="test"||s=="production"}
func TestRealAuthStore(t *testing.T){
 addr:=os.Getenv("CHAT_INTEGRATION_REDIS_ADDR");if !strings.HasPrefix(addr,"127.0.0.1:"){t.Fatal("fixture required")}
 ctx,cancel:=context.WithTimeout(context.Background(),8*time.Second);defer cancel()
 c:=redis.NewClient(&redis.Options{Addr:addr});defer c.Close()
 s,e:=NewRedisStore(c,"test","bxchat:test:");if e!=nil{t.Fatal(e)}
 p,e:=NewRedisStore(c,"production","bxchat:production:");if e!=nil{t.Fatal(e)}
 key:="nonce:"+strings.Repeat("a",64)
 defer c.Del(ctx,"bxchat:test:auth:"+key)
 ok,e:=s.Put(ctx,key,"fixture",time.Second);if e!=nil||!ok{t.Fatal("put",e)}
 ok,e=s.Put(ctx,key,"duplicate",time.Second);if e!=nil||ok{t.Fatal("duplicate accepted")}
 if v,e:=p.Take(ctx,key);e!=nil||v!=""{t.Fatal("cross environment")}
 if v,e:=s.Take(ctx,key);e!=nil||v!="fixture"{t.Fatal("consume",e)}
 if v,e:=s.Take(ctx,key);e!=nil||v!=""{t.Fatal("replay accepted")}
 if ok,e:=s.Put(ctx,key,"expire",time.Second);e!=nil||!ok{t.Fatal("expiry setup")}
 time.Sleep(1200*time.Millisecond)
 if v,e:=s.Take(ctx,key);e!=nil||v!=""{t.Fatal("expired ticket")}
 down:=redis.NewClient(&redis.Options{Addr:"127.0.0.1:1",MaxRetries:-1,DialTimeout:100*time.Millisecond});defer down.Close()
 d,_:=NewRedisStore(down,"test","bxchat:test:")
 if _,e=d.Put(ctx,key,"unsafe",time.Second);e==nil{t.Fatal("outage accepted nonce")}
 if _,e=d.Take(ctx,key);e==nil{t.Fatal("outage accepted consume")}
}
