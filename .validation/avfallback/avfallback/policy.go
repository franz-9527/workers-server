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
