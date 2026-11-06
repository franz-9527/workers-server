package message

import "errors"

var ErrMedia = errors.New("media unavailable")

const (
	MaxVoiceBytes int64 = 6 << 20
	MaxVideoBytes int64 = 32 << 20
)
