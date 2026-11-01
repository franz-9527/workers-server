package dependencies

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/calls"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
	"io"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"strings"
	"time"
)

type CallsConfig struct {
	PublishPrivilegesVerified bool   `json:"publish_privileges_verified"`
	Enabled                   bool   `json:"enabled"`
	PHPEndpoint               string `json:"php_endpoint"`
	KeyID                     string `json:"key_id"`
	KeyEnv                    string `json:"key_env"`
	ShengwangAppID            string `json:"shengwang_app_id"`
	CertificateEnv            string `json:"certificate_env"`
	CustomerIDEnv             string `json:"customer_id_env"`
	CustomerSecretEnv         string `json:"customer_secret_env"`
}

func (c CallsConfig) Validate() error {
	if !c.Enabled {
		return nil
	}
	u, e := url.Parse(c.PHPEndpoint)
	if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.Path != "/chat-calls.php" || u.RawQuery != "" || u.Fragment != "" || !secretName.MatchString(c.KeyEnv) || !regexp.MustCompile("^[a-z0-9_-]{1,32}$").MatchString(c.KeyID) {
		return ports.ErrInvalid
	}
	if c.ShengwangAppID != "" {
		if !regexp.MustCompile("^[a-f0-9]{32}$").MatchString(c.ShengwangAppID) || !secretName.MatchString(c.CertificateEnv) || !secretName.MatchString(c.CustomerIDEnv) || !secretName.MatchString(c.CustomerSecretEnv) {
			return ports.ErrInvalid
		}
	}
	return nil
}

type callBroker struct {
	transport     *HTTP
	signer        *auth.InternalAuth
	endpoint, kid string
}

func (b *callBroker) request(ctx context.Context, q any, target any) error {
	raw, e := json.Marshal(q)
	if e != nil {
		return calls.ErrInvalid
	}
	req, e := http.NewRequestWithContext(ctx, "POST", b.endpoint, bytes.NewReader(raw))
	if e != nil {
		return e
	}
	req.Header.Set("Content-Type", "application/json")
	if e = b.signer.Sign(req, raw, b.kid, time.Now()); e != nil {
		return e
	}
	data, status, e := b.transport.Do(ctx, req)
	if e != nil {
		return calls.ErrUnavailable
	}
	if status != 200 {
		var fault struct {
			Code string `json:"code"`
		}
		if json.Unmarshal(data, &fault) == nil && regexp.MustCompile("^CALL_[a-zA-Z_]{1,55}$").MatchString(fault.Code) {
			return callFault(fault.Code)
		}
		return calls.ErrUnavailable
	}
	if json.Unmarshal(data, target) != nil {
		return calls.ErrInvalid
	}
	return nil
}

type callFault string

