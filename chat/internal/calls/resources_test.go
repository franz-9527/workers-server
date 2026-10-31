package calls

import (
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func pool(provider Provider, status string) ResourceDecision {
	unit := "wechat_equivalent"
	if provider == Shengwang {
		unit = "shengwang_standard"
	}
	amount := "0"
	if status == "available" {
		amount = "10000"
	}
	return ResourceDecision{1, provider, "synthetic:test", "synthetic-cycle",
		"18446744073709551615", unit, status, amount, 200}
}

func routing() RoutingSettings {
	return RoutingSettings{true, true, true, true,
		[2]Provider{Wechat, Shengwang}, [2]Provider{Shengwang, Wechat}}
}

func TestResourceDecode(t *testing.T) {
	data, _ := json.Marshal(pool(Wechat, "available"))
	got, err := DecodeResourceDecision(data)
	if err != nil || got.ConfigRevision != "18446744073709551615" {
		t.Fatalf("exact uint64: %+v %v", got, err)
	}
	valid := string(data)
	for name, raw := range map[string]string{
		"duplicate":         strings.Replace(valid, `"schema_version":1`, `"schema_version":1,"schema_version":1`, 1),
		"escaped_duplicate": strings.Replace(valid, `"schema_version":1`, `"schema_version":1,"schema_\u0076ersion":1`, 1),
		"unknown":           strings.Replace(valid, `"schema_version":1`, `"schema_version":1,"user_quota":10`, 1),
		"null":              strings.Replace(valid, `"schema_version":1`, `"schema_version":null`, 1),
		"missing":           strings.Replace(valid, `"schema_version":1,`, "", 1),
		"number_amount":     strings.Replace(valid, `"10000"`, "10000", 1),
		"float_amount":      strings.Replace(valid, `"10000"`, `"1.0"`, 1),
		"negative":          strings.Replace(valid, `"10000"`, `"-1"`, 1),
		"overflow":          strings.Replace(valid, `"10000"`, `"9223372036854775808"`, 1),
		"leading_zero":      strings.Replace(valid, `"10000"`, `"01"`, 1),
		"number_revision":   strings.Replace(valid, `"18446744073709551615"`, "18446744073709551615", 1),
		"revision_overflow": strings.Replace(valid, "18446744073709551615", "18446744073709551616", 1),
		"wrong_unit":        strings.Replace(valid, "wechat_equivalent", "shengwang_standard", 1),
		"empty_available":   strings.Replace(valid, `"10000"`, `"0"`, 1),
		"nonzero_busy":      strings.Replace(valid, `"available"`, `"busy"`, 1),
		"unknown_status":    strings.Replace(valid, `"available"`, `"ok"`, 1),
		"expiry_string":     strings.Replace(valid, `"expires_at":200`, `"expires_at":"200"`, 1),
		"extra_json":        valid + " {}",
		"oversize":          strings.Repeat(" ", 4097) + valid,
		"array":             "[]", "empty": "", "truncated": valid[:len(valid)-1],
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := DecodeResourceDecision([]byte(raw)); err == nil {
				t.Fatalf("accepted %s", raw)
			}
		})
	}
	for _, status := range []string{"busy", "exhausted", "disabled", "waiting_verification", "unavailable", "safety_insufficient"} {
		data, _ := json.Marshal(pool(Wechat, status))
		if _, err := DecodeResourceDecision(data); err != nil {
			t.Fatalf("valid status %s: %v", status, err)
		}
	}
	max := pool(Wechat, "available")
	max.AvailableUnits = "9223372036854775807"
	data, _ = json.Marshal(max)
	if _, err := DecodeResourceDecision(data); err != nil {
		t.Fatal(err)
	}
}

