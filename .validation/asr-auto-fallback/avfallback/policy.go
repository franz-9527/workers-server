package avfallback

import (
	"strings"
	"unicode/utf8"
)

const ReviewCode = "CHAT_AV_ASR_PROVIDER_FALLBACK_REVIEW"
const MaxTranscriptBytes = 4000
const MaxTranscriptRunes = 1000

func ValidOptionalTranscript(kind, value string) bool {
	if value == "" {
		return true
	}
	return kind == "audio" &&
		utf8.ValidString(value) &&
		!strings.ContainsRune(value, 0) &&
		value == strings.TrimSpace(value) &&
		len(value) <= MaxTranscriptBytes &&
		utf8.RuneCountInString(value) <= MaxTranscriptRunes
}

func Eligible(kind, providerCode, value string) bool {
	if kind != "audio" || !ValidOptionalTranscript(kind, value) {
		return false
	}
	return providerCode == "9301002" || providerCode == "9301003"
}

func ReviewTransition(kind, errorCode, clientTranscript, sourceProof, rawDecision string) (phase string, clearTranscript bool) {
	if kind == "audio" && errorCode == ReviewCode && sourceProof == "" && rawDecision == "" {
		return "raw_ready", false
	}
	if kind == "audio" && sourceProof == "" && rawDecision != "" {
		return "ready", clientTranscript != ""
	}
	return "ready", false
}

func PublishCandidate(kind, transcript, sourceProof, textProof string) bool {
	return kind == "audio" && strings.TrimSpace(transcript) == "" && sourceProof == "" && textProof == ""
}

func PublishAllowed(candidate, _ bool, rawDecision string) bool {
	// Provider fallback is allowed only after the original audio receives an
	// explicit WeChat raw-media pass. Human approval is not part of this path.
	return !candidate || rawDecision == "pass"
}

func RawAuthorityCompatible(sourceProof, providerProof string) bool {
	return providerProof != "" && (sourceProof == "" || sourceProof == providerProof)
}

func ClearAfterRawPass(kind, sourceProof, textProof, _ string) bool {
	return kind == "audio" && sourceProof == "" && textProof == ""
}
