package message

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/storagepolicy"
)

var ErrSupportImagesDisabled = errors.New("CHAT_SUPPORT_IMAGES_DISABLED")

func imageRule(scene string) string {
	if scene == ports.DirectScene {
		return "direct-images-v1"
	}
	if scene == ports.SupportScene {
		return "feedback-images-v1"
	}
	return ImageRule
}

func (m *MediaService) authorizeUpload(ctx context.Context, tx *sql.Tx, p ports.Principal, cid ports.ID) (ports.Grant, error) {
	grant, err := m.base.authorize(ctx, tx, p, cid)
	if err == nil && grant.ConversationType == ports.SupportScene && !grant.ServiceImagesEnabled {
		err = ErrSupportImagesDisabled
	}
	return grant, err
}

func (m *MediaService) authorizeImageIntent(ctx context.Context, tx *sql.Tx, p ports.Principal, cid, intent ports.ID) (ports.Grant, error) {
	grant, err := m.authorizeUpload(ctx, tx, p, cid)
	if err != nil || grant.ConversationType != ports.SupportScene {
		return grant, err
	}
	var generation string
	err = tx.QueryRowContext(ctx, `SELECT COALESCE(CAST(feedback_image_generation AS CHAR),'')
		FROM chat_image_bundles WHERE intent_id=?`, string(intent)).Scan(&generation)
	if err != nil {
		return grant, ports.ErrUnavailable
	}
	if generation != string(grant.ServiceImageGeneration) {
		return grant, ErrSupportImagesDisabled
	}
	return grant, nil
}

// A background worker derives the scene from the durable intent. The client
// header is only a route selector and is never persisted as authority.
func (m *MediaService) imageIntentContext(ctx context.Context, id ports.ID) (context.Context, error) {
	var kind string
	err := m.base.db.QueryRowContext(ctx, `SELECT c.conversation_type FROM chat_send_intents i
		JOIN chat_conversations c ON c.id=i.conversation_id WHERE i.id=?`, string(id)).Scan(&kind)
	switch {
	case err == sql.ErrNoRows:
		return ctx, ErrMedia
	case err != nil:
		return ctx, ports.ErrUnavailable
	case kind == ports.SupportScene:
		return policy.WithFeedback(ctx), nil
	case kind == ports.DirectScene:
		return policy.WithDirect(ctx), nil
	case kind == ports.OrderScene && !policy.FeedbackOnly(ctx) && !policy.DirectOnly(ctx):
		return ctx, nil
	default:
		return ctx, policy.ErrDenied
	}
}

func recordSupportPublication(ctx context.Context, tx *sql.Tx, grant ports.Grant, cid ports.ID, mid int64, seq uint64) error {
	if grant.ConversationType != ports.SupportScene {
		return nil
	}
	var err error
	if grant.MemberRole == "support_owner" {
		_, err = tx.ExecContext(ctx, `INSERT INTO chat_support_work
			(conversation_id,first_user_message_id,last_user_seq,work_version,handled,updated_at)
			VALUES(?,?,?,1,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_user_seq=VALUES(last_user_seq),
			work_version=work_version+1,handled=0,handled_by=NULL,updated_at=UTC_TIMESTAMP(6)`, string(cid), mid, seq)
	} else {
		_, err = tx.ExecContext(ctx, `INSERT INTO chat_support_message_labels(message_id,service_name,service_revision) VALUES(?,?,?)`,
			mid, grant.ServiceName, string(grant.ServiceRevision))
	}
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}


func appendSupportAutoReply(ctx context.Context, tx *sql.Tx, grant ports.Grant, cid ports.ID, firstMessageID int64, seq, change uint64) (uint64, uint64, int64, error) {
	if grant.ConversationType != ports.SupportScene || grant.MemberRole != "support_owner" || !grant.ServiceAutoReplyEnabled || grant.ServiceAutoReplyText == "" {
		return seq, change, firstMessageID, nil
	}
	var recordedFirst int64
	if err := tx.QueryRowContext(ctx, `SELECT first_user_message_id FROM chat_support_work WHERE conversation_id=? LOCK IN SHARE MODE`, string(cid)).Scan(&recordedFirst); err != nil {
		return seq, change, firstMessageID, ports.ErrUnavailable
	}
	if recordedFirst != firstMessageID {
		return seq, change, firstMessageID, nil
	}
	rows, err := tx.QueryContext(ctx, `SELECT actor_id FROM chat_members WHERE conversation_id=? AND actor_kind='mini' AND role='support_staff' AND can_send=1 ORDER BY actor_id LIMIT 2`, string(cid))
	if err != nil {
		return seq, change, firstMessageID, ports.ErrUnavailable
	}
	var staff []uint64
	for rows.Next() {
		var actor uint64
		if rows.Scan(&actor) != nil {
			rows.Close()
			return seq, change, firstMessageID, ports.ErrUnavailable
		}
		staff = append(staff, actor)
	}
	if rows.Err() != nil {
		rows.Close()
		return seq, change, firstMessageID, ports.ErrUnavailable
	}
	rows.Close()
	if len(staff) != 1 || seq == ^uint64(0) || change == ^uint64(0) {
		return seq, change, firstMessageID, nil
	}
	seq++
	change++
	clientID := fmt.Sprintf("%010d.%032x", time.Now().Unix(), uint64(firstMessageID))
	res, err := tx.ExecContext(ctx, `INSERT INTO chat_messages(conversation_id,seq,sender_id,client_message_id,policy_version,message_type,text_body,state,state_version,safety_rule_version,created_at)
		VALUES(?,?,?,?,?,'text',?,'published',1,'support.auto_reply.v1',UTC_TIMESTAMP(6))`,
		string(cid), seq, staff[0], clientID, string(grant.Version), grant.ServiceAutoReplyText)
	if err != nil {
		return seq - 1, change - 1, firstMessageID, ports.ErrUnavailable
	}
	messageID, err := res.LastInsertId()
	if err != nil || messageID <= 0 {
		return seq - 1, change - 1, firstMessageID, ports.ErrUnavailable
	}
	if err = storagepolicy.Stamp(ctx, tx, messageID, "text"); err != nil {
		return seq - 1, change - 1, firstMessageID, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO chat_support_message_labels(message_id,service_name,service_revision) VALUES(?,?,?)`,
		messageID, grant.ServiceName, string(grant.ServiceRevision)); err != nil {
		return seq - 1, change - 1, firstMessageID, ports.ErrUnavailable
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at)
		VALUES(?,?,?,1,'message.accepted',UTC_TIMESTAMP(6))`, string(cid), change, messageID); err != nil {
		return seq - 1, change - 1, firstMessageID, ports.ErrUnavailable
	}
	return seq, change, messageID, nil
}