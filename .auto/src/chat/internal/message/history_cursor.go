package message

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrResync = errors.New("CHAT_RESYNC_REQUIRED")

const cursorTTL = 10 * time.Minute

type HistoryConfig struct {
	Enabled      bool   `json:"enabled"`
	CursorKeyEnv string `json:"cursor_key_env"`
}

var cursorEnv = regexp.MustCompile(`^CHAT_[A-Z0-9_]{1,58}$`)

func (c HistoryConfig) Key() ([]byte, error) {
	if !c.Enabled {
		if c.CursorKeyEnv != "" {
			return nil, ports.ErrInvalid
		}
		return nil, nil
	}
	if !cursorEnv.MatchString(c.CursorKeyEnv) {
		return nil, ports.ErrInvalid
	}
	key, e := hex.DecodeString(os.Getenv(c.CursorKeyEnv))
	if e != nil || len(key) != 32 {
		return nil, ports.ErrInvalid
	}
	return key, nil
}

type historyCursor struct {
	Schema       int      `json:"v"`
	Mode         string   `json:"mode"`
	Binding      string   `json:"binding"`
	Conversation ports.ID `json:"conversation"`
	Policy       ports.ID `json:"policy"`
	Member       string   `json:"member"`
	Seq          ports.ID `json:"seq"`
	Change       ports.ID `json:"change"`
	Position     ports.ID `json:"position"`
	Pending      bool     `json:"pending"`
	Issued       int64    `json:"issued"`
	Expiry       int64    `json:"expiry"`
}

func bindingFor(env string, p ports.Principal) string {
	sum := sha256.Sum256([]byte(strings.Join([]string{env, string(p.ActorID), p.Kind, string(p.SourceID), p.SessionID, p.SessionVersion}, "\n")))
	return hex.EncodeToString(sum[:])
}
func number(id ports.ID) uint64 { n, _ := strconv.ParseUint(string(id), 10, 64); return n }
func decimal(n uint64) ports.ID { return ports.ID(strconv.FormatUint(n, 10)) }
func (s *HistoryService) sign(c historyCursor) string {
	body, _ := json.Marshal(c)
	mac := hmac.New(sha256.New, s.key)
	mac.Write(body)
	return base64.RawURLEncoding.EncodeToString(body) + "." + base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}
func (s *HistoryService) parse(token, mode string) (historyCursor, error) {
	var c historyCursor
	if len(token) > 2048 {
		return c, ErrResync
	}
	parts := strings.Split(token, ".")
	if len(parts) != 2 {
		return c, ErrResync
	}
	body, e := base64.RawURLEncoding.Strict().DecodeString(parts[0])
	if e != nil {
		return c, ErrResync
	}
	signature, e := base64.RawURLEncoding.Strict().DecodeString(parts[1])
	if e != nil {
		return c, ErrResync
	}
	mac := hmac.New(sha256.New, s.key)
	mac.Write(body)
	if !hmac.Equal(signature, mac.Sum(nil)) {
		return c, ErrResync
	}
	if json.Unmarshal(body, &c) != nil || c.Schema != 1 || c.Mode != mode || !validID(c.Conversation) || !validID(c.Policy) || !c.Seq.Valid() || !c.Change.Valid() || !c.Position.Valid() || c.Member == "" || c.Expiry-c.Issued != int64(cursorTTL/time.Second) || c.Issued > time.Now().Unix()+5 || c.Expiry <= time.Now().Unix() {
		return c, ErrResync
	}
	return c, nil
}
