package message

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

func TestMySQLS21TestCenterInstance(t *testing.T) {
	path := os.Getenv("CHAT_TEST_CENTER_FIXTURE")
	if path == "" {
		t.Skip("test center fixture not requested")
	}
	raw, err := os.ReadFile(path)
	if err != nil || len(raw) > 4096 {
		t.Fatal("fixture input")
	}
	var fixture struct {
		Schema      int    `json:"schema"`
		Instance    string `json:"instance"`
		Suite       string `json:"suite"`
		Environment string `json:"environment"`
		Provider    string `json:"provider"`
		Order       struct {
			Workflow string `json:"workflow"`
			Source   string `json:"source"`
			Dispatch string `json:"dispatch"`
		} `json:"order"`
		Actors []string `json:"actors"`
		Counts []int    `json:"image_counts"`
	}
	d := json.NewDecoder(strings.NewReader(string(raw)))
	d.DisallowUnknownFields()
	if d.Decode(&fixture) != nil || fixture.Schema != 1 || fixture.Instance == "" || fixture.Environment != "test" || fixture.Provider != "simulated" {
		t.Fatal("isolated fixture")
	}
	f := newFixture(t)
	f.php("test-center", fixture.Order.Source, fixture.Order.Workflow, fixture.Order.Dispatch)
	if _, err = f.store.Poll(f.ctx, 100); err != nil {
		t.Fatal(err)
	}
	cid, err := f.store.FindOrder(f.ctx, principal("10202"), "401", "401")
	if err != nil {
		t.Fatal(err)
	}
	s, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	q := request(210)
	q.ConversationID = cid
	out := accepted(t, s, q)
	// Recompose actual services on the same durable DB, without presence or a Redis cache.
	restarted, err := NewService(f.goDB, f.store, safetyFunc(pass), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	again, err := restarted.Send(f.ctx, principal("10202"), q)
	if err != nil || again.MessageID != out.MessageID || f.text("SELECT COUNT(*) FROM chat_messages") != "1" {
		t.Fatal("restart replay", again, err)
	}
	h, err := NewHistoryService(f.goDB, f.store, "test", []byte(strings.Repeat("k", 32)), 3*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	initial, err := h.Read(f.ctx, principal("10202"), HistoryRequest{ConversationID: cid}, "bootstrap")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = restarted.Recall(f.ctx, principal("10202"), cid, out.MessageID); err != nil {
		t.Fatal(err)
	}
	changes, err := h.Read(f.ctx, principal("10202"), HistoryRequest{ConversationID: cid, Cursor: initial.ChangeCursor}, "changes")
	if err != nil || len(changes.Changes) != 1 || changes.Changes[0].Message.Text != nil {
		t.Fatal("durable change cursor", changes, err)
	}
	// Replaying PHP events never resets a committed seq or revives a recalled body.
	if _, err = f.store.Poll(f.ctx, 100); err != nil {
		t.Fatal(err)
	}
	if f.text("SELECT state FROM chat_messages WHERE id=?", string(out.MessageID)) != "recalled" {
		t.Fatal("projection revived message")
	}
	f.exec("UPDATE login_sessions SET status='revoked' WHERE id=10202")
	if _, err = h.Read(context.Background(), principal("10202"), HistoryRequest{ConversationID: cid}, "bootstrap"); err == nil {
		t.Fatal("old login restored read")
	}
}
