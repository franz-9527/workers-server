package avpipeline

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/message"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

type helperFailure struct{ diagnostic string }

func (e helperFailure) Error() string { return message.ErrCheck.Error() }
func (e helperFailure) Unwrap() error { return message.ErrCheck }

type Helper struct{ Python, Script, FFmpeg, FFprobe, TempRoot string }
type boundedBuffer struct {
	bytes.Buffer
	limit int
}

func (b *boundedBuffer) Write(v []byte) (int, error) {
	if b.Len()+len(v) > b.limit {
		return 0, message.ErrMedia
	}
	return b.Buffer.Write(v)
}
func (h *Helper) Process(ctx context.Context, raw []byte, kind, source string) (message.AVArtifact, error) {
	var out message.AVArtifact
	limit, duration := int64(message.MaxVideoBytes), 120000
	switch source {
	case "voice":
		limit = message.MaxVoiceBytes
		duration = 60000
	case "camera":
		limit = message.MaxRecordingBytes
		duration = 30000
	case "album":
	default:
		return out, message.ErrMedia
	}
	if len(raw) < 1 || int64(len(raw)) > limit || kind != "audio" && kind != "video" || kind == "audio" && source != "voice" || kind == "video" && source == "voice" {
		return out, message.ErrAVLimit
	}
	root, e := os.MkdirTemp(h.TempRoot, "bxchat-av-")
	if e != nil {
		return out, message.ErrMedia
	}
	defer os.RemoveAll(root)
	if e = os.Chmod(root, 0700); e != nil {
		return out, message.ErrMedia
	}
	if e = os.WriteFile(filepath.Join(root, "input"), raw, 0600); e != nil {
		return out, message.ErrMedia
	}
	work, cancel := context.WithTimeout(ctx, 45*time.Second)
	defer cancel()
	cmd := exec.CommandContext(work, h.Python, h.Script, root, source, h.FFmpeg, h.FFprobe, "inspect")
	if configureMediaProcess(cmd) != nil {
		return out, message.ErrMedia
	}
	stdout := &boundedBuffer{limit: 16384}
	stderr := &boundedBuffer{limit: 4096}
	cmd.Stdout = stdout
	cmd.Stderr = stderr
	if cmd.Run() != nil {
		return out, helperFailure{diagnostic: stderr.String()}
	}
	var meta struct {
		Frames []struct {
			File string `json:"file"`
			AtMS int    `json:"at_ms"`
		} `json:"frames"`
		Kind       string   `json:"kind"`
		MIME       string   `json:"mime"`
		Main       string   `json:"main"`
		Poster     string   `json:"poster"`
		Segments   []string `json:"segments"`
		DurationMS int      `json:"duration_ms"`
		HasAudio   bool     `json:"has_audio"`
		QRDecision string   `json:"qr_decision"`
	}
	d := json.NewDecoder(bytes.NewReader(stdout.Bytes()))
	d.DisallowUnknownFields()
	if d.Decode(&meta) != nil || d.Decode(new(any)) != io.EOF || meta.Kind != kind || meta.DurationMS < 1 || meta.DurationMS > duration || len(meta.Segments) > 2 || len(meta.Frames) > 8 || kind == "audio" && len(meta.Frames) > 0 || kind == "video" && len(meta.Frames) < 1 || (meta.QRDecision != "clear" && meta.QRDecision != "review" && meta.QRDecision != "blocked") {
		return out, message.ErrCheck
	}
	read := func(name string, max int64) ([]byte, error) {
		retained := false
		for i := 0; i < 8; i++ {
			if name == fmt.Sprintf("retained-%d.jpg", i) {
				retained = true
			}
		}
		if !retained && name != "main.mp3" && name != "main.mp4" && name != "poster.jpg" && name != "check-0.mp3" && name != "check-1.mp3" {
			return nil, message.ErrMedia
		}
		path := filepath.Join(root, name)
		info, e := os.Lstat(path)
		if e != nil || !info.Mode().IsRegular() || info.Size() < 1 || info.Size() > max {
			return nil, message.ErrMedia
		}
		file, e := os.Open(path)
		if e != nil {
			return nil, message.ErrMedia
		}
		defer file.Close()
		return io.ReadAll(io.LimitReader(file, max+1))
	}
	expectedMain, expectedMIME := "main.mp4", "video/mp4"
	if kind == "audio" {
		expectedMain = "main.mp3"
		expectedMIME = "audio/mpeg"
	}
	if meta.Main != expectedMain || meta.MIME != expectedMIME || kind == "audio" && (meta.DurationMS < 1000 || !meta.HasAudio || meta.Poster != "" || len(meta.Segments) != 1 || meta.Segments[0] != "main.mp3") || kind == "video" && meta.Poster != "poster.jpg" || kind == "video" && meta.HasAudio != (len(meta.Segments) > 0) {
		return out, message.ErrCheck
	}
	out.Main, e = read(meta.Main, limit)
	if e != nil {
		return out, e
	}
	if meta.Poster != "" {
		out.Poster, e = read(meta.Poster, 65536)
		if e != nil {
			return out, e
		}
	}
	for ordinal, name := range meta.Segments {
		expected := "check-0.mp3"
		if ordinal == 1 {
			expected = "check-1.mp3"
		}
		if kind == "audio" {
			expected = "main.mp3"
		}
		if name != expected {
			return out, message.ErrCheck
		}
		if kind == "audio" {
			out.Segments = append(out.Segments, out.Main)
		} else {
			segment, e := read(name, message.MaxVoiceBytes)
			if e != nil {
				return out, e
			}
			out.Segments = append(out.Segments, segment)
		}
	}
	for i, frame := range meta.Frames {
		if frame.File != fmt.Sprintf("retained-%d.jpg", i) || frame.AtMS < 0 || frame.AtMS >= meta.DurationMS {
			return out, message.ErrCheck
		}
		b, err := read(frame.File, 65536)
		if err != nil {
			return out, err
		}
		out.Frames = append(out.Frames, message.AVFrameArtifact{Body: b, AtMS: frame.AtMS})
	}
	out.DurationMS = meta.DurationMS
	out.HasAudio = meta.HasAudio
	out.MIME = meta.MIME
	out.QRDecision = meta.QRDecision
	return out, nil
}
