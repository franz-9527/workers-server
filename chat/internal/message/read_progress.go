package message

import (
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/hex"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

func memberHash(key string) string {
	sum := sha256.Sum256([]byte(key))
	return hex.EncodeToString(sum[:])
}

// Acknowledge is the only read-watermark writer. A locate/history cursor, an
// unfinished sync page, an expired token or a different login cannot mark read.
// The token is a proof of an S08 server-committed contiguous sync position;
// the UI must still call this only after explicitly reading to that position.
func (s *HistoryService) Acknowledge(ctx context.Context, p ports.Principal, id ports.ID, cursor string) (ports.ID, error) {
	if !validID(id) || !p.ActorID.Valid() || p.Environment != s.environment {
		return "", ports.ErrInvalid
	}
	c, err := s.parse(cursor, "sync")
	if err != nil {
		return "", err
	}
	if c.Binding != bindingFor(s.environment, p) || c.Conversation != id || c.Pending || c.Position != c.Seq {
		return "", ErrResync
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return "", ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, err := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"})
	if err != nil {
		return "", err
	}
	// This must happen inside the same locked transaction as the actual write.
	if g.Version != c.Policy || g.MemberKey != c.Member {
		return "", ErrResync
	}
	var upper, floor uint64
	if err = tx.QueryRowContext(ctx, `SELECT last_seq,sync_floor_seq FROM chat_conversations WHERE id=?`, string(id)).Scan(&upper, &floor); err != nil {
		return "", ports.ErrUnavailable
	}
	target := number(c.Seq)
	if target > upper || target < floor {
		return "", ErrResync
	}
	hash := memberHash(g.MemberKey)
	// A row is created only for an explicit acknowledgement, never for an
	// unread query or a conversation summary.
	_, err = tx.ExecContext(ctx, `INSERT INTO chat_member_reads
		(conversation_id,actor_id,member_key_hash,read_seq,updated_at)
		VALUES(?,?,?,0,UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(id), string(p.ActorID), hash)
	if err != nil {
		return "", ports.ErrUnavailable
	}
	var storedHash string
	var current uint64
	err = tx.QueryRowContext(ctx, `SELECT member_key_hash,read_seq FROM chat_member_reads
		WHERE conversation_id=? AND actor_id=? FOR UPDATE`, string(id), string(p.ActorID)).Scan(&storedHash, &current)
	if err != nil {
		return "", ports.ErrUnavailable
	}
	if subtle.ConstantTimeCompare([]byte(storedHash), []byte(hash)) != 1 {
		current = 0
		_, err = tx.ExecContext(ctx, `UPDATE chat_member_reads SET member_key_hash=?,read_seq=0,updated_at=UTC_TIMESTAMP(6)
			WHERE conversation_id=? AND actor_id=?`, hash, string(id), string(p.ActorID))
		if err != nil {
			return "", ports.ErrUnavailable
		}
	}
	if target > current {
		_, err = tx.ExecContext(ctx, `UPDATE chat_member_reads SET read_seq=?,updated_at=UTC_TIMESTAMP(6)
			WHERE conversation_id=? AND actor_id=?`, target, string(id), string(p.ActorID))
		if err != nil {
			return "", ports.ErrUnavailable
		}
		current = target
	}
	if !time.Now().Before(p.ExpiresAt) {
		return "", policy.ErrDenied
	}
	if err = tx.Commit(); err != nil {
		return "", ports.ErrUnavailable
	}
	return decimal(current), nil
}
