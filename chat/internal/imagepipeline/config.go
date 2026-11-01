package imagepipeline

import (
	"os"
	"path/filepath"
	"regexp"

	"github.com/franz-9527/workers/chat/internal/message"
)

type Config struct {
	Enabled        bool   `json:"enabled"`
	Mode           string `json:"mode"`
	Bucket         string `json:"bucket"`
	Private        bool   `json:"private_bucket"`
	AccessKeyEnv   string `json:"access_key_env"`
	SecretKeyEnv   string `json:"secret_key_env"`
	DownloadOrigin string `json:"download_origin"`
	UploadOrigin   string `json:"upload_origin"`
	PHP            string `json:"php_binary"`
	Python         string `json:"python_binary"`
	PHPScript      string `json:"php_script"`
	PythonScript   string `json:"python_script"`
	TempRoot       string `json:"temp_root"`
	MaxBytes       int    `json:"max_file_bytes"`
	MaxEdge        int    `json:"max_long_edge_px"`
}

// Build is used by explicit isolated composition roots. The application's
// release gate still rejects images.enabled until S21/S22 deployment review.
func Build(c Config, env string, base *message.Service) (*message.MediaService, error) {
	if !c.Enabled {
		return nil, nil
	}
	names := regexp.MustCompile(`^CHAT_[A-Z0-9_]{1,60}$`)
	if c.Mode != "manual_precheck" || !names.MatchString(c.AccessKeyEnv) || !names.MatchString(c.SecretKeyEnv) || !filepath.IsAbs(c.TempRoot) || c.MaxBytes < 10240 || c.MaxBytes > message.MaxImageBytes || c.MaxEdge < 800 || c.MaxEdge > 2400 {
		return nil, message.ErrMedia
	}
	for _, path := range []string{c.PHP, c.Python, c.PHPScript, c.PythonScript} {
		info, e := os.Stat(path)
		if !filepath.IsAbs(path) || e != nil || !info.Mode().IsRegular() {
			return nil, message.ErrMedia
		}
	}
	info, e := os.Stat(c.TempRoot)
	if e != nil || !info.IsDir() {
		return nil, message.ErrMedia
	}
	storage, e := NewQiniu(QiniuConfig{Bucket: c.Bucket, AccessKey: os.Getenv(c.AccessKeyEnv), SecretKey: os.Getenv(c.SecretKeyEnv), Private: c.Private, DownloadOrigin: c.DownloadOrigin, UploadOrigin: c.UploadOrigin, Environment: env})
	if e != nil {
		return nil, e
	}
	helper := &Helper{PHP: c.PHP, Python: c.Python, PHPScript: c.PHPScript, PythonScript: c.PythonScript, TempRoot: c.TempRoot, MaxBytes: c.MaxBytes, MaxEdge: c.MaxEdge}
	return message.NewMediaService(base, storage, helper, env)
}
