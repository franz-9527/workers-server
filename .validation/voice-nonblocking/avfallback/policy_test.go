package avfallback

import "testing"

func TestRecognitionFallbackDoesNotRequireRawReceipt(t *testing.T) {
	for _, transcript := range []string{"", "同声传译兜底文字"} {
		if !PublishCandidate("audio", transcript, "", "") {
			t.Fatalf("proofless audio transcript=%q was not recognized as fallback", transcript)
		}
		if !PublishAllowed(true, false, "") {
			t.Fatal("voice fallback without raw receipt was blocked")
		}
	}
}

func TestExplicitRawRiskIsNeverConvertedToPass(t *testing.T) {
	if PublishAllowed(true, false, "review") || PublishAllowed(true, false, "risky") {
		t.Fatal("explicit raw decision was bypassed")
	}
	if !PublishAllowed(true, false, "pass") {
		t.Fatal("explicit raw pass was rejected")
	}
}

func TestFallbackScopeRemainsAudioOnly(t *testing.T) {
	if PublishCandidate("video", "", "", "") {
		t.Fatal("video was incorrectly treated as nonblocking voice fallback")
	}
	if PublishCandidate("audio", "", "trusted-proof", "") {
		t.Fatal("trusted server-ASR path was misclassified as auxiliary fallback")
	}
}
