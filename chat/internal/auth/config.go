package auth

import (
	"crypto/ed25519"
	"encoding/base64"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type Config struct {
	Enabled    bool              `json:"enabled"`
	PublicKeys map[string]string `json:"public_keys,omitempty"`
}

func (c Config) Verifier(environment string) (*Verifier, error) {
	keys := map[string]ed25519.PublicKey{}
	for id, encoded := range c.PublicKeys {
		k, e := base64.StdEncoding.Strict().DecodeString(encoded)
		if e != nil || len(k) != 32 || base64.StdEncoding.EncodeToString(k) != encoded {
			return nil, ports.ErrInvalid
		}
		keys[id] = ed25519.PublicKey(k)
	}
	return NewVerifier(environment, keys)
}
