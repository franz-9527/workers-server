package message

import (
	"context"
	"database/sql"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
	"time"
)

type objectTierer interface {
	LowFrequency(context.Context, string) error
}

// This operation never downloads or transcodes media. The disabled default
// returns before contacting Qiniu. Ordinary access stamps last_access_at.
func (s *Service) TierOne(ctx context.Context) error {
	tx, e := s.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	settings, e := storagepolicy.Load(ctx, tx)
	if e != nil {
		return e
	}
	t := settings.Policy.Tiering
	if !t.Enabled {
		return nil
	}
	type candidate struct {
		key    string
		upload ports.ID
		av     bool
	}
	var v candidate
	remaining := int(t.RemainingDays)
	age, idle := int(t.AgeDays), int(t.IdleDays)
	// Use only formal live references. Source parts, review chunks, evidence and
	// orphans are never tiered; finite objects need more than the minimum billing period.
	if t.Images && s.media != nil {
		e = tx.QueryRowContext(ctx, `SELECT o.object_key,CAST(o.upload_id AS CHAR) FROM chat_image_objects o JOIN chat_image_uploads u ON u.id=o.upload_id WHERE o.storage_class=0 AND o.deleted_at IS NULL AND o.size_bytes>=? AND o.created_at<TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6)) AND COALESCE(o.last_access_at,o.created_at)<TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6)) AND u.legal_hold=0 AND EXISTS(SELECT 1 FROM chat_image_items i JOIN chat_messages m ON m.id=i.message_id WHERE i.item_id=u.id AND m.state='published' AND (m.retention_mode='permanent' AND m.expires_at IS NULL OR m.retention_mode='days' AND m.expires_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))) ORDER BY o.object_key LIMIT 1`, t.MinBytes, age, idle, remaining).Scan(&v.key, &v.upload)
		if e != nil && e != sql.ErrNoRows {
			return ports.ErrUnavailable
		}
	}
	if v.key == "" && (t.Audio || t.Videos) && s.av != nil {
		e = tx.QueryRowContext(ctx, `SELECT o.object_key,CAST(o.upload_id AS CHAR) FROM chat_av_objects o JOIN chat_av_uploads u ON u.id=o.upload_id WHERE o.storage_class=0 AND o.deleted_at IS NULL AND o.role='main' AND o.size_bytes>=? AND o.created_at<TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6)) AND COALESCE(o.last_access_at,o.created_at)<TIMESTAMPADD(DAY,-?,UTC_TIMESTAMP(6)) AND u.legal_hold=0 AND (u.kind='audio' AND ?=1 OR u.kind='video' AND ?=1) AND EXISTS(SELECT 1 FROM chat_av_items i JOIN chat_messages m ON m.id=i.message_id WHERE i.upload_id=u.id AND m.state='published' AND (m.retention_mode='permanent' AND m.expires_at IS NULL OR m.retention_mode='days' AND m.expires_at>TIMESTAMPADD(DAY,?,UTC_TIMESTAMP(6)))) ORDER BY o.object_key LIMIT 1`, t.MinBytes, age, idle, t.Audio, t.Videos, remaining).Scan(&v.key, &v.upload)
		v.av = true
		if e != nil && e != sql.ErrNoRows {
			return ports.ErrUnavailable
		}
	}
	if v.key == "" {
		return nil
	}
	table, uploads := "chat_image_objects", "chat_image_uploads"
	var provider objectTierer
	if v.av {
		table, uploads = "chat_av_objects", "chat_av_uploads"
		provider, _ = s.av.storage.(objectTierer)
	} else {
		provider, _ = s.media.storage.(objectTierer)
	}
	if provider == nil {
		return ErrMedia
	}
	var hold bool
	if tx.QueryRowContext(ctx, "SELECT legal_hold FROM "+uploads+" WHERE id=? FOR UPDATE", string(v.upload)).Scan(&hold) != nil {
		return ports.ErrUnavailable
	}
	if hold {
		return nil
	}
	var class int
	var accessed sql.NullTime
	if tx.QueryRowContext(ctx, "SELECT storage_class,last_access_at FROM "+table+" WHERE object_key=? FOR UPDATE", v.key).Scan(&class, &accessed) != nil {
		return ports.ErrUnavailable
	}
	if class != 0 || accessed.Valid && time.Since(accessed.Time) < time.Duration(t.IdleDays)*24*time.Hour {
		return nil
	}
	// Keep the shared policy lock until the bounded request completes; a
	// concurrent disable cannot commit while an old policy begins a cloud change.
	work, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	if e = provider.LowFrequency(work, v.key); e != nil {
		return e
	}
	if _, e = tx.ExecContext(ctx, "UPDATE "+table+" SET storage_class=1 WHERE object_key=?", v.key); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
