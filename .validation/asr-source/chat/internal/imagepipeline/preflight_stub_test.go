package imagepipeline

import (
	"context"
	"encoding/base64"
	"net/http"
	"strconv"
)

type QiniuConfig struct {
	Bucket, AccessKey, SecretKey, DownloadOrigin, UploadOrigin, Environment string
	Private bool
}
type Qiniu struct {
	config QiniuConfig
	client *http.Client
}
func (q *Qiniu) valid(string) bool { return true }
func (q *Qiniu) signature(string) string { return "fixture-signature" }
func (q *Qiniu) Keep(context.Context, string) error { return nil }
func (q *Qiniu) putForm(context.Context, string, []byte, string, string, string, string) error { return nil }
func (q *Qiniu) manage(ctx context.Context, path string) (*http.Response,error) {
	req, e := http.NewRequestWithContext(ctx, http.MethodPost, "https://rs.qiniuapi.com"+path, nil)
	if e != nil { return nil, e }
	return q.client.Do(req)
}
func b64(raw []byte) string { return base64.URLEncoding.EncodeToString(raw) }
func itoa(n int) string { return strconv.Itoa(n) }
