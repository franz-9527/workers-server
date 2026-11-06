package avfallback

import (
	"strings"
	"testing"
)

func TestFallbackEligibility(t *testing.T) {
	if !ValidOptionalTranscript("audio", "") {
		t.Fatal("empty native transcript must remain valid")
	}
	if !Eligible("audio", "9301002", "需要人工核对") || !Eligible("audio", "9301003", "需要人工核对") {
		t.Fatal("known provider failures must allow reviewed auxiliary transcript")
	}
	for _, tc := range []struct{ kind, code, text string }{
		{"video", "9301002", "不能用于视频"},
		{"audio", "9301010", "不能掩盖额度错误"},
		{"audio", "9301002", " 前后空格 "},
		{"audio", "9301002", "含" + string(rune(0)) + "空字节"},
		{"audio", "9301002", strings.Repeat("中", MaxTranscriptRunes+1)},
	} {
		if Eligible(tc.kind, tc.code, tc.text) {
			t.Fatalf("unexpected eligible: %#v", tc)
		}
	}
}

func TestFallbackReviewTransitions(t *testing.T) {
	phase, clear := ReviewTransition(ReviewCode, "辅助文字", "", "")
	if phase != "raw_ready" || clear {
		t.Fatalf("first review transition: %s %v", phase, clear)
	}
	phase, clear = ReviewTransition("", "辅助文字", "", "review")
	if phase != "ready" || !clear {
		t.Fatalf("second review transition: %s %v", phase, clear)
	}
	phase, clear = ReviewTransition("", "", "trusted-proof", "review")
	if phase != "ready" || clear {
		t.Fatalf("trusted review transition: %s %v", phase, clear)
	}
	if !PublishCandidate("audio", "辅助文字", "", "", "") {
		t.Fatal("auxiliary fallback candidate not recognized")
	}
	if PublishAllowed(true, false, "pass") || PublishAllowed(true, true, "risky") {
		t.Fatal("auxiliary text bypassed manual/raw safety")
	}
	if !PublishAllowed(true, true, "pass") || !PublishAllowed(true, true, "review") || !PublishAllowed(false, false, "") {
		t.Fatal("valid publish state rejected")
	}
	if !ClearAfterRawPass("audio", "辅助文字", "", "", "approved") || ClearAfterRawPass("audio", "辅助文字", "", "", "pending") {
		t.Fatal("raw-pass cleanup state")
	}
}
