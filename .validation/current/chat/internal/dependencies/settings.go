package dependencies

import (
	"net"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type Settings struct {
	OperationTimeout    string        `json:"operation_timeout"`
	ProbeInterval       string        `json:"probe_interval"`
	ExternalConcurrency int           `json:"external_concurrency"`
	MySQL               MySQLSettings `json:"mysql"`
	Redis               RedisSettings `json:"redis"`
}
type MySQLSettings struct {
	Enabled              bool   `json:"enabled"`
	Address              string `json:"address"`
	Database             string `json:"database"`
	Username             string `json:"username"`
	PasswordEnv          string `json:"password_env"`
	SharedAppCredentials bool   `json:"shared_app_credentials"`
	MaxOpenServe         int    `json:"max_open_serve"`
	MaxOpenWorker        int    `json:"max_open_worker"`
	MaxIdle              int    `json:"max_idle"`
}
type RedisSettings struct {
	Enabled     bool   `json:"enabled"`
	Address     string `json:"address"`
	KeyPrefix   string `json:"key_prefix"`
	PasswordEnv string `json:"password_env"`
	PoolServe   int    `json:"pool_serve"`
	PoolWorker  int    `json:"pool_worker"`
}

func Defaults() Settings {
	return Settings{OperationTimeout: "500ms", ProbeInterval: "10s", ExternalConcurrency: 4,
		MySQL: MySQLSettings{Address: "127.0.0.1:3306", PasswordEnv: "CHAT_MYSQL_PASSWORD", MaxOpenServe: 4, MaxOpenWorker: 2, MaxIdle: 1},
		Redis: RedisSettings{Address: "127.0.0.1:6379", PoolServe: 2, PoolWorker: 1}}
}

var identifier = regexp.MustCompile(`^[a-zA-Z0-9_]{1,64}$`)
var sharedDatabaseIdentifier = regexp.MustCompile(`^[a-zA-Z0-9_$.-]{1,64}$`)
var sharedUsername = regexp.MustCompile(`^[a-zA-Z0-9_.@$-]{1,32}$`)
var secretName = regexp.MustCompile(`^CHAT_[A-Z0-9_]{1,60}$`)

func (s Settings) Validate(environment string) error {
	if environment != "development" && environment != "test" && environment != "production" {
		return ports.ErrInvalid
	}
	t, e := time.ParseDuration(s.OperationTimeout)
	i, ie := time.ParseDuration(s.ProbeInterval)
	if e != nil || ie != nil || t < 50*time.Millisecond || t > 5*time.Second || i < time.Second || i > time.Minute || i < t || s.ExternalConcurrency < 1 || s.ExternalConcurrency > 32 {
		return ports.ErrInvalid
	}
	m, r := s.MySQL, s.Redis
	if m.MaxOpenServe < 1 || m.MaxOpenWorker < 1 || m.MaxOpenServe+m.MaxOpenWorker > 32 || m.MaxIdle < 0 || m.MaxIdle > m.MaxOpenServe || m.MaxIdle > m.MaxOpenWorker {
		return ports.ErrInvalid
	}
	if r.PoolServe < 1 || r.PoolWorker < 1 || r.PoolServe+r.PoolWorker > 16 {
		return ports.ErrInvalid
	}
	if m.Enabled {
		if !loopback(m.Address) || !secretName.MatchString(m.PasswordEnv) {
			return ports.ErrInvalid
		}
		if m.SharedAppCredentials {
			// Managed deployments deliberately reuse the already isolated PHP
			// site's database and credential. The source PHP environment is the
			// authority for prod/test separation; no second chat database is required.
			if !sharedDatabaseIdentifier.MatchString(m.Database) || !sharedUsername.MatchString(m.Username) {
				return ports.ErrInvalid
			}
		} else {
			prefix := map[string]string{"development": "bixuan_dev_", "test": "bixuan_test_"}[environment]
			if !identifier.MatchString(m.Database) || !identifier.MatchString(m.Username) || len(m.Username) > 32 || !strings.HasPrefix(m.Username, "bxchat_"+environment+"_") {
				return ports.ErrInvalid
			}
			if prefix != "" && !strings.HasPrefix(m.Database, prefix) {
				return ports.ErrInvalid
			}
			if environment == "production" && (strings.HasPrefix(m.Database, "bixuan_dev_") || strings.HasPrefix(m.Database, "bixuan_test_")) {
				return ports.ErrInvalid
			}
		}
	}
	if r.Enabled && (!loopback(r.Address) || r.KeyPrefix != "bxchat:"+environment+":" || (r.PasswordEnv != "" && !secretName.MatchString(r.PasswordEnv))) {
		return ports.ErrInvalid
	}
	return nil
}
func loopback(address string) bool {
	host, p, e := net.SplitHostPort(address)
	n, ne := strconv.Atoi(p)
	ip := net.ParseIP(host)
	return e == nil && ne == nil && n > 0 && n <= 65535 && ip != nil && ip.IsLoopback()
}
func (s Settings) Timeout() time.Duration  { t, _ := time.ParseDuration(s.OperationTimeout); return t }
func (s Settings) Interval() time.Duration { t, _ := time.ParseDuration(s.ProbeInterval); return t }
func password(name string, required bool) (string, error) {
	if name == "" && !required {
		return "", nil
	}
	value, ok := os.LookupEnv(name)
	if !ok || value == "" {
		return "", ports.ErrInvalid
	}
	return value, nil
}
