package message

import (
	"context"
	"database/sql"
	"errors"
	"os"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/moderation"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type avMemory struct{ *imageMemory }

func (s avMemory) Keep(ctx context.Context, key string) error {
	_, e := s.Read(ctx, key, MaxVideoBytes)
	return e
}
func (s avMemory) GrantAVPart(ctx context.Context, key string, size int64) (string, string, error) {
	return s.Grant(ctx, key, size)
}
func (s avMemory) PutAV(ctx context.Context, key string, b []byte, mime string) error {
	return s.Put(ctx, key, b, mime)
}
func (s avMemory) StatAV(ctx context.Context, key string) (int64, error) {
	b, e := s.Read(ctx, key, MaxVideoBytes)
	return int64(len(b)), e
}
func (s avMemory) InspectionURL(ctx context.Context, key string) (string, error) {
	return "https://private.fixture.invalid/" + key, nil
}

type avProcessorFunc func(context.Context, []byte, string, string) (AVArtifact, error)

func (f avProcessorFunc) Process(c context.Context, b []byte, k, s string) (AVArtifact, error) {
	return f(c, b, k, s)
}

type avProviderFailure string

func (e avProviderFailure) Error() string       { return string(e) }
func (e avProviderFailure) FailureCode() string { return string(e) }

type avInspectorFixture struct {
	asrCalls, rawCalls atomic.Int32
	unknown            bool
	providerCode       string
}

func (f *avInspectorFixture) MediaAppID() string { return "wx0123456789abcdef" }
func (f *avInspectorFixture) Recognize(context.Context, ports.AudioInspectionRequest) (ports.Transcription, error) {
	f.asrCalls.Add(1)
	if f.unknown {
		return ports.Transcription{Dispatched: true}, ErrCheck
	}
	if f.providerCode != "" {
		return ports.Transcription{ServiceID: "fixture", APIName: "SentenceASR", Engine: "16k_zh", ProviderCode: f.providerCode, ProviderMessage: "fixture provider failure", Known: true, Dispatched: true}, avProviderFailure("CHAT_AV_ASR_WECHAT_" + f.providerCode)
	}
	return ports.Transcription{Text: "正在施工", RequestID: "request-1", AuthorityProof: strings.Repeat("a", 64), ServiceID: "fixture", APIName: "verified_api", Engine: "16k_zh", Known: true, Dispatched: true}, nil
}
func (f *avInspectorFixture) SubmitAudio(context.Context, ports.AudioInspectionRequest) (ports.AudioTicket, error) {
	f.rawCalls.Add(1)
	return ports.AudioTicket{TraceID: "fixture-trace", AuthorityProof: strings.Repeat("a", 64), Known: true, Dispatched: true}, nil
}

type avSafety struct{ safetyFunc }

func (avSafety) MediaProof(context.Context, ports.Principal) (ports.SafetyResult, error) {
	return ports.SafetyResult{Decision: "media_identity", RuleVersion: "av-text-fixture", AuthorityProof: strings.Repeat("a", 64)}, nil
}

func avFixture(t *testing.T, kind string) (*fixtureDB, *Service, *AVService, avMemory, *avInspectorFixture, Request) {
	f, s, q := setupMessage(t, avSafety{safetyFunc(func(ctx context.Context, r ports.TextRequest) (ports.SafetyResult, error) {
		return ports.SafetyResult{Decision: "passed", RuleVersion: "av-text-fixture", AuthorityProof: strings.Repeat("a", 64)}, nil
	})})
	storage := avMemory{&imageMemory{objects: map[string][]byte{}}}
	inspector := &avInspectorFixture{}
	a, e := NewAVService(s, storage, avProcessorFunc(func(ctx context.Context, b []byte, kind, source string) (AVArtifact, error) {
		out := AVArtifact{Main: []byte("canonical:" + string(b)), DurationMS: 1500, HasAudio: true, QRDecision: "clear", MIME: "audio/mpeg"}
		out.Segments = [][]byte{out.Main}
		if kind == "video" {
			out.MIME = "video/mp4"
			out.Poster = []byte("poster")
			out.Frames = []AVFrameArtifact{{Body: []byte("sample"), AtMS: 0}}
			out.Segments = [][]byte{[]byte("full-audio")}
			out.DurationMS = 120000
		}
		return out, nil
	}), inspector, "test", strings.Repeat("c", 64), true, true, 2000)
	if e != nil {
		t.Fatal(e)
	}
	if e = s.AttachAV(a); e != nil {
		t.Fatal(e)
	}
	q.Text = ""
	q.MessageType = kind
	q.Revision = 1
	return f, s, a, storage, inspector, q
}
func avSubmit(t *testing.T, a *AVService, storage avMemory, q Request) Request {
	t.Helper()
	ctx := context.Background()
	source := "voice"
	if q.MessageType == "video" {
		source = "album"
	}
	body := []byte("source")
	u, e := a.Upload(ctx, principal("10202"), q.ConversationID, q.ClientMessageID, source, int64(len(body)), fileMD5(body))
	if e != nil {
		t.Fatal(e)
	}
	q.Items = []MediaItem{{AttachmentID: u.AttachmentID, ObjectVersion: u.ObjectVersion}}
	if _, e = a.Send(ctx, principal("10202"), q); e != nil {
		t.Fatal(e)
	}
	g, e := a.Part(ctx, principal("10202"), q.ConversationID, u.AttachmentID, 1, false)
	if e != nil {
		t.Fatal(e)
	}
	if e = storage.Put(ctx, g.Key, body, "application/octet-stream"); e != nil {
		t.Fatal(e)
	}
	if _, e = a.Part(ctx, principal("10202"), q.ConversationID, u.AttachmentID, 1, true); e != nil {
		t.Fatal(e)
	}
	if e = a.Complete(ctx, principal("10202"), q.ConversationID, u.AttachmentID); e != nil {
		t.Fatal(e)
	}
	return q
}
func avSteps(t *testing.T, a *AVService, n int) {
	t.Helper()
	for i := 0; i < n; i++ {
		if e := a.ProcessOne(context.Background()); e != nil {
			t.Fatal("process", i, e)
		}
	}
}
func TestMySQLAVAudioPublishesWithoutRawCallback(t *testing.T) {
	f, s, a, storage, inspector, q := avFixture(t, "audio")
	ctx := context.Background()
	q = avSubmit(t, a, storage, q)
	noMessages(t, f)
	avSteps(t, a, 5)
	if inspector.asrCalls.Load() != 0 {
		t.Fatal("voice publication waited for ASR")
	}
	if e := a.ProcessBackgroundASROne(ctx); e != nil {
		t.Fatal(e)
	}
	if inspector.asrCalls.Load() != 1 || inspector.rawCalls.Load() != 0 {
		t.Fatal("voice send still depends on raw media gate")
	}
	r, e := s.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || r.State != "accepted" {
		t.Fatal(r, e)
	}
	if f.text("SELECT retention_mode FROM chat_messages") != "permanent" || f.text("SELECT COUNT(*) FROM chat_messages WHERE expires_at IS NOT NULL") != "0" {
		t.Fatal("audio not permanent")
	}
	again, e := s.Send(ctx, principal("10202"), q)
	if e != nil || again.MessageID != r.MessageID || f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("not idempotent", e)
	}
	access, e := a.URL(ctx, principal("10203"), q.ConversationID, r.MessageID, false)
	if e != nil || access.RetentionMode != "permanent" || access.ObjectHash == "" {
		t.Fatal(access, e)
	}
	if e = a.Listened(ctx, principal("10203"), q.ConversationID, r.MessageID); e != nil {
		t.Fatal(e)
	}
	if _, e = s.Recall(ctx, principal("10202"), q.ConversationID, r.MessageID); e != nil {
		t.Fatal(e)
	}
}

