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

const ASRServiceID = "wxa8386175898e12c9"

type AVConfig struct {
	APIName      string `json:"api_name"`
	DataEncoding string `json:"data_encoding"`
}
type AV struct {
	s      *Service
	c      AVConfig
	client HTTPClient
	gate   chan struct{}
}

func NewAV(s *Service, c AVConfig, client HTTPClient) (*AV, error) {
	if s == nil || !regexp.MustCompile(`^[A-Za-z0-9_]{1,128}$`).MatchString(c.APIName) || (c.DataEncoding != "object" && c.DataEncoding != "json_string") {
		return nil, ports.ErrInvalid
	}
	if client == nil {
		client = &http.Client{Timeout: 12 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }, Transport: &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: 3 * time.Second}).DialContext, MaxConnsPerHost: 1, ResponseHeaderTimeout: 10 * time.Second}}
	}
	return &AV{s, c, client, make(chan struct{}, 1)}, nil
}
func (a *AV) MediaAppID() string { return a.s.appID }
func (a *AV) binding(ctx context.Context, q ports.AudioInspectionRequest) (binding, Credential, error) {
	if q.Actor.Kind != "mini" || !validID(q.IntentID) || !validID(q.ConversationID) || !hexPattern.MatchString(q.ObjectHash) || q.Segment < 0 || q.Segment > 1 || !hexPattern.MatchString(q.UserAudioKey) {
		return binding{}, Credential{}, ports.ErrInvalid
	}
	u, e := url.Parse(q.URL)
	if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.Fragment != "" {
		return binding{}, Credential{}, ports.ErrInvalid
	}
	identity, e := a.s.identity(ctx, q.Actor, nil)
	if e != nil {
		return binding{}, Credential{}, e
	}
	credential, e := a.s.credentials.Get()
	return identity, credential, e
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
	req.Header.Set("Content-Type", "application/json")
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
	out := ports.Transcription{ServiceID: ASRServiceID, APIName: a.c.APIName, Engine: "16k_zh", Known: true, Retryable: true}
	select {
	case a.gate <- struct{}{}:
		defer func() { <-a.gate }()
	default:
		return out, ports.ErrBusy
	}
	identity, c, e := a.binding(ctx, q)
	if e != nil {
		return out, e
	}
	return a.recognize(ctx, q, identity, c)
}

// The protocol adapter stays separate from the current-identity database gate.
func (a *AV) recognize(ctx context.Context, q ports.AudioInspectionRequest, identity binding, c Credential) (ports.Transcription, error) {
	out := ports.Transcription{ServiceID: ASRServiceID, APIName: a.c.APIName, Engine: "16k_zh", Known: true, Retryable: true}
	data := any(map[string]any{"Action": "SentenceRecognitionWX", "Version": "2019-06-14", "ProjectId": 0, "SubServiceType": 2, "EngSerViceType": "16k_zh", "SourceType": 0, "VoiceFormat": "mp3", "Url": q.URL, "UsrAudioKey": q.UserAudioKey})
	if a.c.DataEncoding == "json_string" {
		raw, _ := json.Marshal(data)
		data = string(raw)
	}
	raw, dispatched, e := a.call(ctx, "/wxa/servicemarket", c, map[string]any{"service": ASRServiceID, "api": a.c.APIName, "client_msg_id": q.UserAudioKey, "data": data})
	out.Dispatched = dispatched
	out.Known = !dispatched
	out.AuthorityProof = identity.proof
	if e != nil {
		return out, e
	}
	var response struct {
		Code *int            `json:"errcode"`
		Data json.RawMessage `json:"data"`
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
	dataRaw := response.Data
	var encoded string
	if json.Unmarshal(dataRaw, &encoded) == nil {
		dataRaw = []byte(encoded)
	}
	var result struct {
		Response *struct {
			Result    *string `json:"Result"`
			RequestID string  `json:"RequestId"`
			Error     *struct {
				Code string `json:"Code"`
			} `json:"Error"`
		} `json:"Response"`
	}
	if !avJSON(dataRaw, &result) || result.Response == nil {
		return out, ports.ErrUnavailable
	}
	// An explicit service error is a definitive response, not a lost result.
	// Do not repeat a potentially billable invocation for provider/config errors.
	if result.Response.Error != nil && result.Response.Error.Code != "" {
		out.Known = true
		out.Retryable = false
		return out, ports.ErrUnavailable
	}
	if result.Response.Error != nil || result.Response.Result == nil || result.Response.RequestID == "" || !tracePattern.MatchString(result.Response.RequestID) || !utf8.ValidString(*result.Response.Result) || utf8.RuneCountInString(*result.Response.Result) > 1000 {
		return out, ports.ErrUnavailable
	}
	out.Text = *result.Response.Result
	out.RequestID = result.Response.RequestID
	out.Known = true
	out.Retryable = false
	return out, nil
}
func (a *AV) SubmitAudio(ctx context.Context, q ports.AudioInspectionRequest) (ports.AudioTicket, error) {
	out := ports.AudioTicket{Known: true, Retryable: true}
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
