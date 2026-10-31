package conversation

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"log/slog"
	"regexp"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

var eventIDPattern = regexp.MustCompile(`^[a-f0-9]{32}$`)

type Lease struct{ EventID, Owner, PayloadHash string }
type Batch struct{ Claimed, Applied, Deferred, Rejected int }

// Claim writes only the Go receipt. The source is immutable PHP-owned data.
// A durable lease survives a crash before the projection transaction begins.
func (s *Store) Claim(ctx context.Context, eventID string) (Lease, error) {
	if !eventIDPattern.MatchString(eventID) {
		return Lease{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	var nonce [16]byte
	if _, err := rand.Read(nonce[:]); err != nil {
		return Lease{}, ports.ErrUnavailable
	}
	owner := hex.EncodeToString(nonce[:])
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return Lease{}, ports.ErrUnavailable
	}
	defer tx.Rollback()
	_, err = tx.ExecContext(ctx, `INSERT IGNORE INTO chat_event_receipts(event_id,aggregate_key,aggregate_version,payload_hash,status,attempts,next_attempt_at)
		SELECT event_id,aggregate_key,aggregate_version,SHA2(payload_json,256),'retry',0,UTC_TIMESTAMP(6) FROM chat_business_events WHERE event_id=?`, eventID)
	if err != nil {
		return Lease{}, ports.ErrUnavailable
	}
	var hash string
	err = tx.QueryRowContext(ctx, `SELECT payload_hash FROM chat_event_receipts WHERE event_id=? FOR UPDATE`, eventID).Scan(&hash)
	if err != nil {
		return Lease{}, ports.ErrUnavailable
	}
	result, err := tx.ExecContext(ctx, `UPDATE chat_event_receipts SET status='processing',lease_owner=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 30 SECOND,
		attempts=LEAST(attempts+1,1000000),error_code=NULL WHERE event_id=? AND
		((status='retry' AND next_attempt_at<=UTC_TIMESTAMP(6)) OR (status='processing' AND lease_until<=UTC_TIMESTAMP(6)))`, owner, eventID)
	if err != nil {
		return Lease{}, ports.ErrUnavailable
	}
	n, _ := result.RowsAffected()
	if n != 1 {
		return Lease{}, ports.ErrBusy
	}
	if tx.Commit() != nil {
		return Lease{}, ports.ErrUnavailable
	}
	return Lease{eventID, owner, hash}, nil
}

// Consume fences ownership again in the SAME transaction as intervals and the
// applied version. A late old owner cannot commit after a replacement claimant.
func (s *Store) Consume(ctx context.Context, lease Lease) error {
	if !eventIDPattern.MatchString(lease.EventID) || !eventIDPattern.MatchString(lease.Owner) || len(lease.PayloadHash) != 64 {
		return ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return ports.ErrUnavailable
	}
	defer tx.Rollback()
	var scope, kind string
	var version ports.ID
	var schema int
	var body sql.NullString
	err = tx.QueryRowContext(ctx, `SELECT aggregate_key,CAST(aggregate_version AS CHAR),schema_version,event_type,
		CASE WHEN OCTET_LENGTH(payload_json)<=65536 THEN payload_json ELSE NULL END FROM chat_business_events WHERE event_id=?`, lease.EventID).Scan(&scope, &version, &schema, &kind, &body)
	if err == sql.ErrNoRows {
		return policy.ErrDenied
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	if schema != 1 || kind != "chat.policy.changed" || !body.Valid {
		return policy.ErrDenied
	}
	sum := sha256.Sum256([]byte(body.String))
	if hex.EncodeToString(sum[:]) != lease.PayloadHash {
		return policy.ErrDenied
	}
	p, err := policy.DecodeEvent([]byte(body.String), scope, version)
	if err != nil {
		return err
	}
	current, err := s.reader.CurrentLocked(ctx, tx, scope)
	if err != nil {
		return err
	}
	if current.OrderID != p.OrderID || current.AssignmentID != p.AssignmentID {
		return policy.ErrDenied
	}
	if compare(p.Version, current.Version) > 0 {
		return ErrGap
	}
	if p.Version == current.Version && p.Hash != current.Hash {
		return policy.ErrDenied
	}
	if err = s.apply(ctx, tx, p); err != nil {
		return err
	}
	result, err := tx.ExecContext(ctx, `UPDATE chat_event_receipts SET status='done',lease_owner=NULL,lease_until=NULL,error_code=NULL,completed_at=UTC_TIMESTAMP(6)
		WHERE event_id=? AND status='processing' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND payload_hash=?`, lease.EventID, lease.Owner, lease.PayloadHash)
	if err != nil {
		return ports.ErrUnavailable
	}
	n, _ := result.RowsAffected()
	if n != 1 {
		return ErrLease
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}

func (s *Store) deferLease(ctx context.Context, lease Lease, cause error) error {
	status, code := "retry", "CHAT_EVENT_RETRY"
	if errors.Is(cause, policy.ErrDenied) {
		status, code = "rejected", "CHAT_EVENT_INVALID"
	}
	if errors.Is(cause, ErrGap) {
		code = "CHAT_EVENT_VERSION_GAP"
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	_, err := s.db.ExecContext(ctx, `UPDATE chat_event_receipts SET status=?,error_code=?,next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 5 SECOND,lease_owner=NULL,lease_until=NULL
		WHERE event_id=? AND status='processing' AND lease_owner=?`, status, code, lease.EventID, lease.Owner)
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}

// No MAX(id) or created_at watermark. The anti-join re-discovers a lower event
// ID whose PHP transaction committed after a later event was already consumed.
// Poll is authoritative; a lost Redis wake cannot lose any business event.
func (s *Store) Poll(ctx context.Context, limit int) (Batch, error) {
	var stats Batch
	if limit < 1 || limit > 100 {
		return stats, ports.ErrInvalid
	}
	queryCtx, cancel := context.WithTimeout(ctx, s.timeout)
	rows, err := s.db.QueryContext(queryCtx, `SELECT e.event_id FROM chat_business_events e LEFT JOIN chat_event_receipts r ON r.event_id=e.event_id
		WHERE r.event_id IS NULL OR (r.status='retry' AND r.next_attempt_at<=UTC_TIMESTAMP(6))
		OR (r.status='processing' AND r.lease_until<=UTC_TIMESTAMP(6)) ORDER BY e.id LIMIT ?`, limit)
	if err != nil {
		cancel()
		return stats, ports.ErrUnavailable
	}
	ids := []string{}
	for rows.Next() {
		var id string
		if rows.Scan(&id) != nil {
			rows.Close()
			cancel()
			return stats, ports.ErrUnavailable
		}
		ids = append(ids, id)
	}
	err = rows.Err()
	rows.Close()
	cancel()
	if err != nil {
		return stats, ports.ErrUnavailable
	}
	for _, id := range ids {
		if ctx.Err() != nil {
			return stats, ctx.Err()
		}
		lease, e := s.Claim(ctx, id)
		if errors.Is(e, ports.ErrBusy) {
			continue
		}
		if e != nil {
			return stats, e
		}
		stats.Claimed++
		if e = s.Consume(ctx, lease); e == nil {
			stats.Applied++
			continue
		}
		if err = s.deferLease(ctx, lease, e); err != nil {
			return stats, err
		}
		if errors.Is(e, policy.ErrDenied) {
			stats.Rejected++
		} else {
			stats.Deferred++
		}
	}
	return stats, nil
}

func (s *Store) Run(ctx context.Context, logger *slog.Logger) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		stats, err := s.Poll(ctx, 100)
		if ctx.Err() != nil {
			return
		}
		if err != nil {
			logger.Warn("conversation_projection_retry", "code", "CHAT_EVENT_RETRY")
		} else if stats.Claimed > 0 {
			logger.Info("conversation_projection_batch", "claimed", stats.Claimed, "applied", stats.Applied, "deferred", stats.Deferred, "rejected", stats.Rejected)
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}