func TestMySQLAVSentenceASRFailureUsesWechatSITranscript(t *testing.T) {
	f, s, a, storage, inspector, q := avFixture(t, "audio")
	inspector.providerCode = "9301003"
	q.ClientTranscript = "同声传译兜底文字"
	q = avSubmit(t, a, storage, q)
	avSteps(t, a, 4)
	if e := a.ProcessBackgroundASROne(context.Background()); e != nil {
		t.Fatal(e)
	}
	r, e := s.Query(context.Background(), principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || r.State != "accepted" {
		t.Fatal(r, e)
	}
	if inspector.asrCalls.Load() != 1 || inspector.rawCalls.Load() != 0 {
		t.Fatal("provider failure did not degrade directly")
	}
	if got := f.text("SELECT transcript FROM chat_av_uploads"); got != q.ClientTranscript {
		t.Fatalf("WechatSI fallback transcript lost: %q", got)
	}
	if f.text("SELECT state FROM chat_av_asr_requests") != "failed" {
		t.Fatal("provider failure diagnostic state lost")
	}
	if f.text("SELECT JSON_UNQUOTE(JSON_EXTRACT(asr_metadata,'$.source')) FROM chat_av_uploads") != "client_plugin" {
		t.Fatal("fallback provenance missing")
	}
}
func TestMySQLAVUnknownRecognitionStillPublishesPureVoice(t *testing.T) {
	f, s, a, storage, inspector, q := avFixture(t, "audio")
	inspector.unknown = true
	q = avSubmit(t, a, storage, q)
	avSteps(t, a, 4)
	if e := a.ProcessBackgroundASROne(context.Background()); e != nil {
		t.Fatal(e)
	}
	r, e := s.Query(context.Background(), principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || r.State != "accepted" {
		t.Fatal(r, e)
	}
	if inspector.asrCalls.Load() != 1 || inspector.rawCalls.Load() != 0 {
		t.Fatal("unknown ASR result retried or raw-gated")
	}
	if f.text("SELECT COUNT(*) FROM chat_av_asr_requests WHERE state='unknown'") != "1" {
		t.Fatal("unknown ASR diagnostic not retained")
	}
	if f.text("SELECT COALESCE(transcript,'') FROM chat_av_uploads") != "" {
		t.Fatal("pure voice fabricated transcript")
	}
}
func TestMySQLAVVideoFullPrereviewAndCancellationWin(t *testing.T) {
	f, _, a, storage, _, q := avFixture(t, "video")
	q = avSubmit(t, a, storage, q)
	avSteps(t, a, 4)
	noMessages(t, f)
	if f.text("SELECT phase FROM chat_av_jobs") != "review_pending" {
		t.Fatal("video bypassed review")
	}
	if _, e := a.Status(context.Background(), principal("10202"), q.ConversationID, q.ClientMessageID, true); e != nil {
		t.Fatal(e)
	}
	f.exec("UPDATE chat_av_jobs SET phase='ready',review_state='approved',general_confirmed=1,group_confirmed=1")
	if r, e := a.Publish(context.Background(), ports.ID(f.text("SELECT CAST(intent_id AS CHAR) FROM chat_av_jobs"))); e == nil && r.State == "accepted" {
		t.Fatal("cancel overridden")
	}
	noMessages(t, f)
}
func TestMySQLAVQuotaAndUnconfirmedUploadNeverPublishes(t *testing.T) {
	f, _, a, _, _, q := avFixture(t, "video")
	for i := 0; i < 4; i++ {
		if _, e := a.Upload(context.Background(), principal("10202"), q.ConversationID, client(500+i), "album", 20<<20, strings.Repeat("a", 32)); e != nil {
			t.Fatal(e)
		}
	}
	if _, e := a.Upload(context.Background(), principal("10202"), q.ConversationID, client(600), "album", 20<<20, strings.Repeat("a", 32)); e != ports.ErrBusy {
		t.Fatal("active upload bound", e)
	}
	if _, e := a.Upload(context.Background(), principal("10202"), q.ConversationID, client(601), "album", (20<<20)+1, strings.Repeat("a", 32)); e != ErrAVLimit {
		t.Fatal("byte limit", e)
	}
	avSteps(t, a, 2)
	noMessages(t, f)
}

var _ = time.Second

func TestMySQLAVVideoCloudExpiryKeepsMessagePosterAndFrames(t *testing.T) {
	f, service, a, storage, _, q := avFixture(t, "video")
	ctx := context.Background()
	q = avSubmit(t, a, storage, q)
	avSteps(t, a, 4)
	f.exec("INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(90001,'av-review-fixture','fixture','operations','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
	f.exec(`INSERT INTO chat_admin_sessions(id,admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at) VALUES(90001,90001,REPEAT('a',64),REPEAT('b',64),REPEAT('c',64),'["chat:audit","chat:moderate","chat:media","chat:evidence"]',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())`)
	admin := mediaAdmin()
	admin.Scopes = []string{"chat:audit", "chat:moderate", "chat:media", "chat:evidence"}
	list, e := a.Reviews(ctx, admin, "0")
	if e != nil || len(list) != 1 {
		t.Fatal(list, e)
	}
	v, e := a.Review(ctx, admin, list[0].IntentID, list[0].Version, "claim", "完整音视频预审", false, false)
	if e != nil {
		t.Fatal(e)
	}
	if _, e = a.Review(ctx, admin, v.IntentID, v.Version, "approve", "未打开媒体", true, true); e != ErrMedia {
		t.Fatal("unseen approval", e)
	}
	if _, e = a.ReviewURL(ctx, admin, v.IntentID, v.Version); e != nil {
		t.Fatal(e)
	}
	if _, e = a.Review(ctx, admin, v.IntentID, v.Version, "approve", "完整画面及原声与引流检查通过", true, true); e != nil {
		t.Fatal(e)
	}
	avSteps(t, a, 1)
	result, e := service.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || result.State != "accepted" {
		t.Fatal(result, e)
	}
	f.exec("UPDATE chat_messages SET created_at=UTC_TIMESTAMP(6)-INTERVAL 61 DAY,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE id=?", string(result.MessageID))
	access, e := a.URL(ctx, principal("10203"), q.ConversationID, result.MessageID, false)
	if e != nil || access.CloudAvailable || access.URL != "" || access.ObjectHash == "" {
		t.Fatal("cache authorization after deadline", access, e)
	}
	if e = service.CleanCloudOne(ctx); e != nil {
		t.Fatal(e)
	}
	main := f.text("SELECT main_key FROM chat_av_uploads")
	poster := f.text("SELECT poster_key FROM chat_av_uploads")
	storage.mu.Lock()
	_, mainExists := storage.objects[main]
	_, posterExists := storage.objects[poster]
	storage.mu.Unlock()
	if mainExists || !posterExists {
		t.Fatal("cloud video or poster cleanup")
	}
	cover, e := a.URL(ctx, principal("10203"), q.ConversationID, result.MessageID, true)
	if e != nil || !cover.CloudAvailable || cover.URL == "" {
		t.Fatal("poster lost", e)
	}
	frame, e := a.URL(ctx, principal("10203"), q.ConversationID, result.MessageID, false, 0)
	if e != nil || !frame.CloudAvailable || frame.MIME != "image/jpeg" {
		t.Fatal("sample picture lost", frame, e)
	}
	if f.text("SELECT state FROM chat_messages") != "published" || f.text("SELECT CAST(state_version AS CHAR) FROM chat_messages") != "1" || f.text("SELECT COUNT(*) FROM chat_av_items") != "1" {
		t.Fatal("cloud expiry changed message authority")
	}
	if _, e = a.URL(ctx, principal("10203"), q.ConversationID, result.MessageID, false); e != nil {
		t.Fatal("cloud deletion denied cached access", e)
	}
	if _, e = service.Recall(ctx, principal("10202"), q.ConversationID, result.MessageID); e != ErrRecallWindow {
		t.Fatal("cloud expiry changed recall window", e)
	}
}

func TestMySQLMediaRelayOnlyStoresLocalTrace(t *testing.T) {
	prodDSN, testDSN := os.Getenv("CHAT_MEDIA_RELAY_PROD_DSN"), os.Getenv("CHAT_MEDIA_RELAY_TEST_DSN")
	if prodDSN == "" || testDSN == "" {
		t.Skip("isolated media relay databases unavailable")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	open := func(dsn string) (*sql.DB, *AVService) {
		db, err := sql.Open("mysql", dsn)
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { db.Close() })
		for _, query := range []string{
			"CREATE TABLE chat_av_jobs (raw_trace_id varchar(128) PRIMARY KEY, phase varchar(24), expires_at datetime(6))",
			"CREATE TABLE chat_av_raw_receipts (trace_id varchar(128) PRIMARY KEY, app_id varchar(64), decision varchar(24), code varchar(40), fingerprint char(64), created_at datetime(6))",
		} {
			if _, err := db.ExecContext(ctx, query); err != nil {
				t.Fatal(err)
			}
		}
		return db, &AVService{base: &Service{db: db}, inspector: &avInspectorFixture{}}
	}
	prodDB, prod := open(prodDSN)
	testDB, test := open(testDSN)
	addJob := func(db *sql.DB, trace string) {
		if _, err := db.ExecContext(ctx, "INSERT INTO chat_av_jobs VALUES (?, 'raw_wait', UTC_TIMESTAMP(6)+INTERVAL 1 HOUR)", trace); err != nil {
			t.Fatal(err)
		}
	}
	addJob(prodDB, "prod-owner")
	addJob(testDB, "unrelated-test-job")
	v := ports.AudioReceipt{AppID: test.inspector.MediaAppID(), TraceID: "test-owner", Decision: "pass", Code: "official_pass", Fingerprint: strings.Repeat("1", 64)}
	if !errors.Is(prod.ReceiveOwnedAudio(ctx, v), moderation.ErrReceiptUnowned) || !errors.Is(test.ReceiveOwnedAudio(ctx, v), moderation.ErrReceiptUnowned) {
		t.Fatal("a receipt without an exact owner must not be accepted")
	}
	addJob(testDB, "test-owner")
	for i := 0; i < 2; i++ {
		if err := test.ReceiveOwnedAudio(ctx, v); err != nil {
			t.Fatal(err)
		}
	}
	v.Decision, v.Code, v.Fingerprint = "risky", "official_risky", strings.Repeat("2", 64)
	if err := test.ReceiveOwnedAudio(ctx, v); err != nil {
		t.Fatal(err)
	}
	var n int
	if err := prodDB.QueryRowContext(ctx, "SELECT COUNT(*) FROM chat_av_raw_receipts").Scan(&n); err != nil || n != 0 {
		t.Fatalf("production received test data: %d, %v", n, err)
	}
	var decision, code string
	if err := testDB.QueryRowContext(ctx, "SELECT decision,code FROM chat_av_raw_receipts").Scan(&decision, &code); err != nil || decision != "error" || code != "conflicting_receipt" {
		t.Fatalf("conflicting receipt did not fail closed: %s %s %v", decision, code, err)
	}
}
