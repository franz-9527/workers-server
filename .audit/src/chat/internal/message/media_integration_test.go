package message

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type imageMemory struct {
	mu      sync.Mutex
	objects map[string][]byte
}

func (s *imageMemory) Grant(context.Context, string, int64) (string, string, error) {
	return "test-token", "https://upload.fixture.invalid", nil
}
func (s *imageMemory) Read(ctx context.Context, key string, limit int64) ([]byte, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	b, ok := s.objects[key]
	if !ok || int64(len(b)) > limit {
		return nil, ErrMedia
	}
	return append([]byte(nil), b...), nil
}
func (s *imageMemory) Put(ctx context.Context, key string, b []byte, mime string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if old, ok := s.objects[key]; ok && string(old) != string(b) {
		return ErrMedia
	}
	s.objects[key] = append([]byte(nil), b...)
	return nil
}
func (s *imageMemory) Sign(ctx context.Context, key string, ttl time.Duration) (string, error) {
	if ttl > 60*time.Second {
		return "", ErrMedia
	}
	return "https://private.fixture.invalid/" + key + "?e=short", nil
}
func (s *imageMemory) Delete(ctx context.Context, key string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.objects, key)
	return nil
}

type imageProcessorFunc func(context.Context, []byte) (ProcessedImage, error)

