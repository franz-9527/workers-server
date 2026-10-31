package dependencies

import "github.com/franz-9527/workers/chat/internal/message"
import "github.com/franz-9527/workers/chat/internal/ports"

func (r *Runtime) EnableHistory(c message.HistoryConfig) error {
	key, e := c.Key()
	if e != nil {
		return e
	}
	if !c.Enabled {
		return nil
	}
	if r.sql == nil || r.mode != "disabled" || r.Identity == nil || r.Conversations == nil {
		return ports.ErrInvalid
	}
	r.History, e = message.NewHistoryService(r.sql, r.Conversations, r.environment, key, r.settings.Timeout())
	if e != nil {
		return e
	}
	r.HistoryHTTP, e = message.NewHistoryHandler(r.History, r.Identity, 16)
	return e
}
