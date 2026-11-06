package avfallback

import (
	"strings"
	"unicode/utf8"
)

const ReviewCode = "CHAT_AV_ASR_CLIENT_TRANSCRIPT_REVIEW"
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
	if value == "" || !ValidOptionalTranscript(kind, value) {
		return false
	}
	return providerCode == "9301002" || providerCode == "9301003"
}

func ReviewTransition(errorCode, clientTranscript, sourceProof, rawDecision string) (phase string, clearTranscript bool) {
	if errorCode == ReviewCode && clientTranscript != "" && sourceProof == "" && rawDecision == "" {
		return "raw_ready", false
	}
	if clientTranscript != "" && sourceProof == "" {
		return "ready", true
	}
	return "ready", false
}

func PublishCandidate(kind, clientTranscript, transcript, sourceProof, textProof string) bool {
	return kind == "audio" && clientTranscript != "" && strings.TrimSpace(transcript) == "" && sourceProof == "" && textProof == ""
}

func PublishAllowed(candidate, approved bool, rawDecision string) bool {
	return !candidate || approved && (rawDecision == "pass" || rawDecision == "review")
}

func ClearAfterRawPass(kind, clientTranscript, sourceProof, textProof, reviewState string) bool {
	return kind == "audio" && clientTranscript != "" && sourceProof == "" && textProof == "" && reviewState == "approved"
}