func (fn imageProcessorFunc) Process(ctx context.Context, b []byte) (ProcessedImage, error) {
	return fn(ctx, b)
}
func mediaFixture(t *testing.T) (*fixtureDB, *Service, *MediaService, *imageMemory, Request) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	storage := &imageMemory{objects: map[string][]byte{}}
	m, e := NewMediaService(s, storage, imageProcessorFunc(func(ctx context.Context, b []byte) (ProcessedImage, error) {
		if string(b) == "fail" {
			return ProcessedImage{}, ErrCheck
		}
		decision := "clear"
		if string(b) == "personal" {
			decision = "blocked"
		}
		return ProcessedImage{Main: append([]byte("main:"), b...), Thumb: append([]byte("thumb:"), b...), MIME: "image/jpeg", Method: "original", Width: 100, Height: 100, QRStatus: "completed", QRDecision: decision, QRVersion: "fixture-v1", OCRStatus: "not_run"}, nil
	}), "test")
	if e != nil {
		t.Fatal(e)
	}
	s = s.WithMedia(m)
	f.exec(`INSERT INTO admin_users(id,username,password_hash,role,status,must_change_password,created_at,updated_at) VALUES(90001,'image-review-fixture','fixture','operations','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())`)
	f.exec(`INSERT INTO chat_admin_sessions(id,admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at) VALUES(90001,90001,REPEAT('a',64),REPEAT('b',64),REPEAT('c',64),'["chat:audit","chat:moderate","chat:images"]',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())`)
	q.Text = ""
	q.MessageType = "image"
	q.Revision = 1
	q.Items = nil
	return f, s, m, storage, q
}
func mediaAdmin() ports.Principal {
	return ports.Principal{ActorID: "90001", Kind: "admin", SourceID: "90001", SessionID: strings.Repeat("a", 64), SessionVersion: strings.Repeat("b", 64), Environment: "test", Scopes: []string{"chat:audit", "chat:moderate", "chat:images"}, ExpiresAt: time.Now().Add(time.Minute)}
}
func mediaAdd(t *testing.T, m *MediaService, storage *imageMemory, cid ports.ID, n int, body string) MediaItem {
	t.Helper()
	ctx := context.Background()
	g, e := m.Upload(ctx, principal("10202"), cid, client(500+n), int64(len(body)))
	if e != nil {
		t.Fatal("upload", e)
	}
	storage.Put(ctx, g.Key, []byte(body), "image/jpeg")
	if _, e = m.Uploaded(ctx, principal("10202"), cid, g.AttachmentID); e != nil {
		t.Fatal(e)
	}
	return MediaItem{AttachmentID: g.AttachmentID, ObjectVersion: g.ObjectVersion}
}
func mediaProcess(t *testing.T, m *MediaService, n int) {
	t.Helper()
	for i := 0; i < n; i++ {
		if e := m.ProcessOne(context.Background()); e != nil {
			t.Fatal("process", e)
		}
	}
	if e := m.Advance(context.Background()); e != nil {
		t.Fatal("advance", e)
	}
}
func mediaApprove(t *testing.T, m *MediaService) ports.ID {
	t.Helper()
	ctx := context.Background()
	list, e := m.Reviews(ctx, mediaAdmin(), "0")
	if e != nil || len(list) != 1 {
		t.Fatal("reviews", list, e)
	}
	v, e := m.Review(ctx, mediaAdmin(), list[0].IntentID, list[0].Version, "claim", "逐图检查", false, false)
	if e != nil {
		t.Fatal(e)
	}
	for _, item := range list[0].Items {
		if _, e = m.ReviewURL(ctx, mediaAdmin(), v.IntentID, v.Version, item.AttachmentID); e != nil {
			t.Fatal("review URL", e)
		}
	}
	if _, e = m.Review(ctx, mediaAdmin(), v.IntentID, v.Version, "approve", "全部图片一般安全及整组导流检查通过", true, true); e != nil {
		t.Fatal("approve", e)
	}
	if e := m.Advance(ctx); e != nil {
		t.Fatal("publish", e)
	}
	return v.IntentID
}
func TestMySQLS16ImageExplicitSubmitReviewAndRecall(t *testing.T) {
	f, s, m, storage, q := mediaFixture(t)
	ctx := context.Background()
	q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 1, "construction")}
	mediaProcess(t, m, 1)
	var count int
	f.root.QueryRow("SELECT COUNT(*) FROM chat_messages").Scan(&count)
	if count != 0 {
		t.Fatal("upload published")
	}
	list, e := m.Reviews(ctx, mediaAdmin(), "0")
	if e != nil || len(list) != 0 {
		t.Fatal("unsubmitted review", list, e)
	}
	if _, e := s.Send(ctx, principal("10202"), q); e != nil {
		t.Fatal(e)
	}
	if e := m.Advance(ctx); e != nil {
		t.Fatal(e)
	}
	list, _ = m.Reviews(ctx, mediaAdmin(), "0")
	v, e := m.Review(ctx, mediaAdmin(), list[0].IntentID, list[0].Version, "claim", "检查图片", false, false)
	if e != nil {
		t.Fatal(e)
	}
	if _, e = m.Review(ctx, mediaAdmin(), v.IntentID, v.Version, "approve", "尚未取完整图片", true, true); e != ErrMedia {
		t.Fatal("unseen approval", e)
	}
	if _, e = m.ReviewURL(ctx, mediaAdmin(), v.IntentID, v.Version, q.Items[0].AttachmentID); e != nil {
		t.Fatal(e)
	}
	if _, e = m.Review(ctx, mediaAdmin(), v.IntentID, v.Version, "approve", "一般安全和图片导流检查通过", true, true); e != nil {
		t.Fatal(e)
	}
	if e := m.Advance(ctx); e != nil {
		t.Fatal(e)
	}
	r, e := s.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || r.State != "accepted" || r.Seq != "1" {
		t.Fatal(r, e)
	}
	h, _ := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
	page, e := h.Read(ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if e != nil || len(page.Messages) != 1 || page.Messages[0].State != "published" || len(page.Messages[0].Images) != 1 {
		t.Fatal("history", page, e)
	}
	encoded, _ := json.Marshal(page)
	if strings.Contains(string(encoded), "chat/test/") || strings.Contains(string(encoded), "source_sha") {
		t.Fatal("history leaked keys")
	}
	if _, e = m.URL(ctx, principal("10203"), q.ConversationID, r.MessageID, q.Items[0].AttachmentID, false); e != nil {
		t.Fatal(e)
	}
	report, e := m.Report(ctx, principal("10203"), q.ConversationID, r.MessageID, q.Items[0].AttachmentID, "疑似引流图片")
	if e != nil {
		t.Fatal(e)
	}
	again, e := m.Report(ctx, principal("10203"), q.ConversationID, r.MessageID, q.Items[0].AttachmentID, "重复举报")
	if e != nil || again != report {
		t.Fatal("report dedup", e)
	}
	if _, e = m.Report(ctx, principal("10203"), q.ConversationID, r.MessageID, "99999", "错误条目"); e == nil {
		t.Fatal("foreign report item")
	}
	reply := Request{ConversationID: q.ConversationID, ClientMessageID: client(700), MessageType: "text", Text: "看这张图", ReplyTo: r.MessageID, ReplyItem: q.Items[0].AttachmentID}
	if _, e := s.Send(ctx, principal("10203"), reply); e != nil {
		t.Fatal("image reply", e)
	}
	if _, e = s.Recall(ctx, principal("10202"), q.ConversationID, r.MessageID); e != nil {
		t.Fatal(e)
	}
	if _, e = m.URL(ctx, principal("10203"), q.ConversationID, r.MessageID, q.Items[0].AttachmentID, false); e == nil {
		t.Fatal("recalled media signed")
	}
	page, e = h.Read(ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if e != nil || len(page.Messages[0].Images) != 0 || page.Messages[1].Reply.State != "recalled" {
		t.Fatal("recall projection", page, e)
	}
}
func TestMySQLS17AlbumRetryOrderAndSingleUnread(t *testing.T) {
	f, s, m, storage, q := mediaFixture(t)
	ctx := context.Background()
	q.MessageType = "image_album"
	for i := 0; i < 9; i++ {
		body := fmt.Sprint("construction-", i)
		if i == 4 {
			body = "fail"
		}
		item := mediaAdd(t, m, storage, q.ConversationID, i+1, body)
		item.Ordinal = i
		q.Items = append(q.Items, item)
	}
	if _, e := s.Send(ctx, principal("10202"), q); e != nil {
		t.Fatal(e)
	}
	for i := 0; i < 9; i++ {
		e := m.ProcessOne(ctx)
		if e != nil && e != ErrCheck {
			t.Fatal(e)
		}
	}
	m.Advance(ctx)
	status, e := m.Status(ctx, principal("10202"), q.ConversationID, q.ClientMessageID, false)
	if e != nil || status.State != "retryable" {
		t.Fatal(status, e)
	}
	var count int
	f.root.QueryRow("SELECT COUNT(*) FROM chat_messages").Scan(&count)
	if count != 0 {
		t.Fatal("partial album")
	}
	// Retry only the failed immutable object. Same bytes may now process after
	// the unavailable infrastructure recovers; successful items keep attempts=1.
	m.processor = imageProcessorFunc(func(ctx context.Context, b []byte) (ProcessedImage, error) {
		return ProcessedImage{Main: append([]byte("main:"), b...), Thumb: []byte("thumb"), MIME: "image/jpeg", Method: "original", Width: 100, Height: 100, QRStatus: "unavailable", QRDecision: "review", QRVersion: "zxing-unavailable", OCRStatus: "not_run"}, nil
	})
	if _, e = m.Uploaded(ctx, principal("10202"), q.ConversationID, q.Items[4].AttachmentID); e != nil {
		t.Fatal(e)
	}
	mediaProcess(t, m, 1)
	mediaApprove(t, m)
	r, e := s.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || r.State != "accepted" || r.Seq != "1" {
		t.Fatal(r, e)
	}
	h, _ := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
	unread, e := h.Unread(ctx, principal("10203"), q.ConversationID)
	if e != nil || unread.Count != 1 {
		t.Fatal("album unread", unread, e)
	}
	page, e := h.Read(ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
	if e != nil || len(page.Messages) != 1 || len(page.Messages[0].Images) != 9 {
		t.Fatal("album", page, e)
	}
	for i, v := range page.Messages[0].Images {
		if v.Ordinal != i || v.ItemID != q.Items[i].AttachmentID {
			t.Fatal("ordered manifest")
		}
	}
	changed := q
	changed.Items = append([]MediaItem(nil), q.Items...)
	changed.Items[0], changed.Items[1] = changed.Items[1], changed.Items[0]
	changed.Items[0].Ordinal = 0
	changed.Items[1].Ordinal = 1
	if _, e := s.Send(ctx, principal("10202"), changed); e != ErrConflict {
		t.Fatal("changed order same ID", e)
	}
	var attempts int
	f.root.QueryRow("SELECT attempts FROM chat_image_uploads WHERE id=?", string(q.Items[0].AttachmentID)).Scan(&attempts)
	if attempts != 1 {
		t.Fatal("rechecked success")
	}
}
func TestMySQLS16CanceledUnknownAndFinalAuthority(t *testing.T) {
	for _, change := range []string{"cancel", "unknown", "expiry", "ban", "logout", "close", "rule", "corrupt"} {
		t.Run(change, func(t *testing.T) {
			f, s, m, storage, q := mediaFixture(t)
			ctx := context.Background()
			q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 1, "construction")}
			mediaProcess(t, m, 1)
			if change == "unknown" {
				if _, e := m.Status(ctx, principal("10202"), q.ConversationID, q.ClientMessageID, true); e != nil {
					t.Fatal(e)
				}
				r, e := s.Send(ctx, principal("10202"), q)
				if e != nil || r.State != "canceled" {
					t.Fatal("tombstone", r, e)
				}
				return
			}
			if _, e := s.Send(ctx, principal("10202"), q); e != nil {
				t.Fatal(e)
			}
			m.Advance(ctx)
			list, _ := m.Reviews(ctx, mediaAdmin(), "0")
			v, e := m.Review(ctx, mediaAdmin(), list[0].IntentID, list[0].Version, "claim", "检查图片", false, false)
			if e != nil {
				t.Fatal(e)
			}
			m.ReviewURL(ctx, mediaAdmin(), v.IntentID, v.Version, q.Items[0].AttachmentID)
			if _, e = m.Review(ctx, mediaAdmin(), v.IntentID, v.Version, "approve", "一般安全和整组检查通过", true, true); e != nil {
				t.Fatal(e)
			}
			switch change {
			case "cancel":
				if _, e = m.Status(ctx, principal("10202"), q.ConversationID, q.ClientMessageID, true); e != nil {
					t.Fatal(e)
				}
			case "expiry":
				f.exec("UPDATE chat_image_bundles SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND")
			case "ban":
				f.exec("UPDATE chat_restrictions SET permanent=1 WHERE actor_id=10202")
			case "logout":
				f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
			case "close":
				f.php("close")
				if _, e = f.store.Poll(ctx, 100); e != nil {
					t.Fatal(e)
				}
			case "rule":
				f.exec("UPDATE chat_image_bundles SET rule_version='old-rule'")
			case "corrupt":
				var key string
				f.root.QueryRow("SELECT main_key FROM chat_image_uploads LIMIT 1").Scan(&key)
				storage.mu.Lock()
				storage.objects[key] = []byte("changed")
				storage.mu.Unlock()
			}
			_, _ = m.Publish(ctx, v.IntentID)
			var count int
			f.root.QueryRow("SELECT COUNT(*) FROM chat_messages").Scan(&count)
			if count != 0 {
				t.Fatal("published after", change)
			}
		})
	}
}
func TestMySQLS16ExpiredAccessAndConcurrentPublish(t *testing.T) {
	f, s, m, storage, q := mediaFixture(t)
	ctx := context.Background()
	q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 1, "construction")}
	mediaProcess(t, m, 1)
	p := principal("10202")
	p.ExpiresAt = time.Now().Add(time.Second)
	if _, e := s.Send(ctx, p, q); e != nil {
		t.Fatal(e)
	}
	m.Advance(ctx)
	list, _ := m.Reviews(ctx, mediaAdmin(), "0")
	v, _ := m.Review(ctx, mediaAdmin(), list[0].IntentID, list[0].Version, "claim", "检查图片", false, false)
	m.ReviewURL(ctx, mediaAdmin(), v.IntentID, v.Version, q.Items[0].AttachmentID)
	if _, e := m.Review(ctx, mediaAdmin(), v.IntentID, v.Version, "approve", "一般安全和整组检查通过", true, true); e != nil {
		t.Fatal(e)
	}
	// Expire only the original access deadline, not the underlying login.
	f.exec(`UPDATE chat_image_bundles SET principal_json=JSON_SET(principal_json,'$.ExpiresAt','2020-01-01T00:00:00Z')`)
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); _, _ = m.Publish(ctx, v.IntentID) }()
	}
	wg.Wait()
	r, e := s.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
	if e != nil || r.State != "accepted" {
		t.Fatal("access expiry canceled confirmation", r, e)
	}
	var count int
	f.root.QueryRow("SELECT COUNT(*) FROM chat_messages").Scan(&count)
	if count != 1 {
		t.Fatal("duplicate publication")
	}
}
func TestS17StrictManifest(t *testing.T) {
	q := Request{ConversationID: "1", ClientMessageID: client(1), MessageType: "image_album", Revision: 1, Items: []MediaItem{{AttachmentID: "1", ObjectVersion: strings.Repeat("a", 64), Ordinal: 0}, {AttachmentID: "2", ObjectVersion: strings.Repeat("b", 64), Ordinal: 1}}}
	b, _ := json.Marshal(q)
	if _, e := decodeMediaSend(b); e != nil {
		t.Fatal(e)
	}
	for _, raw := range []string{strings.Replace(string(b), `"ordinal":0`, `"ordinal":0,"ordinal":1`, 1), strings.Replace(string(b), `"items":[`, `"unknown":1,"items":[`, 1), strings.Replace(string(b), `"ordinal":0`, `"ordinal":null`, 1), strings.Replace(string(b), `,"ordinal":0`, "", 1)} {
		if _, e := decodeMediaSend([]byte(raw)); e == nil {
			t.Fatal("ambiguous manifest accepted")
		}
	}
	q.Items[1].AttachmentID = "1"
	if _, e := mediaFingerprint(q); !errors.Is(e, ports.ErrInvalid) {
		t.Fatal("duplicate attachment", e)
	}
}

