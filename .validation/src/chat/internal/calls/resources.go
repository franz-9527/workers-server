// Package calls defines pure call/resource contracts. It performs no I/O,
// grants no business authority and does not issue provider credentials.
package calls

import (
    "bytes"
    "encoding/json"
    "errors"
    "io"
    "strconv"

    "github.com/franz-9527/workers/chat/internal/ports"
)

var (
    ErrInvalid = errors.New("CALL_CONTRACT_INVALID")
    ErrConflict = errors.New("CALL_STATE_CONFLICT")
    ErrExpired = errors.New("CALL_INVITATION_EXPIRED")
)

type Provider string
type Media string

const (
    Wechat Provider = "wechat"
    Shengwang Provider = "shengwang"
    Audio Media = "audio"
    Video Media = "video"
)

func (p Provider) valid() bool { return p == Wechat || p == Shengwang }
func positive(id ports.ID) bool { return id.Valid() && id != "0" }

// ResourceDecision is internal PHP -> Go pool availability, NOT proof that
// resources have been reserved. No media can be issued on this snapshot alone.
type ResourceDecision struct {
    SchemaVersion int `json:"schema_version"`
    Provider Provider `json:"provider"`
    ScopeID string `json:"quota_scope_id"`
    CycleID string `json:"cycle_id"`
    ConfigRevision ports.ID `json:"config_revision"`
    Unit string `json:"unit"`
    Status string `json:"status"`
    AvailableUnits string `json:"available_units"`
    ExpiresAt int64 `json:"expires_at"`
}

func resourceKey(value string) bool {
    if len(value) == 0 || len(value) > 128 { return false }
    for i, c := range []byte(value) {
        if (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') { continue }
        if i > 0 && (c == ':' || c == '.' || c == '_' || c == '-') { continue }
        return false
    }
    return true
}

func (d ResourceDecision) units() (int64, error) {
    n, err := strconv.ParseInt(d.AvailableUnits, 10, 64)
    if err != nil || n < 0 || strconv.FormatInt(n, 10) != d.AvailableUnits { return 0, ErrInvalid }
    return n, nil
}

func (d ResourceDecision) valid() bool {
    n, err := d.units()
    unit := "wechat_equivalent"
    if d.Provider == Shengwang { unit = "shengwang_standard" }
    if err != nil || d.SchemaVersion != 1 || !d.Provider.valid() || d.Unit != unit ||
        !resourceKey(d.ScopeID) || !resourceKey(d.CycleID) || !positive(d.ConfigRevision) || d.ExpiresAt < 1 { return false }
    switch d.Status {
    case "available": return n > 0
    case "disabled", "waiting_verification", "unavailable", "exhausted", "busy", "safety_insufficient": return n == 0
    default: return false
    }
}

// DecodeResourceDecision rejects duplicate/unknown/missing/null fields,
// numeric IDs or amounts, unsigned overflow, and trailing JSON.
func DecodeResourceDecision(data []byte) (ResourceDecision, error) {
    var result ResourceDecision
    if len(data) > 4096 { return result, ErrInvalid }
    required := map[string]bool{
        "schema_version":true, "provider":true, "quota_scope_id":true,
        "cycle_id":true, "config_revision":true, "unit":true,
        "status":true, "available_units":true, "expires_at":true,
    }
    dec := json.NewDecoder(bytes.NewReader(data))
    token, err := dec.Token()
    if err != nil || token != json.Delim('{') { return result, ErrInvalid }
    seen := map[string]bool{}
    for dec.More() {
        token, err = dec.Token()
        key, ok := token.(string)
        if err != nil || !ok || !required[key] || seen[key] { return result, ErrInvalid }
        seen[key] = true
        var raw json.RawMessage
        if dec.Decode(&raw) != nil || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) { return result, ErrInvalid }
    }
    if token, err = dec.Token(); err != nil || token != json.Delim('}') || len(seen) != len(required) { return result, ErrInvalid }
    if dec.Decode(new(any)) != io.EOF || json.Unmarshal(data, &result) != nil || !result.valid() { return ResourceDecision{}, ErrInvalid }
    return result, nil
}

type RoutingSettings struct {
    Enabled bool
    AudioEnabled bool
    VideoEnabled bool
    AllowFallback bool
    AudioPriority [2]Provider
    VideoPriority [2]Provider
}

type Route struct {
    Provider Provider
    Reason string
}

// SelectProvider compares each provider in its OWN unit. required contains
// the verified cost of the next safe window for BOTH legs, keyed by provider.
// No user/role/monthly-per-person budget is an input.
func SelectProvider(settings RoutingSettings, media Media, pools []ResourceDecision, required map[Provider]int64, now int64) (Route, error) {
    if now < 1 || (media != Audio && media != Video) { return Route{}, ErrInvalid }
    priority := settings.AudioPriority
    if media == Video { priority = settings.VideoPriority }
    if !priority[0].valid() || !priority[1].valid() || priority[0] == priority[1] { return Route{}, ErrInvalid }
    byProvider := map[Provider]ResourceDecision{}
    if len(pools) > 2 || len(required) != 2 { return Route{}, ErrInvalid }
    for _, p := range []Provider{Wechat, Shengwang} {
        if required[p] <= 0 { return Route{}, ErrInvalid }
    }
    for _, pool := range pools {
        if !pool.valid() { return Route{}, ErrInvalid }
        if _, exists := byProvider[pool.Provider]; exists { return Route{}, ErrInvalid }
        byProvider[pool.Provider] = pool
    }
    if !settings.Enabled || (media == Audio && !settings.AudioEnabled) || (media == Video && !settings.VideoEnabled) {
        return Route{Reason:"disabled"}, nil
    }
    // Missing/stale/disabled providers are service conditions, not exhaustion.
    reasons := []string{}
    for index, provider := range priority {
        if index == 1 && !settings.AllowFallback { break }
        pool, exists := byProvider[provider]
        if !exists || pool.ExpiresAt <= now { reasons = append(reasons, "unavailable"); continue }
        if pool.Status == "available" {
            n, _ := pool.units()
            if n >= required[provider] { return Route{Provider:provider, Reason:"available"}, nil }
            reasons = append(reasons, "safety_insufficient")
        } else { reasons = append(reasons, pool.Status) }
    }
    reason := "exhausted"
    for _, candidate := range reasons {
        if candidate == "disabled" || candidate == "waiting_verification" || candidate == "unavailable" {
            return Route{Reason:"unavailable"}, nil
        }
        if candidate == "busy" { reason = "busy" }
        if candidate == "safety_insufficient" && reason != "busy" { reason = "safety_insufficient" }
    }
    return Route{Reason:reason}, nil
}
