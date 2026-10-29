package message

import (
	"context"
	"database/sql"
	"errors"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
	"time"
)

var ErrObjectMissing = errors.New("CHAT_MEDIA_OBJECT_MISSING")

type ObjectKeeper interface {
	Keep(context.Context, string) error
}

// ApplyStorageOne consumes immutable PHP audit snapshots, oldest first. PHP can
// change low-frequency settings without blocking behind a long history batch.
func (s *Service) ApplyStorageOne(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	var revision, through uint64
	var raw []byte
	e := s.db.QueryRowContext(ctx, `SELECT a.revision,a.policy_json,a.apply_through_id FROM chat_storage_policy_audit a LEFT JOIN chat_storage_applications j ON j.revision=a.revision WHERE a.apply_through_id>0 AND (j.revision IS NULL OR j.state NOT IN ('completed','completed_with_errors')) ORDER BY a.revision LIMIT 1`).Scan(&revision, &raw, &through)
	if e == sql.ErrNoRows {
		return nil
	}
	if e != nil {
		return ports.ErrUnavailable
	}
	policy, e := storagepolicy.Decode(raw)
	if e != nil {
		return ports.ErrUnavailable
	}
	if _, e = s.db.ExecContext(ctx, `INSERT IGNORE INTO chat_storage_applications(revision,last_message_id,state,updated_at) VALUES(?,0,'pending',UTC_TIMESTAMP(6))`, revision); e != nil {
		return ports.ErrUnavailable
	}
	tx, e := s.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var last uint64
	var live bool
	if tx.QueryRowContext(ctx, `SELECT last_message_id,COALESCE(lease_until>UTC_TIMESTAMP(6),0) FROM chat_storage_applications WHERE revision=? FOR UPDATE`, revision).Scan(&last, &live) != nil {
		return ports.ErrUnavailable
	}
	if live {
		return nil
	}
	var m storageMessage
	e = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),CAST(conversation_id AS CHAR),seq,CAST(policy_version AS CHAR),message_type,state,state_version,retention_policy_version,COALESCE((retention_mode='permanent' AND expires_at IS NULL OR retention_mode='days' AND expires_at>UTC_TIMESTAMP(6)),0) FROM chat_messages WHERE id>? AND id<=? ORDER BY id LIMIT 1`, last, through).Scan(&m.id, &m.cid, &m.seq, &m.policyVersion, &m.kind, &m.state, &m.version, &m.retentionVersion, &m.live)
	if e == sql.ErrNoRows {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_storage_applications SET state=IF(errors=0,'completed','completed_with_errors'),lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE revision=?`, revision); e != nil || tx.Commit() != nil {
			return ports.ErrUnavailable
		}
		return nil
	}
	if e != nil {
		return ports.ErrUnavailable
	}
	owner := randomMedia()
	if owner == "" {
		return ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_storage_applications SET state='running',lease_owner=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 60 SECOND,updated_at=UTC_TIMESTAMP(6) WHERE revision=?`, owner, revision); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	diagnostic := ""
	if m.state == "published" && m.retentionVersion < revision {
		if _, e = policy.Rule(m.kind); e != nil {
			diagnostic = "CHAT_STORAGE_TYPE_UNSUPPORTED"
		} else if e = s.keepMessageObjects(ctx, m.id, m.kind); e != nil {
			if errors.Is(e, ErrObjectMissing) {
				diagnostic = ErrObjectMissing.Error()
			} else {
				_, _ = s.db.ExecContext(ctx, `UPDATE chat_storage_applications SET state='retrying',diagnostic='CHAT_MEDIA_UNAVAILABLE',lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE revision=? AND lease_owner=?`, revision, owner)
				return e
			}
		}
	}
	return s.finishStorageApplication(ctx, revision, owner, policy, m, diagnostic)
}

type storageMessage struct {
	id, cid                   ports.ID
	seq                       uint64
	policyVersion             ports.ID
	kind, state               string
	version, retentionVersion uint64
	live                      bool
}

func (s *Service) keepMessageObjects(ctx context.Context, id ports.ID, kind string) error {
	if kind == "text" {
		return nil
	}
	if kind != "image" && kind != "image_album" {
		return s.keepAVObjects(ctx, id, kind)
	}
	if s.media == nil {
		return ErrMedia
	}
	keeper, ok := s.media.storage.(ObjectKeeper)
	if !ok {
		if s.media.environment == "test" {
			return nil
		}
		return ErrMedia
	}
	rows, e := s.db.QueryContext(ctx, `SELECT u.main_key,u.thumb_key FROM chat_image_items i JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version WHERE i.message_id=? ORDER BY i.ordinal`, string(id))
	if e != nil {
		return ports.ErrUnavailable
	}
	keys := []string{}
	for rows.Next() {
		var main, thumb string
		if rows.Scan(&main, &thumb) != nil {
			rows.Close()
			return ErrMedia
		}
		keys = append(keys, main, thumb)
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return ports.ErrUnavailable
	}
	if len(keys) == 0 {
		return ErrObjectMissing
	}
	for _, key := range keys {
		if key == "" {
			return ErrObjectMissing
		}
		if e = keeper.Keep(ctx, key); e != nil {
			return e
		}
	}
	return nil
}
func (s *Service) finishStorageApplication(ctx context.Context, revision uint64, owner string, policy storagepolicy.Policy, m storageMessage, diagnostic string) error {
	tx, e := s.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var valid bool
	if tx.QueryRowContext(ctx, `SELECT lease_owner=? AND lease_until>UTC_TIMESTAMP(6) FROM chat_storage_applications WHERE revision=? FOR UPDATE`, owner, revision).Scan(&valid) != nil {
		return ports.ErrUnavailable
	}
	if !valid {
		return ports.ErrBusy
	}
	var change uint64
	if tx.QueryRowContext(ctx, `SELECT last_change_seq FROM chat_conversations WHERE id=? FOR UPDATE`, string(m.cid)).Scan(&change) != nil {
		return ports.ErrUnavailable
	}
	var state string
	var live bool
	var version, retention uint64
	if tx.QueryRowContext(ctx, `SELECT state,state_version,retention_policy_version,COALESCE((retention_mode='permanent' AND expires_at IS NULL OR retention_mode='days' AND expires_at>UTC_TIMESTAMP(6)),0) FROM chat_messages WHERE id=? FOR UPDATE`, string(m.id)).Scan(&state, &version, &retention, &live) != nil {
		return ports.ErrUnavailable
	}
	if diagnostic == "" && state == "published" {
		missing, err := missingCloudReferences(ctx, tx, m.id, m.kind)
		if err != nil {
			return err
		}
		if missing {
			diagnostic = ErrObjectMissing.Error()
		}
	}
	changed := false
	if diagnostic == "" && state == "published" && m.state == state && version == m.version && retention < revision {
		rule, e := policy.Rule(m.kind)
		if e != nil {
			return ports.ErrUnavailable
		}
		if change == ^uint64(0) || version == ^uint64(0) {
			return ports.ErrUnavailable
		}
		change++
		var days any
		if rule.Mode == "days" {
			days = rule.Days
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_messages SET retention_policy_version=?,retention_mode=?,expires_at=IF(? IS NULL,NULL,TIMESTAMPADD(DAY,?,created_at)) WHERE id=?`, revision, rule.Mode, days, days, string(m.id)); e != nil {
			return ports.ErrUnavailable
		}
		if _, e = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) VALUES(?,?,?,?, 'message.retention',UTC_TIMESTAMP(6))`, string(m.cid), change, string(m.id), version); e != nil {
			return ports.ErrUnavailable
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_change_seq=? WHERE id=?`, change, string(m.cid)); e != nil {
			return ports.ErrUnavailable
		}
		changed = true
	}
	if diagnostic != "" {
		if _, e = tx.ExecContext(ctx, `INSERT INTO chat_storage_application_errors(revision,message_id,code,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))`, revision, string(m.id), diagnostic); e != nil {
			return ports.ErrUnavailable
		}
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_storage_applications SET last_message_id=?,state='running',errors=errors+?,diagnostic=?,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE revision=? AND lease_owner=?`, string(m.id), boolInt(diagnostic != ""), nullableString(diagnostic), revision, owner); e != nil {
		return ports.ErrUnavailable
	}
	if tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	if changed && s.publisher != nil {
		event := ports.CommittedMessage{ConversationID: m.cid, Seq: decimal(m.seq), PolicyVersion: m.policyVersion}
		if publisher, ok := s.publisher.(interface{ Changed(ports.CommittedMessage) }); ok {
			publisher.Changed(event)
		} else {
			s.publisher.Committed(event)
		}
	}
	return nil
}
func boolInt(v bool) int {
	if v {
		return 1
	}
	return 0
}
func nullableString(v string) any {
	if v == "" {
		return nil
	}
	return v
}
