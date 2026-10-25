package message

import (
	"context"
	"database/sql"
	"time"

	"github.com/franz-9527/workers/chat/internal/conversation"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// HistoryService is shared by HTTP and future WSS. All reads are bounded and
// hold current authority and the same conversation commit lock as publication.
type HistoryService struct {
	db            *sql.DB
	conversations *conversation.Store
	environment   string
	key           []byte
	timeout       time.Duration
	capabilities  MessageCapabilities
}

func NewHistoryService(db *sql.DB, c *conversation.Store, env string, key []byte, timeout time.Duration) (*HistoryService, error) {
	if db == nil || c == nil || len(key) != 32 || (env != "test" && env != "development" && env != "production") || timeout < 100*time.Millisecond || timeout > 3*time.Second {
		return nil, ports.ErrInvalid
	}
	return &HistoryService{db: db, conversations: c, environment: env, key: append([]byte(nil), key...), timeout: timeout, capabilities: MessageCapabilities{Cache: true}}, nil
}

type HistoryRequest struct {
	ConversationID ports.ID `json:"conversation_id"`
	Cursor         string   `json:"cursor,omitempty"`
	TargetID       ports.ID `json:"message_id,omitempty"`
	Limit          int      `json:"limit,omitempty"`
}
type MessageView struct {
 Sender *SenderIdentity `json:"sender,omitempty"`
	AV            *AVView        `json:"av,omitempty"`
	RetentionMode string         `json:"retention_mode"`
	ExpiresAt     *time.Time     `json:"cloud_expires_at,omitempty"`
	ImageCount    int            `json:"image_count,omitempty"`
	Images        []ImageView    `json:"images,omitempty"`
	ReplyItem     ports.ID       `json:"-"`
	ID            ports.ID       `json:"message_id"`
	Seq           ports.ID       `json:"seq"`
	SenderID      ports.ID       `json:"sender_id"`
	PolicyVersion ports.ID       `json:"policy_version"`
	Type          string         `json:"message_type"`
	State         string         `json:"state"`
	StateVersion  ports.ID       `json:"state_version"`
	Text          *string        `json:"text,omitempty"`
	CreatedAt     time.Time      `json:"created_at"`
	ReplyTo       ports.ID       `json:"-"`
	EditedFrom    ports.ID       `json:"-"`
	Reply         *ReferenceView `json:"reply,omitempty"`
	EditedSource  *ReferenceView `json:"edited_from,omitempty"`
}
type ChangeView struct {
	Seq     ports.ID    `json:"change_seq"`
	Type    string      `json:"change_type"`
	Message MessageView `json:"message"`
}
type MessageCapabilities struct {
	Cache  bool `json:"cache"`
	Images bool `json:"images"`
	Audio  bool `json:"audio"`
	Video  bool `json:"video"`
}

func (s *HistoryService) ConfigureMedia(images, audio, video bool) {
	s.capabilities = MessageCapabilities{Cache: true, Images: images, Audio: audio, Video: video}
}

type HistoryPage struct {
 Mode string `json:"conversation_mode,omitempty"`
	Capabilities      *MessageCapabilities `json:"capabilities,omitempty"`
	OrderContext      *ports.OrderContext  `json:"order_context,omitempty"`
	MemberRole        string               `json:"member_role"`
	CanSend           bool                 `json:"can_send"`
	ViewerID          ports.ID             `json:"viewer_actor_id,omitempty"`
	Messages          []MessageView        `json:"messages"`
	Changes           []ChangeView         `json:"changes"`
	PolicyVersion     ports.ID             `json:"policy_version"`
	SnapshotMaxSeq    ports.ID             `json:"snapshot_max_seq"`
	SnapshotMaxChange ports.ID             `json:"snapshot_max_change_seq"`
	NextSync          ports.ID             `json:"next_sync_cursor,omitempty"`
	NextChange        ports.ID             `json:"next_change_cursor,omitempty"`
	SyncCursor        string               `json:"sync_cursor,omitempty"`
	ChangeCursor      string               `json:"change_cursor,omitempty"`
	HistoryCursor     string               `json:"history_cursor,omitempty"`
	HasMore           bool                 `json:"has_more"`
	ExpiresAt         int64                `json:"expires_at"`
}
type historyRead struct {
	actor                              ports.ID
	tx                                 *sql.Tx
	grant                              ports.Grant
	cursor                             historyCursor
	seq, change, floorSeq, floorChange uint64
}

func (s *HistoryService) open(ctx context.Context, p ports.Principal, q HistoryRequest, mode string) (*historyRead, error) {
	if !validID(q.ConversationID) || p.Environment != s.environment || q.Limit < 0 || q.Limit > 50 {
		return nil, ports.ErrInvalid
	}
	var c historyCursor
	var e error
	if mode != "bootstrap" && mode != "locate" {
		c, e = s.parse(q.Cursor, mode)
		if e != nil {
			return nil, e
		}
		if c.Binding != bindingFor(s.environment, p) || c.Conversation != q.ConversationID {
			return nil, ErrResync
		}
	} else if q.Cursor != "" {
		return nil, ports.ErrInvalid
	}
	tx, e := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	fail := func(e error) (*historyRead, error) { tx.Rollback(); return nil, e }
	g, e := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: q.ConversationID, Action: "read"})
	if e != nil {
		return fail(e)
	}
	r := &historyRead{tx: tx, grant: g, actor: p.ActorID}
	if e = tx.QueryRowContext(ctx, `SELECT last_seq,last_change_seq,sync_floor_seq,change_floor_seq FROM chat_conversations WHERE id=?`, string(q.ConversationID)).Scan(&r.seq, &r.change, &r.floorSeq, &r.floorChange); e != nil {
		return fail(ports.ErrUnavailable)
	}
	if r.floorSeq > r.seq || r.floorChange > r.change {
		return fail(ports.ErrUnavailable)
	}
	if mode == "bootstrap" || mode == "locate" {
		now := time.Now().Unix()
		c = historyCursor{Schema: 1, Binding: bindingFor(s.environment, p), Conversation: q.ConversationID, Policy: g.Version, Member: g.MemberKey, Seq: decimal(r.seq), Change: decimal(r.change), Issued: now, Expiry: now + int64(cursorTTL/time.Second)}
	} else {
		if c.Policy != g.Version || c.Member != g.MemberKey || number(c.Seq) > r.seq || number(c.Change) > r.change {
			return fail(ErrResync)
		}
		upper := number(c.Seq)
		floor := r.floorSeq
		if mode == "changes" {
			upper = number(c.Change)
			floor = r.floorChange
		}
		if number(c.Position) > upper || number(c.Position) < floor {
			return fail(ErrResync)
		}
		if mode != "history" && !c.Pending {
			c.Seq = decimal(r.seq)
			c.Change = decimal(r.change)
			c.Issued = time.Now().Unix()
			c.Expiry = c.Issued + int64(cursorTTL/time.Second)
		}
	}
	r.cursor = c
	return r, nil
}
func emptyPage(r *historyRead) HistoryPage {
	return HistoryPage{Mode:r.grant.Mode,OrderContext: r.grant.OrderContext, MemberRole: r.grant.MemberRole, CanSend: r.grant.CanSend, ViewerID: r.actor, Messages: []MessageView{}, Changes: []ChangeView{}, PolicyVersion: r.grant.Version, SnapshotMaxSeq: r.cursor.Seq, SnapshotMaxChange: r.cursor.Change, ExpiresAt: r.cursor.Expiry}
}

