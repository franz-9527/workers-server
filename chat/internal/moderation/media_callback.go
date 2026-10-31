package moderation

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/sha1"
	"crypto/subtle"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/xml"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"
)

type AudioReceiver interface {
	ReceiveAudio(context.Context, ports.AudioReceipt) error
}
type MediaCallback struct {
	AppID, Token string
	Key          []byte
	Receiver     AudioReceiver
}

func NewMediaCallback(appID, token, key string, r AudioReceiver) (*MediaCallback, error) {
	raw, e := base64.StdEncoding.DecodeString(key + "=")
	if !appPattern.MatchString(appID) || len(token) < 16 || len(token) > 128 || len(key) != 43 || e != nil || len(raw) != 32 || base64.RawStdEncoding.EncodeToString(raw) != key || r == nil {
		return nil, ports.ErrInvalid
	}
	return &MediaCallback{appID, token, raw, r}, nil
}
func (h *MediaCallback) decrypt(signature, timestamp, nonce, encoded string, now time.Time) ([]byte, error) {
	stamp, e := strconv.ParseInt(timestamp, 10, 64)
	if e != nil || len(timestamp) < 10 || len(timestamp) > 11 || len(nonce) < 1 || len(nonce) > 128 || stamp < now.Unix()-300 || stamp > now.Unix()+300 {
		return nil, ports.ErrInvalid
	}
	parts := []string{h.Token, timestamp, nonce, encoded}
	sort.Strings(parts)
	sum := sha1.Sum([]byte(strings.Join(parts, "")))
	if subtle.ConstantTimeCompare([]byte(signature), []byte(hex.EncodeToString(sum[:]))) != 1 {
		return nil, ports.ErrInvalid
	}
	raw, e := base64.StdEncoding.DecodeString(encoded)
	if e != nil || len(raw) < 32 || len(raw) > 65536 || len(raw)%16 != 0 {
		return nil, ports.ErrInvalid
	}
	block, e := aes.NewCipher(h.Key)
	if e != nil {
		return nil, ports.ErrInvalid
	}
	plain := make([]byte, len(raw))
	cipher.NewCBCDecrypter(block, h.Key[:16]).CryptBlocks(plain, raw)
	padding := int(plain[len(plain)-1])
	if padding < 1 || padding > 32 || padding > len(plain) {
		return nil, ports.ErrInvalid
	}
	for _, v := range plain[len(plain)-padding:] {
		if int(v) != padding {
			return nil, ports.ErrInvalid
		}
	}
	plain = plain[:len(plain)-padding]
	if len(plain) < 20 {
		return nil, ports.ErrInvalid
	}
	size := int(binary.BigEndian.Uint32(plain[16:20]))
	if size < 1 || size > len(plain)-20 || string(plain[20+size:]) != h.AppID {
		return nil, ports.ErrInvalid
	}
	return plain[20 : 20+size], nil
}
func (h *MediaCallback) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	if r.Method != "POST" && r.Method != "GET" {
		w.WriteHeader(405)
		return
	}
	q, e := url.ParseQuery(r.URL.RawQuery)
	if e != nil {
		w.WriteHeader(400)
		return
	}
	for _, key := range []string{"signature", "msg_signature", "timestamp", "nonce", "echostr", "encrypt_type"} {
		if len(q[key]) > 1 {
			w.WriteHeader(400)
			return
		}
	}
	// WeChat's URL ownership check can use the token signature even when
	// subsequent POST events use AES. This GET path never processes events.
	if r.Method == "GET" && q.Get("msg_signature") == "" {
		stamp, err := strconv.ParseInt(q.Get("timestamp"), 10, 64)
		now := time.Now().Unix()
		parts := []string{h.Token, q.Get("timestamp"), q.Get("nonce")}
		sort.Strings(parts)
		sum := sha1.Sum([]byte(strings.Join(parts, "")))
		if err != nil || stamp < now-300 || stamp > now+300 || len(q.Get("nonce")) < 1 || len(q.Get("nonce")) > 128 || len(q.Get("echostr")) < 1 || len(q.Get("echostr")) > 1024 || q.Get("encrypt_type") != "" || subtle.ConstantTimeCompare([]byte(q.Get("signature")), []byte(hex.EncodeToString(sum[:]))) != 1 {
			w.WriteHeader(403)
			return
		}
		io.WriteString(w, q.Get("echostr"))
		return
	}
	encrypted := q.Get("echostr")
	if r.Method == "POST" {
		raw, e := io.ReadAll(io.LimitReader(r.Body, 65537))
		if e != nil || len(raw) > 65536 {
			w.WriteHeader(400)
			return
		}
		var wrapper struct {
			Encrypt string `json:"Encrypt" xml:"Encrypt"`
		}
		if !callbackDecode(raw, &wrapper) {
			w.WriteHeader(400)
			return
		}
		encrypted = wrapper.Encrypt
	}
	raw, e := h.decrypt(q.Get("msg_signature"), q.Get("timestamp"), q.Get("nonce"), encrypted, time.Now())
	if e != nil {
		w.WriteHeader(403)
		return
	}
	if r.Method == "GET" {
		w.Write(raw)
		return
	}
	var event struct {
		AppID   string `json:"appid" xml:"appid"`
		Event   string `json:"Event" xml:"Event"`
		Trace   string `json:"trace_id" xml:"trace_id"`
		Version int    `json:"version" xml:"version"`
		Code    *int   `json:"errcode" xml:"errcode"`
		Result  struct {
			Suggest string `json:"suggest" xml:"suggest"`
		} `json:"result" xml:"result"`
	}
	if !callbackDecode(raw, &event) {
		w.WriteHeader(400)
		return
	}
	if event.AppID != h.AppID || event.Event != "wxa_media_check" || event.Version != 2 || event.Code == nil || event.Trace == "" || !tracePattern.MatchString(event.Trace) {
		w.WriteHeader(400)
		return
	}
	decision := event.Result.Suggest
	if *event.Code != 0 || decision != "pass" && decision != "risky" && decision != "review" {
		decision = "error"
	}
	receipt := ports.AudioReceipt{AppID: h.AppID, TraceID: event.Trace, Decision: decision, Code: "official_" + decision, Fingerprint: hash(h.AppID + "\n" + event.Trace + "\n" + decision)}
	if h.Receiver.ReceiveAudio(r.Context(), receipt) != nil {
		w.WriteHeader(503)
		return
	}
	io.WriteString(w, "success")
}

