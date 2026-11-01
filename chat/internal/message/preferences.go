package message

import (
	"context"
	"database/sql"
	"errors"
	"math"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrPreferenceConflict = errors.New("CHAT_PREFERENCE_CONFLICT")
var ErrPinLimit = errors.New("CHAT_PIN_LIMIT")

type Preferences struct {
	MemberVersion   string   `json:"member_version"`
	Pinned          bool     `json:"pinned"`
	PinnedVersion   ports.ID `json:"pinned_version"`
	Later           bool     `json:"later"`
	LaterVersion    ports.ID `json:"later_version"`
	AccountRevision ports.ID `json:"account_revision"`
}
type PreferenceRequest struct {
	ConversationID ports.ID
	MemberVersion  string
	Field          string
	Value          bool
	Expected       ports.ID
}

func preferenceLocked(ctx context.Context, tx *sql.Tx, p ports.Principal, id ports.ID, g ports.Grant) (Preferences, error) {
	out := Preferences{MemberVersion: memberHash(g.MemberKey), PinnedVersion: "0", LaterVersion: "0", AccountRevision: "0"}
	var hash string
	err := tx.QueryRowContext(ctx, `SELECT member_key_hash,pinned,CAST(pinned_version AS CHAR),later,CAST(later_version AS CHAR) FROM chat_user_preferences WHERE conversation_id=? AND actor_id=?`, string(id), string(p.ActorID)).Scan(&hash, &out.Pinned, &out.PinnedVersion, &out.Later, &out.LaterVersion)
	if err != nil && err != sql.ErrNoRows {
		return Preferences{}, ports.ErrUnavailable
	}
	if hash != out.MemberVersion {
		out.Pinned = false
		out.Later = false
		out.PinnedVersion = "0"
		out.LaterVersion = "0"
	}
	return out, nil
}
func accountRevision(ctx context.Context, db interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}, actor ports.ID) (ports.ID, error) {
	var revision ports.ID
	err := db.QueryRowContext(ctx, `SELECT CAST(revision AS CHAR) FROM chat_preference_accounts WHERE actor_id=?`, string(actor)).Scan(&revision)
	if err == sql.ErrNoRows {
		return "0", nil
	}
	if err != nil {
		return "", ports.ErrUnavailable
	}
	return revision, nil
}
func (s *HistoryService) Preferences(ctx context.Context, p ports.Principal, id ports.ID) (Preferences, error) {
	if !validID(id) || p.Environment != s.environment {
		return Preferences{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, err := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: id, Action: "read"})
	if err != nil {
		return Preferences{}, err
	}
	out, err := preferenceLocked(ctx, tx, p, id, g)
	if err != nil {
		return Preferences{}, err
	}
	out.AccountRevision, err = accountRevision(ctx, tx, p.ActorID)
	if err != nil {
		return Preferences{}, err
	}
	if !time.Now().Before(p.ExpiresAt) {
		return Preferences{}, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	return out, nil
}

// SetPreference serializes this actor's preference writes after the existing
// guard/login/conversation lock order. Independent fields never overwrite each
// other. A retry may repeat only the immediate same-value version transition.
func (s *HistoryService) SetPreference(ctx context.Context, p ports.Principal, q PreferenceRequest) (Preferences, error) {
	if !validID(q.ConversationID) || p.Environment != s.environment || !q.Expected.Valid() || !memberVersionPattern.MatchString(q.MemberVersion) || (q.Field != "pinned" && q.Field != "later") {
		return Preferences{}, ports.ErrInvalid
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	defer tx.Rollback()
	g, err := s.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: q.ConversationID, Action: "read"})
	if err != nil {
		return Preferences{}, err
	}
	if memberHash(g.MemberKey) != q.MemberVersion {
		return Preferences{}, ErrResync
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO chat_preference_accounts(actor_id,revision,updated_at) VALUES(?,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=actor_id`, string(p.ActorID))
	if err != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	revision, err := accountRevision(ctx, tx, p.ActorID)
	if err != nil {
		return Preferences{}, err
	}
	out, err := preferenceLocked(ctx, tx, p, q.ConversationID, g)
	if err != nil {
		return Preferences{}, err
	}
	out.AccountRevision = revision
	version, value := out.PinnedVersion, out.Pinned
	if q.Field == "later" {
		version, value = out.LaterVersion, out.Later
	}
	if q.Expected != version {
		if !(value == q.Value && number(version) > 0 && number(q.Expected) == number(version)-1) {
			return out, ErrPreferenceConflict
		}
		if !time.Now().Before(p.ExpiresAt) {
			return Preferences{}, policy.ErrDenied
		}
		if tx.Commit() != nil {
			return Preferences{}, ports.ErrUnavailable
		}
		return out, nil
	}
	if value == q.Value {
		if !time.Now().Before(p.ExpiresAt) {
			return Preferences{}, policy.ErrDenied
		}
		if tx.Commit() != nil {
			return Preferences{}, ports.ErrUnavailable
		}
		return out, nil
	}
	if number(version) == math.MaxUint64 || number(revision) == math.MaxUint64 {
		return Preferences{}, ports.ErrUnavailable
	}
	if q.Field == "pinned" && q.Value {
		// Only current projected generations consume a pin slot. PHP authorization
		// is still rechecked for every returned row; this count grants no access.
		var count int
		err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM (SELECT p.conversation_id FROM chat_user_preferences p JOIN chat_members m ON m.conversation_id=p.conversation_id AND m.actor_id=p.actor_id WHERE p.actor_id=? AND p.pinned=1 AND m.actor_kind='mini' AND m.can_read=1 AND p.member_key_hash=SHA2(m.member_key,256) LIMIT 10) active_pins`, string(p.ActorID)).Scan(&count)
		if err != nil {
			return Preferences{}, ports.ErrUnavailable
		}
		if count >= 10 {
			return out, ErrPinLimit
		}
	}
	if q.Field == "pinned" {
		out.Pinned = q.Value
		out.PinnedVersion = decimal(number(version) + 1)
	} else {
		out.Later = q.Value
		out.LaterVersion = decimal(number(version) + 1)
	}
	out.AccountRevision = decimal(number(revision) + 1)
	_, err = tx.ExecContext(ctx, `INSERT INTO chat_user_preferences(conversation_id,actor_id,member_key_hash,pinned,pinned_version,later,later_version,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE member_key_hash=VALUES(member_key_hash),pinned=VALUES(pinned),pinned_version=VALUES(pinned_version),later=VALUES(later),later_version=VALUES(later_version),updated_at=VALUES(updated_at)`, string(q.ConversationID), string(p.ActorID), out.MemberVersion, out.Pinned, string(out.PinnedVersion), out.Later, string(out.LaterVersion))
	if err != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	_, err = tx.ExecContext(ctx, `UPDATE chat_preference_accounts SET revision=?,updated_at=UTC_TIMESTAMP(6) WHERE actor_id=?`, string(out.AccountRevision), string(p.ActorID))
	if err != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	if !time.Now().Before(p.ExpiresAt) {
		return Preferences{}, policy.ErrDenied
	}
	if tx.Commit() != nil {
		return Preferences{}, ports.ErrUnavailable
	}
	return out, nil
}
