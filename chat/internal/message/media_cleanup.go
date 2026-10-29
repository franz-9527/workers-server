package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Deletion is serialized with the upload row used by final publication and
// evidence holds. A failed remote delete keeps the ledger for a later retry.
func (m *MediaService) CleanOne(ctx context.Context) error {
	for _, kind := range []string{"object", "processed_source", "expired_source"} {
		if err := m.cleanPart(ctx, kind); err != nil {
			return err
		}
	}
	return nil
}
func (m *MediaService) cleanPart(ctx context.Context, kind string) error {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	tx, err := m.base.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return ports.ErrUnavailable
	}
	defer tx.Rollback()
	var id ports.ID
	var key string
	switch kind {
	case "object":
		err = tx.QueryRowContext(ctx, `SELECT CAST(o.upload_id AS CHAR),o.object_key FROM chat_image_objects o JOIN chat_image_uploads u ON u.id=o.upload_id WHERE o.created_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY AND u.legal_hold=0 AND (u.lease_until IS NULL OR u.lease_until<UTC_TIMESTAMP(6)) AND ((o.object_key<>COALESCE(u.main_key,'') AND o.object_key<>COALESCE(u.thumb_key,'')) OR (u.expires_at<UTC_TIMESTAMP(6) AND NOT EXISTS(SELECT 1 FROM chat_image_items i WHERE i.item_id=u.id))) ORDER BY o.created_at LIMIT 1`).Scan(&id, &key)
	case "processed_source":
		err = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),source_key FROM chat_image_uploads WHERE state IN ('processed','blocked') AND source_cleaned=0 AND legal_hold=0 ORDER BY id LIMIT 1`).Scan(&id, &key)
	case "expired_source":
		err = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),source_key FROM chat_image_uploads u WHERE expires_at<UTC_TIMESTAMP(6) AND legal_hold=0 AND state<>'cleaned' AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP(6)) AND NOT EXISTS(SELECT 1 FROM chat_image_items i WHERE i.item_id=u.id) AND NOT EXISTS(SELECT 1 FROM chat_image_objects o WHERE o.upload_id=u.id) ORDER BY id LIMIT 1`).Scan(&id, &key)
	default:
		return ports.ErrInvalid
	}
	if err == sql.ErrNoRows {
		return nil
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	var hold bool
	if tx.QueryRowContext(ctx, `SELECT legal_hold FROM chat_image_uploads WHERE id=? FOR UPDATE`, string(id)).Scan(&hold) != nil {
		return ports.ErrUnavailable
	}
	if hold {
		return nil
	}
	var eligible bool
	switch kind {
	case "object":
		err = tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM chat_image_objects o JOIN chat_image_uploads u ON u.id=o.upload_id WHERE o.upload_id=? AND o.object_key=? AND o.created_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY AND u.legal_hold=0 AND (u.lease_until IS NULL OR u.lease_until<UTC_TIMESTAMP(6)) AND ((o.object_key<>COALESCE(u.main_key,'') AND o.object_key<>COALESCE(u.thumb_key,'')) OR (u.expires_at<UTC_TIMESTAMP(6) AND NOT EXISTS(SELECT 1 FROM chat_image_items i WHERE i.item_id=u.id))))`, string(id), key).Scan(&eligible)
	case "processed_source":
		err = tx.QueryRowContext(ctx, `SELECT state IN ('processed','blocked') AND source_cleaned=0 AND legal_hold=0 AND source_key=? FROM chat_image_uploads WHERE id=?`, key, string(id)).Scan(&eligible)
	case "expired_source":
		err = tx.QueryRowContext(ctx, `SELECT expires_at<UTC_TIMESTAMP(6) AND legal_hold=0 AND state<>'cleaned' AND source_key=? AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP(6)) AND NOT EXISTS(SELECT 1 FROM chat_image_items i WHERE i.item_id=u.id) AND NOT EXISTS(SELECT 1 FROM chat_image_objects o WHERE o.upload_id=u.id) FROM chat_image_uploads u WHERE id=?`, key, string(id)).Scan(&eligible)
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	if !eligible {
		return nil
	}
	if m.storage.Delete(ctx, key) != nil {
		return ErrMedia
	}
	switch kind {
	case "object":
		_, err = tx.ExecContext(ctx, `DELETE FROM chat_image_objects WHERE object_key=? AND upload_id=?`, key, string(id))
	case "processed_source":
		_, err = tx.ExecContext(ctx, `UPDATE chat_image_uploads SET source_cleaned=1 WHERE id=?`, string(id))
	case "expired_source":
		_, err = tx.ExecContext(ctx, `UPDATE chat_image_uploads SET state='cleaned',source_cleaned=1,source_sha256=NULL WHERE id=?`, string(id))
	}
	if err != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
