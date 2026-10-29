package auth

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"sync"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

// OneTimeStore must implement linearizable put-if-absent and take-and-delete.
// The key is already hashed/namespaced by its adapter; outages fail closed.
type OneTimeStore interface {
	Put(context.Context, string, string, time.Duration) (bool, error)
	Take(context.Context, string) (string, error)
}

type Service struct {
	verifier *Verifier
	source   SessionSource
	store    OneTimeStore
	gate     chan struct{}
	timeout  time.Duration
}

func NewService(v *Verifier, source SessionSource, store OneTimeStore, concurrency int, timeout time.Duration) (*Service, error) {
	if v == nil || source == nil || concurrency < 1 || concurrency > 32 || timeout < 50*time.Millisecond || timeout > 5*time.Second {
		return nil, ports.ErrInvalid
	}
	return &Service{verifier: v, source: source, store: store, gate: make(chan struct{}, concurrency), timeout: timeout}, nil
}

func (s *Service) Authenticate(ctx context.Context, token string) (ports.Principal, error) {
	c, e := s.Check(ctx, token)
	if e != nil {
		return ports.Principal{}, e
	}
	return ports.Principal{ActorID: c.ActorID, SessionID: c.SessionID, Environment: c.Environment, Scopes: append([]string(nil), c.Scopes...), Kind: c.Kind, SourceID: c.SourceID, SessionVersion: c.Version, ExpiresAt: time.Unix(c.ExpiresAt, 0).UTC()}, nil
}

func (s *Service) Check(ctx context.Context, token string) (Claims, error) {
	c, e := s.verifier.Verify(token, time.Now())
	if e != nil {
		return Claims{}, e
	}
	if e = s.Validate(ctx, c); e != nil {
		return Claims{}, e
	}
	return c, nil
}

func (s *Service) Validate(ctx context.Context, c Claims) error {
	if ctx.Err() != nil {
		return ports.ErrUnavailable
	}
	if c.Environment != s.verifier.environment || c.ExpiresAt <= time.Now().Unix() {
		return ErrUnauthorized
	}
	select {
	case s.gate <- struct{}{}:
		defer func() { <-s.gate }()
	default:
		return ports.ErrBusy
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	err := s.source.Validate(ctx, c)
	if err != nil {
		if err == ErrUnauthorized {
			return err
		}
		return ports.ErrUnavailable
	}
	if ctx.Err() != nil {
		return ports.ErrUnavailable
	}
	if c.ExpiresAt <= time.Now().Unix() {
		return ErrUnauthorized
	}
	return nil
}

func randomID() (string, error) {
	var b [32]byte
	if _, e := rand.Read(b[:]); e != nil {
		return "", ports.ErrUnavailable
	}
	return hex.EncodeToString(b[:]), nil
}
func digest(s string) string { h := sha256.Sum256([]byte(s)); return hex.EncodeToString(h[:]) }

func (s *Service) IssueTicket(ctx context.Context, token string) (string, time.Time, error) {
	c, e := s.Check(ctx, token)
	if e != nil {
		return "", time.Time{}, e
	}
	if s.store == nil {
		return "", time.Time{}, ports.ErrUnavailable
	}
	ticket, e := randomID()
	if e != nil {
		return "", time.Time{}, e
	}
	exp := time.Now().Add(30 * time.Second)
	if limit := time.Unix(c.ExpiresAt, 0); exp.After(limit) {
		exp = limit
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	ok, e := s.store.Put(ctx, "ticket:"+digest(ticket), token, time.Until(exp))
	if e != nil || !ok {
		return "", time.Time{}, ports.ErrUnavailable
	}
	return ticket, exp, nil
}

func (s *Service) ConsumeTicket(ctx context.Context, ticket string) (Claims, error) {
	if !hex64.MatchString(ticket) {
		return Claims{}, ErrUnauthorized
	}
	if s.store == nil {
		return Claims{}, ports.ErrUnavailable
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	token, e := s.store.Take(ctx, "ticket:"+digest(ticket))
	if e != nil {
		return Claims{}, ports.ErrUnavailable
	}
	if token == "" {
		return Claims{}, ErrUnauthorized
	}
	return s.Check(ctx, token)
}

// SocketIdentity is transport-independent; S10 must call Current before every
// protected command/push and close on expiry. Refresh cannot switch principals.
type SocketIdentity struct {
	mu     sync.Mutex
	claims Claims
}

func NewSocketIdentity(c Claims) *SocketIdentity {
	c.Scopes = append([]string(nil), c.Scopes...)
	return &SocketIdentity{claims: c}
}
func (s *SocketIdentity) Current(ctx context.Context, auth *Service) (Claims, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if e := auth.Validate(ctx, s.claims); e != nil {
		return Claims{}, e
	}
	c := s.claims
	c.Scopes = append([]string(nil), c.Scopes...)
	return c, nil
}
func (s *SocketIdentity) Refresh(ctx context.Context, auth *Service, token string) error {
	c, e := auth.Check(ctx, token)
	if e != nil {
		return e
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	old := s.claims
	if old.ExpiresAt <= time.Now().Unix() || c.ActorID != old.ActorID || c.Kind != old.Kind || c.SourceID != old.SourceID || c.SessionID != old.SessionID || c.Environment != old.Environment || c.Version != old.Version {
		return ErrUnauthorized
	}
	for _, scope := range c.Scopes {
		found := false
		for _, prior := range old.Scopes {
			if scope == prior {
				found = true
			}
		}
		if !found {
			return ErrUnauthorized
		}
	}
	if c.ExpiresAt < old.ExpiresAt {
		return ErrUnauthorized
	}
	s.claims = c
	return nil
}
