package moderation

import (
	"bytes"
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"mime/multipart"
	"net/http"
	"net/url"
	"time"
)

// CheckImage calls the actual image-content check. Only an explicit provider
// success can satisfy automatic approval. Local text mode never implies image pass.
// The caller supplies a full-frame JPEG/PNG inspection projection, at most 1 MiB.
func (s *Service) CheckImage(ctx context.Context, p ports.Principal, body []byte) (string, error) {
	if len(body) == 0 || len(body) > 1<<20 {
		return "", ports.ErrInvalid
	}
	if _, err := s.identity(ctx, p, nil); err != nil {
		return "", err
	}
	if s.credentials == nil {
		return "", ports.ErrUnavailable
	}
	credential, err := s.credentials.Get()
	if err != nil {
		return "", err
	}
	select {
	case s.gate <- struct{}{}:
		defer func() { <-s.gate }()
	default:
		return "", ports.ErrBusy
	}
	return s.inspectImage(ctx, credential, body)
}
func (s *Service) inspectImage(ctx context.Context, c Credential, body []byte) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 12*time.Second)
	defer cancel()
	var buffer bytes.Buffer
	form := multipart.NewWriter(&buffer)
	part, err := form.CreateFormFile("media", "inspection.jpg")
	if err != nil {
		return "", ports.ErrUnavailable
	}
	if _, err = part.Write(body); err != nil {
		return "", ports.ErrUnavailable
	}
	if form.Close() != nil {
		return "", ports.ErrUnavailable
	}
	req, err := http.NewRequestWithContext(ctx, "POST", "https://api.weixin.qq.com/wxa/img_sec_check?"+url.Values{"access_token": {c.Token}}.Encode(), &buffer)
	if err != nil {
		return "", ports.ErrUnavailable
	}
	req.Header.Set("Content-Type", form.FormDataContentType())
	client := s.client
	if client == nil {
		client = &http.Client{Timeout: 12 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	}
	res, err := client.Do(req)
	if err != nil || res == nil || res.Body == nil {
		return "", ports.ErrUnavailable
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(res.Body, 32769))
	if err != nil || len(raw) > 32768 || res.StatusCode != 200 {
		return "", ports.ErrUnavailable
	}
	var response struct {
		Code *int `json:"errcode"`
	}
	if !avJSON(raw, &response) || response.Code == nil {
		return "", ports.ErrUnavailable
	}
	switch *response.Code {
	case 0:
		return "passed", nil
	case 87014:
		return "review", nil
	case 40001, 40014, 42001:
		s.credentials.Reject(c.Generation)
	}
	return "", ports.ErrUnavailable
}
