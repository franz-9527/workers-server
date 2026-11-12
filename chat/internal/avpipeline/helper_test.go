package avpipeline

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/message"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

func TestOfflineMediaHelperRealFormatsAndLimits(t *testing.T) {
	if os.Getenv("CHAT_AV_HELPER_TEST") != "1" {
		t.Skip("explicit offline helper fixture required")
	}
	python, e := exec.LookPath("python3")
	if e != nil {
		t.Fatal(e)
	}
	ffmpeg, e := exec.LookPath("ffmpeg")
	if e != nil {
		t.Fatal(e)
	}
	ffprobe, e := exec.LookPath("ffprobe")
	if e != nil {
		t.Fatal(e)
	}
	script, _ := filepath.Abs("../../helpers/media/inspect.py")
	root := t.TempDir()
	h := &Helper{python, script, ffmpeg, ffprobe, root}
	makeFile := func(name string, args ...string) []byte {
		t.Helper()
		path := filepath.Join(root, name)
		cmd := exec.Command(ffmpeg, append([]string{"-v", "error", "-nostdin", "-threads", "1", "-y"}, append(args, path)...)...)
		if out, e := cmd.CombinedOutput(); e != nil {
			t.Fatalf("fixture %v %s", e, out)
		}
		raw, e := os.ReadFile(path)
		if e != nil {
			t.Fatal(e)
		}
		return raw
	}
	voice := makeFile("voice.mp3", "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=16000", "-t", "60", "-ac", "1", "-c:a", "libmp3lame", "-b:a", "48k")
	out, e := h.Process(context.Background(), voice, "audio", "voice")
	if e != nil || out.DurationMS != 60000 || out.MIME != "audio/mpeg" || len(out.Segments) != 1 {
		t.Fatal("sixty second voice", out.DurationMS, e)
	}
	longer := makeFile("long.mp3", "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=16000", "-t", "60.1", "-ac", "1", "-c:a", "libmp3lame", "-b:a", "48k")
	if _, e = h.Process(context.Background(), longer, "audio", "voice"); e == nil {
		t.Fatal("voice duration bypass")
	}
	for _, source := range []string{"camera", "album"} {
		duration := "30"
		if source == "album" {
			duration = "120"
		}
		video := makeFile(source+".mp4", "-f", "lavfi", "-i", "color=c=blue:s=320x240:r=24", "-t", duration, "-an", "-c:v", "libx264", "-threads", "1", "-pix_fmt", "yuv420p", "-movflags", "+faststart")
		out, e = h.Process(context.Background(), video, "video", source)
		if e != nil || out.HasAudio || len(out.Segments) != 0 || len(out.Poster) == 0 || len(out.Frames) == 0 || len(out.Frames) > 8 {
			if diagnostic, ok := e.(helperFailure); ok {
				t.Log(diagnostic.diagnostic)
			}
			t.Fatal(source, e)
		}
	}
	withAudio := makeFile("album-audio.mp4", "-f", "lavfi", "-i", "color=c=blue:s=320x240:r=24", "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=16000", "-t", "120", "-c:v", "libx264", "-threads", "1", "-pix_fmt", "yuv420p", "-c:a", "aac", "-ac", "1", "-b:a", "48k", "-movflags", "+faststart")
	out, e = h.Process(context.Background(), withAudio, "video", "album")
	if e != nil || !out.HasAudio || out.DurationMS != 120000 || len(out.Segments) != 2 || len(out.Frames) != 8 {
		if diagnostic, ok := e.(helperFailure); ok {
			t.Log(diagnostic.diagnostic)
		}
		t.Fatal("complete two-minute audio/video", out.DurationMS, len(out.Segments), len(out.Frames), e)
	}
	for i, frame := range out.Frames {
		if len(frame.Body) == 0 || len(frame.Body) > 65536 || frame.AtMS < 0 || frame.AtMS >= out.DurationMS || i > 0 && frame.AtMS <= out.Frames[i-1].AtMS {
			t.Fatal("retained picture bounds", i)
		}
	}
	if _, e = h.Process(context.Background(), []byte("fake.mp4"), "video", "album"); e == nil {
		t.Fatal("forged file accepted")
	}
	if _, e = h.Process(context.Background(), make([]byte, message.MaxVideoBytes+1), "video", "album"); e != message.ErrAVLimit {
		t.Fatal("size", e)
	}
}
