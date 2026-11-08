package message

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/franz-9527/workers/chat/internal/ports"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

type automaticSafety struct {
	safetyFunc
	decision string
	err      error
	calls    int
}

func (s *automaticSafety) CheckImage(context.Context, ports.Principal, []byte) (string, error) {
	s.calls++
	return s.decision, s.err
}
func TestJSONResponseNeverEmptyOnInvalidID(t *testing.T) {
	w := httptest.NewRecorder()
	writeJSON(w, map[string]any{"data": ImageReview{IntentID: "1", Version: "1"}})
	var out map[string]any
	if w.Code != 500 || json.Unmarshal(w.Body.Bytes(), &out) != nil || out["code"] != "CHAT_RESPONSE_UNAVAILABLE" {
		t.Fatal(w.Code, w.Body.String())
	}
}
func TestMySQLAutomaticImageContentVerdictAndAuditRecall(t *testing.T) {
	for _, decision := range []string{"passed", "review", "unavailable"} {
		t.Run(decision, func(t *testing.T) {
			f, s, m, storage, q := mediaFixture(t)
			ctx := context.Background()
			check := &automaticSafety{safetyFunc: safetyFunc(pass), decision: decision}
			if decision == "unavailable" {
				check.err = errors.New("provider offline")
			}
			s.safety = check
			m.base.safety = check
			m.WithAutomaticReview(true)
			q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 1, "ordinary")}
			if _, e := s.Send(ctx, principal("10202"), q); e != nil {
				t.Fatal(e)
			}
			mediaProcess(t, m, 1)
			r, e := s.Query(ctx, principal("10202"), q.ConversationID, q.ClientMessageID)
			if e != nil {
				t.Fatal(e)
			}
			if check.calls != 1 {
				t.Fatal("missing content check", check.calls)
			}
			switch decision {
			case "passed":
				if r.State != "accepted" {
					t.Fatal(r)
				}
				f.php("profiles-check")
				h, _ := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("s", 32)), 3*time.Second)
				page, err := h.Read(ctx, principal("10203"), HistoryRequest{ConversationID: q.ConversationID}, "bootstrap")
				if err != nil || len(page.Messages) != 1 || len(page.Messages[0].Images) != 1 || page.Messages[0].Images[0].MainBytes != int64(len("main:ordinary")) {
					t.Fatal("processed main size not projected", page, err)
				}
				var main, thumb string
				f.goDB.QueryRow("SELECT main_key,thumb_key FROM chat_image_uploads").Scan(&main, &thumb)
				if !strings.HasSuffix(main, ".jpg") || !strings.HasSuffix(thumb, ".jpg") {
					t.Fatal(main, thumb)
				}
				if _, e = s.Recall(ctx, principal("10202"), q.ConversationID, r.MessageID); e != nil {
					t.Fatal(e)
				}
				g, admin := governanceFixture(t, f, s, m)
				page, e := g.AuditHistory(ctx, admin, HistoryRequest{ConversationID: q.ConversationID})
				if e != nil || len(page.Messages) != 1 || page.Messages[0].State != "recalled" || len(page.Messages[0].Images) != 1 {
					t.Fatal(page, e)
				}
				if _, e = g.AuditImage(ctx, admin, q.ConversationID, r.MessageID, q.Items[0].AttachmentID, false); e != nil {
					t.Fatal("admin recall image", e)
				}
				if _, e = m.URL(ctx, principal("10203"), q.ConversationID, r.MessageID, q.Items[0].AttachmentID, false); e == nil {
					t.Fatal("member could access recalled image")
				}
				f.exec("UPDATE chat_messages SET retention_mode='days',expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY")
				for i := 0; i < 2; i++ {
					if e = s.CleanCloudOne(ctx); e != nil {
						t.Fatal(e)
					}
				}
				if f.text("SELECT COUNT(*) FROM chat_image_objects WHERE deleted_at IS NULL") != "1" {
					t.Fatal("thumbnail should remain after recalled main expires")
				}
			case "review":
				if r.State != "review_pending" {
					t.Fatal(r)
				}
				rows, e := m.Reviews(ctx, mediaAdmin(), "0")
				if e != nil || len(rows) != 1 {
					t.Fatal(rows, e)
				}
				claimed, e := m.Review(ctx, mediaAdmin(), rows[0].IntentID, rows[0].Version, "claim", "查看风险图片", false, false)
				if e != nil {
					t.Fatal(e)
				}
				if _, e = json.Marshal(claimed); e != nil {
					t.Fatal("claim produces empty HTTP response", e)
				}
			default:
				if r.State == "accepted" || r.State == "review_pending" {
					t.Fatal("failure mistaken for pass or violation", r)
				}
			}
		})
	}
}
func TestMySQLAutomaticVideoChecksFramesAndText(t *testing.T) {
	for _, decision := range []string{"passed", "review"} {
		t.Run(decision, func(t *testing.T) {
			f, s, a, storage, _, q := avFixture(t, "video")
			check := &automaticSafety{safetyFunc: safetyFunc(func(context.Context, ports.TextRequest) (ports.SafetyResult, error) {
				return ports.SafetyResult{Decision: "passed", RuleVersion: "av-text-fixture", AuthorityProof: strings.Repeat("a", 64)}, nil
			}), decision: decision}
			s.safety = check
			a.WithAutomaticReview(true)
			q = avSubmit(t, a, storage, q)
			avSteps(t, a, 7)
			r, e := s.Query(context.Background(), principal("10202"), q.ConversationID, q.ClientMessageID)
			want := "accepted"
			if decision == "review" {
				want = "review_pending"
			}
			if e != nil || r.State != want || check.calls != 1 {
				t.Fatal(r, e, check.calls)
			}
			if decision == "passed" && f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
				t.Fatal("missing video")
			}
		})
	}
}
func TestMySQLReportDecisionDoesNotRequireEvidenceView(t *testing.T) {
	f, s, q := setupMessage(t, safetyFunc(pass))
	g, admin := governanceFixture(t, f, s, nil)
	ctx := context.Background()
	sent := accepted(t, s, q)
	id, e := g.Report(ctx, principal("10203"), q.ConversationID, sent.MessageID, "", "举报内容不合适")
	if e != nil {
		t.Fatal(e)
	}
	c, e := g.Decide(ctx, admin, CaseDecision{ID: id, Expected: "1", Action: "block", Reason: "根据举报直接屏蔽"})
	if e != nil || c.State != "block" {
		t.Fatal(c, e)
	}
	if _, e = g.Decide(ctx, admin, CaseDecision{ID: id, Expected: "1", Action: "dismiss", Reason: "旧版本重复操作"}); e == nil {
		t.Fatal("stale decision accepted")
	}
	if f.text("SELECT COUNT(*) FROM chat_governance_audit WHERE action='case.evidence'") != "0" {
		t.Fatal("implicit evidence view")
	}
}

func TestMySQLAutomaticImageStopsAfterExpiryOrRevocation(t *testing.T) {
	for _, change := range []string{"expired", "revoked"} {
		t.Run(change, func(t *testing.T) {
			f, s, m, storage, q := mediaFixture(t)
			ctx := context.Background()
			check := &automaticSafety{safetyFunc: safetyFunc(pass), decision: "passed"}
			s.safety = check
			m.base.safety = check
			m.WithAutomaticReview(true)
			q.Items = []MediaItem{mediaAdd(t, m, storage, q.ConversationID, 1, "ordinary")}
			if _, e := s.Send(ctx, principal("10202"), q); e != nil {
				t.Fatal(e)
			}
			if e := m.ProcessOne(ctx); e != nil {
				t.Fatal(e)
			}
			if change == "expired" {
				f.exec("UPDATE chat_image_bundles SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND")
			} else {
				f.exec("UPDATE users SET status='disabled' WHERE id=10202")
			}
			if e := m.Advance(ctx); e != nil {
				t.Fatal(e)
			}
			if f.text("SELECT state FROM chat_send_intents") != "rejected" || check.calls != 0 {
				t.Fatal("terminal intent remained pending or performed external checks", check.calls)
			}
		})
	}
}
