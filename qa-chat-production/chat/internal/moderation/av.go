package moderation

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"
)

type asrFailure string

func (e asrFailure) Error() string       { return ports.ErrUnavailable.Error() }
func (e asrFailure) Unwrap() error       { return ports.ErrUnavailable }
func (e asrFailure) FailureCode() string { return string(e) }

type AVConfig struct {
	APIName      string `json:"api_name"`
	DataEncoding string `json:"data_encoding"`
	Provider     string `json:"provider"`
	Engine       string `json:"engine"`
	SecretID     string `json:"-"`
	SecretKey    string `json:"-"`
}
type AV struct {
	s      *Service
	c      AVConfig
	client HTTPClient
	gate   chan struct{}
}

func NewAV(s *Service, c AVConfig, client HTTPClient) (*AV, error) {
	if c.Provider == "" {
		c.Provider = "tencent_cloud"
	}
	if s == nil || s.credentials == nil {
		return nil, ports.ErrInvalid
	}
	if c.Provider != "tencent_cloud" {
		return nil, ports.ErrInvalid
	}
	{
		credentialsEmpty := c.SecretID == "" && c.SecretKey == ""
		credentialsValid := regexp.MustCompile(`^[A-Za-z0-9]{10,128}$`).MatchString(c.SecretID) && len(c.SecretKey) >= 16 && len(c.SecretKey) <= 256 && !regexp.MustCompile(`[\x00-\x20\x7f]`).MatchString(c.SecretKey)
		if c.APIName != "SentenceRecognition" || c.DataEncoding != "json" || !validTencentASREngine(c.Engine) || (!credentialsEmpty && !credentialsValid) {
			return nil, ports.ErrInvalid
		}
	}
	if client == nil {
		client = &http.Client{Timeout: 12 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }, Transport: &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: 3 * time.Second}).DialContext, MaxConnsPerHost: 1, ResponseHeaderTimeout: 10 * time.Second}}
	}
	return &AV{s, c, client, make(chan struct{}, 1)}, nil
}
func (a *AV) MediaAppID() string { return a.s.appID }
func (a *AV) ASRMetadata() ports.Transcription {
	return ports.Transcription{ServiceID: "tencent_cloud_asr", APIName: a.c.APIName, Engine: a.c.Engine, DataEncoding: "json"}
}
func (a *AV) binding(ctx context.Context, q ports.AudioInspectionRequest) (binding, Credential, error) {
	if q.Actor.Kind != "mini" || !validID(q.IntentID) || !validID(q.ConversationID) || !hexPattern.MatchString(q.ObjectHash) || q.Segment < 0 || q.Segment > 1 || !asrKeyPattern.MatchString(q.UserAudioKey) {
		return binding{}, Credential{}, ports.ErrInvalid
	}
	identity, e := a.s.identity(ctx, q.Actor, nil)
	if e != nil {
		return binding{}, Credential{}, e
	}
	credential, e := a.s.credentials.Get()
	return identity, credential, e
}
func validAudioInspectionURL(raw string) bool {
	u, e := url.Parse(raw)
	return e == nil && len(raw) > 0 && len(raw) < 2048 && (u.Scheme == "https" || u.Scheme == "http") && u.Host != "" && u.User == nil && u.Fragment == ""
}
func safeASRDiagnosticText(value string) string {
	value = strings.TrimSpace(value)
	if value == "" || !utf8.ValidString(value) {
		return ""
	}
	value = regexp.MustCompile(`(?i)https?://[^\s"'<>]+`).ReplaceAllString(value, "[url]")
	value = regexp.MustCompile(`(?i)(access_token|token|secret|signature)\s*[:=]\s*[^\s,;]+`).ReplaceAllString(value, "$1=[redacted]")
	runes := []rune(value)
	if len(runes) > 240 {
		value = string(runes[:240])
	}
	return value
}
func (a *AV) call(ctx context.Context, path string, c Credential, body any) ([]byte, bool, error) {
	raw, e := json.Marshal(body)
	if e != nil {
		return nil, false, e
	}
	req, e := http.NewRequestWithContext(ctx, "POST", "https://api.weixin.qq.com"+path+"?"+url.Values{"access_token": {c.Token}}.Encode(), strings.NewReader(string(raw)))
	if e != nil {
		return nil, false, e
	}
	req.Header.Set("Content-Type", "application/json; charset=utf-8")
	req.Header.Set("Accept", "application/json")
	res, e := a.client.Do(req)
	if e != nil || res == nil || res.Body == nil {
		return nil, true, ports.ErrUnavailable
	}
	defer res.Body.Close()
	raw, e = io.ReadAll(io.LimitReader(res.Body, 65537))
	if e != nil || len(raw) > 65536 || res.StatusCode != 200 {
		return nil, true, ports.ErrUnavailable
	}
	return raw, true, nil
}
func (a *AV) Recognize(ctx context.Context, q ports.AudioInspectionRequest) (ports.Transcription, error) {
	out := a.ASRMetadata()
	out.Known = true
	out.Retryable = true
	if !validAudioInspectionURL(q.URL) || q.Actor.Kind != "mini" || !validID(q.IntentID) || !validID(q.ConversationID) || !hexPattern.MatchString(q.ObjectHash) || q.Segment < 0 || q.Segment > 1 || !asrKeyPattern.MatchString(q.UserAudioKey) {
		return out, ports.ErrInvalid
	}
	select {
	case a.gate <- struct{}{}:
		defer func() { <-a.gate }()
	default:
		return out, ports.ErrBusy
	}
	identity, e := a.s.identity(ctx, q.Actor, nil)
	if e != nil {
		return out, e
	}
	return a.recognizeTencent(ctx, q, identity)
}
func (a *AV) SubmitAudio(ctx context.Context, q ports.AudioInspectionRequest) (ports.AudioTicket, error) {
	out := ports.AudioTicket{Known: true, Retryable: true}
	if !validAudioInspectionURL(q.URL) {
		return out, ports.ErrInvalid
	}
	identity, c, e := a.binding(ctx, q)
	if e != nil {
		return out, e
	}
	return a.submitAudio(ctx, q, identity, c)
}
func (a *AV) submitAudio(ctx context.Context, q ports.AudioInspectionRequest, identity binding, c Credential) (ports.AudioTicket, error) {
	out := ports.AudioTicket{Known: true, Retryable: true}
	raw, dispatched, e := a.call(ctx, "/wxa/media_check_async", c, map[string]any{"version": 2, "scene": 1, "openid": identity.openid, "media_url": q.URL, "media_type": 1})
	out.Dispatched = dispatched
	out.Known = !dispatched
	out.AuthorityProof = identity.proof
	if e != nil {
		return out, e
	}
	var response struct {
		Code  *int   `json:"errcode"`
		Trace string `json:"trace_id"`
	}
	if !avJSON(raw, &response) || response.Code == nil {
		return out, ports.ErrUnavailable
	}
	if *response.Code != 0 {
		out.Known = true
		out.Retryable = *response.Code == 40001 || *response.Code == 40014 || *response.Code == 42001 || *response.Code == -1
		if *response.Code == 40001 || *response.Code == 40014 || *response.Code == 42001 {
			a.s.credentials.Reject(c.Generation)
		}
		return out, ports.ErrUnavailable
	}
	if response.Trace == "" || !tracePattern.MatchString(response.Trace) {
		return out, ports.ErrUnavailable
	}
	out.TraceID = response.Trace
	out.Known = true
	out.Retryable = false
	return out, nil
}
func (s *Service) MediaProof(ctx context.Context, p ports.Principal) (ports.SafetyResult, error) {
	v, e := s.identity(ctx, p, nil)
	return ports.SafetyResult{Decision: "media_identity", RuleVersion: RuleVersion, AuthorityProof: v.proof}, e
}
func (s *Service) ValidateMediaProofLocked(ctx context.Context, tx *sql.Tx, p ports.Principal, v ports.SafetyResult) error {
	if v.Decision != "media_identity" {
		return ports.ErrInvalid
	}
	return s.ValidateTextLocked(ctx, tx, p, v)
}

func avJSON(raw []byte, target any) bool {
	if !utf8.Valid(raw) {
		return false
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	var walk func(int) bool
	walk = func(depth int) bool {
		if depth > 12 {
			return false
		}
		t, e := d.Token()
		if e != nil {
			return false
		}
		switch t {
		case json.Delim('{'):
			seen := map[string]bool{}
			for d.More() {
				k, e := d.Token()
				name, ok := k.(string)
				if e != nil || !ok || seen[strings.ToLower(name)] {
					return false
				}
				seen[strings.ToLower(name)] = true
				if !walk(depth + 1) {
					return false
				}
			}
			t, e = d.Token()
			return e == nil && t == json.Delim('}')
		case json.Delim('['):
			n := 0
			for d.More() {
				n++
				if n > 100 || !walk(depth+1) {
					return false
				}
			}
			t, e = d.Token()
			return e == nil && t == json.Delim(']')
		}
		return true
	}
	if !walk(0) {
		return false
	}
	if _, e := d.Token(); e != io.EOF {
		return false
	}
	return json.Unmarshal(raw, target) == nil
}
