package dependencies

import (
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/ports"
	"net/http"
)

// Explicit composition for the S21-S23 isolated/release composition root. No
// call is made by the still-closed production bootstrap.
type Governance struct {
	Service      *message.GovernanceService
	HTTP         http.Handler
	Reminders    *message.ReminderService
	InternalHTTP http.Handler
}

func (r *Runtime) ComposeGovernance(base *message.Service, media *message.MediaService, n ports.Notification, internal *auth.InternalAuth) (*Governance, error) {
	if r.History == nil || r.Identity == nil || base == nil || n == nil || internal == nil {
		return nil, ports.ErrInvalid
	}
	g, err := message.NewGovernanceService(base, r.History, media, r.environment)
	if err != nil {
		return nil, err
	}
	h, err := message.NewGovernanceHandler(g, r.Identity, 8)
	if err != nil {
		return nil, err
	}
	reminders, err := message.NewReminderService(base, n)
	if err != nil {
		return nil, err
	}
	ih, err := message.NewReminderInternalHandler(reminders, internal)
	if err != nil {
		return nil, err
	}
	return &Governance{g, h, reminders, ih}, nil
}
