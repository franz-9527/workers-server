package avfallback

import "testing"

func TestProviderFailureFallbackWithoutClientTranscript(t *testing.T) {
	for _, code := range []string{"9301002", "9301003"} {
		if !Eligible("audio", code, "") {
			t.Fatalf("%s must allow original-audio fallback without client transcript", code)
		}
	}
	if Eligible("video", "9301002", "") || Eligible("audio", "9301008", "") {
		t.Fatal("fallback scope widened beyond audio 9301002/9301003")
	}
	if Eligible("audio", "9301002", " bad ") {
		t.Fatal("invalid auxiliary transcript accepted")
	}
}

func TestLegacyProviderFallbackReviewTransitions(t *testing.T) {
	phase, clear := ReviewTransition("audio", ReviewCode, "", "", "")
	if phase != "raw_ready" || clear {
		t.Fatalf("legacy first audio review = %s clear=%v", phase, clear)
	}
	phase, clear = ReviewTransition("audio", ReviewCode, "辅助文字", "", "")
	if phase != "raw_ready" || clear {
		t.Fatalf("legacy auxiliary review = %s clear=%v", phase, clear)
	}
	phase, clear = ReviewTransition("audio", "", "辅助文字", "", "review")
	if phase != "ready" || !clear {
		t.Fatalf("legacy second auxiliary review = %s clear=%v", phase, clear)
	}
}

func TestOptionalVoiceRecognitionDoesNotGatePublicationOrOverrideExplicitRisk(t *testing.T) {
	if !PublishCandidate("audio", "", "", "") {
		t.Fatal("audio-only fallback not recognized at publish")
	}
	if PublishCandidate("video", "", "", "") || PublishCandidate("audio", "trusted", "trusted-proof", "trusted-proof") {
		t.Fatal("normal media misclassified as provider fallback")
	}
	for _, tc := range []struct {
		approved bool
		raw      string
		want     bool
	}{
		{false, "", true},
		{false, "risky", false},
		{false, "review", false},
		{false, "pass", true},
		{true, "review", false},
		{true, "pass", true},
	} {
		if got := PublishAllowed(true, tc.approved, tc.raw); got != tc.want {
			t.Fatalf("approved=%v raw=%s got=%v want=%v", tc.approved, tc.raw, got, tc.want)
		}
	}
	if !ClearAfterRawPass("audio", "", "", "pending") {
		t.Fatal("proofless fallback must clear after raw pass without admin review")
	}
}

func TestRawAuthorityCompatibleAllowsProoflessFallbackOnlyWithProviderProof(t *testing.T) {
	if !RawAuthorityCompatible("", "current-proof") {
		t.Fatal("proofless provider fallback must accept the current raw-media authority")
	}
	if !RawAuthorityCompatible("same-proof", "same-proof") {
		t.Fatal("normal media must preserve matching authority")
	}
	if RawAuthorityCompatible("old-proof", "new-proof") || RawAuthorityCompatible("", "") {
		t.Fatal("mismatched or missing raw-media authority accepted")
	}
}
