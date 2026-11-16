package calls

import (
	"context"
	"testing"

	"github.com/franz-9527/workers/chat/internal/ports"
)

func TestAudioOnlyServiceRejectsVideoBeforeBrokerOrDatabase(t *testing.T) {
	s := &Service{audio: true, video: false}
	q := Request{ConversationID: "1", PeerID: "2", Media: Video, Operation: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", Instance: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}
	_, err := s.Create(context.Background(), ports.Principal{ActorID: "3"}, q)
	if err != ErrUnavailable {
		t.Fatalf("video crossed the audio-only release boundary: %v", err)
	}
}
