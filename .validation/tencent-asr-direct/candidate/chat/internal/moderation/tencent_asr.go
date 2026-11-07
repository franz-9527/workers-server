package moderation

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/ports"
)

const (
	tencentASRHost    = "asr.tencentcloudapi.com"
	tencentASRService = "asr"
	tencentASRAction  = "SentenceRecognition"
	tencentASRVersion = "2019-06-14"
)

func validTencentASREngine(v string) bool {
	switch v {
	case "16k_zh_dialect", "16k_zh", "16k_zh-PY", "16k_yue":
		return true
	default:
		return false
	}
}

func hmacSHA256(key []byte, value string) []byte {
	h := hmac.New(sha256.New, key)
	_, _ = h.Write([]byte(value))
	return h.Sum(nil)
}

func sha256Hex(value []byte) string {
	s := sha256.Sum256(value)
	return hex.EncodeToString(s[:])
}

func tencentAuthorization(secretID, secretKey string, payload []byte, at time.Time) string {
	stamp := strconv.FormatInt(at.UTC().Unix(), 10)
	date := at.UTC().Format("2006-01-02")
	canonicalHeaders := "content-type:application/json; charset=utf-8\nhost:" + tencentASRHost + "\n"
	signedHeaders := "content-type;host"
	canonicalRequest := "POST\n/\n\n" + canonicalHeaders + "\n" + signedHeaders + "\n" + sha256Hex(payload)
	scope := date + "/" + tencentASRService + "/tc3_request"
	stringToSign := "TC3-HMAC-SHA256\n" + stamp + "\n" + scope + "\n" + sha256Hex([]byte(canonicalRequest))
	secretDate := hmacSHA256([]byte("TC3"+secretKey), date)
	secretService := hmacSHA256(secretDate, tencentASRService)
	secretSigning := hmacSHA256(secretService, "tc3_request")
	signature := hex.EncodeToString(hmacSHA256(secretSigning, stringToSign))
	return "TC3-HMAC-SHA256 Credential=" + secretID + "/" + scope + ", SignedHeaders=" + signedHeaders + ", Signature=" + signature
}

func tencentErrorCode(value string) string {
	value = strings.ToUpper(strings.TrimSpace(value))
	value = regexp.MustCompile(`[^A-Z0-9]+`).ReplaceAllString(value, "_")
	value = strings.Trim(value, "_")
	if value == "" || len(value) > 72 {
		return "PROVIDER_REJECTED"
	}
	return value
}

func tencentRetryable(code string) bool {
	upper := strings.ToUpper(code)
	return strings.HasPrefix(upper, "REQUESTLIMITEXCEEDED") ||
		strings.HasPrefix(upper, "INTERNALERROR") ||
		strings.HasPrefix(upper, "SERVICEUNAVAILABLE") ||
		strings.HasPrefix(upper, "RESOURCEUNAVAILABLE")
}

func (a *AV) recognizeTencent(ctx context.Context, q ports.AudioInspectionRequest, identity binding) (ports.Transcription, error) {
	out := ports.Transcription{
		ServiceID: "tencent_cloud_asr", APIName: tencentASRAction, Engine: a.c.Engine,
		DataEncoding: "json", AuthorityProof: identity.proof, Known: true, Retryable: true,
	}
	if a.c.SecretID == "" || a.c.SecretKey == "" {
		out.ProviderCode = "NOT_CONFIGURED"
		out.ProviderMessage = "Tencent Cloud ASR credentials are not configured"
		out.Retryable = false
		return out, asrFailure("CHAT_AV_ASR_TENCENT_NOT_CONFIGURED")
	}
	parsed, _ := urlParseHTTPS(q.URL)
	if parsed == "" {
		return out, ports.ErrInvalid
	}
	out.InputHost = parsed
	out.InputURLLength = len(q.URL)

	payload, e := json.Marshal(map[string]any{
		"ProjectId":      0,
		"SubServiceType": 2,
		"EngSerViceType": a.c.Engine,
		"VoiceFormat":    "mp3",
		"SourceType":     0,
		"Url":            q.URL,
		"FilterDirty":    0,
		"FilterModal":    0,
		"FilterPunc":     0,
		"ConvertNumMode": 1,
	})
	if e != nil {
		return out, ports.ErrUnavailable
	}
	now := time.Now().UTC()
	req, e := http.NewRequestWithContext(ctx, http.MethodPost, "https://"+tencentASRHost+"/", bytes.NewReader(payload))
	if e != nil {
		return out, ports.ErrUnavailable
	}
	req.Header.Set("Content-Type", "application/json; charset=utf-8")
	req.Header.Set("X-TC-Action", tencentASRAction)
	req.Header.Set("X-TC-Version", tencentASRVersion)
	req.Header.Set("X-TC-Timestamp", strconv.FormatInt(now.Unix(), 10))
	req.Header.Set("Authorization", tencentAuthorization(a.c.SecretID, a.c.SecretKey, payload, now))
	res, e := a.client.Do(req)
	out.Dispatched = true
	out.Known = false
	if e != nil || res == nil || res.Body == nil {
		return out, ports.ErrUnavailable
	}
	defer res.Body.Close()
	raw, e := io.ReadAll(io.LimitReader(res.Body, 65537))
	if e != nil || len(raw) > 65536 || res.StatusCode != http.StatusOK || !utf8.Valid(raw) {
		return out, ports.ErrUnavailable
	}
	var envelope struct {
		Response struct {
			RequestID string  `json:"RequestId"`
			Result    *string `json:"Result"`
			Error     *struct {
				Code    string `json:"Code"`
				Message string `json:"Message"`
			} `json:"Error"`
		} `json:"Response"`
	}
	if !avJSON(raw, &envelope) {
		return out, ports.ErrUnavailable
	}
	out.RequestID = strings.TrimSpace(envelope.Response.RequestID)
	if envelope.Response.Error != nil && strings.TrimSpace(envelope.Response.Error.Code) != "" {
		out.Known = true
		out.ProviderCode = strings.TrimSpace(envelope.Response.Error.Code)
		out.ProviderMessage = safeASRDiagnosticText(envelope.Response.Error.Message)
		out.Retryable = tencentRetryable(out.ProviderCode)
		return out, asrFailure("CHAT_AV_ASR_TENCENT_" + tencentErrorCode(out.ProviderCode))
	}
	if envelope.Response.Result == nil || out.RequestID == "" || !tracePattern.MatchString(out.RequestID) ||
		!utf8.ValidString(*envelope.Response.Result) || utf8.RuneCountInString(*envelope.Response.Result) > 1000 {
		return out, ports.ErrUnavailable
	}
	out.Text = strings.TrimSpace(*envelope.Response.Result)
	out.Known = true
	out.Retryable = false
	return out, nil
}

func urlParseHTTPS(raw string) (string, error) {
	u, e := url.Parse(raw)
	if e != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.Fragment != "" {
		return "", ports.ErrInvalid
	}
	return u.Hostname(), nil
}
