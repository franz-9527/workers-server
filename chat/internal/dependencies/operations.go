package dependencies

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"time"
)

type OperationsConfig struct {
	GateFile string `json:"gate_file"`
	Epoch    string `json:"epoch"`
}

var recoveryEpoch = regexp.MustCompile("^[0-9a-f]{64}$")

func (c OperationsConfig) Validate(required bool) bool {
	if c.GateFile == "" && c.Epoch == "" {
		return !required
	}
	return filepath.IsAbs(c.GateFile) && recoveryEpoch.MatchString(c.Epoch)
}

// Gate belongs to independent recovery control, never a DB backup or release directory.
func (c OperationsConfig) Ready(env string) bool {
	if c.GateFile == "" {
		return c.Epoch == ""
	}
	info, err := os.Lstat(c.GateFile)
	if err != nil || !info.Mode().IsRegular() || info.Size() > 1024 || info.Mode().Perm()&0022 != 0 {
		return false
	}
	file, err := os.Open(c.GateFile)
	if err != nil {
		return false
	}
	defer file.Close()
	raw, err := io.ReadAll(io.LimitReader(file, 1025))
	if err != nil || len(raw) > 1024 {
		return false
	}
	var mark struct {
		Schema      int    `json:"schema"`
		Environment string `json:"environment"`
		Epoch       string `json:"epoch"`
		State       string `json:"state"`
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	if d.Decode(&mark) != nil {
		return false
	}
	var rest any
	if d.Decode(&rest) != io.EOF {
		return false
	}
	canonical, _ := json.Marshal(mark)
	var compact bytes.Buffer
	if json.Compact(&compact, raw) != nil || !bytes.Equal(canonical, compact.Bytes()) {
		return false
	}
	return mark.Schema == 1 && mark.Environment == env && mark.Epoch == c.Epoch && mark.State == "ready"
}
func (r *Runtime) ConfigureOperations(c OperationsConfig) { r.operations = c }
func (r *Runtime) OperationsReady() bool                  { return r.operations.Ready(r.environment) }
func (r *Runtime) RunProjection(ctx context.Context, logger *slog.Logger) {
	timer := time.NewTicker(time.Second)
	defer timer.Stop()
	for {
		if r.OperationsReady() {
			pass, cancel := context.WithTimeout(ctx, 5*time.Second)
			out, err := r.Conversations.Poll(pass, 100)
			cancel()
			if err != nil {
				logger.Warn("projection_unavailable", "code", "CHAT_PROJECTION_UNAVAILABLE")
			} else if out.Applied > 0 {
				logger.Info("projection_pass", "applied", out.Applied)
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}
	}
}
func (r *Runtime) Metrics(w http.ResponseWriter, q *http.Request) {
	w.Header().Set("Content-Type", "text/plain; version=0.0.4")
	w.Header().Set("Cache-Control", "no-store")
	if q.Method != http.MethodGet && q.Method != http.MethodHead {
		w.Header().Set("Allow", "GET, HEAD")
		w.WriteHeader(405)
		return
	}
	if q.Method == http.MethodHead {
		return
	}
	ready := 0
	if r.OperationsReady() {
		ready = 1
	}
	s := r.Snapshot()
	mysqlReady, redisReady := 0, 0
	if s.MySQL.State == "ready" {
		mysqlReady = 1
	}
	if s.Redis.State == "ready" {
		redisReady = 1
	}
	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	_, _ = fmt.Fprintf(w, "chat_recovery_ready %d\nchat_mysql_ready %d\nchat_redis_ready %d\nchat_goroutines %d\nchat_heap_bytes %d\n", ready, mysqlReady, redisReady, runtime.NumGoroutine(), m.HeapAlloc)
	if r.sql != nil {
		d := r.sql.Stats()
		_, _ = fmt.Fprintf(w, "chat_db_open %d\nchat_db_in_use %d\nchat_db_wait_total %d\n", d.OpenConnections, d.InUse, d.WaitCount)
	}
}

type recoveryError struct{}

func (recoveryError) Error() string              { return "CHAT_RECOVERY_BLOCKED" }
func (recoveryError) PublicCode() (string, bool) { return "CHAT_RECOVERY_BLOCKED", true }

type guardedCommands struct {
	runtime *Runtime
	inner   socket.Commands
}

func (g guardedCommands) Execute(ctx context.Context, p ports.Principal, kind string, raw json.RawMessage) (string, any, error) {
	if !g.runtime.OperationsReady() {
		return "", nil, recoveryError{}
	}
	return g.inner.Execute(ctx, p, kind, raw)
}

type guardedPolicy struct {
	runtime *Runtime
	inner   ports.RealtimePolicy
}

func (g guardedPolicy) Authorize(ctx context.Context, q ports.PolicyRequest) (ports.Grant, error) {
	if !g.runtime.OperationsReady() {
		return ports.Grant{}, recoveryError{}
	}
	return g.inner.Authorize(ctx, q)
}
func (g guardedPolicy) MemberCandidates(ctx context.Context, id ports.ID) ([]ports.ID, error) {
	if !g.runtime.OperationsReady() {
		return nil, recoveryError{}
	}
	return g.inner.MemberCandidates(ctx, id)
}
