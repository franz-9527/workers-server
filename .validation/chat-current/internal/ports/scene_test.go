package ports

import "testing"

func TestCanonicalConversationScopes(t *testing.T) {
	for key, expected := range map[string]Scope{
		"order:7:assignment:9":                         {OrderScene, "assignment", "7", "9"},
		"order:7:collaboration":                        {OrderScene, "collaboration", "7", ""},
		"sandbox:99999999999999999":                    {OrderScene, "sandbox", "99999999999999999", ""},
		"direct:9:10":                                  {DirectScene, "pair", "9", "10"},
		"direct:9007199254740993:18446744073709551615": {DirectScene, "pair", "9007199254740993", "18446744073709551615"},
		"support:platform_feedback:7":                  {SupportScene, "platform_feedback", "7", ""},
	} {
		if got, ok := ParseScope(key); !ok || got != expected {
			t.Errorf("%q: got %+v %t", key, got, ok)
		}
	}
	for _, key := range []string{"direct:10:9", "direct:7:7", "direct:01:2", "direct:0:9", "direct:1:18446744073709551616", "order:7:assignment:9:", "order:7:collaboration:8", "support:admin:7", "support:platform_feedback:+1", "sandbox:100000000000000000", "group:1", ""} {
		if _, ok := ParseScope(key); ok {
			t.Errorf("unexpected canonical scope %q", key)
		}
	}
}
