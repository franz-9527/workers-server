package message

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// Locate is a bounded index seek, not repeated history-page traversal. It is
// also the basis for S15 references/S09 unread anchors; it never marks read.
func (s *HistoryService) locate(ctx context.Context, p ports.Principal, q HistoryRequest) (HistoryPage, error) {
	if !validID(q.TargetID) {
		return HistoryPage{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	r, e := s.open(ctx, p, q, "locate")
	if e != nil {
		return HistoryPage{}, e
	}
	defer r.tx.Rollback()
	selectSQL := `SELECT ` + viewColumns + `,` + visibleColumn + ` FROM chat_messages m WHERE m.conversation_id=? AND `
	args := []any{string(r.grant.Version), string(p.ActorID), string(q.ConversationID), string(q.TargetID), r.floorSeq, r.seq}
	rows, e := r.tx.QueryContext(ctx, selectSQL+`m.id=? AND m.seq>? AND m.seq<=? LIMIT 1`, args...)
	if e != nil {
		return HistoryPage{}, ports.ErrUnavailable
	}
	var target candidate
	found := rows.Next()
	if found {
		target, e = scanCandidate(rows, false)
	}
	rowError := rows.Err()
	rows.Close()
	if e != nil || rowError != nil {
		return HistoryPage{}, ports.ErrUnavailable
	}
	if !found || !target.visible {
		return HistoryPage{}, policy.ErrDenied
	}
	page := emptyPage(r)
	limit := limitFor(q)
	before := (limit - 1) / 2
	after := limit - before - 1
	fetch := func(pre bool, n int) ([]candidate, error) {
		cmp := `m.seq>? AND m.seq<=? ORDER BY m.seq ASC`
		lo, hi := number(target.view.Seq), r.seq
		if pre {
			cmp = `m.seq>? AND m.seq<? ORDER BY m.seq DESC`
			lo, hi = r.floorSeq, number(target.view.Seq)
		}
		rows, err := r.tx.QueryContext(ctx, selectSQL+cmp+` LIMIT ?`, string(r.grant.Version), string(p.ActorID), string(q.ConversationID), lo, hi, n)
		if err != nil {
			return nil, ports.ErrUnavailable
		}
		out := make([]candidate, 0, n)
		for rows.Next() {
			v, err := scanCandidate(rows, false)
			if err != nil {
				rows.Close()
				return nil, err
			}
			out = append(out, v)
		}
		err = rows.Err()
		rows.Close()
		if err != nil {
			return nil, ports.ErrUnavailable
		}
		return out, nil
	}
	prior, e := fetch(true, before+1)
	if e != nil {
		return HistoryPage{}, e
	}
	page.HasMore = len(prior) > before
	if page.HasMore {
		prior = prior[:before]
	}
	next := number(target.view.Seq) - 1
	if len(prior) > 0 {
		next = number(prior[len(prior)-1].view.Seq) - 1
	}
	for i := len(prior) - 1; i >= 0; i-- {
		if prior[i].visible {
			page.Messages = append(page.Messages, prior[i].view)
		}
	}
	page.Messages = append(page.Messages, target.view)
	if after > 0 {
		following, e := fetch(false, after)
		if e != nil {
			return HistoryPage{}, e
		}
		for _, v := range following {
			if v.visible {
				page.Messages = append(page.Messages, v.view)
			}
		}
	}
	c := r.cursor
	if page.HasMore {
		c.Mode = "history"
		c.Position = decimal(next)
		c.Pending = true
		page.HistoryCursor = s.sign(c)
	}
	// Locate is not bootstrap: it must not certify that newer messages were
	// consumed. The caller retains its existing independent sync/change cursors.
	page.NextSync = ""
	page.NextChange = ""
	return s.finish(ctx,p, r, page)
}
