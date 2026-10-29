package dependencies

import (
	"context"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// Scenario is an explicit isolated fixture, not a permissive fallback. The
// runtime uses an empty scenario: no identity/permission/safety is fabricated.
type Scenario struct {
	Credential                   string
	Principal                    ports.Principal
	Grant                        ports.Grant
	Safety                       ports.SafetyResult
	Media                        ports.MediaResult
	Receipt                      ports.Receipt
	Delay                        time.Duration
	Failure                      error
	ConversationID               ports.ID
	Fingerprint, Text, ObjectKey string
	Reminder                     ports.Reminder
}
type provider struct {
	scenario    Scenario
	mode        string
	gate        chan struct{}
	timeout     time.Duration
	environment string
}

func NewProviders(environment, mode string, concurrency int, timeout time.Duration, s Scenario) (ports.Set, error) {
	if concurrency < 1 || concurrency > 32 || timeout <= 0 || timeout > 5*time.Second || (environment != "development" && environment != "test" && environment != "production") || (mode != "disabled" && mode != "fake") || (mode == "fake" && environment != "development" && environment != "test") {
		return ports.Set{}, ports.ErrInvalid
	}
	if s.Safety.Finding != nil {
		copy := *s.Safety.Finding
		s.Safety.Finding = &copy
	}
	s.Principal.Scopes = append([]string(nil), s.Principal.Scopes...)
	p := &provider{scenario: s, mode: mode, gate: make(chan struct{}, concurrency), timeout: timeout, environment: environment}
	return ports.Set{Identity: p, Policy: p, Safety: p, Media: p, Notification: p}, nil
}
func (p *provider) call(ctx context.Context, fn func() error) error {
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if p.mode != "fake" {
		return ports.ErrUnavailable
	}
	select {
	case p.gate <- struct{}{}:
		defer func() { <-p.gate }()
	default:
		return ports.ErrBusy
	}
	ctx, cancel := context.WithTimeout(ctx, p.timeout)
	defer cancel()
	if p.scenario.Delay > 0 {
		t := time.NewTimer(p.scenario.Delay)
		defer t.Stop()
		select {
		case <-t.C:
		case <-ctx.Done():
			return ctx.Err()
		}
	}
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if p.scenario.Failure != nil {
		return ports.ErrUnavailable
	}
	return fn()
}
func (p *provider) Authenticate(ctx context.Context, credential string) (ports.Principal, error) {
	var result ports.Principal
	err := p.call(ctx, func() error {
		if p.scenario.Credential == "" || credential != p.scenario.Credential || !p.actor(p.scenario.Principal) {
			return ports.ErrUnavailable
		}
		result = p.scenario.Principal
		result.Scopes = append([]string(nil), result.Scopes...)
		return nil
	})
	return result, err
}
func (p *provider) Authorize(ctx context.Context, q ports.PolicyRequest) (ports.Grant, error) {
	var result ports.Grant
	err := p.call(ctx, func() error {
		if !p.actor(q.Actor) || q.ConversationID != p.scenario.ConversationID || !q.ConversationID.Valid() || !p.scenario.Grant.Version.Valid() || p.scenario.Grant.ScopeKey == "" {
			return ports.ErrUnavailable
		}
		result = p.scenario.Grant
		return nil
	})
	return result, err
}
func (p *provider) CheckText(ctx context.Context, q ports.TextRequest) (ports.SafetyResult, error) {
	var result ports.SafetyResult
	err := p.call(ctx, func() error {
		if !p.actor(q.Actor) || q.Fingerprint == "" || q.Fingerprint != p.scenario.Fingerprint || q.Text != p.scenario.Text || q.ConversationID != p.scenario.ConversationID || (p.scenario.Safety.Decision != "passed" && p.scenario.Safety.Decision != "blocked" && p.scenario.Safety.Decision != "review") || p.scenario.Safety.RuleVersion == "" {
			return ports.ErrUnavailable
		}
		result = p.scenario.Safety
		if result.Finding != nil {
			copy := *result.Finding
			result.Finding = &copy
		}
		return nil
	})
	return result, err
}
func (p *provider) Inspect(ctx context.Context, q ports.MediaRequest) (ports.MediaResult, error) {
	var result ports.MediaResult
	err := p.call(ctx, func() error {
		s := p.scenario.Media
		if !p.actor(q.Actor) || q.ObjectKey == "" || q.ObjectKey != p.scenario.ObjectKey || q.ObjectVersion == "" || q.ObjectVersion != s.ObjectVersion || q.ManifestHash == "" || q.ManifestHash != s.ManifestHash || (s.Decision != "passed" && s.Decision != "blocked") {
			return ports.ErrUnavailable
		}
		result = s
		return nil
	})
	return result, err
}
func (p *provider) Submit(ctx context.Context, q ports.Reminder) (ports.Receipt, error) {
	var result ports.Receipt
	err := p.call(ctx, func() error {
		if q != p.scenario.Reminder {
			return ports.ErrInvalid
		}
		if q.OperationID == "" || q.OperationID != p.scenario.Receipt.OperationID || (p.scenario.Receipt.State != "accepted" && p.scenario.Receipt.State != "provider_unknown") {
			return ports.ErrUnavailable
		}
		result = p.scenario.Receipt
		return nil
	})
	return result, err
}

func (p *provider) actor(a ports.Principal) bool {
	b := p.scenario.Principal
	return a.ActorID.Valid() && a.ActorID != "0" && a.ActorID == b.ActorID && a.SessionID != "" && a.SessionID == b.SessionID && a.Environment == p.environment && a.Environment == b.Environment
}
