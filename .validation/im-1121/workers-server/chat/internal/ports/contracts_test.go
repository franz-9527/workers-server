package ports

import (
	"encoding/json"
	"testing"
)

func TestBIGINTStringContract(t *testing.T) {
	for _, v := range []ID{"0", "9", "10", "9007199254740993", "18446744073709551615"} {
		b, e := json.Marshal(v)
		if e != nil || string(b) != "\""+string(v)+"\"" {
			t.Fatal("lost bigint precision")
		}
		var got ID
		if json.Unmarshal(b, &got) != nil || got != v {
			t.Fatal("roundtrip failed")
		}
	}
	for _, v := range []string{`1`, `null`, `""`, `"01"`, `"-1"`, `"1e3"`, `"18446744073709551616"`} {
		var got ID
		if json.Unmarshal([]byte(v), &got) == nil {
			t.Fatalf("invalid id accepted: %s", v)
		}
	}
}

func TestSandboxMiniAdminWireIdentity(t *testing.T) {
	user, ok := SandboxMiniAdminUser("mini-admin:42", "6000000000000000042")
	if !ok || user != "42" {
		t.Fatal("valid mini administrator rejected")
	}
	for _, item := range []struct {
		key  string
		wire ID
	}{
		{"admin:42", "6000000000000000042"},
		{"mini-admin:42", "7000000000000000042"},
		{"mini-admin:42", "6000000000000000043"},
		{"mini-admin:042", "6000000000000000042"},
		{"mini-admin:0", "6000000000000000000"},
		{"mini-admin:1000000000000000000", "7000000000000000000"},
	} {
		if _, ok := SandboxMiniAdminUser(item.key, item.wire); ok {
			t.Errorf("accepted mismatched sandbox identity %s / %s", item.key, item.wire)
		}
	}
}
