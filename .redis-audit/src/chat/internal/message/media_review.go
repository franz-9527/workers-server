package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type ImageReview struct {
	IntentID     ports.ID          `json:"intent_id"`
	Version      ports.ID          `json:"expected_version"`
	ManifestHash string            `json:"manifest_hash"`
	SubmittedAt  time.Time         `json:"submitted_at"`
	ExpiresAt    time.Time         `json:"expires_at"`
	State        string            `json:"review_state"`
	Items        []MediaItemStatus `json:"items"`
}

func (m *MediaService) admin(ctx context.Context, tx *sql.Tx, p ports.Principal) error {
	if p.Environment != m.environment {
		return auth.ErrUnauthorized
	}
	a, e := auth.NewMySQLSource(m.base.db)
	if e != nil {
		return e
	}
	return a.ValidateMediaAdminLocked(ctx, tx, p)
}
func (m *MediaService) Reviews(ctx context.Context, p ports.Principal, after ports.ID) ([]ImageReview, error) {
	if after == "" {
		after = "0"
	}
	if !after.Valid() {
		return nil, ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return nil, e
	}
	defer tx.Rollback()
	if e = m.admin(ctx, tx, p); e != nil {
		return nil, e
	}
	rows, e := tx.QueryContext(ctx, `SELECT CAST(b.intent_id AS CHAR),CAST(b.review_version AS CHAR),b.manifest_hash,b.submitted_at,b.expires_at,b.review_state,b.manifest_json FROM chat_image_bundles b JOIN chat_send_intents i ON i.id=b.intent_id WHERE b.intent_id>? AND b.review_state IN ('pending','claimed') AND b.expires_at>UTC_TIMESTAMP(6) AND i.state='review_pending' ORDER BY b.intent_id LIMIT 20`, string(after))
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	list := []ImageReview{}
	for rows.Next() {
		var v ImageReview
		var raw string
		if rows.Scan(&v.IntentID, &v.Version, &v.ManifestHash, &v.SubmittedAt, &v.ExpiresAt, &v.State, &raw) != nil {
			rows.Close()
			return nil, ports.ErrUnavailable
		}
		var q Request
		if json.Unmarshal([]byte(raw), &q) != nil {
			rows.Close()
			return nil, ports.ErrUnavailable
		}
		for _, i := range q.Items {
			i.ItemID = i.AttachmentID
			v.Items = append(v.Items, MediaItemStatus{MediaItem: i, State: "review_pending"})
		}
		list = append(list, v)
	}
	e = rows.Err()
	rows.Close()
	if e != nil || tx.Commit() != nil {
		return nil, ports.ErrUnavailable
	}
	return list, nil
}
func imageAudit(ctx context.Context, tx *sql.Tx, id ports.ID, p ports.Principal, action, reason, hash string, version ports.ID, item ports.ID) error {
	_, e := tx.ExecContext(ctx, `INSERT INTO chat_image_audit(intent_id,admin_id,action,review_version,manifest_hash,reason,item_id,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))`, string(id), string(p.ActorID), action, string(version), hash, reason, nullableID(item))
	if e != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (m *MediaService) Review(ctx context.Context, p ports.Principal, id, expected ports.ID, action, reason string, general, group bool) (ImageReview, error) {
	if !validID(id) || !validID(expected) || (action != "claim" && action != "approve" && action != "reject") || utf8.RuneCountInString(reason) < 2 || utf8.RuneCountInString(reason) > 200 {
		return ImageReview{}, ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return ImageReview{}, e
	}
	defer tx.Rollback()
	if e = m.admin(ctx, tx, p); e != nil {
		return ImageReview{}, e
	}
	var v ImageReview
	v.IntentID = id
	var raw, rule string
	var reviewer sql.NullString
	var lease, live bool
	e = tx.QueryRowContext(ctx, `SELECT CAST(review_version AS CHAR),manifest_hash,submitted_at,expires_at,review_state,manifest_json,rule_version,CAST(reviewer_id AS CHAR),COALESCE(review_lease_until>UTC_TIMESTAMP(6),0),expires_at>UTC_TIMESTAMP(6) FROM chat_image_bundles WHERE intent_id=? FOR UPDATE`, string(id)).Scan(&v.Version, &v.ManifestHash, &v.SubmittedAt, &v.ExpiresAt, &v.State, &raw, &rule, &reviewer, &lease, &live)
	if e != nil || v.Version != expected || !live || rule != ImageRule {
		return v, ErrMediaVersion
	}
	var q Request
	if json.Unmarshal([]byte(raw), &q) != nil {
		return v, ports.ErrUnavailable
	}
	if action == "claim" {
		if v.State != "pending" && !(v.State == "claimed" && (!lease || reviewer.String == string(p.ActorID))) {
			return v, ErrMediaVersion
		}
		_, e = tx.ExecContext(ctx, `UPDATE chat_image_bundles SET review_state='claimed',reviewer_id=?,review_lease_until=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE,review_version=review_version+1 WHERE intent_id=?`, string(p.ActorID), string(id))
		v.State = "claimed"
	} else {
		if v.State != "claimed" || !lease || reviewer.String != string(p.ActorID) {
			return v, ErrMediaVersion
		}
		if action == "approve" {
			if !general || !group {
				return v, ports.ErrInvalid
			}
			for _, item := range q.Items {
				var viewed int
				if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_image_audit WHERE intent_id=? AND admin_id=? AND review_version=? AND action='view' AND item_id=? AND manifest_hash=?`, string(id), string(p.ActorID), string(expected), string(item.AttachmentID), v.ManifestHash).Scan(&viewed) != nil || viewed == 0 {
					return v, ErrMedia
				}
			}
		}
		next := "approved"
		result := "passed"
		if action == "reject" {
			next = "rejected"
			result = "blocked"
		}
		_, e = tx.ExecContext(ctx, `UPDATE chat_image_bundles SET review_state=?,general_safety_result=?,anti_diversion_result=?,review_reason=?,review_version=review_version+1,review_lease_until=NULL WHERE intent_id=?`, next, result, result, reason, string(id))
		v.State = next
	}
	if e != nil {
		return v, ports.ErrUnavailable
	}
	if e = imageAudit(ctx, tx, id, p, action, reason, v.ManifestHash, expected, ""); e != nil {
		return v, e
	}
	v.Version = decimal(number(v.Version) + 1)
	if !time.Now().Before(p.ExpiresAt) {
		return v, auth.ErrUnauthorized
	}
	if tx.Commit() != nil {
		return v, ports.ErrUnavailable
	}
	// Publication is a separate canonical authority/intent transaction. Approval
	// never impersonates the user or overrides cancel/expiry/closure/logout.

	return v, nil
}
