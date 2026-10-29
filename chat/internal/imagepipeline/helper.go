// Package imagepipeline is an offline, bounded adapter to existing optimization
// and local ZXing. It has no chat-table write authority or network OCR fallback.
package imagepipeline

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"time"

	"github.com/franz-9527/workers/chat/internal/message"
)

type Helper struct {
	PHP, Python, PHPScript, PythonScript, TempRoot string
	MaxBytes, MaxEdge                              int
}
type boundedOutput struct{ bytes.Buffer }

func (b *boundedOutput) Write(p []byte) (int, error) {
	if b.Len()+len(p) > 8192 {
		return 0, errors.New("helper output budget")
	}
	return b.Buffer.Write(p)
}
func (h *Helper) command(ctx context.Context, name string, args ...string) ([]byte, error) {
	if !filepath.IsAbs(name) {
		return nil, message.ErrMedia
	}
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.WaitDelay = time.Second
	cmd.Env = []string{"PATH=" + os.Getenv("PATH"), "LANG=C.UTF-8", "PYTHONNOUSERSITE=1", "OMP_NUM_THREADS=1", "OPENBLAS_NUM_THREADS=1", "CHAT_IMAGE_EDGE=" + itoa(h.MaxEdge), "CHAT_IMAGE_BYTES=" + itoa(h.MaxBytes)}
	var out boundedOutput
	cmd.Stdout = &out
	cmd.Stderr = io.Discard
	if cmd.Run() != nil {
		return nil, message.ErrCheck
	}
	return out.Bytes(), nil
}
func itoa(n int) string { b, _ := json.Marshal(n); return string(b) }
func (h *Helper) Process(ctx context.Context, raw []byte) (message.ProcessedImage, error) {
	var result message.ProcessedImage
	if len(raw) < 1 || len(raw) > message.MaxImageBytes || h.MaxBytes < 10240 || h.MaxBytes > message.MaxImageBytes || h.MaxEdge < 800 || h.MaxEdge > 2400 || !filepath.IsAbs(h.PHPScript) || !filepath.IsAbs(h.PythonScript) {
		return result, message.ErrMedia
	}
	ctx, cancel := context.WithTimeout(ctx, 45*time.Second)
	defer cancel()
	job, e := os.MkdirTemp(h.TempRoot, "bxchat-image-")
	if e != nil {
		return result, message.ErrCheck
	}
	defer os.RemoveAll(job)
	if e = os.WriteFile(filepath.Join(job, "source"), raw, 0600); e != nil {
		return result, message.ErrCheck
	}
	sanitized, e := h.command(ctx, h.Python, h.PythonScript, "sanitize", job)
	if e != nil {
		return result, e
	}
	var sourceInfo struct {
		Orientation bool `json:"orientation_normalized"`
	}
	if json.Unmarshal(sanitized, &sourceInfo) != nil {
		return result, message.ErrCheck
	}
	optimized, e := h.command(ctx, h.PHP, "-d", "memory_limit=256M", "-d", "max_execution_time=20", h.PHPScript, job)
	if e != nil {
		return result, e
	}
	var optimization struct{ Method string }
	if json.Unmarshal(optimized, &optimization) != nil || optimization.Method == "" {
		return result, message.ErrCheck
	}
	inspected, e := h.command(ctx, h.Python, h.PythonScript, "inspect", job)
	if e != nil {
		return result, e
	}
	var info struct {
		MIME       string `json:"mime"`
		Width      int    `json:"width"`
		Height     int    `json:"height"`
		QRStatus   string `json:"qr_status"`
		QRDecision string `json:"qr_decision"`
		QRVersion  string `json:"qr_version"`
		OCRStatus  string `json:"ocr_status"`
	}
	if json.Unmarshal(inspected, &info) != nil {
		return result, message.ErrCheck
	}
	for _, name := range []string{"main", "thumb"} {
		p := filepath.Join(job, name)
		stat, err := os.Lstat(p)
		if err != nil || !stat.Mode().IsRegular() || stat.Size() > message.MaxImageBytes {
			return result, message.ErrMedia
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return result, message.ErrMedia
		}
		if name == "main" {
			result.Main = b
		} else {
			result.Thumb = b
		}
	}
	result.MIME = info.MIME
	result.Width = info.Width
	result.Height = info.Height
	result.Method = optimization.Method
	if sourceInfo.Orientation {
		result.Method = "orientation-normalized-" + result.Method
	}
	result.QRStatus = info.QRStatus
	result.QRDecision = info.QRDecision
	result.QRVersion = info.QRVersion
	result.OCRStatus = info.OCRStatus
	return result, nil
}
