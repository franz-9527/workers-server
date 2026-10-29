package calls

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	token "github.com/franz-9527/workers/chat/internal/agoratoken/accesstoken2"
	"github.com/franz-9527/workers/chat/internal/ports"
	"io"
	"net/http"
	"net/url"
 "regexp"
	"strconv"
	"time"
)

type ProviderCredentials struct {
 Schema int `json:"schema"`
 Managed bool `json:"managed"`
 AppID string `json:"app_id"`
 Certificate string `json:"certificate"`
 CustomerID string `json:"customer_id"`
 CustomerSecret string `json:"customer_secret"`
 PublishPrivilegesVerified bool `json:"publish_privileges_verified"`
}
type Providers struct {
 Resolve func(context.Context) (ProviderCredentials, error)
	PublishPrivilegesVerified                                   bool
	Broker                                                      Broker
	Environment, AppID, Certificate, CustomerID, CustomerSecret string
	Client                                                      *http.Client
}

func (p *Providers) Ready(provider Provider) bool {
	if provider == Wechat { return true }
 if provider != Shengwang { return false }
 ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
 defer cancel()
 configured, err := p.configured(ctx)
 return err == nil && configured.PublishPrivilegesVerified && configured.SupplierControlReady()
}

// Resolve per operation so saved administrator credentials take effect without a restart.
func (p *Providers) configured(ctx context.Context) (*Providers, error) {
 copy := *p
 copy.Resolve = nil
 if p.Resolve == nil { return &copy, nil }
 value, err := p.Resolve(ctx)
 if err != nil || value.Schema != 1 { return nil, ErrUnavailable }
 if !value.Managed { return &copy, nil }
 copy.AppID, copy.Certificate = value.AppID, value.Certificate
 copy.CustomerID, copy.CustomerSecret = value.CustomerID, value.CustomerSecret
 copy.PublishPrivilegesVerified = value.PublishPrivilegesVerified
 return &copy, nil
}
// Closing admission must not disable remedial stop/query for existing exposure.
func (p *Providers) SupplierControlReady() bool {
	return regexp.MustCompile("^[a-f0-9]{32}$").MatchString(p.AppID) && regexp.MustCompile("^[a-f0-9]{32}$").MatchString(p.Certificate) && p.CustomerID != "" && p.CustomerSecret != ""
}
func (p *Providers) channel(s Session, app string) string {
	return "bx_" + sha256String(p.Environment + ":" + app + ":" + string(s.CallID) + ":" + strconv.FormatUint(s.Epoch, 10))[:40]
}
func (p *Providers) Credentials(ctx context.Context, s Session, permit Permit, actor ports.ID) (any, error) {
	if actor != s.CallerID && actor != s.CalleeID {
		return nil, ErrInvalid
	}
	ttl := permit.Until - time.Now().Unix()
	if ttl < 2 || ttl > 300 {
		return nil, ErrUnavailable
	}
	if s.Provider == Wechat {
		q := ResourceRequest{"identities", sha256String("identities:" + string(s.CallID)), s.CallID, ports.ID(strconv.FormatUint(s.Epoch, 10)), permit.PoolID, s.Media, s.CallerID, s.CalleeID}
		people, e := p.Broker.Identities(ctx, q)
		if e != nil {
			return nil, e
		}
		if people["caller_id"].OpenID == "" || people["callee_id"].OpenID == "" {
			return nil, ErrUnavailable
		}
		return map[string]any{"provider": Wechat, "caller": people["caller_id"], "listener": people["callee_id"], "initiator": actor == s.CallerID, "expires_at": permit.Until}, nil
	}
	if p.Resolve != nil {
  configured, err := p.configured(ctx)
  if err != nil { return nil, ErrUnavailable }
  return configured.Credentials(ctx, s, permit, actor)
 }
 if !p.Ready(Shengwang) || permit.AppID != p.AppID {
		return nil, ErrUnavailable
	}
	uid := uint32(1)
	peer := uint32(2)
	if actor == s.CalleeID {
		uid, peer = 2, 1
	}
	channel := p.channel(s, permit.AppID)
	// Omit forbidden privileges entirely: an expiry of zero is not a denial.
	signedToken := token.NewAccessToken(p.AppID, p.Certificate, uint32(ttl))
	rtc := token.NewServiceRtc(channel, token.GetUidStr(uid))
	rtc.AddPrivilege(token.PrivilegeJoinChannel, uint32(ttl))
	rtc.AddPrivilege(token.PrivilegePublishAudioStream, uint32(ttl))
	if s.Media == Video {
		rtc.AddPrivilege(token.PrivilegePublishVideoStream, uint32(ttl))
	}
	signedToken.AddService(rtc)
	signed, e := signedToken.Build()
	if e != nil {
		return nil, ErrUnavailable
	}
	return map[string]any{"provider": Shengwang, "app_id": p.AppID, "channel": channel, "uid": uid, "peer_uid": peer, "token": signed, "expires_at": permit.Until}, nil
}
func (p *Providers) request(ctx context.Context, method, path string, body any) (map[string]json.RawMessage, error) {
	if !p.SupplierControlReady() {
		return nil, ErrUnavailable
	}
	var raw []byte
	if body != nil {
		raw, _ = json.Marshal(body)
	}
	req, e := http.NewRequestWithContext(ctx, method, "https://api.sd-rtn.com"+path, bytes.NewReader(raw))
	if e != nil {
		return nil, e
	}
	req.SetBasicAuth(p.CustomerID, p.CustomerSecret)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	client := p.Client
	if client == nil {
		client = &http.Client{Timeout: 5 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	}
	response, e := client.Do(req)
	if e != nil {
		return nil, ErrUnavailable
	}
	defer response.Body.Close()
	data, e := io.ReadAll(io.LimitReader(response.Body, 16385))
	if e != nil || len(data) > 16384 || response.StatusCode != 200 {
		return nil, ErrUnavailable
	}
	result := map[string]json.RawMessage{}
	if json.Unmarshal(data, &result) != nil {
		return nil, ErrUnavailable
	}
	return result, nil
}

// A supplier-accepted ban must be followed by an authoritative empty-channel
// query. SDK/client "left" and token expiry alone never release exposure.
func (p *Providers) Stop(ctx context.Context, s Session, permit Permit) (bool, error) {
	if s.Provider == Wechat {
		return false, nil
	} // Native 1v1 has no verified server close/query API. Evidence review is explicit.
	if p.Resolve != nil {
  configured, err := p.configured(ctx)
  if err != nil { return false, ErrUnavailable }
  return configured.Stop(ctx, s, permit)
 }
 if permit.AppID != p.AppID || !p.SupplierControlReady() {
		return false, ErrUnavailable
	}
	channel := p.channel(s, permit.AppID)
	minutes := int64(2)
	if permit.Until > time.Now().Unix() {
		minutes += (permit.Until - time.Now().Unix() + 59) / 60
	}
	result, e := p.request(ctx, "POST", "/dev/v1/kicking-rule", map[string]any{"appid": p.AppID, "cname": channel, "time": minutes, "privileges": []string{"join_channel"}})
	if e != nil {
		return false, e
	}
	var status string
	if json.Unmarshal(result["status"], &status) != nil || status != "success" {
		return false, ErrUnavailable
	}
	result, e = p.request(ctx, "GET", fmt.Sprintf("/dev/v1/channel/user/%s/%s", url.PathEscape(p.AppID), url.PathEscape(channel)), nil)
	if e != nil {
		return false, e
	}
	var success bool
	var data struct {
		Exists *bool `json:"channel_exist"`
	}
	if json.Unmarshal(result["success"], &success) != nil || !success || json.Unmarshal(result["data"], &data) != nil || data.Exists == nil {
		return false, ErrUnavailable
	}
	return !*data.Exists, nil
}
