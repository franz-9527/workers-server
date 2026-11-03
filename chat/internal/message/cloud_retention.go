package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

// Published references/hash metadata remain stable for permanent device caches.
// Only cloud bytes are removed; video posters and sampled frames are retained.
func (s *Service) CleanCloudOne(ctx context.Context) error {
	for _, av := range []bool{false, true} {
		if !av && s.media == nil || av && s.av == nil {
			continue
		}
		if e := s.cleanCloudObject(ctx, av); e != nil {
			return e
		}
	}
	return nil
}
func (s *Service) cleanCloudObject(ctx context.Context, av bool) error {
	objects, uploads, items, join, role := "chat_image_objects", "chat_image_uploads", "chat_image_items", "i.item_id=u.id", ""
	reference := "kept.item_id=u.id"
	if av {
		reference = "kept.upload_id=u.id"
	}
	var deleteObject func(context.Context, string) error
	if av {
		objects, uploads, items, join, role = "chat_av_objects", "chat_av_uploads", "chat_av_items", "i.upload_id=u.id", " AND o.role='main'"
		deleteObject = s.av.storage.Delete
	} else {
		deleteObject = s.media.storage.Delete
	}
	var cid, mid, upload ports.ID
	var key string
	query := "SELECT CAST(m.conversation_id AS CHAR),CAST(m.id AS CHAR),CAST(u.id AS CHAR),o.object_key FROM " + objects + " o JOIN " + uploads + " u ON u.id=o.upload_id JOIN " + items + " i ON " + join + " JOIN chat_messages m ON m.id=i.message_id WHERE o.deleted_at IS NULL AND u.legal_hold=0 AND m.state='published' AND m.retention_mode='days' AND m.expires_at<=UTC_TIMESTAMP(6)" + role + " AND NOT EXISTS(SELECT 1 FROM " + items + " kept JOIN chat_messages live ON live.id=kept.message_id WHERE " + reference + " AND live.state='published' AND (live.retention_mode='permanent' AND live.expires_at IS NULL OR live.expires_at>UTC_TIMESTAMP(6))) ORDER BY m.expires_at,m.id LIMIT 1"
	e := s.db.QueryRowContext(ctx, query).Scan(&cid, &mid, &upload, &key)
	if e == sql.ErrNoRows {
		return nil
	}
	if e != nil {
		return ports.ErrUnavailable
	}
	tx, e := s.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var found int
	if tx.QueryRowContext(ctx, `SELECT 1 FROM chat_conversations WHERE id=? FOR UPDATE`, string(cid)).Scan(&found) != nil {
		return ports.ErrUnavailable
	}
	var due bool
	if tx.QueryRowContext(ctx, `SELECT state='published' AND retention_mode='days' AND expires_at<=UTC_TIMESTAMP(6) FROM chat_messages WHERE id=? FOR UPDATE`, string(mid)).Scan(&due) != nil {
		return ports.ErrUnavailable
	}
	if !due {
		return nil
	}
	var retained bool
	if tx.QueryRowContext(ctx, "SELECT EXISTS(SELECT 1 FROM "+items+" kept JOIN chat_messages live ON live.id=kept.message_id WHERE "+reference+" AND live.state='published' AND (live.retention_mode='permanent' AND live.expires_at IS NULL OR live.expires_at>UTC_TIMESTAMP(6))) FROM "+uploads+" u WHERE u.id=?", string(upload)).Scan(&retained) != nil {
		return ports.ErrUnavailable
	}
	if retained {
		return nil
	}
	var hold bool
	if tx.QueryRowContext(ctx, "SELECT legal_hold FROM "+uploads+" WHERE id=? FOR UPDATE", string(upload)).Scan(&hold) != nil {
		return ports.ErrUnavailable
	}
	if hold {
		return nil
	}
	var deleted sql.NullTime
	if tx.QueryRowContext(ctx, "SELECT deleted_at FROM "+objects+" WHERE object_key=? FOR UPDATE", key).Scan(&deleted) != nil {
		return ports.ErrUnavailable
	}
	if deleted.Valid {
		return nil
	}
	work, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	if e = deleteObject(work, key); e != nil {
		return e
	}
	if _, e = tx.ExecContext(ctx, "UPDATE "+objects+" SET deleted_at=UTC_TIMESTAMP(6) WHERE object_key=?", key); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func missingCloudReferences(ctx context.Context, tx *sql.Tx, id ports.ID, kind string) (bool, error) {
	var missing bool
	var query string
	switch kind {
	case "text":
		query = `SELECT text_body IS NULL FROM chat_messages WHERE id=?`
	case "image", "image_album":
		query = `SELECT EXISTS(SELECT 1 FROM chat_image_items i JOIN chat_image_uploads u ON u.id=i.item_id WHERE i.message_id=? AND (NOT EXISTS(SELECT 1 FROM chat_image_objects o WHERE o.object_key=u.main_key AND o.deleted_at IS NULL) OR NOT EXISTS(SELECT 1 FROM chat_image_objects o WHERE o.object_key=u.thumb_key AND o.deleted_at IS NULL)))`
	case "audio", "video":
		query = `SELECT EXISTS(SELECT 1 FROM chat_av_items i JOIN chat_av_uploads u ON u.id=i.upload_id WHERE i.message_id=? AND NOT EXISTS(SELECT 1 FROM chat_av_objects o WHERE o.object_key=u.main_key AND o.deleted_at IS NULL))`
	default:
		return true, nil
	}
	if tx.QueryRowContext(ctx, query, string(id)).Scan(&missing) != nil {
		return false, ports.ErrUnavailable
	}
	return missing, nil
}