// Projection is safe in SQL too: hidden bodies never cross the DB boundary.
const viewColumns = `CAST(m.id AS CHAR),CAST(m.seq AS CHAR),CAST(m.sender_id AS CHAR),CAST(m.policy_version AS CHAR),m.message_type,m.state,CAST(m.state_version AS CHAR),CASE WHEN m.state='published' AND m.message_type='text' THEN m.text_body ELSE NULL END,m.created_at,CAST(m.reply_to_message_id AS CHAR),CAST(m.edited_from_recalled_message_id AS CHAR),CAST(m.reply_to_item_id AS CHAR),m.retention_mode,m.expires_at`
const visibleColumn = `(m.policy_version<=? AND EXISTS(SELECT 1 FROM chat_member_intervals i WHERE i.conversation_id=m.conversation_id AND i.actor_id=? AND i.start_version<=m.policy_version AND (i.end_version IS NULL OR i.end_version>m.policy_version)))`

type candidate struct {
	view    MessageView
	visible bool
	change  ports.ID
	kind    string
}

func scanCandidate(rows *sql.Rows, changes bool) (candidate, error) {
	var c candidate
	var text, reply, edited, replyItem sql.NullString
	args := []any{&c.view.ID, &c.view.Seq, &c.view.SenderID, &c.view.PolicyVersion, &c.view.Type, &c.view.State, &c.view.StateVersion, &text, &c.view.CreatedAt, &reply, &edited, &replyItem, &c.view.RetentionMode, &c.view.ExpiresAt, &c.visible}
	if changes {
		args = append(args, &c.change, &c.kind)
	}
	if rows.Scan(args...) != nil || !validID(c.view.ID) || !validID(c.view.Seq) || !validID(c.view.StateVersion) {
		return c, ports.ErrUnavailable
	}
	if replyItem.Valid {
		c.view.ReplyItem = ports.ID(replyItem.String)
	}
	if reply.Valid {
		c.view.ReplyTo = ports.ID(reply.String)
	}
	if edited.Valid {
		c.view.EditedFrom = ports.ID(edited.String)
	}
	if c.view.State == "published" && c.view.Type == "text" && text.Valid {
		v := text.String
		c.view.Text = &v
	} else if c.view.State == "published" && (c.view.Type == "text" || c.view.Type == "image" || c.view.Type == "image_album" || c.view.Type == "audio" || c.view.Type == "video") {
		c.view.Text = nil
	} else {
		c.view.Text = nil
		if c.view.State != "recalled" && c.view.State != "blocked" && c.view.State != "expired" {
			c.view.State = "unavailable"
		}
	}
	return c, nil
}
func limitFor(q HistoryRequest) int {
	if q.Limit == 0 {
		return 30
	}
	return q.Limit
}
func (s *HistoryService) finish(ctx context.Context, p ports.Principal, r *historyRead, page HistoryPage) (HistoryPage, error) {
	caps := s.capabilities
	page.Capabilities = &caps
	views := make([]*MessageView, 0, len(page.Messages)+len(page.Changes))
	for i := range page.Messages {
		views = append(views, &page.Messages[i])
	}
	for i := range page.Changes {
		views = append(views, &page.Changes[i].Message)
	}
	if err:=hydrateSenders(ctx,r.tx,views);err!=nil{return HistoryPage{},err}
	if err := hydrateImages(ctx, r.tx, views); err != nil {
		return HistoryPage{}, err
	}
	if err := hydrateAV(ctx, r.tx, p, views); err != nil {
		return HistoryPage{}, err
	}
	if err := hydrateReferences(ctx, r.tx, p, r.grant, r.cursor.Conversation, views); err != nil {
		return HistoryPage{}, err
	}
	if !time.Now().Before(p.ExpiresAt) {
		return HistoryPage{}, policy.ErrDenied
	}
	if r.tx.Commit() != nil {
		return HistoryPage{}, ports.ErrUnavailable
	}
	return page, nil
}
func (s *HistoryService) Read(ctx context.Context, p ports.Principal, q HistoryRequest, mode string) (HistoryPage, error) {
	if mode != "bootstrap" && mode != "history" && mode != "sync" && mode != "changes" && mode != "locate" {
		return HistoryPage{}, ports.ErrInvalid
	}
	if mode == "locate" {
		return s.locate(ctx, p, q)
	}
	if q.TargetID != "" {
		return HistoryPage{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	r, e := s.open(ctx, p, q, mode)
	if e != nil {
		return HistoryPage{}, e
	}
	defer r.tx.Rollback()
	page := emptyPage(r)
	c := r.cursor
	limit := limitFor(q)
	position := number(c.Position)
	upper := number(c.Seq)
	floor := r.floorSeq
	descending := mode == "bootstrap" || mode == "history"
	changes := mode == "changes"
	if mode == "bootstrap" {
		position = upper
	}
	if changes {
		upper = number(c.Change)
		floor = r.floorChange
	}
	table := `chat_messages m`
	index := `m.seq`
	extra := ``
	if changes {
		table = `chat_message_changes ch STRAIGHT_JOIN chat_messages m ON m.id=ch.message_id AND m.conversation_id=ch.conversation_id`
		index = `ch.change_seq`
		extra = `,CAST(ch.change_seq AS CHAR),ch.change_type`
	}
	predicate := index + `>? AND ` + index + `<=?`
	start, end := position, upper
	order := ` ASC`
	if descending {
		predicate = index + `>? AND ` + index + `<=?`
		start, end = floor, position
		order = ` DESC`
	}
	scopeColumn := "m.conversation_id"
	if changes {
		scopeColumn = "ch.conversation_id"
	}
	query := `SELECT ` + viewColumns + `,` + visibleColumn + extra + ` FROM ` + table + ` WHERE ` + scopeColumn + `=? AND ` + predicate + ` ORDER BY ` + index + order + ` LIMIT ?`
	rows, e := r.tx.QueryContext(ctx, query, string(r.grant.Version), string(p.ActorID), string(q.ConversationID), start, end, limit+1)
	if e != nil {
		return HistoryPage{}, ports.ErrUnavailable
	}
	candidates := make([]candidate, 0, limit+1)
	for rows.Next() {
		v, err := scanCandidate(rows, changes)
		if err != nil {
			rows.Close()
			return HistoryPage{}, err
		}
		candidates = append(candidates, v)
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return HistoryPage{}, ports.ErrUnavailable
	}
	page.HasMore = len(candidates) > limit
	if page.HasMore {
		candidates = candidates[:limit]
	}
	next := upper
	if descending {
		next = floor
	}
	if len(candidates) > 0 && (page.HasMore || descending) {
		last := candidates[len(candidates)-1]
		next = number(last.view.Seq)
		if changes {
			next = number(last.change)
		}
		if descending {
			next--
		}
	}
	for _, v := range candidates {
		if !v.visible {
			continue
		}
		if changes {
			page.Changes = append(page.Changes, ChangeView{v.change, v.kind, v.view})
		} else {
			page.Messages = append(page.Messages, v.view)
		}
	}
	if descending {
		for i, j := 0, len(page.Messages)-1; i < j; i, j = i+1, j-1 {
			page.Messages[i], page.Messages[j] = page.Messages[j], page.Messages[i]
		}
		h := c
		h.Mode = "history"
		h.Position = decimal(next)
		h.Pending = true
		if page.HasMore {
			page.HistoryCursor = s.sign(h)
		}
		if mode == "bootstrap" {
			page.NextSync = c.Seq
			page.NextChange = c.Change
			sc := c
			sc.Mode = "sync"
			sc.Position = c.Seq
			sc.Pending = false
			page.SyncCursor = s.sign(sc)
			sc.Mode = "changes"
			sc.Position = c.Change
			page.ChangeCursor = s.sign(sc)
		} else {
			page.NextSync = ""
			page.NextChange = ""
		}
	} else {
		c.Mode = mode
		c.Position = decimal(next)
		c.Pending = page.HasMore
		if changes {
			page.NextChange = c.Position
			page.ChangeCursor = s.sign(c)
		} else {
			page.NextSync = c.Position
			page.SyncCursor = s.sign(c)
		}
	}
	return s.finish(ctx, p, r, page)
}
