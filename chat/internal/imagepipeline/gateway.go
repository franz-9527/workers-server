package imagepipeline

import (
    "bytes"
    "context"
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "net/http"
    "net/url"
    "path"
    "regexp"
    "strconv"
    "strings"
    "time"

    "github.com/franz-9527/workers/chat/internal/message"
)

func validNamespace(value string) bool {
    return len(value) <= 60 && regexp.MustCompile("^[A-Za-z0-9_-]{1,40}(/[A-Za-z0-9_-]{1,40}){0,3}$").MatchString(value)
}
func (q *Qiniu) Namespace() string { return q.config.Namespace }
func (q *Qiniu) remoteURL(key string, ttl time.Duration) string {
    object := strings.TrimRight(q.config.DownloadOrigin, "/")+"/"+key
    if !q.config.Private { return object }
    object += "?e="+strconv.FormatInt(time.Now().Add(ttl).Unix(),10)
    return object+"&token="+q.config.AccessKey+":"+q.signature(object)
}
func (q *Qiniu) proxySignature(key, expires string) string {
    mac := hmac.New(sha256.New, []byte(q.config.ProxyKey))
    mac.Write([]byte("chat-media-url-v1\n"+q.config.Environment+"\n"+key+"\n"+expires))
    return hex.EncodeToString(mac.Sum(nil))
}
func (q *Qiniu) proxyURL(key string, ttl time.Duration) string {
    expires := strconv.FormatInt(time.Now().Add(ttl).Unix(),10)
    query := url.Values{"key":{key},"expires":{expires},"signature":{q.proxySignature(key,expires)}}
    return strings.TrimRight(q.config.ProxyOrigin,"/")+"/chat/api/v1/media/object?"+query.Encode()
}

// The server fetches from the configured public Qiniu origin; clients and WeChat
// inspection receive the site's existing HTTPS origin. No caller-supplied URL.
func (q *Qiniu) Gateway() http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
        _ = http.NewResponseController(w).SetWriteDeadline(time.Now().Add(40*time.Second))
        w.Header().Set("Cache-Control","private, no-store")
        w.Header().Set("X-Content-Type-Options","nosniff")
        if r.Method!="GET" && r.Method!="HEAD" { w.WriteHeader(405);return }
        values, err := url.ParseQuery(r.URL.RawQuery)
        if err!=nil || len(values)!=3 || len(values["key"])!=1 || len(values["expires"])!=1 || len(values["signature"])!=1 { w.WriteHeader(403);return }
        key, expires := values.Get("key"), values.Get("expires")
        until, err := strconv.ParseInt(expires,10,64)
        now:=time.Now().Unix()
        signature, decodeErr:=hex.DecodeString(values.Get("signature"))
        expected,_:=hex.DecodeString(q.proxySignature(key,expires))
        if q.config.ProxyOrigin=="" || err!=nil || decodeErr!=nil || until<=now || until>now+1200 ||
            strconv.FormatInt(until,10)!=expires || !q.valid(key) || !hmac.Equal(signature,expected) { w.WriteHeader(403);return }
        select {case q.proxyGate<-struct{}{}:defer func(){<-q.proxyGate}();default:w.Header().Set("Retry-After","2");w.WriteHeader(503);return}
        ctx,cancel:=context.WithTimeout(r.Context(),25*time.Second);defer cancel()
        body,err:=q.Read(ctx,key,message.MaxVideoBytes)
        if err!=nil { w.WriteHeader(502);return }
        mime:=http.DetectContentType(body)
        if mime=="application/octet-stream" && len(body)>2 && body[0]==0xff && body[1]&0xe0==0xe0 { mime="audio/mpeg" }
        w.Header().Set("Content-Type",mime)
        http.ServeContent(w,r,path.Base(key),time.Time{},bytes.NewReader(body))
    })
}
