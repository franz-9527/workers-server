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
