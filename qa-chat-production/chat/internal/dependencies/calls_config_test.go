package dependencies

import "testing"

func TestCallsConfigRequiresExplicitMediaPolicy(t *testing.T) {
	base := CallsConfig{Enabled: true, PHPEndpoint: "https://example.com/chat-calls.php", KeyID: "chat-internal-v1", KeyEnv: "CHAT_INTERNAL_KEY"}
	if base.Validate() == nil {
		t.Fatal("enabled calls accepted without an explicit media policy")
	}
	base.AudioEnabled = true
	if err := base.Validate(); err != nil {
		t.Fatalf("audio-only calls rejected: %v", err)
	}
	base.Enabled = false
	if base.Validate() == nil {
		t.Fatal("disabled calls retained an enabled audio surface")
	}
}