// JSON and XML are supported by the platform. Reject duplicate/case-alias
// security fields before decoding rather than accepting the last value.
func callbackDecode(raw []byte, target any) bool {
	trimmed := strings.TrimSpace(string(raw))
	if strings.HasPrefix(trimmed, "{") {
		return avJSON(raw, target)
	}
	if !strings.HasPrefix(trimmed, "<") {
		return false
	}
	d := xml.NewDecoder(strings.NewReader(trimmed))
	path := []string{}
	seen := map[string]bool{}
	roots := 0
	critical := map[string]bool{"xml/encrypt": true, "xml/appid": true, "xml/event": true, "xml/trace_id": true, "xml/version": true, "xml/errcode": true, "xml/result": true, "xml/result/suggest": true}
	for {
		token, err := d.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			return false
		}
		switch v := token.(type) {
		case xml.StartElement:
			if v.Name.Space != "" || len(path) >= 12 {
				return false
			}
			if len(path) == 0 {
				roots++
				if roots != 1 || v.Name.Local != "xml" {
					return false
				}
			}
			path = append(path, v.Name.Local)
			key := strings.ToLower(strings.Join(path, "/"))
			if critical[key] {
				if seen[key] {
					return false
				}
				seen[key] = true
			}
		case xml.EndElement:
			if len(path) == 0 {
				return false
			}
			path = path[:len(path)-1]
		case xml.Directive:
			return false
		case xml.CharData:
			if len(path) == 0 && strings.TrimSpace(string(v)) != "" {
				return false
			}
		}
	}
	return roots == 1 && len(path) == 0 && xml.Unmarshal(raw, target) == nil
}
