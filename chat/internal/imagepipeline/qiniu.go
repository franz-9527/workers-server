package imagepipeline

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha1"
	"encoding/base64"
	"encoding/json"
	"io"
	"mime/multipart"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/message"
)

type QiniuConfig struct {
	Bucket, AccessKey, SecretKey, DownloadOrigin, UploadOrigin, Environment string
	Private                                                                 bool
}
type Qiniu struct {
	config QiniuConfig
	client *http.Client
}

var objectKey = regexp.MustCompile(`^chat/(test|development|production)/[1-9][0-9]{0,19}/[0-9a-f]{64}/(source|[0-9a-f]{64}/(main|thumb))$`)

func publicImageOrigin(raw string) bool {
	u, e := url.Parse(raw)
	return e == nil && u.Scheme == "https" && u.Hostname() != "" && net.ParseIP(u.Hostname()) == nil && u.User == nil && u.RawQuery == "" && u.Fragment == "" && (u.Path == "" || u.Path == "/") && (u.Port() == "" || u.Port() == "443")
}
func NewQiniu(c QiniuConfig) (*Qiniu, error) {
	upload, e := url.Parse(c.UploadOrigin)
	if !c.Private || !publicImageOrigin(c.DownloadOrigin) || !publicImageOrigin(c.UploadOrigin) || e != nil ||
		!(strings.HasSuffix(upload.Hostname(), ".qiniup.com") || strings.HasSuffix(upload.Hostname(), ".qiniu.com")) ||
		!regexp.MustCompile(`^[A-Za-z0-9_-]{1,63}$`).MatchString(c.Bucket) || c.AccessKey == "" || c.SecretKey == "" ||
		(c.Environment != "production" && c.Environment != "development" && c.Environment != "test") {
		return nil, message.ErrMedia
	}
	dialer := &net.Dialer{Timeout: 5 * time.Second}
	transport := &http.Transport{Proxy: nil, MaxIdleConns: 2, MaxConnsPerHost: 2, IdleConnTimeout: 30 * time.Second, TLSHandshakeTimeout: 5 * time.Second, ResponseHeaderTimeout: 10 * time.Second}
	transport.DialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
		host, port, e := net.SplitHostPort(address)
		if e != nil {
			return nil, message.ErrMedia
		}
		addresses, e := net.DefaultResolver.LookupIPAddr(ctx, host)
		if e != nil || len(addresses) == 0 {
			return nil, message.ErrMedia
		}
		for _, ip := range addresses {
			if !ip.IP.IsGlobalUnicast() || ip.IP.IsPrivate() || ip.IP.IsLoopback() || ip.IP.IsLinkLocalUnicast() {
				return nil, message.ErrMedia
			}
		}
		return dialer.DialContext(ctx, network, net.JoinHostPort(addresses[0].IP.String(), port))
	}
	return &Qiniu{c, &http.Client{Timeout: 20 * time.Second, Transport: transport, CheckRedirect: func(*http.Request, []*http.Request) error { return message.ErrMedia }}}, nil
}
func b64(b []byte) string { return base64.URLEncoding.EncodeToString(b) }
func (q *Qiniu) signature(s string) string {
	h := hmac.New(sha1.New, []byte(q.config.SecretKey))
	h.Write([]byte(s))
	return b64(h.Sum(nil))
}
func (q *Qiniu) valid(key string) bool {
	return objectKey.MatchString(key) && strings.HasPrefix(key, "chat/"+q.config.Environment+"/") || avObjectKey.MatchString(key) && strings.HasPrefix(key, "chatav/"+q.config.Environment+"/")
}
func (q *Qiniu) Grant(ctx context.Context, key string, size int64) (string, string, error) {
	if !objectKey.MatchString(key) || !q.valid(key) || size < 1 || size > message.MaxImageBytes {
		return "", "", message.ErrMedia
	}
	policy, _ := json.Marshal(map[string]any{"scope": q.config.Bucket + ":" + key, "deadline": time.Now().Add(5 * time.Minute).Unix(), "insertOnly": 1, "fsizeMin": size, "fsizeLimit": size, "detectMime": 1, "mimeLimit": "image/jpeg;image/png;image/webp"})
	encoded := b64(policy)
	return q.config.AccessKey + ":" + q.signature(encoded) + ":" + encoded, q.config.UploadOrigin, nil
}
func (q *Qiniu) Sign(ctx context.Context, key string, ttl time.Duration) (string, error) {
	if !q.valid(key) || ttl <= 0 || ttl > 60*time.Second {
		return "", message.ErrMedia
	}
	u := strings.TrimRight(q.config.DownloadOrigin, "/") + "/" + key + "?e=" + itoa(int(time.Now().Add(ttl).Unix()))
	return u + "&token=" + q.config.AccessKey + ":" + q.signature(u), nil
}
func (q *Qiniu) Read(ctx context.Context, key string, limit int64) ([]byte, error) {
	if limit < 1 || limit > message.MaxVideoBytes {
		return nil, message.ErrMedia
	}
	u, e := q.Sign(ctx, key, 60*time.Second)
	if e != nil {
		return nil, e
	}
	req, _ := http.NewRequestWithContext(ctx, "GET", u, nil)
	res, e := q.client.Do(req)
	if e != nil {
		return nil, message.ErrMedia
	}
	defer res.Body.Close()
	if res.StatusCode != 200 || res.ContentLength > limit {
		return nil, message.ErrMedia
	}
	b, e := io.ReadAll(io.LimitReader(res.Body, limit+1))
	if e != nil || int64(len(b)) > limit || len(b) == 0 {
		return nil, message.ErrMedia
	}
	return b, nil
}
func (q *Qiniu) Put(ctx context.Context, key string, b []byte, mime string) error {
	token, host, e := q.Grant(ctx, key, int64(len(b)))
	if e != nil {
		return e
	}
	return q.putForm(ctx, key, b, token, host, "image")
}
func (q *Qiniu) putForm(ctx context.Context, key string, b []byte, token, host, filename string) error {
	var body bytes.Buffer
	writer := multipart.NewWriter(&body)
	writer.WriteField("token", token)
	writer.WriteField("key", key)
	part, e := writer.CreateFormFile("file", filename)
	if e != nil {
		return message.ErrMedia
	}
	part.Write(b)
	writer.Close()
	req, _ := http.NewRequestWithContext(ctx, "POST", host, &body)
	req.Header.Set("Content-Type", writer.FormDataContentType())
	res, e := q.client.Do(req)
	if e != nil {
		return message.ErrMedia
	}
	io.Copy(io.Discard, io.LimitReader(res.Body, 4096))
	res.Body.Close()
	if res.StatusCode < 200 || res.StatusCode >= 300 {
		// insertOnly may report an existing object on retry. It is reusable only
		// after byte-for-byte verification, never by trusting provider etag.
		existing, err := q.Read(ctx, key, int64(len(b)))
		if err != nil || !bytes.Equal(existing, b) {
			return message.ErrMedia
		}
	}
	return nil
}
func (q *Qiniu) Delete(ctx context.Context, key string) error {
	if !q.valid(key) {
		return message.ErrMedia
	}
	path := "/delete/" + b64([]byte(q.config.Bucket+":"+key))
	req, _ := http.NewRequestWithContext(ctx, "POST", "https://rs.qiniuapi.com"+path, nil)
	req.Header.Set("Authorization", "QBox "+q.config.AccessKey+":"+q.signature(path+"\n"))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	res, e := q.client.Do(req)
	if e != nil {
		return message.ErrMedia
	}
	io.Copy(io.Discard, io.LimitReader(res.Body, 4096))
	res.Body.Close()
	if res.StatusCode >= 200 && res.StatusCode < 300 || res.StatusCode == 612 {
		return nil
	}
	return message.ErrMedia
}
