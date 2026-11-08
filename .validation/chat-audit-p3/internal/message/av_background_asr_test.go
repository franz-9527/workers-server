package message

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type asrChangePublisher struct{ changed, created int }

func (p *asrChangePublisher) Committed(ports.CommittedMessage) { p.created++ }
func (p *asrChangePublisher) Changed(ports.CommittedMessage)   { p.changed++ }

func TestMySQLBackgroundASRUpdatesExistingConversation(t *testing.T) {
	f, s, a, storage, inspector, q := avFixture(t, "audio")
	q.ClientTranscript = "客户端临时文字"
	q = avSubmit(t, a, storage, q)
	avSteps(t, a, 4)
	if inspector.asrCalls.Load() != 0 || f.text("SELECT transcript FROM chat_av_uploads") != q.ClientTranscript {
		t.Fatal("voice must publish with the client fallback before server ASR")
	}
	h, e := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
	if e != nil {
		t.Fatal(e)
	}
	h.ConfigureMedia(false, true, false)
	page := readPage(t, h, HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	v, e := a.job(context.Background(), ports.ID(f.text("SELECT CAST(intent_id AS CHAR) FROM chat_av_jobs")))
	if e != nil {
		t.Fatal(e)
	}
	publisher := &asrChangePublisher{}
	s.publisher = publisher
	if e = a.ProcessBackgroundASROne(context.Background()); e != nil {
		t.Fatal(e)
	}
	if inspector.asrCalls.Load() != 1 || publisher.changed != 1 || publisher.created != 0 {
		t.Fatal("ASR must notify one change, without a new message")
	}
	changes := readPage(t, h, HistoryRequest{ConversationID: q.ConversationID, Cursor: page.ChangeCursor}, "changes")
	if len(changes.Changes) != 1 || changes.Changes[0].Type != "message.transcript" || changes.Changes[0].Message.StateVersion != "2" || changes.Changes[0].Message.AV == nil || changes.Changes[0].Message.AV.Transcript == nil || *changes.Changes[0].Message.AV.Transcript != "正在施工" {
		t.Fatalf("open conversation did not receive server transcript: %+v", changes.Changes)
	}
	if f.text("SELECT last_seq FROM chat_conversations") != "1" || f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("transcription created a new unread message")
	}
	if e = a.ProcessBackgroundASROne(context.Background()); e != nil || inspector.asrCalls.Load() != 1 {
		t.Fatal("terminal provider request dispatched again", e)
	}
	result := ports.Transcription{Text: "迟到重放", RequestID: "replay", AuthorityProof: strings.Repeat("a", 64)}
	if e = a.finishBackgroundASR(context.Background(), v, "completed", `{}`, result, true); e != ports.ErrBusy || publisher.changed != 1 || f.text("SELECT transcript FROM chat_av_uploads") != "正在施工" {
		t.Fatal("completion replay changed transcript or notified twice", e)
	}
}

func TestMySQLBackgroundASRDoesNotReviveRecalledOrExpiredMessages(t *testing.T) {
	for _, state := range []string{"recalled", "expired"} {
		t.Run(state, func(t *testing.T) {
			f, s, a, storage, _, q := avFixture(t, "audio")
			q.ClientTranscript = "原备用文字"
			q = avSubmit(t, a, storage, q)
			avSteps(t, a, 4)
			if state == "recalled" {
				if _, e := s.Recall(context.Background(), principal("10202"), q.ConversationID, ports.ID(f.text("SELECT CAST(id AS CHAR) FROM chat_messages"))); e != nil {
					t.Fatal(e)
				}
			} else {
				f.exec("UPDATE chat_messages SET retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
			}
			before := f.text("SELECT last_change_seq FROM chat_conversations")
			publisher := &asrChangePublisher{}
			s.publisher = publisher
			if e := a.ProcessBackgroundASROne(context.Background()); e != nil {
				t.Fatal(e)
			}
			if f.text("SELECT transcript FROM chat_av_uploads") != q.ClientTranscript || publisher.changed != 0 || f.text("SELECT last_change_seq FROM chat_conversations") != before {
				t.Fatal("late result modified an unavailable message")
			}
		})
	}
}

func TestMySQLInterruptedBackgroundASRIsUnknownWithoutRetry(t *testing.T) {
	f, _, a, storage, inspector, q := avFixture(t, "audio")
	avSubmit(t, a, storage, q)
	avSteps(t, a, 4)
	f.exec(`UPDATE chat_av_asr_requests SET state='started',created_at=UTC_TIMESTAMP(6)-INTERVAL 6 MINUTE,metadata_json='{"service":"tencent_cloud_asr"}'`)
	if e := a.sweepExpired(context.Background()); e != nil {
		t.Fatal(e)
	}
	if e := a.ProcessBackgroundASROne(context.Background()); e != nil {
		t.Fatal(e)
	}
	if f.text("SELECT state FROM chat_av_asr_requests") != "unknown" || inspector.asrCalls.Load() != 0 {
		t.Fatal("interrupted request must retain uncertainty without another provider call")
	}
}

func TestMySQLClientFallbackNeverQueuesTencentASR(t *testing.T) {
	for _, text := range []string{"", "备用转写"} {
		t.Run(text, func(t *testing.T) {
			f, s, a, storage, inspector, q := avFixture(t, "audio")
			q.ClientASRProvider = "wechat_si"
			q.ClientTranscript = text
			q = avSubmit(t, a, storage, q)
			avSteps(t, a, 4)
			if result, e := s.Query(context.Background(), principal("10202"), q.ConversationID, q.ClientMessageID); e != nil || result.State != "accepted" {
				t.Fatal(result, e)
			}
			if e := a.ProcessBackgroundASROne(context.Background()); e != nil {
				t.Fatal(e)
			}
			if f.text("SELECT COUNT(*) FROM chat_av_asr_requests") != "0" || inspector.asrCalls.Load() != 0 {
				t.Fatal("client fallback dispatched Tencent too")
			}
			if f.text("SELECT JSON_UNQUOTE(JSON_EXTRACT(asr_metadata,'$.server_asr')) FROM chat_av_uploads") != "skipped_client_fallback" {
				t.Fatal("fallback provenance missing")
			}
		})
	}
}
