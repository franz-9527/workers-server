package dependencies

import (
	"context"
	"encoding/base64"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/avpipeline"
	"github.com/franz-9527/workers/chat/internal/imagepipeline"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/ports"
	"log/slog"
	"net/http"
	"os"
	"regexp"
	"time"
)

type RemindersConfig struct {
	Enabled     bool   `json:"enabled"`
	PHPEndpoint string `json:"php_endpoint"`
	KeyID       string `json:"key_id"`
	KeyEnv      string `json:"key_env"`
}
type publicationRelay struct{ runtime *Runtime }

func (p publicationRelay) Committed(m ports.CommittedMessage) {
	if p.runtime.Socket != nil {
		p.runtime.Socket.Committed(m)
	}
}
func (p publicationRelay) Changed(m ports.CommittedMessage) {
	if p.runtime.Socket != nil {
		p.runtime.Socket.Changed(m)
	}
}

// The only process composition: HTTP/WSS share the exact same sender; media,
// governance and reminders share its durable DB and current authority locks.
func (r *Runtime) EnableMessaging(c message.Config, images imagepipeline.Config, reminders RemindersConfig) error {
	if !c.Enabled {
		if images.Enabled || reminders.Enabled {
			return ports.ErrInvalid
		}
		return nil
	}
	if r.sql == nil || r.mode != "disabled" || r.Identity == nil || r.Conversations == nil || r.Moderation == nil || r.History == nil {
		return ports.ErrInvalid
	}
	base, err := message.NewService(r.sql, r.Conversations, r.Moderation, min(r.settings.Timeout(), 3*time.Second))
	if err != nil {
		return err
	}
	base = base.WithPublisher(publicationRelay{r})
	media, err := imagepipeline.Build(images, r.environment, base)
	if err != nil {
		return err
	}
	base = base.WithMedia(media)
	r.Messages = base
	r.Media = media
	r.History.ConfigureMedia(media != nil, false, false)
	r.MessageHTTP, err = message.NewHandler(base, r.Identity, 16)
	if err != nil {
		return err
	}
	if media != nil {
		r.MediaHTTP, err = message.NewMediaHandler(media, r.Identity, 8)
		if err != nil {
			return err
		}
	}
	g, err := message.NewGovernanceService(base, r.History, media, r.environment)
	if err != nil {
		return err
	}
	r.Governance = g
	r.GovernanceHTTP, err = message.NewGovernanceHandler(g, r.Identity, 8)
	if err != nil {
		return err
	}
	if reminders.Enabled {
		if r.redis == nil || !regexp.MustCompile("^CHAT_[A-Z0-9_]{1,60}$").MatchString(reminders.KeyEnv) {
			return ports.ErrInvalid
		}
		keyText := os.Getenv(reminders.KeyEnv)
		key, e := base64.StdEncoding.Strict().DecodeString(keyText)
		if e != nil || len(key) != 32 || base64.StdEncoding.EncodeToString(key) != keyText {
			return ports.ErrInvalid
		}
		store, e := auth.NewRedisStore(r.redis, r.environment, r.settings.Redis.KeyPrefix)
		if e != nil {
			return e
		}
		internal, e := auth.NewInternal(r.environment, map[string][]byte{reminders.KeyID: key}, store, time.Second)
		if e != nil {
			return e
		}
		h, e := NewHTTP(4, 3*time.Second, 8192)
		if e != nil {
			return e
		}
		r.reminderTransport = h
		n, e := NewReminderHTTP(h, internal, reminders.PHPEndpoint, reminders.KeyID)
		if e != nil {
			return e
		}
		r.Reminders, e = message.NewReminderService(base, n)
		if e != nil {
			return e
		}
		r.ReminderHTTP, e = message.NewReminderInternalHandler(r.Reminders, internal)
		if e != nil {
			return e
		}
	}
	return nil
}
func (r *Runtime) RunMessaging(ctx context.Context, logger *slog.Logger) {
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	for {
		if r.OperationsReady() {
			pass, cancel := context.WithTimeout(ctx, 15*time.Second)
			if r.Media != nil {
				for _, work := range []func(context.Context) error{r.Media.ProcessOne, r.Media.Advance, r.Media.CleanOne} {
					if err := work(pass); err != nil {
						logger.Warn("media_pass_unavailable", "code", "CHAT_MEDIA_UNAVAILABLE")
						break
					}
				}
			}
			if err := r.Governance.Maintain(pass, r.Reminders); err != nil {
				logger.Warn("governance_pass_unavailable", "code", "CHAT_GOVERNANCE_UNAVAILABLE")
			}
			cancel()
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}

var _ http.Handler = (*message.GovernanceHandler)(nil)

func (r *Runtime) EnableAV(c avpipeline.Config) error {
	if !c.Enabled {
		return c.Validate()
	}
	if r.Messages == nil || r.Moderation == nil || r.History == nil {
		return ports.ErrInvalid
	}
	service, callback, e := avpipeline.Build(c, r.environment, r.Messages, r.Moderation)
	if e != nil {
		return e
	}
	if e = r.Messages.AttachAV(service); e != nil {
		return e
	}
	handler, e := message.NewAVHandler(service, r.Identity, 8)
	if e != nil {
		return e
	}
	r.AV = service
	r.AVHTTP = handler
	r.AVCallback = callback
	r.History.ConfigureMedia(r.Media != nil, c.Audio, c.Video)
	return nil
}
func (r *Runtime) RunAV(ctx context.Context) {
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	for {
		if ctx.Err() != nil {
			return
		}
		if r.OperationsReady() {
			pass, cancel := context.WithTimeout(ctx, 80*time.Second)
			if r.AV != nil {
				_ = r.AV.ProcessOne(pass)
				_ = r.AV.CleanOne(pass)
			}
			if r.Messages != nil {
				_ = r.Messages.ApplyStorageOne(pass)
				_ = r.Messages.CleanCloudOne(pass)
				if time.Now().Unix()%60 == 0 {
					_ = r.Messages.TierOne(pass)
				}
			}
			cancel()
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}
