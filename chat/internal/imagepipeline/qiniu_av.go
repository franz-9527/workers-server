package imagepipeline

import (
	"context"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/message"
	"io"
	"regexp"
	"strings"
	"time"
)

var avObjectKey = regexp.MustCompile(`^chatav/(test|development|production)/[1-9][0-9]{0,19}/[0-9a-f]{64}/(part-[1-9][0-9]?|[0-9a-f]{64}/(main|poster|check-0|check-1|frame-[0-7]))$`)

func (q *Qiniu) avKey(key string) bool { return avObjectKey.MatchString(key) && q.valid(key) }
func (q *Qiniu) avGrant(key string, size int64, mime string, temporaryDays int) (string, string, error) {
	if !q.avKey(key) || size < 1 || size > message.MaxVideoBytes {
		return "", "", message.ErrMedia
	}
	policy := map[string]any{"scope": q.config.Bucket + ":" + key, "deadline": time.Now().Add(5 * time.Minute).Unix(), "insertOnly": 1, "fsizeMin": size, "fsizeLimit": size}
	if mime != "" {
		policy["detectMime"] = 1
		policy["mimeLimit"] = mime
	}
	if temporaryDays > 0 {
		policy["deleteAfterDays"] = temporaryDays
	}
	raw, _ := json.Marshal(policy)
	encoded := b64(raw)
	return q.config.AccessKey + ":" + q.signature(encoded) + ":" + encoded, q.config.UploadOrigin, nil
}
func (q *Qiniu) GrantAVPart(ctx context.Context, key string, size int64) (string, string, error) {
	if !q.avKey(key) || !strings.Contains(key, "/part-") || size > 4<<20 {
		return "", "", message.ErrMedia
	}
	return q.avGrant(key, size, "", 1)
}
func (q *Qiniu) PutAV(ctx context.Context, key string, body []byte, mime string) error {
	limit := int64(0)
	switch mime {
	case "video/mp4":
		limit = message.MaxVideoBytes
	case "audio/mpeg":
		limit = message.MaxVoiceBytes
	case "image/jpeg":
		limit = 65536
	}
	if !q.avKey(key) || strings.Contains(key, "/part-") || limit == 0 || int64(len(body)) > limit {
		return message.ErrMedia
	}
	temporary := 0
	if strings.HasSuffix(key, "/check-0") || strings.HasSuffix(key, "/check-1") {
		temporary = 1
	}
	token, host, e := q.avGrant(key, int64(len(body)), mime, temporary)
	if e != nil {
		return e
	}
	if e = q.putForm(ctx, key, body, token, host, "media"); e != nil {
		return e
	}
	if temporary == 0 {
		return q.Keep(ctx, key)
	}
	return nil
}
func (q *Qiniu) StatAV(ctx context.Context, key string) (int64, error) {
	if !q.avKey(key) {
		return 0, message.ErrMedia
	}
	res, e := q.manage(ctx, "/stat/"+b64([]byte(q.config.Bucket+":"+key)))
	if e != nil {
		return 0, e
	}
	defer res.Body.Close()
	raw, e := io.ReadAll(io.LimitReader(res.Body, 8193))
	if e != nil || len(raw) > 8192 {
		return 0, message.ErrMedia
	}
	var result struct {
		Size *int64 `json:"fsize"`
	}
	if json.Unmarshal(raw, &result) != nil || result.Size == nil || *result.Size < 1 || *result.Size > message.MaxVideoBytes {
		return 0, message.ErrMedia
	}
	return *result.Size, nil
}
func (q *Qiniu) InspectionURL(ctx context.Context, key string) (string, error) {
	if !q.avKey(key) || strings.Contains(key, "/part-") || strings.HasSuffix(key, "/poster") {
		return "", message.ErrMedia
	}
	u := strings.TrimRight(q.config.DownloadOrigin, "/") + "/" + key + "?e=" + itoa(int(time.Now().Add(5*time.Minute).Unix()))
	return u + "&token=" + q.config.AccessKey + ":" + q.signature(u), nil
}