func (e callFault) Error() string { return string(e) }
func (b *callBroker) Availability(ctx context.Context) (calls.Availability, error) {
	var r calls.Availability
	e := b.request(ctx, map[string]string{"action": "availability"}, &r)
	return r, e
}
func (b *callBroker) Resource(ctx context.Context, q calls.ResourceRequest) (calls.Permit, error) {
	var r calls.Permit
	e := b.request(ctx, q, &r)
	return r, e
}
func (b *callBroker) Identities(ctx context.Context, q calls.ResourceRequest) (map[string]calls.NativePerson, error) {
	r := map[string]calls.NativePerson{}
	e := b.request(ctx, q, &r)
	return r, e
}
func (b *callBroker) ProviderCredentials(ctx context.Context) (calls.ProviderCredentials, error) {
	var result calls.ProviderCredentials
	err := b.request(ctx, map[string]string{"action": "provider_credentials"}, &result)
	return result, err
}
func (r *Runtime) EnableCalls(c CallsConfig) error {
	if !c.Enabled {
		return nil
	}
	if c.Validate() != nil || r.sql == nil || r.redis == nil || r.Identity == nil || r.Conversations == nil || r.Messages == nil || r.mode != "disabled" {
		return ports.ErrInvalid
	}
	encoded := os.Getenv(c.KeyEnv)
	key, e := base64.StdEncoding.Strict().DecodeString(encoded)
	if e != nil || len(key) != 32 || base64.StdEncoding.EncodeToString(key) != encoded {
		return ports.ErrInvalid
	}
	nonces, e := auth.NewRedisStore(r.redis, r.environment, r.settings.Redis.KeyPrefix)
	if e != nil {
		return e
	}
	signer, e := auth.NewInternal(r.environment, map[string][]byte{c.KeyID: key}, nonces, time.Second)
	if e != nil {
		return e
	}
	transport, e := NewHTTP(8, 3*time.Second, 64<<10)
	if e != nil {
		return e
	}
	r.callTransport = transport
	broker := &callBroker{transport, signer, c.PHPEndpoint, c.KeyID}
	provider := &calls.Providers{Resolve: broker.ProviderCredentials, PublishPrivilegesVerified: c.PublishPrivilegesVerified, Broker: broker, Environment: r.environment, AppID: c.ShengwangAppID, Certificate: os.Getenv(c.CertificateEnv), CustomerID: os.Getenv(c.CustomerIDEnv), CustomerSecret: os.Getenv(c.CustomerSecretEnv)}
	authority, e := calls.NewOrderAuthority(r.sql, r.Conversations, r.environment)
	if e != nil {
		return e
	}
	service, e := calls.NewService(r.sql, authority, broker, provider)
	if e != nil {
		return e
	}
	r.Calls = service
	r.CallsHTTP = calls.NewHandler(service, r.Identity)
	r.CallsInternal = http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		if req.Method != "POST" || req.URL.RequestURI() != "/chat/internal/v1/calls/stop-confirmed" {
			http.Error(w, "unavailable", 403)
			return
		}
		raw, e := io.ReadAll(io.LimitReader(req.Body, 1025))
		if e != nil || len(raw) > 1024 || signer.Verify(req.Context(), req, raw, time.Now()) != nil {
			http.Error(w, "unavailable", 403)
			return
		}
		var q struct {
			Call     ports.ID `json:"call_id"`
			Epoch    ports.ID `json:"attempt_epoch"`
			Evidence string   `json:"evidence_hash"`
		}
		decoder := json.NewDecoder(bytes.NewReader(raw))
		decoder.DisallowUnknownFields()
		if decoder.Decode(&q) != nil || decoder.Decode(new(any)) != io.EOF || service.ConfirmStopped(req.Context(), q.Call, q.Epoch, q.Evidence) != nil {
			http.Error(w, "conflict", 409)
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]bool{"ok": true})
	})
	return nil
}
func (r *Runtime) RunCalls(ctx context.Context) {
	timer := time.NewTicker(5 * time.Second)
	defer timer.Stop()
	for {
		if r.OperationsReady() {
			pass, cancel := context.WithTimeout(ctx, 20*time.Second)
			_ = r.Calls.Tick(pass)
			cancel()
		}
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}
	}
}

type callCommands struct {
	runtime *Runtime
	other   socket.Commands
}

func (c callCommands) Execute(ctx context.Context, p ports.Principal, kind string, raw json.RawMessage) (string, any, error) {
	if !strings.HasPrefix(kind, "calls.") {
		return c.other.Execute(ctx, p, kind, raw)
	}
	if c.runtime.Calls == nil {
		return "", nil, callCommandError{"CALL_DISABLED"}
	}
	action := strings.TrimPrefix(kind, "calls.")
	q, e := calls.Decode(action, raw)
	if e != nil {
		return "", nil, callCommandError{"CALL_REQUEST_INVALID"}
	}
	result, e := c.runtime.Calls.Execute(ctx, p, action, q)
	if e != nil {
		if errors.Is(e, policy.ErrDenied) || errors.Is(e, auth.ErrUnauthorized) {
			return "", nil, callCommandError{"CHAT_PERMISSION_DENIED"}
		}
		code := e.Error()
		if !regexp.MustCompile("^CALL_[A-Za-z_]{1,55}$").MatchString(code) {
			code = "CALL_UNAVAILABLE"
		}
		return "", nil, callCommandError{code}
	}
	return kind + ".result", result, nil
}

type callCommandError struct{ code string }

func (e callCommandError) Error() string              { return e.code }
func (e callCommandError) PublicCode() (string, bool) { return e.code, e.code == "CALL_UNAVAILABLE" }

var _ socket.Commands = callCommands{}
var _ socket.Commands = message.SocketCommands{}
