package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type MediaAccess struct {
	CloudAvailable   bool       `json:"cloud_available"`
	URL              string     `json:"url"`
	ExpiresAt        int64      `json:"expires_at"`
	ObjectHash       string     `json:"object_hash"`
	RetentionMode    string     `json:"retention_mode"`
	MessageExpiresAt *time.Time `json:"cloud_expires_at,omitempty"`
	StateVersion     ports.ID   `json:"state_version"`
	MIME             string     `json:"mime_type"`
	ServerTime       time.Time  `json:"server_time"`
}

func hydrateImages(ctx context.Context, tx *sql.Tx, views []*MessageView) error {
	ids := []string{}
	args := []any{}
	byID := map[ports.ID][]*MessageView{}
	for _, v := range views {
		v.Images = nil
		if v.State == "published" && (v.Type == "image" || v.Type == "image_album") {
			if len(byID[v.ID]) == 0 {
				ids = append(ids, "?")
				args = append(args, string(v.ID))
			}
			byID[v.ID] = append(byID[v.ID], v)
		}
	}
	if len(ids) == 0 {
		return nil
	}
	rows, e := tx.QueryContext(ctx, `SELECT CAST(i.message_id AS CHAR),CAST(i.item_id AS CHAR),i.ordinal,u.width_px,u.height_px FROM chat_image_items i JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version WHERE i.message_id IN (`+strings.Join(ids, ",")+`) ORDER BY i.message_id,i.ordinal`, args...)
	if e != nil {
		return ports.ErrUnavailable
	}
	defer rows.Close()
	for rows.Next() {
		var id ports.ID
		var v ImageView
		if rows.Scan(&id, &v.ItemID, &v.Ordinal, &v.Width, &v.Height) != nil {
			return ports.ErrUnavailable
		}
		for _, parent := range byID[id] {
			if len(parent.Images) >= 9 {
				return ports.ErrUnavailable
			}
			parent.Images = append(parent.Images, v)
			parent.ImageCount = len(parent.Images)
		}
	}
	if rows.Err() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (m *MediaService) URL(ctx context.Context, p ports.Principal, cid, mid, item ports.ID, thumb bool) (string, error) {
	access, e := m.URLInfo(ctx, p, cid, mid, item, thumb)
	return access.URL, e
}
func (m *MediaService) URLInfo(ctx context.Context, p ports.Principal, cid, mid, item ports.ID, thumb bool) (MediaAccess, error) {
	if !validID(cid) || !validID(mid) || !validID(item) {
		return MediaAccess{}, ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return MediaAccess{}, e
	}
	defer tx.Rollback()
	g, e := m.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"})
	if e != nil {
		return MediaAccess{}, e
	}
	var key string
	var expiry sql.NullTime
	var hash, mode, mime string
	var version ports.ID
	column := "u.main_key"
	hashColumn := "u.main_sha256"
	if thumb {
		column = "u.thumb_key"
		hashColumn = "u.thumb_sha256"
	}
	e = tx.QueryRowContext(ctx, `SELECT `+column+`,m.expires_at,`+hashColumn+`,m.retention_mode,CAST(m.state_version AS CHAR),u.mime_type FROM chat_messages m JOIN chat_image_items i ON i.message_id=m.id JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version WHERE m.conversation_id=? AND m.id=? AND i.item_id=? AND m.state='published' AND m.message_type IN ('image','image_album') AND `+retainedTarget+` AND `+visibleColumn, string(cid), string(mid), string(item), string(g.Version), string(p.ActorID)).Scan(&key, &expiry, &hash, &mode, &version, &mime)
	if e != nil || key == "" {
		return MediaAccess{}, ErrMedia
	}
	var exists bool
	if tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM chat_image_objects WHERE object_key=? AND deleted_at IS NULL)`, key).Scan(&exists) != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	available := exists && (!expiry.Valid || expiry.Time.After(time.Now()))
	accessExpiry := expiry
	if !available {
		accessExpiry = sql.NullTime{}
	}
	ttl := mediaReadTTL(p, accessExpiry)
	if ttl < time.Second {
		return MediaAccess{}, ErrMedia
	}
	url := ""
	if available {
		url, e = m.storage.Sign(ctx, key, ttl)
		if e != nil {
			return MediaAccess{}, ErrMedia
		}
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_image_objects SET last_access_at=UTC_TIMESTAMP(6) WHERE object_key=?`, key); e != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return MediaAccess{}, ports.ErrUnavailable
	}
	if thumb {
		mime = "image/jpeg"
	}
	access := MediaAccess{CloudAvailable: available, URL: url, ExpiresAt: time.Now().Add(ttl).Unix(), ObjectHash: hash, RetentionMode: mode, StateVersion: version, MIME: mime, ServerTime: time.Now().UTC()}
	if expiry.Valid {
		access.MessageExpiresAt = &expiry.Time
	}
	return access, nil
}
func (m *MediaService) ReviewURL(ctx context.Context, p ports.Principal, id, version, item ports.ID) (string, error) {
	if !validID(id) || !validID(version) || !validID(item) {
		return "", ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return "", e
	}
	defer tx.Rollback()
	if e = m.admin(ctx, tx, p); e != nil {
		return "", e
	}
	var raw, hash string
	e = tx.QueryRowContext(ctx, `SELECT manifest_json,manifest_hash FROM chat_image_bundles WHERE intent_id=? AND review_version=? AND reviewer_id=? AND review_state='claimed' AND review_lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6) AND rule_version=? AND EXISTS(SELECT 1 FROM chat_send_intents i WHERE i.id=chat_image_bundles.intent_id AND i.state='review_pending') FOR UPDATE`, string(id), string(version), string(p.ActorID), ImageRule).Scan(&raw, &hash)
	if e != nil {
		return "", ErrMediaVersion
	}
	var q Request
	if json.Unmarshal([]byte(raw), &q) != nil {
		return "", ErrMedia
	}
	found := ""
	for _, v := range q.Items {
		if v.AttachmentID == item {
			found = v.ObjectVersion
		}
	}
	if found == "" {
		return "", ErrMedia
	}
	var key string
	if tx.QueryRowContext(ctx, `SELECT main_key FROM chat_image_uploads WHERE id=? AND object_version=? AND state='processed' AND rule_version=?`, string(item), found, ImageRule).Scan(&key) != nil {
		return "", ErrMedia
	}
	url, e := m.storage.Sign(ctx, key, 60*time.Second)
	if e != nil {
		return "", ErrMedia
	}
	if e = imageAudit(ctx, tx, id, p, "view", "发布前完整图片检查", hash, version, item); e != nil {
		return "", e
	}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit() != nil {
		return "", ports.ErrUnavailable
	}
	return url, nil
}
func (m *MediaService) Status(ctx context.Context, p ports.Principal, cid ports.ID, client string, cancel bool) (MediaStatus, error) {
	if !validID(cid) || !validClient(client) {
		return MediaStatus{}, ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return MediaStatus{}, e
	}
	defer tx.Rollback()
	if _, e = m.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"}); e != nil {
		return MediaStatus{}, e
	}
	i, e := m.base.lookup(ctx, tx, p, cid, client)
	if e != nil {
		return MediaStatus{}, e
	}
	// A cancellation tombstone serializes against first submit via the
	// conversation lock. An unknown action can never be resurrected afterward.
	if i == nil && cancel {
		if !active(client, time.Now()) {
			return MediaStatus{}, ErrExpired
		}
		_, e = tx.ExecContext(ctx, `INSERT INTO chat_send_intents(sender_id,conversation_id,client_message_id,fingerprint,state,error_code,action_expires_at,retain_until,created_at,updated_at) VALUES(?,?,?,?,'canceled','CHAT_ACTION_CANCELED',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, string(p.ActorID), string(cid), client, strings.Repeat("0", 64), expires(client), expires(client).Add(MinimumRetention))
		if e != nil || tx.Commit() != nil {
			return MediaStatus{}, ports.ErrUnavailable
		}
		return MediaStatus{Result: Result{ClientMessageID: client, State: "canceled", Code: "CHAT_ACTION_CANCELED"}}, nil
	}
	if i == nil {
		return MediaStatus{}, ErrUnknown
	}
	result := MediaStatus{Result: i.result, IntentID: i.id, Items: []MediaItemStatus{}}
	var reviewVersion ports.ID
	if err := tx.QueryRowContext(ctx, `SELECT CAST(review_version AS CHAR) FROM chat_image_bundles WHERE intent_id=?`, string(i.id)).Scan(&reviewVersion); err == nil {
		result.ReviewVersion = reviewVersion
	} else if err != sql.ErrNoRows {
		return result, ports.ErrUnavailable
	}
	if i.result.State == "accepted" {
		if tx.Commit() != nil {
			return result, ports.ErrUnavailable
		}
		return result, nil
	}
	var raw, review string
	var live bool
	e = tx.QueryRowContext(ctx, `SELECT manifest_json,revision,manifest_hash,expires_at,review_state,expires_at>UTC_TIMESTAMP(6) FROM chat_image_bundles WHERE intent_id=? FOR UPDATE`, string(i.id)).Scan(&raw, &result.Revision, &result.ManifestHash, &result.ExpiresAt, &review, &live)
	if e == sql.ErrNoRows && i.result.State == "canceled" {
		if tx.Commit() != nil {
			return result, ports.ErrUnavailable
		}
		return result, nil
	}
	if e != nil {
		return result, ErrMedia
	}
	if cancel && i.result.State != "rejected" && i.result.State != "canceled" {
		result.State = "canceled"
		result.Code = "CHAT_ACTION_CANCELED"
	}
	if !live && result.State != "canceled" && result.State != "rejected" {
		result.State = "rejected"
		result.Code = ErrExpired.Error()
	}
	if result.State != i.result.State {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state=?,error_code=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, result.State, result.Code, string(i.id)); e != nil {
			return result, ports.ErrUnavailable
		}
	}
	var q Request
	if json.Unmarshal([]byte(raw), &q) != nil {
		return result, ports.ErrUnavailable
	}
	for _, v := range q.Items {
		var row MediaItemStatus
		row.MediaItem = v
		row.ItemID = v.AttachmentID
		if tx.QueryRowContext(ctx, `SELECT state,COALESCE(error_code,'') FROM chat_image_uploads WHERE id=?`, string(v.AttachmentID)).Scan(&row.State, &row.Code) != nil {
			return result, ports.ErrUnavailable
		}
		result.Items = append(result.Items, row)
	}
	if tx.Commit() != nil {
		return result, ports.ErrUnavailable
	}
	return result, nil
}
func (m *MediaService) Pending(ctx context.Context, p ports.Principal, cid ports.ID) ([]MediaStatus, error) {
	if _, e := m.base.conversations.Authorize(ctx, ports.PolicyRequest{Actor: p, ConversationID: cid, Action: "read"}); e != nil {
		return nil, e
	}
	rows, e := m.base.db.QueryContext(ctx, `SELECT i.client_message_id FROM chat_send_intents i JOIN chat_image_bundles b ON b.intent_id=i.id WHERE i.sender_id=? AND i.conversation_id=? AND i.state IN ('checking','review_pending','retryable') AND b.expires_at>UTC_TIMESTAMP(6) ORDER BY i.id DESC LIMIT 20`, string(p.ActorID), string(cid))
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	clients := []string{}
	for rows.Next() {
		var client string
		if rows.Scan(&client) != nil {
			rows.Close()
			return nil, ports.ErrUnavailable
		}
		clients = append(clients, client)
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	out := []MediaStatus{}
	for _, client := range clients {
		v, err := m.Status(ctx, p, cid, client, false)
		if err != nil {
			return nil, err
		}
		out = append(out, v)
	}
	return out, nil
}

func mediaReadTTL(p ports.Principal, expiry sql.NullTime) time.Duration {
	ttl := min(60*time.Second, time.Until(p.ExpiresAt))
	if expiry.Valid {
		ttl = min(ttl, time.Until(expiry.Time))
	}
	return ttl
}
