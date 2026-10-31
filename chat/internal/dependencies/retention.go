package dependencies

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/ports"
	"log/slog"
	"time"
)

func (r *Runtime) EnableRetention(c message.RetentionConfig) error {
	if c.Validate() != nil {
		return ports.ErrInvalid
	}
	if !c.Enabled {
		return nil
	}
	if r.sql == nil || r.mode != "disabled" {
		return ports.ErrInvalid
	}
	var err error
	r.Retention, err = message.NewRetention(r.sql, r.environment, c)
	return err
}
func (r *Runtime) MaintainRetention(ctx context.Context, logger *slog.Logger) {
	after := ports.ID("0")
	ticker := time.NewTicker(time.Minute)
	defer ticker.Stop()
	for {
		if r.OperationsReady() {
			pass, cancel := context.WithTimeout(ctx, 10*time.Second)
			out, err := r.Retention.Pass(pass, after)
			cancel()
			if err != nil {
				logger.Warn("retention_unavailable", "code", "CHAT_RETENTION_UNAVAILABLE")
			} else {
				after = out.Next
				logger.Info("retention_pass", "conversations", out.Conversations, "messages", out.Messages, "changes", out.Changes)
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}
