package dependencies

import (
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// EnableIdentity only enables identity exchange, never policy/message providers.
func (r *Runtime) EnableIdentity(c auth.Config) error {
	if !c.Enabled {
		return nil
	}
	if r.sql == nil || r.mode != "disabled" {
		return ports.ErrInvalid
	}
	v, e := c.Verifier(r.environment)
	if e != nil {
		return e
	}
	source, e := auth.NewMySQLSource(r.sql)
	if e != nil {
		return e
	}
	var store auth.OneTimeStore
	if r.redis != nil {
		store, e = auth.NewRedisStore(r.redis, r.environment, r.settings.Redis.KeyPrefix)
		if e != nil {
			return e
		}
	}
	r.Identity, e = auth.NewService(v, source, store, r.settings.ExternalConcurrency, r.settings.Timeout())
	if e != nil {
		return e
	}
	r.Ports.Identity = r.Identity
	r.IdentityHTTP, e = auth.NewHandler(r.Identity, r.settings.ExternalConcurrency)
	return e
}
