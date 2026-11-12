package sourceprobe

import (
	"context"
	"io"
	"mime"
	"net/http"
	"strings"
)

// Stable, non-sensitive failure codes. A failed source check must never
// dispatch an ASR provider invocation or expose a signed storage URL.
const (
	CodeUnreachable = "CHAT_AV_ASR_SOURCE_UNREACHABLE"
	CodeInvalidMP3  = "CHAT_AV_ASR_SOURCE_INVALID_MP3"
)

type Failure string

func (f Failure) Error() string       { return "ASR audio source preflight failed" }
func (f Failure) FailureCode() string { return string(f) }

// VerifyMP3 checks the *same* short-lived download URL that will be sent to
// the provider. The caller supplies a restricted HTTP client (pinned DNS,
// no redirects, no proxy). Read at most 4096 bytes to avoid large downloads.
func VerifyMP3(ctx context.Context, client *http.Client, signedURL string) error {
	if client == nil || signedURL == "" {
		return Failure(CodeUnreachable)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, signedURL, nil)
	if err != nil {
		return Failure(CodeUnreachable)
	}
	req.Header.Set("Range", "bytes=0-4095")
	resp, err := client.Do(req)
	if err != nil || resp == nil || resp.Body == nil {
		return Failure(CodeUnreachable)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusPartialContent {
		return Failure(CodeUnreachable)
	}
	// Do not allow a 200 received from a rewritten/redirected URL to count
	// as a successful check. Production Qiniu clients also reject redirects.
	if resp.Request != nil && resp.Request.URL.String() != req.URL.String() {
		return Failure(CodeUnreachable)
	}
	if resp.StatusCode == http.StatusPartialContent {
		rng := resp.Header.Get("Content-Range")
		if rng != "" && !strings.HasPrefix(strings.TrimSpace(rng), "bytes 0-") {
			return Failure(CodeInvalidMP3)
		}
	}
	if mediaType := resp.Header.Get("Content-Type"); mediaType != "" {
		mimeType, _, e := mime.ParseMediaType(mediaType)
		if e != nil || (mimeType != "audio/mpeg" && mimeType != "audio/mp3" && mimeType != "application/octet-stream" && mimeType != "binary/octet-stream") {
			return Failure(CodeInvalidMP3)
		}
	}
	header, err := io.ReadAll(io.LimitReader(resp.Body, 4096))
	if err != nil {
		return Failure(CodeUnreachable)
	}
	if !looksLikeMP3(header) {
		return Failure(CodeInvalidMP3)
	}
	return nil
}

func looksLikeMP3(data []byte) bool {
	if len(data) < 4 {
		return false
	}
	if string(data[:3]) == "ID3" {
		return true
	}
	// A genuine MPEG audio frame may be preceded by a small encoder prefix.
	for i := 0; i < len(data)-3 && i < 32; i++ {
		b := data[i:]
		if b[0] == 0xff && b[1]&0xe0 == 0xe0 &&
			b[1]&0x18 != 0x08 && b[1]&0x06 != 0 &&
			b[2]&0xf0 != 0 && b[2]&0xf0 != 0xf0 && b[2]&0x0c != 0x0c {
			return true
		}
	}
	return false
}
