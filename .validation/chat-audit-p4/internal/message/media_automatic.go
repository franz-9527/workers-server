package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type imageSafety interface {
	CheckImage(context.Context, ports.Principal, []byte) (string, error)
}

// Network checks happen outside business locks. The verdict is bound to the
// immutable bundle and its processed objects; final publication rechecks authority.
func (m *MediaService) checkAutomatic(ctx context.Context, id ports.ID) error {
	if !m.automaticReview {
		return nil
	}
	var raw, hash, review, general, state string
	var recent, live bool
	err := m.base.db.QueryRowContext(ctx, `SELECT b.manifest_json,b.manifest_hash,b.review_state,COALESCE(b.general_safety_result,''),i.state,COALESCE(i.error_code='',1) OR i.updated_at<UTC_TIMESTAMP(6)-INTERVAL 30 SECOND,b.expires_at>UTC_TIMESTAMP(6) FROM chat_image_bundles b JOIN chat_send_intents i ON i.id=b.intent_id WHERE b.intent_id=?`, string(id)).Scan(&raw, &hash, &review, &general, &state, &recent, &live)
	if err != nil {
		return ports.ErrUnavailable
	}
	if !live || state == "accepted" || state == "rejected" || state == "canceled" || review != "waiting" && review != "pending" || general != "" {
		return nil
	}
	if !recent {
		return ErrCheck
	}
	checker, ok := m.base.safety.(imageSafety)
	if !ok {
		return ErrCheck
	}
	p, q, err := m.workerPrincipal(ctx, id)
	if err != nil {
		if err == policy.ErrDenied || err == ErrRestricted || err == auth.ErrUnauthorized {
			m.rejectMedia(ctx, id, err.Error())
			return nil
		}
		return err
	}
	if json.Unmarshal([]byte(raw), &q) != nil {
		return ErrMedia
	}
	authority, e := m.base.begin(ctx)
	if e != nil {
		return e
	}
	_, e = m.base.authorize(ctx, authority, p, q.ConversationID)
	authority.Rollback()
	if e != nil {
		if e == auth.ErrUnauthorized || e == policy.ErrDenied || e == ErrRestricted {
			m.rejectMedia(ctx, id, e.Error())
			return nil
		}
		return e
	}
	result := "passed"
	for _, item := range q.Items {
		var main, thumb, status, qr, decision string
		err = m.base.db.QueryRowContext(ctx, `SELECT COALESCE(main_key,''),COALESCE(thumb_key,''),state,COALESCE(qr_status,''),COALESCE(anti_diversion_result,'') FROM chat_image_uploads WHERE id=? AND object_version=?`, string(item.AttachmentID), item.ObjectVersion).Scan(&main, &thumb, &status, &qr, &decision)
		if err != nil {
			return ports.ErrUnavailable
		}
		if status != "processed" || qr != "completed" || decision != "clear" {
			return nil
		}
		body, e := m.storage.Read(ctx, main, MaxImageBytes)
		if e != nil {
			return ErrCheck
		}
		// Originals remain lossless; a separate bounded full-frame projection is
		// used only by the provider's limited-size inspection endpoint.
		if projector, ok := m.processor.(interface {
			Inspection(context.Context, []byte) ([]byte, error)
		}); ok {
			body, e = projector.Inspection(ctx, body)
		} else if len(body) > 1<<20 {
			body, e = m.storage.Read(ctx, thumb, 1<<20)
		}
		if e == nil {
			result, e = checker.CheckImage(ctx, p, body)
		}
		if e != nil {
			_, _ = m.base.db.ExecContext(ctx, `UPDATE chat_send_intents SET state='checking',error_code=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state IN ('checking','review_pending','retryable')`, ErrCheck.Error(), string(id))
			return ErrCheck
		}
		if result != "passed" {
			result = "review"
			break
		}
	}
	_, err = m.base.db.ExecContext(ctx, `UPDATE chat_image_bundles SET general_safety_result=?,review_reason='wechat.img_sec_check; immutable image bundle' WHERE intent_id=? AND manifest_hash=? AND review_state IN ('waiting','pending') AND general_safety_result IS NULL`, result, string(id), hash)
	if err != nil && err != sql.ErrNoRows {
		return ports.ErrUnavailable
	}
	return nil
}