func TestS17FingerprintBindsEveryItemAndSource(t *testing.T) {
	q := Request{ConversationID: "1", ClientMessageID: client(1), MessageType: "image_album", Revision: 1, Items: []MediaItem{{AttachmentID: "1", ObjectVersion: strings.Repeat("a", 64), Ordinal: 0}, {AttachmentID: "2", ObjectVersion: strings.Repeat("b", 64), Ordinal: 1}}}
	first, e := mediaFingerprint(q)
	if e != nil || first == mediaHash(nil) {
		t.Fatal("empty/invalid structural encoding", e)
	}
	changed := q
	changed.Items = append([]MediaItem(nil), q.Items...)
	changed.Items[0].ObjectVersion = strings.Repeat("c", 64)
	second, e := mediaFingerprint(changed)
	if e != nil || first == second {
		t.Fatal("object version missing from fingerprint")
	}
	changed = q
	changed.ReplyTo = "7"
	changed.ReplyItem = "2"
	third, e := mediaFingerprint(changed)
	if e != nil || first == third {
		t.Fatal("source missing from fingerprint")
	}
	changed = q
	changed.Revision = 2
	fourth, e := mediaFingerprint(changed)
	if e != nil || first == fourth {
		t.Fatal("revision missing from fingerprint")
	}
}

