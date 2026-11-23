package message

import (
	"context"
	"database/sql"
	"strings"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type senderDisplay struct {
	Name    string   `json:"name"`
	Roles   []string `json:"roles"`
}

func captureDisplay(ctx context.Context, tx *sql.Tx, g ports.Grant, actor ports.ID, messageID int64) error {
	if g.ConversationType != ports.DirectScene && g.MemberRole != "support_owner" {
		return nil
	}
	var display string
	var worker, admin bool
	err := tx.QueryRowContext(ctx, `SELECT CASE WHEN w.user_id IS NOT NULL THEN
		CASE WHEN BINARY w.real_name_hash=BINARY d.real_name_hash AND d.verified_name<>'' THEN d.verified_name
		ELSE COALESCE(NULLIF(TRIM(w.worker_nickname),''),CONCAT('师傅',CONVERT(RIGHT(u.public_id,6) USING utf8mb4))) END ELSE
		CASE WHEN u.mobile_masked LIKE '%*%' THEN CONCAT('用户',u.mobile_masked)
			WHEN CHAR_LENGTH(u.mobile_masked)=11 THEN CONCAT('用户',LEFT(u.mobile_masked,3),'****',RIGHT(u.mobile_masked,4))
			ELSE CONCAT('用户',CONVERT(RIGHT(u.public_id,6) USING utf8mb4)) END END,
		w.user_id IS NOT NULL,
		COALESCE(d.admin_user_version=a.authority_version AND d.admin_config_version=cfg.authority_version,0)
		FROM users u
		LEFT JOIN chat_profile_worker_display w ON w.user_id=u.id
		LEFT JOIN chat_profile_authorized_display d ON d.user_id=u.id
		LEFT JOIN chat_staff_authority_versions a ON a.user_id=u.id
		LEFT JOIN chat_staff_authority_versions cfg ON cfg.user_id=0
		WHERE u.id=? LOCK IN SHARE MODE`, string(actor)).Scan(&display, &worker, &admin)
	if err != nil {
		return ports.ErrUnavailable
	}
	var roles []string
	if worker {
		roles = append(roles, "worker")
	}
	if admin {
		roles = append(roles, "admin")
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO chat_message_display(message_id,display_name,role_tags) VALUES(?,?,?)`,
		messageID, display, strings.Join(roles, ","))
	if err != nil {
		return ports.ErrUnavailable
	}
	return nil
}

func hydrateDisplays(ctx context.Context, tx *sql.Tx, views []*MessageView) error {
	if len(views) == 0 {
		return nil
	}
	targets := make(map[ports.ID][]*MessageView, len(views))
	args := make([]any, 0, len(views))
	for _, view := range views {
		if _, exists := targets[view.ID]; !exists {
			args = append(args, string(view.ID))
		}
		targets[view.ID] = append(targets[view.ID], view)
	}
	slots := strings.TrimSuffix(strings.Repeat("?,", len(args)), ",")
	rows, err := tx.QueryContext(ctx, `SELECT CAST(stored.message_id AS CHAR),
		CASE WHEN FIND_IN_SET('worker',stored.role_tags)>0 AND w.user_id IS NOT NULL THEN
			CASE WHEN BINARY w.real_name_hash=BINARY auth.real_name_hash AND auth.verified_name<>'' THEN auth.verified_name
				ELSE COALESCE(NULLIF(TRIM(w.worker_nickname),''),CONCAT('师傅',CONVERT(RIGHT(u.public_id,6) USING utf8mb4))) END
		ELSE CASE WHEN u.mobile_masked LIKE '%*%' THEN CONCAT('用户',u.mobile_masked)
			WHEN CHAR_LENGTH(u.mobile_masked)=11 THEN CONCAT('用户',LEFT(u.mobile_masked,3),'****',RIGHT(u.mobile_masked,4))
			ELSE CONCAT('用户',CONVERT(RIGHT(u.public_id,6) USING utf8mb4)) END END,
		stored.role_tags FROM chat_message_display stored
		JOIN chat_messages m ON m.id=stored.message_id JOIN users u ON u.id=m.sender_id
		LEFT JOIN chat_profile_worker_display w ON w.user_id=u.id
		LEFT JOIN chat_profile_authorized_display auth ON auth.user_id=u.id
		WHERE stored.message_id IN (`+slots+`)`, args...)
	if err != nil {
		return ports.ErrUnavailable
	}
	for rows.Next() {
		var id ports.ID
		var label senderDisplay
		var tags string
		if rows.Scan(&id, &label.Name, &tags) != nil {
			rows.Close()
			return ports.ErrUnavailable
		}
		label.Roles = []string{}
		if tags != "" {
			label.Roles = strings.Split(tags, ",")
		}
		for _, view := range targets[id] {
			copy := label
			view.Display = &copy
		}
	}
	if rows.Err() != nil {
		rows.Close()
		return ports.ErrUnavailable
	}
	rows.Close()
	labels, err := tx.QueryContext(ctx, `SELECT CAST(message_id AS CHAR),service_name,CAST(service_revision AS CHAR)
		FROM chat_support_message_labels WHERE message_id IN (`+slots+`)`, args...)
	if err != nil {
		return ports.ErrUnavailable
	}
	defer labels.Close()
	for labels.Next() {
		var id ports.ID
		var name string
		var version ports.ID
		if labels.Scan(&id,&name,&version) != nil {
			return ports.ErrUnavailable
		}
		for _, view := range targets[id] {
			if view.Display != nil {
				continue
			}
			view.Display=&senderDisplay{Name:name,Roles:[]string{"platform"}}
		}
	}
	if labels.Err() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
