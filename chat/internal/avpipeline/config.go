package avpipeline

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/imagepipeline"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/moderation"
	"github.com/franz-9527/workers/chat/internal/ports"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
)

type Config struct {
	Enabled          bool                `json:"enabled"`
	Audio            bool                `json:"audio"`
	Video            bool                `json:"video"`
	Python           string              `json:"python_binary"`
	Script           string              `json:"python_script"`
	FFmpeg           string              `json:"ffmpeg_binary"`
	FFprobe          string              `json:"ffprobe_binary"`
	TempRoot         string              `json:"temp_root"`
	Bucket           string              `json:"bucket"`
	Private          bool                `json:"private_bucket"`
	DownloadOrigin   string              `json:"download_origin"`
	UploadOrigin     string              `json:"upload_origin"`
	AccessKeyEnv     string              `json:"access_key_env"`
	SecretKeyEnv     string              `json:"secret_key_env"`
	ASR              moderation.AVConfig `json:"asr"`
	DailyASR         int                 `json:"daily_asr_budget"`
	CallbackTokenEnv string              `json:"callback_token_env"`
	CallbackKeyEnv   string              `json:"callback_key_env"`
}

func (c Config) Validate() error {
	if !c.Enabled {
		if c.Audio || c.Video {
			return ports.ErrInvalid
		}
		return nil
	}
	names := regexp.MustCompile(`^CHAT_[A-Z0-9_]{1,60}$`)
	if !c.Audio && !c.Video || runtime.GOOS != "linux" || !c.Private || c.DailyASR < 1 || c.DailyASR > 20000 {
		return ports.ErrInvalid
	}
	for _, name := range []string{c.AccessKeyEnv, c.SecretKeyEnv, c.CallbackTokenEnv, c.CallbackKeyEnv} {
		if !names.MatchString(name) {
			return ports.ErrInvalid
		}
	}
	for _, path := range []string{c.Python, c.Script, c.FFmpeg, c.FFprobe} {
		info, e := os.Stat(path)
		if !filepath.IsAbs(path) || e != nil || !info.Mode().IsRegular() {
			return ports.ErrInvalid
		}
	}
	info, e := os.Stat(c.TempRoot)
	if e != nil || !filepath.IsAbs(c.TempRoot) || !info.IsDir() || info.Mode().Perm()&0077 != 0 {
		return ports.ErrInvalid
	}
	return nil
}
func Build(c Config, env string, base *message.Service, safety *moderation.Service) (*message.AVService, *moderation.MediaCallback, error) {
	if c.Validate() != nil {
		return nil, nil, ports.ErrInvalid
	}
	if !c.Enabled {
		return nil, nil, nil
	}
	storage, e := imagepipeline.NewQiniu(imagepipeline.QiniuConfig{Bucket: c.Bucket, Private: c.Private, AccessKey: os.Getenv(c.AccessKeyEnv), SecretKey: os.Getenv(c.SecretKeyEnv), DownloadOrigin: c.DownloadOrigin, UploadOrigin: c.UploadOrigin, Environment: env})
	if e != nil {
		return nil, nil, e
	}
	inspector, e := moderation.NewAV(safety, c.ASR, nil)
	if e != nil {
		return nil, nil, e
	}
	raw, _ := json.Marshal(c)
	hash := sha256.Sum256(raw)
	helper := &Helper{c.Python, c.Script, c.FFmpeg, c.FFprobe, c.TempRoot}
	service, e := message.NewAVService(base, storage, helper, inspector, env, hex.EncodeToString(hash[:]), c.Audio, c.Video, c.DailyASR)
	if e != nil {
		return nil, nil, e
	}
	callback, e := moderation.NewMediaCallback(inspector.MediaAppID(), os.Getenv(c.CallbackTokenEnv), os.Getenv(c.CallbackKeyEnv), service)
	if e != nil {
		return nil, nil, e
	}
	return service, callback, nil
}
