package conversation

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// Projections only select candidates. Every socket output rechecks the current
// PHP guard, login, epochs and membership; this list never grants permission.
func (s *Store) MemberCandidates(ctx context.Context, id ports.ID) ([]ports.ID, error) {
	if !positive(id) {
		return nil, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	rows, e := s.db.QueryContext(ctx, `SELECT CAST(actor_id AS CHAR) FROM chat_members WHERE conversation_id=? AND can_read=1 ORDER BY actor_id LIMIT 65`, string(id))
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	defer rows.Close()
	ids := []ports.ID{}
	for rows.Next() {
		var actor ports.ID
		if rows.Scan(&actor) != nil || !positive(actor) {
			return nil, ports.ErrUnavailable
		}
		ids = append(ids, actor)
	}
	if rows.Err() != nil || len(ids) > 64 {
		return nil, ports.ErrUnavailable
	}
	return ids, nil
}
