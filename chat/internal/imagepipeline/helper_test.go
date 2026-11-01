package imagepipeline

import (
	"bytes"
	"context"
	"image"
	"image/color"
	"image/png"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func helperFixture(t *testing.T) *Helper {
	t.Helper()
	if os.Getenv("CHAT_IMAGE_HELPER_TEST") != "1" {
		t.Skip("explicit offline helper fixture")
	}
	php, e := exec.LookPath("php")
	if e != nil {
		t.Fatal(e)
	}
	python, e := exec.LookPath("python3")
	if e != nil {
		t.Fatal(e)
	}
	a, _ := filepath.Abs("../../../server/bin/chat-images.php")
	b, _ := filepath.Abs("../../helpers/images/inspect.py")
	return &Helper{PHP: php, Python: python, PHPScript: a, PythonScript: b, TempRoot: t.TempDir(), MaxBytes: 1048576, MaxEdge: 1600}
}
func TestOfflineImagePipelineAndBudgets(t *testing.T) {
	h := helperFixture(t)
	im := image.NewRGBA(image.Rect(0, 0, 800, 600))
	for y := 0; y < 600; y++ {
		for x := 0; x < 800; x++ {
			im.Set(x, y, color.RGBA{byte(x % 200), byte(y % 200), 150, 255})
		}
	}
	var b bytes.Buffer
	png.Encode(&b, im)
	out, e := h.Process(context.Background(), b.Bytes())
	if e != nil || out.QRStatus != "completed" || out.QRDecision != "clear" || out.OCRStatus != "not_run" || out.Width != 800 {
		t.Fatalf("real pipeline %+v %v", out, e)
	}
	if _, e = h.Process(context.Background(), []byte("not an image")); e == nil {
		t.Fatal("invalid bytes")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, e = h.Process(ctx, b.Bytes()); e == nil {
		t.Fatal("cancel ignored")
	}
	entries, _ := os.ReadDir(h.TempRoot)
	if len(entries) != 0 {
		t.Fatal("temporary files retained")
	}
	t.Logf("offline processed main=%d thumb=%d method=%s QR=%s", len(out.Main), len(out.Thumb), out.Method, out.QRVersion)
}
func TestOfflineQRClassificationMetadataAndNoCloud(t *testing.T) {
	h := helperFixture(t)
	script := `import io,sys,zxingcpp
from PIL import Image
im=Image.fromarray(zxingcpp.create_barcode(sys.argv[1],zxingcpp.BarcodeFormat.QRCode).to_image(scale=8))
im.save(sys.stdout.buffer,format="PNG")`
	// ZXing image implements the buffer protocol; Pillow can wrap via frombytes
	// without numpy, which is deliberately absent from the pinned dependency set.

	for _, v := range []struct{ text, want string }{{"weixin://contacts/profile/fixture", "blocked"},
		{"weixin://dl/profile/fixture", "blocked"},
		{"wxp://private-payment", "blocked"},
		{"https://weixin.qq.com/r/fixture", "blocked"},
		{"https://u.wechat.com/fixture", "blocked"},
		{"https://qr.alipay.com/fixture", "blocked"},
		{"tel:+8613800138000", "blocked"},
		{"BEGIN:VCARD\nTEL:13800138000\nEND:VCARD", "blocked"},
		{"weixin://dl/business/?t=fixture", "review"},
		{"weixin://unknown-path", "review"},
		{"https://u.wechat.com.evil.invalid/fixture", "review"},
		{"13800138000", "review"},
		{"6901234567890", "clear"},
		{"https://materials.example.invalid/batch/123", "review"}} {
		raw, e := exec.Command(h.Python, "-c", script, v.text).Output()
		if e != nil {
			t.Fatal("QR fixture", e)
		}
		out, e := h.Process(context.Background(), raw)
		if e != nil || out.QRDecision != v.want {
			t.Fatalf("QR %s => %s %v", v.text, out.QRDecision, e)
		}
	}
	// EXIF description/GPS-related container is stripped without re-encoding JPEG.
	script = `import sys
from PIL import Image
im=Image.new("RGB",(640,480),"white")
exif=Image.Exif();exif[270]="private location"
im.save(sys.stdout.buffer,format="JPEG",exif=exif)`
	raw, e := exec.Command(h.Python, "-c", script).Output()
	if e != nil {
		t.Fatal(e)
	}
	start := time.Now()
	out, e := h.Process(context.Background(), raw)
	if e != nil || bytes.Contains(out.Main, []byte("private location")) || bytes.Contains(out.Main, []byte("Exif")) {
		t.Fatal("metadata retained", e)
	}
	t.Logf("metadata/QR full pipeline duration=%s", time.Since(start))
}
func TestQiniuPrivateKeyPolicy(t *testing.T) {
	config := QiniuConfig{Private: true, Bucket: "fixture-bucket", AccessKey: "fixture-ak", SecretKey: "fixture-sk", Environment: "test", DownloadOrigin: "https://private.example.invalid", UploadOrigin: "https://up-z2.qiniup.com"}
	q, e := NewQiniu(config)
	if e != nil {
		t.Fatal(e)
	}
	key := "chat/test/123/" + strings.Repeat("a", 64) + "/source"
	token, _, e := q.Grant(context.Background(), key, 123)
	if e != nil || token == "" {
		t.Fatal(e)
	}
	if _, e = q.Sign(context.Background(), key, 61*time.Second); e == nil {
		t.Fatal("long URL")
	}
	for _, key := range []string{"https://localhost/a", "chat/production/123/" + strings.Repeat("a", 64) + "/source", "../../etc/passwd"} {
		if _, _, e = q.Grant(context.Background(), key, 123); e == nil {
			t.Fatal("foreign key")
		}
	}
	config.Private = false
	if _, e = NewQiniu(config); e == nil {
		t.Fatal("public bucket")
	}
	config.Private = true
	config.DownloadOrigin = "https://127.0.0.1"
	if _, e = NewQiniu(config); e == nil {
		t.Fatal("private URL")
	}
}

func TestOfflineImageOrientationBeforeMetadataRemoval(t *testing.T) {
	h := helperFixture(t)
	script := `import sys
from PIL import Image
im=Image.new("RGB",(640,480),"white")
exif=Image.Exif();exif[274]=6;exif[270]="private location"
im.save(sys.stdout.buffer,format="JPEG",exif=exif)`
	raw, e := exec.Command(h.Python, "-c", script).Output()
	if e != nil {
		t.Fatal(e)
	}
	out, e := h.Process(context.Background(), raw)
	if e != nil || out.Width != 480 || out.Height != 640 || !strings.HasPrefix(out.Method, "orientation-normalized-") || bytes.Contains(out.Main, []byte("Exif")) {
		t.Fatalf("orientation/metadata width=%d height=%d method=%s error=%v", out.Width, out.Height, out.Method, e)
	}
}
