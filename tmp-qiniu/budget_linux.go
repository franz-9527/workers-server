//go:build linux

package imagepipeline

import (
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"syscall"
	"time"
)

type budgetState struct {
	Version     int    `json:"version"`
	Environment string `json:"environment"`
	Month       string `json:"month"`
	Download    int64  `json:"download_bytes"`
	Get         int64  `json:"get_requests"`
	Write       int64  `json:"write_requests"`
	Upload      int64  `json:"upload_bytes_total"`
}

var errBudget = errors.New("CHAT_MEDIA_BUDGET_UNAVAILABLE_OR_EXCEEDED")
var budgetMonth = regexp.MustCompile(`^\d{4}-(0[1-9]|1[0-2])$`)

func (q *Qiniu) budgetLimits() (budgetState, error) {
	switch q.config.Environment {
	case "production":
		return budgetState{Download: 6 << 30, Get: 600000, Write: 60000, Upload: 6 << 30}, nil
	case "test":
		return budgetState{Download: 2 << 30, Get: 200000, Write: 20000, Upload: 2 << 30}, nil
	default:
		return budgetState{}, errBudget
	}
}

func withinBudget(s, lim budgetState) bool {
	return s.Download >= 0 && s.Download <= lim.Download &&
		s.Get >= 0 && s.Get <= lim.Get && s.Write >= 0 && s.Write <= lim.Write &&
		s.Upload >= 0 && s.Upload <= lim.Upload
}

func (q *Qiniu) budgetLocked(change func(*budgetState, budgetState) (bool, error)) (string, error) {
	root := q.config.BudgetRoot
	lim, err := q.budgetLimits()
	if err != nil || !filepath.IsAbs(root) {
		return "", errBudget
	}
	if err = os.Mkdir(root, 0700); err != nil && !os.IsExist(err) {
		return "", errBudget
	}
	info, err := os.Lstat(root)
	if err != nil || !info.IsDir() || info.Mode().Perm()&0077 != 0 {
		return "", errBudget
	}
	lock, err := os.OpenFile(filepath.Join(root, "state.lock"), os.O_CREATE|os.O_RDWR|syscall.O_NOFOLLOW, 0600)
	if err != nil {
		return "", errBudget
	}
	defer lock.Close()
	deadline := time.Now().Add(time.Second)
	for {
		if err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err == nil {
			break
		}
		if err != syscall.EWOULDBLOCK || time.Now().After(deadline) {
			return "", errBudget
		}
		time.Sleep(10 * time.Millisecond)
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)
	month := time.Now().In(time.FixedZone("UTC+8", 8*3600)).Format("2006-01")
	state := budgetState{Version: 1, Environment: q.config.Environment, Month: month}
	marker, err := io.ReadAll(io.LimitReader(lock, 32))
	if err != nil || (len(marker) != 0 && string(marker) != "initialized\n") {
		return "", errBudget
	}
	path := filepath.Join(root, "state.json")
	if info, err = os.Lstat(path); err == nil {
		if !info.Mode().IsRegular() || info.Size() > 4096 {
			return "", errBudget
		}
		raw, e := os.ReadFile(path)
		var old budgetState
		var fields map[string]json.RawMessage
		if e != nil || json.Unmarshal(raw, &old) != nil || json.Unmarshal(raw, &fields) != nil ||
			old.Version != 1 || old.Environment != state.Environment || !budgetMonth.MatchString(old.Month) ||
			old.Month > month || !withinBudget(old, lim) {
			return "", errBudget
		}
		for _, name := range []string{"version", "environment", "month", "download_bytes", "get_requests", "write_requests", "upload_bytes_total"} {
			if value, ok := fields[name]; !ok || string(value) == "null" {
				return "", errBudget
			}
		}
		if old.Month == month {
			state = old
		} else {
			state.Upload = old.Upload
		}
	} else if !os.IsNotExist(err) || len(marker) != 0 {
		return "", errBudget
	}
	write, err := change(&state, lim)
	if err != nil {
		return "", err
	}
	if write {
		raw, e := json.Marshal(state)
		if e != nil {
			return "", errBudget
		}
		tmp, e := os.CreateTemp(root, ".state-")
		if e != nil {
			return "", errBudget
		}
		defer os.Remove(tmp.Name())
		_, e = tmp.Write(raw)
		if e == nil {
			e = tmp.Sync()
		}
		closeErr := tmp.Close()
		if e != nil || closeErr != nil || os.Rename(tmp.Name(), path) != nil {
			return "", errBudget
		}
		if len(marker) == 0 {
			if _, e := lock.WriteAt([]byte("initialized\n"), 0); e != nil || lock.Sync() != nil {
				return "", errBudget
			}
		}
	}
	return month, nil
}

func (q *Qiniu) reserveBudget(kind string, size int64) (string, error) {
	if q.config.BudgetRoot == "" {
		return "", nil
	}
	if size < 0 || size > (20<<20)+4096 {
		return "", errBudget
	}
	return q.budgetLocked(func(s *budgetState, lim budgetState) (bool, error) {
		switch kind {
		case "get":
			s.Download += size
			s.Get++
		case "upload", "write", "cleanup":
			s.Write++
			if kind == "upload" {
				s.Upload += size
			}
			if kind != "cleanup" && s.Write > lim.Write*9/10 {
				return false, errBudget
			}
		default:
			return false, errBudget
		}
		if !withinBudget(*s, lim) {
			return false, errBudget
		}
		return true, nil
	})
}

func (q *Qiniu) releaseBudget(month string, unused int64) {
	if month == "" || unused <= 0 {
		return
	}
	_, _ = q.budgetLocked(func(s *budgetState, _ budgetState) (bool, error) {
		if s.Month != month || unused > s.Download {
			return false, nil
		}
		s.Download -= unused
		return true, nil
	})
}