func TestMySQLS16ReviewLeaseAuthorityAndCancelledAccess(t *testing.T) {
	f, s, m, storage, q := mediaFixture(t)
	ctx := context.Background()
	q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 1, "construction")}
	mediaProcess(t, m, 1)
	if _, e := s.Send(ctx, principal("10202"), q); e != nil {
		t.Fatal(e)
	}
	m.Advance(ctx)
	list, e := m.Reviews(ctx, mediaAdmin(), "0")
	if e != nil || len(list) != 1 {
		t.Fatal(list, e)
	}
	admin := mediaAdmin()
	admin.Scopes = []string{"chat:audit", "chat:moderate"}
	if _, e = m.Reviews(ctx, admin, "0"); e == nil {
		t.Fatal("generic moderator lacks distinct prereview grant")
	}
	v, e := m.Review(ctx, mediaAdmin(), list[0].IntentID, list[0].Version, "claim", "逐图检查", false, false)
	if e != nil {
		t.Fatal(e)
	}
	if _, e = m.Review(ctx, mediaAdmin(), v.IntentID, list[0].Version, "reject", "旧版本拒绝", false, false); e != ErrMediaVersion {
		t.Fatal("stale decision", e)
	}
	if _, e = m.Status(ctx, principal("10202"), q.ConversationID, q.ClientMessageID, true); e != nil {
		t.Fatal(e)
	}
	if _, e = m.ReviewURL(ctx, mediaAdmin(), v.IntentID, v.Version, q.Items[0].AttachmentID); e == nil {
		t.Fatal("canceled intent granted a fresh review URL")
	}
	f.exec("UPDATE chat_admin_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=90001")
	if _, e = m.Reviews(ctx, mediaAdmin(), "0"); e == nil {
		t.Fatal("revoked reviewer")
	}
}