func TestRouting(t *testing.T) {
	required := map[Provider]int64{Wechat: 100, Shengwang: 143}
	cases := []struct {
		name          string
		media         Media
		first, second string
		want          Provider
		reason        string
	}{
		{"audio", Audio, "available", "available", Wechat, "available"},
		{"video", Video, "available", "available", Shengwang, "available"},
		{"wechat_exhausted", Audio, "exhausted", "available", Shengwang, "available"},
		{"shengwang_exhausted", Video, "available", "exhausted", Wechat, "available"},
		{"both_exhausted", Audio, "exhausted", "exhausted", "", "exhausted"},
		{"reserved", Audio, "busy", "exhausted", "", "busy"},
		{"safety", Video, "exhausted", "safety_insufficient", "", "safety_insufficient"},
		{"unknown", Audio, "waiting_verification", "exhausted", "", "unavailable"},
		{"fault", Video, "unavailable", "exhausted", "", "unavailable"},
		{"closed", Audio, "disabled", "exhausted", "", "unavailable"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, err := SelectProvider(routing(), tc.media, []ResourceDecision{pool(Wechat, tc.first), pool(Shengwang, tc.second)}, required, 100)
			if err != nil || got.Provider != tc.want || got.Reason != tc.reason {
				t.Fatalf("%+v %v", got, err)
			}
		})
	}
	sw := pool(Shengwang, "available")
	wx := pool(Wechat, "available")
	wx.ExpiresAt = 100
	got, err := SelectProvider(routing(), Audio, []ResourceDecision{wx, sw}, required, 100)
	if err != nil || got.Provider != Shengwang {
		t.Fatalf("stale priority: %+v %v", got, err)
	}
	wx = pool(Wechat, "available")
	wx.AvailableUnits = "99"
	sw.AvailableUnits = "142"
	got, err = SelectProvider(routing(), Audio, []ResourceDecision{wx, sw}, required, 100)
	if err != nil || got.Reason != "safety_insufficient" {
		t.Fatalf("minimum window: %+v %v", got, err)
	}
	wx.AvailableUnits = "100"
	got, err = SelectProvider(routing(), Audio, []ResourceDecision{wx, sw}, required, 100)
	if err != nil || got.Provider != Wechat {
		t.Fatalf("exact window: %+v %v", got, err)
	}
	settings := routing()
	settings.Enabled = false
	got, err = SelectProvider(settings, Audio, []ResourceDecision{wx, sw}, required, 100)
	if err != nil || got.Reason != "disabled" || got.Provider != "" {
		t.Fatal("global stop bypass")
	}
	settings = routing()
	settings.VideoEnabled = false
	got, err = SelectProvider(settings, Video, []ResourceDecision{wx, sw}, required, 100)
	if err != nil || got.Reason != "disabled" {
		t.Fatal("video stop")
	}
	settings = routing()
	settings.AllowFallback = false
	got, err = SelectProvider(settings, Audio, []ResourceDecision{pool(Wechat, "exhausted"), pool(Shengwang, "available")}, required, 100)
	if err != nil || got.Reason != "exhausted" {
		t.Fatal("fallback disabled")
	}
	settings = routing()
	settings.AudioPriority = [2]Provider{Shengwang, Wechat}
	got, err = SelectProvider(settings, Audio, []ResourceDecision{pool(Wechat, "available"), pool(Shengwang, "available")}, required, 100)
	if err != nil || got.Provider != Shengwang {
		t.Fatal("configured audio priority")
	}
	for _, input := range [][]ResourceDecision{{pool(Wechat, "available"), pool(Wechat, "available")}, {pool("bad", "available")}} {
		if _, err := SelectProvider(routing(), Audio, input, required, 100); err == nil {
			t.Fatal("invalid pool accepted")
		}
	}
	if _, err := SelectProvider(routing(), "unknown", nil, required, 100); err == nil {
		t.Fatal("media")
	}
	if _, err := SelectProvider(routing(), Audio, nil, map[Provider]int64{Wechat: 100}, 100); err == nil {
		t.Fatal("missing estimate")
	}
}

// This executes the exact PHP policy in a separate process. Normal Go-only
// environments may skip; the isolated acceptance workflow requires PHP.
func TestPHPResourceContract(t *testing.T) {
	php := os.Getenv("CHAT_CALLS_TEST_PHP")
	if php == "" {
		var err error
		php, err = exec.LookPath("php")
		if err != nil {
			t.Skip("PHP runtime unavailable")
		}
	}
	script := filepath.Join("..", "..", "..", "server", "tests", "chat_call_resource_contract.php")
	output, err := exec.Command(php, script, "--emit-decisions").Output()
	if err != nil {
		t.Fatalf("PHP contract: %v", err)
	}
	var envelope struct {
		Now       int64                      `json:"now"`
		Decisions map[string]json.RawMessage `json:"decisions"`
	}
	if json.Unmarshal(output, &envelope) != nil || len(envelope.Decisions) != 8 {
		t.Fatalf("PHP envelope: %s", output)
	}
	for name, raw := range envelope.Decisions {
		got, err := DecodeResourceDecision(raw)
		if err != nil {
			t.Fatalf("PHP %s rejected: %s %v", name, raw, err)
		}
		if name != "shengwang" && got.Status != name {
			t.Fatalf("PHP status: %s", name)
		}
		if got.ExpiresAt != envelope.Now+50 {
			t.Fatalf("PHP freshness: %s", raw)
		}
	}
	wx, _ := DecodeResourceDecision(envelope.Decisions["exhausted"])
	sw, _ := DecodeResourceDecision(envelope.Decisions["shengwang"])
	got, err := SelectProvider(routing(), Audio, []ResourceDecision{wx, sw}, map[Provider]int64{Wechat: 6000, Shengwang: 17160}, envelope.Now)
	if err != nil || got.Provider != Shengwang {
		t.Fatalf("cross-language fallback: %+v %v", got, err)
	}
}
