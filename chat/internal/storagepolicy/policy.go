// Package storagepolicy defines PHP-owned storage settings consumed by Go.
package storagepolicy

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"time"
)

var ErrPolicy = errors.New("CHAT_STORAGE_POLICY_UNAVAILABLE")

type Rule struct {
	Mode string `json:"mode"`
	Days int    `json:"days"`
}
type Retention struct {
	Text  Rule `json:"text"`
	Image Rule `json:"image"`
	Audio Rule `json:"audio"`
	Video Rule `json:"video"`
}
type Tiering struct {
	Enabled       bool  `json:"enabled"`
	Images        bool  `json:"images"`
	Audio         bool  `json:"audio"`
	Videos        bool  `json:"videos"`
	AgeDays       int   `json:"age_days"`
	IdleDays      int   `json:"idle_days"`
	MinBytes      int64 `json:"min_bytes"`
	RemainingDays int   `json:"remaining_days"`
	Batch         int   `json:"batch"`
}
type Quota struct {
	VideoCount int   `json:"video_count"`
	VideoBytes int64 `json:"video_bytes"`
}
type Policy struct {
	Schema    int       `json:"schema"`
	Retention Retention `json:"retention"`
	Tiering   Tiering   `json:"tiering"`
	Quota     Quota     `json:"quota"`
}
type Snapshot struct {
	Revision     uint64 `json:"revision,string"`
	Policy       Policy `json:"policy"`
	ApplyThrough uint64 `json:"apply_through_id,string"`
}

func Defaults() Policy {
	permanent := Rule{Mode: "permanent"}
	return Policy{Schema: 1, Retention: Retention{permanent, permanent, permanent, Rule{"days", 60}},
		Tiering: Tiering{Enabled: false, Images: true, Audio: true, AgeDays: 60, IdleDays: 30, MinBytes: 65536, RemainingDays: 32, Batch: 20},
		Quota:   Quota{VideoCount: 20, VideoBytes: 100 * 1024 * 1024}}
}
func (r Rule) Valid() bool {
	return r.Mode == "permanent" && r.Days == 0 || r.Mode == "days" && r.Days >= 1 && r.Days <= 3650
}
func (p Policy) Valid() bool {
	t := p.Tiering
	return p.Schema == 1 && p.Retention.Text.Valid() && p.Retention.Image.Valid() && p.Retention.Audio.Valid() && p.Retention.Video.Valid() &&
		(!t.Enabled || t.Images || t.Audio || t.Videos) && t.AgeDays >= 30 && t.AgeDays <= 3650 && t.IdleDays >= 1 && t.IdleDays <= 3650 &&
		t.MinBytes >= 65536 && t.MinBytes <= 1024*1024*1024 && t.RemainingDays >= 32 && t.RemainingDays <= 3650 && t.Batch >= 1 && t.Batch <= 50 &&
		p.Quota.VideoCount >= 1 && p.Quota.VideoCount <= 200 && p.Quota.VideoBytes >= 20*1024*1024 && p.Quota.VideoBytes <= 2*1024*1024*1024
}
func Decode(raw []byte) (Policy, error) {
	var p Policy
	if len(raw) == 0 || len(raw) > 8192 || !uniqueJSON(raw) {
		return p, ErrPolicy
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	if d.Decode(&p) != nil || d.Decode(new(any)) != io.EOF || !p.Valid() {
		return Policy{}, ErrPolicy
	}
	// Reject JSON null and duplicate keys, including case aliases: re-encoding a
	// strict, exact-shape object must have the same normalized JSON tree.
	var seen any
	if json.Unmarshal(raw, &seen) != nil {
		return Policy{}, ErrPolicy
	}
	if !exactShape(seen, p) {
		return Policy{}, ErrPolicy
	}
	return p, nil
}
func exactShape(raw any, p Policy) bool {
	canonical, _ := json.Marshal(p)
	var want any
	_ = json.Unmarshal(canonical, &want)
	// Unknown fields/case aliases/missing fields (even zero-valued ones) fail.
	a, _ := json.Marshal(raw)
	b, _ := json.Marshal(want)
	return bytes.Equal(a, b)
}
func uniqueJSON(raw []byte) bool {
	d := json.NewDecoder(bytes.NewReader(raw))
	var visit func() bool
	visit = func() bool {
		token, e := d.Token()
		if e != nil {
			return false
		}
		delim, ok := token.(json.Delim)
		if !ok {
			return true
		}
		if delim == '{' {
			keys := map[string]bool{}
			for d.More() {
				v, e := d.Token()
				k, ok := v.(string)
				if e != nil || !ok || keys[k] {
					return false
				}
				keys[k] = true
				if !visit() {
					return false
				}
			}
			end, e := d.Token()
			return e == nil && end == json.Delim('}')
		}
		if delim == '[' {
			for d.More() {
				if !visit() {
					return false
				}
			}
			end, e := d.Token()
			return e == nil && end == json.Delim(']')
		}
		return false
	}
	return visit()
}
func (p Policy) Rule(kind string) (Rule, error) {
	switch kind {
	case "text":
		return p.Retention.Text, nil
	case "image", "image_album":
		return p.Retention.Image, nil
	case "audio":
		return p.Retention.Audio, nil
	case "video":
		return p.Retention.Video, nil
	}
	return Rule{}, ErrPolicy
}
func (r Rule) Deadline(created time.Time) *time.Time {
	if r.Mode == "permanent" {
		return nil
	}
	v := created.UTC().Add(time.Duration(r.Days) * 24 * time.Hour)
	return &v
}
func Load(ctx context.Context, tx *sql.Tx) (Snapshot, error) {
	var s Snapshot
	var raw []byte
	if tx.QueryRowContext(ctx, "SELECT revision,policy_json,apply_through_id FROM chat_storage_policy WHERE id=1 LOCK IN SHARE MODE").Scan(&s.Revision, &raw, &s.ApplyThrough) != nil || s.Revision == 0 || !uniqueJSON(raw) {
		return Snapshot{}, ErrPolicy
	}
	p, e := Decode(raw)
	if e != nil {
		return Snapshot{}, e
	}
	s.Policy = p
	return s, nil
}
func Stamp(ctx context.Context, tx *sql.Tx, id int64, kind string) error {
	s, e := Load(ctx, tx)
	if e != nil {
		return e
	}
	r, e := s.Policy.Rule(kind)
	if e != nil {
		return e
	}
	var days any
	if r.Mode == "days" {
		days = r.Days
	}
	result, e := tx.ExecContext(ctx, "UPDATE chat_messages SET retention_policy_version=?,retention_mode=?,expires_at=IF(? IS NULL,NULL,TIMESTAMPADD(DAY,?,created_at)) WHERE id=?", s.Revision, r.Mode, days, days, id)
	if e != nil {
		return ErrPolicy
	}
	n, e := result.RowsAffected()
	if e != nil || n != 1 {
		return ErrPolicy
	}
	return nil
}
