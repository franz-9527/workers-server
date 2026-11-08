package dependencies

import (
	"context"
	"io"
	"net"
	"net/http"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// HTTP is reusable transport only. S03 supplies signed identity endpoints;
// this stage never calls PHP or any supplier from a public request.
type HTTP struct {
	client  *http.Client
	gate    chan struct{}
	timeout time.Duration
	maxBody int64
}

func NewHTTP(concurrency int, timeout time.Duration, maxBody int64) (*HTTP, error) {
	if concurrency < 1 || concurrency > 32 || timeout <= 0 || timeout > 5*time.Second || maxBody < 1 || maxBody > 1<<20 {
		return nil, ports.ErrInvalid
	}
	transport := &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: timeout}).DialContext,
		MaxConnsPerHost: concurrency, MaxIdleConns: concurrency, MaxIdleConnsPerHost: concurrency,
		ResponseHeaderTimeout: timeout, IdleConnTimeout: time.Minute, TLSHandshakeTimeout: timeout, MaxResponseHeaderBytes: 16 << 10}
	return &HTTP{client: &http.Client{Transport: transport, Timeout: timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, gate: make(chan struct{}, concurrency), timeout: timeout, maxBody: maxBody}, nil
}
func (h *HTTP) Do(ctx context.Context, req *http.Request) ([]byte, int, error) {
	if req == nil || req.URL == nil || req.URL.User != nil || (req.URL.Scheme != "http" && req.URL.Scheme != "https") || req.ContentLength > 64<<10 {
		return nil, 0, ports.ErrInvalid
	}
	if ctx.Err() != nil {
		return nil, 0, ctx.Err()
	}
	select {
	case h.gate <- struct{}{}:
		defer func() { <-h.gate }()
	default:
		return nil, 0, ports.ErrBusy
	}
	ctx, cancel := context.WithTimeout(ctx, h.timeout)
	defer cancel()
	response, err := h.client.Do(req.Clone(ctx))
	if err != nil {
		return nil, 0, ports.ErrUnavailable
	}
	defer response.Body.Close()
	data, err := io.ReadAll(io.LimitReader(response.Body, h.maxBody+1))
	if err != nil || int64(len(data)) > h.maxBody {
		return nil, response.StatusCode, ports.ErrUnavailable
	}
	return data, response.StatusCode, nil
}
func (h *HTTP) Close() { h.client.CloseIdleConnections() }
