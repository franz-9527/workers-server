package avfallback

import "testing"

func TestProviderFailureFallbackWithoutClientTranscript(t *testing.T) {
	for _, code := range []string{"9301002", "9301003"} {
		if !Eligible("audio", code, "") {
			t.Fatalf("%s must allow original-audio review without client transcript", code)
		}
	}
	if Eligible("video", "9301002", "") || Eligible("audio", "9301008", "") {
		t.Fatal("fallback scope widened beyond audio 9301002/9301003")
	}
	if Eligible("audio", "9301002", " bad ") {
		t.Fatal("invalid auxiliary transcript accepted")
	}
}

func TestProviderFallbackReviewTransitions(t *testing.T) {
	phase, clear := ReviewTransition("audio", ReviewCode, "", "", "")
	if phase != "raw_ready" || clear {
		t.Fatalf("first audio-only review = %s clear=%v", phase, clear)
	}
	phase, clear = ReviewTransition("audio", ReviewCode, "辅助文字", "", "")
	if phase != "raw_ready" || clear {
		t.Fatalf("first auxiliary review = %s clear=%v", phase, clear)
	}
	phase, clear = ReviewTransition("audio", "", "", "", "review")
	if phase != "ready" || clear {
		t.Fatalf("second audio-only review = %s clear=%v", phase, clear)
	}
	phase, clear = ReviewTransition("audio", "", "辅助文字", "", "review")
	if phase != "ready" || !clear {
		t.Fatalf("second auxiliary review = %s clear=%v", phase, clear)
	}
	phase, _ = ReviewTransition("video", ReviewCode, "", "", "")
	if phase != "ready" {
		t.Fatal("video entered audio fallback state")
	}
}

func TestProviderFallbackPublishStillRequiresReviewAndRawDecision(t *testing.T) {
	if !PublishCandidate("audio", "", "", "") {
		t.Fatal("audio-only fallback not recognized at publish")
	}
	if PublishCandidate("video", "", "", "") || PublishCandidate("audio", "trusted", "", "") {
		t.Fatal("normal media misclassified as provider fallback")
	}
	for _, tc := range []struct {
		approved bool
		raw      string
		want     bool
	}{{false, "pass", false}, {true, "", false}, {true, "risky", false}, {true, "pass", true}, {true, "review", true}} {
		if got := PublishAllowed(true, tc.approved, tc.raw); got != tc.want {
			t.Fatalf("approved=%v raw=%s got=%v want=%v", tc.approved, tc.raw, got, tc.want)
		}
	}
	if !ClearAfterRawPass("audio", "", "", "approved") {
		t.Fatal("approved proofless fallback not cleared after raw pass")
	}
	if ClearAfterRawPass("audio", "", "", "pending") {
		t.Fatal("unapproved fallback cleared after raw pass")
	}
}
