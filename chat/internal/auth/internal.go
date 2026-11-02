package auth

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// InternalAuth keys are independent from PHP's Ed25519 signing key. A verified
// service request still needs an explicit route/purpose and actor authorization.
type InternalAuth struct {
	environment string
	keys        map[string][]byte
	store       OneTimeStore
	timeout     time.Duration
}

func NewInternal(environment string, keys map[string][]byte, store OneTimeStore, timeout time.Duration) (*InternalAuth, error) {
	if !validEnvironment(environment) || len(keys) < 1 || len(keys) > 3 || store == nil || timeout < 50*time.Millisecond || timeout > 5*time.Second {
		return nil, ports.ErrInvalid
	}
	a := &InternalAuth{environment: environment, keys: map[string][]byte{}, store: store, timeout: timeout}
	for id, k := range keys {
		if !keyID.MatchString(id) || len(k) != 32 {
			return nil, ports.ErrInvalid
		}
		a.keys[id] = append([]byte(nil), k...)
	}
	return a, nil
}

func canonical(r *http.Request, body []byte, env, kid, stamp, nonce string) (string, error) {
	uri := r.URL.RequestURI()
	if r.RequestURI != "" {
		uri = r.RequestURI
	}
	if r.Method != "POST" || len(body) > 64<<10 || len(uri) > 2048 || !strings.HasPrefix(uri, "/") || strings.ContainsAny(uri, "\r\n#") {
		return "", ErrUnauthorized
	}
	h := sha256.Sum256(body)
	return strings.Join([]string{"chat-internal-v1", r.Method, uri, hex.EncodeToString(h[:]), stamp, nonce, env, kid}, "\n"), nil
}

func (a *InternalAuth) Sign(r *http.Request, body []byte, kid string, now time.Time) error {
	k := a.keys[kid]
	if len(k) != 32 {
		return ports.ErrInvalid
	}
	nonce, e := randomID()
	if e != nil {
		return e
	}
	stamp := strconv.FormatInt(now.Unix(), 10)
	c, e := canonical(r, body, a.environment, kid, stamp, nonce)
	if e != nil {
		return e
	}
	mac := hmac.New(sha256.New, k)
	_, _ = mac.Write([]byte(c))
	for key, value := range map[string]string{"Environment": a.environment, "Key-ID": kid, "Timestamp": stamp, "Nonce": nonce, "Signature": hex.EncodeToString(mac.Sum(nil))} {
		r.Header.Set("X-Chat-"+key, value)
	}
	return nil
}

func (a *InternalAuth) Verify(ctx context.Context, r *http.Request, body []byte, now time.Time) error {
	fields := map[string]string{}
	for _, name := range []string{"Environment", "Key-ID", "Timestamp", "Nonce", "Signature"} {
		v := r.Header.Values("X-Chat-" + name)
		if len(v) != 1 {
			return ErrUnauthorized
		}
		fields[name] = v[0]
	}
	env, kid, stamp, nonce := fields["Environment"], fields["Key-ID"], fields["Timestamp"], fields["Nonce"]
	ts, e := strconv.ParseInt(stamp, 10, 64)
	if e != nil || strconv.FormatInt(ts, 10) != stamp || ts < now.Unix()-30 || ts > now.Unix()+30 || env != a.environment || !hex64.MatchString(nonce) || !hex64.MatchString(fields["Signature"]) {
		return ErrUnauthorized
	}
	k := a.keys[kid]
	if len(k) != 32 {
		return ErrUnauthorized
	}
	c, e := canonical(r, body, env, kid, stamp, nonce)
	if e != nil {
		return e
	}
	mac := hmac.New(sha256.New, k)
	_, _ = mac.Write([]byte(c))
	sig, _ := hex.DecodeString(fields["Signature"])
	if !hmac.Equal(sig, mac.Sum(nil)) {
		return ErrUnauthorized
	}
	ctx, cancel := context.WithTimeout(ctx, a.timeout)
	defer cancel()
	ok, e := a.store.Put(ctx, "nonce:"+digest(kid+":"+nonce), "1", 61*time.Second)
	if e != nil {
		return ports.ErrUnavailable
	}
	if !ok {
		return ErrUnauthorized
	}
	return nil
}
