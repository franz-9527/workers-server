package dependencies

import (
	"context"
	"database/sql"
	"net/http"
	"sync"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
 "github.com/franz-9527/workers/chat/internal/calls"
	"github.com/franz-9527/workers/chat/internal/conversation"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/moderation"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
	"github.com/redis/go-redis/v9"
)

type Runtime struct {
 Calls *calls.Service
 CallsHTTP http.Handler
 CallsInternal http.Handler
 callTransport *HTTP
	Messages                                             *message.Service
	Media                                                *message.MediaService
	AV                                                   *message.AVService
	AVHTTP, AVCallback                                   http.Handler
	Governance                                           *message.GovernanceService
	Reminders                                            *message.ReminderService
	MessageHTTP, MediaHTTP, GovernanceHTTP, ReminderHTTP http.Handler
	reminderTransport                                    *HTTP
	operations                                           OperationsConfig
	Retention                                            *message.Retention
	Identity                                             *auth.Service
	IdentityHTTP                                         http.Handler
	Conversations                                        *conversation.Store
	Moderation                                           *moderation.Service
	History                                              *message.HistoryService
	HistoryHTTP                                          http.Handler
	Socket                                               *socket.Gateway
	sql                                                  *sql.DB
	redis                                                *redis.Client
	Ports                                                ports.Set
	settings                                             Settings
	environment, mode                                    string
	mu                                                   sync.RWMutex
	snapshot                                             Snapshot
	stopping                                             bool
}
type DependencyState struct {
	State    string `json:"state"`
	Required bool   `json:"required"`
	Code     string `json:"code,omitempty"`
}
type Snapshot struct {
	Stage               string          `json:"stage"`
	Scope               string          `json:"scope"`
	InfrastructureReady bool            `json:"infrastructure_ready"`
	BusinessEnabled     bool            `json:"business_enabled"`
	ProviderMode        string          `json:"provider_mode"`
	Simulated           bool            `json:"simulated"`
	CheckedAt           time.Time       `json:"checked_at"`
	MySQL               DependencyState `json:"mysql"`
	Redis               DependencyState `json:"redis"`
}

func Open(s Settings, environment, role, mode string) (*Runtime, error) {
	if s.Validate(environment) != nil || (role != "serve" && role != "worker") {
		return nil, ports.ErrInvalid
	}
	p, err := NewProviders(environment, mode, s.ExternalConcurrency, s.Timeout(), Scenario{})
	if err != nil {
		return nil, err
	}
	r := &Runtime{settings: s, environment: environment, mode: mode, Ports: p}
	if s.MySQL.Enabled {
		r.sql, err = openMySQL(s, role)
		if err != nil {
			r.Close()
			return nil, err
		}
	}
	if s.Redis.Enabled {
		r.redis, err = openRedis(s, role)
		if err != nil {
			r.Close()
			return nil, err
		}
	}
	r.snapshot = r.newSnapshot()
	return r, nil
}
func (r *Runtime) newSnapshot() Snapshot {
	scope := "infrastructure_only"
	if r.Messages != nil {
		scope = "business_dependencies"
	}
	return Snapshot{BusinessEnabled: r.Messages != nil && r.OperationsReady(), Stage: "S23", Scope: scope, ProviderMode: r.mode, Simulated: r.mode == "fake", MySQL: DependencyState{State: "disabled", Required: true}, Redis: DependencyState{State: "disabled", Required: false}}
}

// Projection enables internal order membership only. No public messages/routes.
func (r *Runtime) EnableConversations(c conversation.Config) error {
	if !c.Enabled {
		return nil
	}
	if r.sql == nil || r.mode != "disabled" || r.Identity == nil {
		return ports.ErrInvalid
	}
	var err error
	r.Conversations, err = conversation.NewStore(r.sql, r.environment, r.settings.Timeout())
	if err == nil {
		r.Ports.Policy = r.Conversations
	}
	return err
}

// Safety is independent from the still-closed message release gate.
func (r *Runtime) EnableSafety(c moderation.Config) error {
	if c.Validate() != nil {
		return ports.ErrInvalid
	}
	if !c.Enabled {
		return nil
	}
	if r.sql == nil || r.mode != "disabled" || r.Identity == nil || r.Conversations == nil {
		return ports.ErrInvalid
	}
	service, err := moderation.New(r.sql, r.environment, c, min(r.settings.ExternalConcurrency, 16), min(r.settings.Timeout(), 2*time.Second), nil)
	if err == nil {
		r.Moderation = service
		r.Ports.Safety = service
	}
	return err
}
func (r *Runtime) Refresh(ctx context.Context) Snapshot {
	s := r.newSnapshot()
	s.CheckedAt = time.Now().UTC()
	if r.sql != nil {
		probe, cancel := context.WithTimeout(ctx, r.settings.Timeout())
		err := checkMySQL(probe, r.sql, r.settings.MySQL.Database, r.environment)
		cancel()
		s.MySQL = state(err, true)
		s.InfrastructureReady = err == nil
	}
	if r.redis != nil {
		probe, cancel := context.WithTimeout(ctx, r.settings.Timeout())
		err := r.redis.Ping(probe).Err()
		cancel()
		s.Redis = state(err, false)
	}
	if !r.OperationsReady() {
		s.InfrastructureReady = false
	}
	r.mu.Lock()
	if !r.stopping {
		r.snapshot = s
	}
	r.mu.Unlock()
	return r.Snapshot()
}
func state(err error, required bool) DependencyState {
	if err == nil {
		return DependencyState{State: "ready", Required: required}
	}
	code := "CHAT_DEPENDENCY_UNAVAILABLE"
	if err == ports.ErrInvalid {
		code = "CHAT_DATABASE_CONTRACT_INVALID"
	}
	return DependencyState{State: "unavailable", Required: required, Code: code}
}
func (r *Runtime) Snapshot() Snapshot { r.mu.RLock(); defer r.mu.RUnlock(); return r.snapshot }
func (r *Runtime) Monitor(ctx context.Context) {
	t := time.NewTicker(r.settings.Interval())
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			r.Refresh(ctx)
		}
	}
}
func (r *Runtime) Stop() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.stopping = true
	r.snapshot.InfrastructureReady = false
}
func (r *Runtime) Close() {
 if r.callTransport!=nil {r.callTransport.Close()}
	r.Stop()
	if r.Socket != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		_ = r.Socket.Shutdown(ctx)
		cancel()
	}
	if r.reminderTransport != nil {
		r.reminderTransport.Close()
	}
	if r.Moderation != nil {
		r.Moderation.Close()
	}
	if r.redis != nil {
		_ = r.redis.Close()
	}
	if r.sql != nil {
		_ = r.sql.Close()
	}
}
