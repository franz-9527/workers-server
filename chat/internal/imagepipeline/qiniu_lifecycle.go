package imagepipeline

import (
	"context"
	"io"
	"net/http"
	"strconv"
	"strings"

	"github.com/franz-9527/workers/chat/internal/message"
)

// Management requests use Qiniu v2 canonical signing; only validated object
// keys from the configured private bucket may reach the fixed HTTPS endpoint.
func (q *Qiniu) manage(ctx context.Context, path string) (*http.Response, error) {
	req, e := http.NewRequestWithContext(ctx, "POST", "https://rs.qiniuapi.com"+path, nil)
	if e != nil {
		return nil, message.ErrMedia
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	canonical := "POST " + req.URL.Path + "\nHost: " + req.Host + "\nContent-Type: application/x-www-form-urlencoded\n\n"
	req.Header.Set("Authorization", "Qiniu "+q.config.AccessKey+":"+q.signature(canonical))
	res, e := q.client.Do(req)
	if e != nil {
		return nil, message.ErrMedia
	}
	if res.StatusCode == 612 {
		io.Copy(io.Discard, io.LimitReader(res.Body, 4096))
		res.Body.Close()
		return nil, message.ErrObjectMissing
	}
	if res.StatusCode < 200 || res.StatusCode >= 300 {
		io.Copy(io.Discard, io.LimitReader(res.Body, 4096))
		res.Body.Close()
		return nil, message.ErrMedia
	}
	return res, nil
}
func (q *Qiniu) Keep(ctx context.Context, key string) error {
	if !q.valid(key) {
		return message.ErrMedia
	}
	res, e := q.manage(ctx, "/deleteAfterDays/"+b64([]byte(q.config.Bucket+":"+key))+"/0")
	if e != nil {
		return e
	}
	io.Copy(io.Discard, io.LimitReader(res.Body, 4096))
	res.Body.Close()
	return nil
}
func (q *Qiniu) LowFrequency(ctx context.Context, key string) error {
	if !q.valid(key) || strings.HasSuffix(key, "/source") || strings.Contains(key, "/part-") || strings.Contains(key, "/check-") {
		return message.ErrMedia
	}
	res, e := q.manage(ctx, "/chtype/"+b64([]byte(q.config.Bucket+":"+key))+"/type/"+strconv.Itoa(1))
	if e != nil {
		return e
	}
	io.Copy(io.Discard, io.LimitReader(res.Body, 4096))
	res.Body.Close()
	return nil
}
