package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

type Evidence struct {
	AV          *AVEvidence `json:"av,omitempty"`
	Case        Case        `json:"case"`
	Text        *string     `json:"text,omitempty"`
	Context     *string     `json:"context,omitempty"`
	Items       []ImageView `json:"items"`
	Unavailable bool        `json:"unavailable"`
	URL         string      `json:"url,omitempty"`
}

func (g *GovernanceService) caseLock(ctx context.Context, tx *sql.Tx, id ports.ID) (Case, error) {
	var cid, subject ports.ID
	if tx.QueryRowContext(ctx, `SELECT CAST(conversation_id AS CHAR),CAST(subject_id AS CHAR) FROM chat_cases WHERE id=?`, string(id)).Scan(&cid, &subject) != nil {
		return Case{}, ErrResync
	}
	// Same actor -> conversation order used by send, ban and publication.
	if _, err := restrictionLocked(ctx, tx, subject); err != nil {
		return Case{}, err
	}
	if cid != "0" {
		var found ports.ID
		if tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_conversations WHERE id=? FOR UPDATE`, string(cid)).Scan(&found) != nil {
			return Case{}, ErrResync
		}
	}
	return scanCase(tx.QueryRowContext(ctx, `SELECT `+caseColumns+` FROM chat_cases WHERE id=? AND retain_until>UTC_TIMESTAMP(6) FOR UPDATE`, string(id)))
}
func caseImageItems(ctx context.Context, tx *sql.Tx, c Case) ([]ImageView, error) {
	items := []ImageView{}
	if c.TargetType == "message" {
		query := `SELECT CAST(i.item_id AS CHAR),i.ordinal,u.width_px,u.height_px FROM chat_image_items i JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version WHERE i.message_id=?`
		args := []any{string(c.Target)}
		if c.Item != "0" {
			query += ` AND i.item_id=?`
			args = append(args, string(c.Item))
		}
		query += ` ORDER BY i.ordinal LIMIT 9`
		rows, err := tx.QueryContext(ctx, query, args...)
		if err != nil {
			return nil, ports.ErrUnavailable
		}
		for rows.Next() {
			var v ImageView
			if rows.Scan(&v.ItemID, &v.Ordinal, &v.Width, &v.Height) != nil {
				rows.Close()
				return nil, ports.ErrUnavailable
			}
			items = append(items, v)
		}
		err = rows.Err()
		rows.Close()
		if err != nil {
			return nil, ports.ErrUnavailable
		}
	} else if c.TargetType == "image_intent" {
		var raw, fp string
		if tx.QueryRowContext(ctx, `SELECT manifest_json,manifest_hash FROM chat_image_bundles WHERE intent_id=?`, string(c.Target)).Scan(&raw, &fp) != nil || fp != c.Fingerprint {
			return nil, ErrMediaVersion
		}
		var q Request
		if json.Unmarshal([]byte(raw), &q) != nil || len(q.Items) < 1 || len(q.Items) > 9 {
			return nil, ports.ErrUnavailable
		}
		for _, v := range q.Items {
			items = append(items, ImageView{ItemID: v.AttachmentID, Ordinal: v.Ordinal})
		}
	}
	return items, nil
}
func (g *GovernanceService) Evidence(ctx context.Context, p ports.Principal, id, expected, item ports.ID, reason string) (Evidence, error) {
	if !validID(id) || !validID(expected) || item != "" && !validID(item) || !reasonValid(reason) {
		return Evidence{}, ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return Evidence{}, err
	}
	defer tx.Rollback()
	if err = g.admin(ctx, tx, p, "chat:evidence"); err != nil {
		return Evidence{}, err
	}
	c, err := g.caseLock(ctx, tx, id)
	if err != nil {
		return Evidence{}, err
	}
	if c.Version != expected {
		return Evidence{}, ErrMediaVersion
	}
	out := Evidence{Case: c, Items: []ImageView{}}
	switch c.TargetType {
	case "risk":
		var text, context, fp string
		if tx.QueryRowContext(ctx, `SELECT text_evidence,context_evidence,content_fingerprint FROM chat_risk_events WHERE id=? AND retain_until>UTC_TIMESTAMP(6)`, string(c.Target)).Scan(&text, &context, &fp) != nil || fp != c.Fingerprint {
			out.Unavailable = true
		} else {
			out.Text = &text
			out.Context = &context
		}
	case "message":
		var text sql.NullString
		var fp string
		if tx.QueryRowContext(ctx, `SELECT m.text_body,SHA2(CONCAT(m.id,':',m.client_message_id,':',m.safety_rule_version),256) FROM chat_messages m WHERE m.id=? AND m.conversation_id=? `, string(c.Target), string(c.Conversation)).Scan(&text, &fp) != nil || fp != c.Fingerprint {
			out.Unavailable = true
		} else if text.Valid {
			out.Text = &text.String
		}
	case "restriction":
		out.Unavailable = false
	case "image_intent", "media_intent":
	default:
		return out, ports.ErrInvalid
	}
	out.Items, err = caseImageItems(ctx, tx, c)
	if err != nil {
		return out, err
	}
	av, mediaURL, e := g.avEvidenceURL(ctx, tx, p, c, item)
	if e != nil {
		return out, e
	}
	out.AV = av
	if mediaURL != "" {
		out.URL = mediaURL
	}
	if item != "" && av == nil {
		found := false
		for _, v := range out.Items {
			if v.ItemID == item {
				found = true
			}
		}
		if !found || g.media == nil {
			return out, ErrMedia
		}
		var key string
		if c.TargetType == "message" {
			err = tx.QueryRowContext(ctx, `SELECT u.main_key FROM chat_image_items i JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version WHERE i.message_id=? AND i.item_id=?`, string(c.Target), string(item)).Scan(&key)
		} else {
			var raw string
			if tx.QueryRowContext(ctx, `SELECT manifest_json FROM chat_image_bundles WHERE intent_id=?`, string(c.Target)).Scan(&raw) != nil {
				return out, ErrMedia
			}
			var q Request
			json.Unmarshal([]byte(raw), &q)
			version := ""
			for _, v := range q.Items {
				if v.AttachmentID == item {
					version = v.ObjectVersion
				}
			}
			err = tx.QueryRowContext(ctx, `SELECT main_key FROM chat_image_uploads WHERE id=? AND object_version=? AND legal_hold=1`, string(item), version).Scan(&key)
		}
		if err != nil || key == "" {
			out.Unavailable = true
		} else {
			out.URL, err = g.media.storage.Sign(ctx, key, 60*time.Second)
			if err != nil {
				return out, ErrMedia
			}
		}
	}
	action := "case.evidence"
	if out.Unavailable {
		action = "evidence.unavailable"
	}
	if item != "" && out.URL == "" {
		action = "evidence.unavailable"
	}
	if err = governanceLog(ctx, tx, p, action, c.Conversation, c.ID, item, c.Version, reason); err != nil {
		return out, err
	}
	if err = g.commit(tx, p); err != nil {
		return out, err
	}
	return out, nil
}

type CaseDecision struct {
	ID       ports.ID `json:"case_id"`
	Expected ports.ID `json:"expected_version"`
	Action   string   `json:"action"`
	Reason   string   `json:"reason"`
}

func (g *GovernanceService) Decide(ctx context.Context, p ports.Principal, q CaseDecision) (Case, error) {
	if !validID(q.ID) || !validID(q.Expected) || !reasonValid(q.Reason) || q.Action != "confirm" && q.Action != "correct" && q.Action != "dismiss" && q.Action != "block" {
		return Case{}, ports.ErrInvalid
	}
	tx, err := g.base.begin(ctx)
	if err != nil {
		return Case{}, err
	}
	defer tx.Rollback()
	if err = g.admin(ctx, tx, p, "chat:moderate"); err != nil {
		return Case{}, err
	}
	c, err := g.caseLock(ctx, tx, q.ID)
	if err != nil {
		return c, err
	}
	if c.Version != q.Expected || c.State != "pending" {
		return c, ErrMediaVersion
	}
	var n int
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_governance_audit WHERE actor_kind=? AND actor_id=? AND session_binding=? AND action='case.evidence' AND target_id=? AND target_version=? AND item_id IS NULL AND created_at>UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE`, p.Kind, string(p.ActorID), bindingFor(g.environment, p), string(c.ID), string(c.Version)).Scan(&n) != nil || n == 0 {
		return c, ErrMedia
	}
	// A visible metadata list or thumbnail cannot satisfy full image evidence.
	items, err := caseImageItems(ctx, tx, c)
	if err != nil {
		return c, err
	}
	for _, v := range items {
		if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_governance_audit WHERE actor_kind=? AND actor_id=? AND session_binding=? AND action='case.evidence' AND target_id=? AND target_version=? AND item_id=? AND created_at>UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE`, p.Kind, string(p.ActorID), bindingFor(g.environment, p), string(c.ID), string(c.Version), string(v.ItemID)).Scan(&n) != nil || n == 0 {
			return c, ErrMedia
		}
	}
	av, _, avErr := caseAV(ctx, tx, c)
	if avErr != nil {
		return c, avErr
	}
	if av != nil {
		if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_governance_audit WHERE actor_kind=? AND actor_id=? AND session_binding=? AND action='case.evidence' AND target_id=? AND target_version=? AND item_id=? AND created_at>UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE`, p.Kind, string(p.ActorID), bindingFor(g.environment, p), string(c.ID), string(c.Version), string(av.ItemID)).Scan(&n) != nil || n == 0 {
			return c, ErrMedia
		}
	}
	switch c.TargetType {
	case "risk":
		var riskState, fp string
		if tx.QueryRowContext(ctx, `SELECT review_state,content_fingerprint FROM chat_risk_events WHERE id=? AND retain_until>UTC_TIMESTAMP(6) FOR UPDATE`, string(c.Target)).Scan(&riskState, &fp) != nil || fp != c.Fingerprint {
			return c, ErrResync
		}
		if riskState == "corrected" && q.Action == "confirm" {
			return c, ErrMediaVersion
		}
		if q.Action == "block" {
			return c, ports.ErrInvalid
		}
		if q.Action == "correct" {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_risk_events SET confirmed=0,review_state='corrected' WHERE id=? AND content_fingerprint=?`, string(c.Target), c.Fingerprint); err != nil {
				return c, ports.ErrUnavailable
			}
		} else if q.Action == "confirm" {
			if _, err = tx.ExecContext(ctx, `UPDATE chat_risk_events SET confirmed=1,review_state='confirmed' WHERE id=? AND content_fingerprint=? AND review_state<>'corrected'`, string(c.Target), c.Fingerprint); err != nil {
				return c, ports.ErrUnavailable
			}
		}
		if q.Action == "correct" || q.Action == "confirm" {
			code := "CHAT_CONTENT_BLOCKED"
			if q.Action == "correct" {
				code = "CHAT_REVIEW_CORRECTED"
			}
			if _, err = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='rejected',error_code=?,updated_at=UTC_TIMESTAMP(6) WHERE risk_event_id=? AND state IN ('review_pending','rejected')`, code, string(c.Target)); err != nil {
				return c, ports.ErrUnavailable
			}
		}
		// No send-intent or message is resurrected by a successful appeal.
	case "restriction":
		if q.Action == "block" {
			return c, ports.ErrInvalid
		}
		if q.Action == "correct" {
			r, err := restrictionLocked(ctx, tx, c.Subject)
			if err != nil {
				return c, err
			}
			if r.Version != c.TargetVersion {
				return c, ErrMediaVersion
			}
			if _, err = tx.ExecContext(ctx, `UPDATE chat_restrictions SET permanent=0,blocked_until=NULL,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE actor_id=?`, string(c.Subject)); err != nil {
				return c, ports.ErrUnavailable
			}
		}
	case "message":
		if q.Action == "block" {
			var state string
			var version, seq uint64
			if tx.QueryRowContext(ctx, `SELECT state,state_version,seq FROM chat_messages WHERE id=? AND conversation_id=? FOR UPDATE`, string(c.Target), string(c.Conversation)).Scan(&state, &version, &seq) != nil {
				return c, ErrResync
			}
			if state == "published" {
				if _, err = tx.ExecContext(ctx, `UPDATE chat_conversations SET last_change_seq=last_change_seq+1 WHERE id=?`, string(c.Conversation)); err != nil {
					return c, ports.ErrUnavailable
				}
				if _, err = tx.ExecContext(ctx, `UPDATE chat_messages SET state='blocked',state_version=state_version+1 WHERE id=?`, string(c.Target)); err != nil {
					return c, ports.ErrUnavailable
				}
				if _, err = tx.ExecContext(ctx, `INSERT INTO chat_message_changes(conversation_id,change_seq,message_id,state_version,change_type,created_at) SELECT id,last_change_seq,?,?, 'message.blocked',UTC_TIMESTAMP(6) FROM chat_conversations WHERE id=?`, string(c.Target), version+1, string(c.Conversation)); err != nil {
					return c, ports.ErrUnavailable
				}
			}
		}
	case "image_intent", "media_intent":
		if q.Action == "block" {
			return c, ports.ErrInvalid
		}
		// Only the governance result changes. Old rejected manifests stay rejected.
	default:
		return c, ports.ErrInvalid
	}
	if _, err = tx.ExecContext(ctx, `UPDATE chat_cases SET state=?,version=version+1,resolution=?,reviewer_id=?,decided_at=UTC_TIMESTAMP(6) WHERE id=?`, q.Action, q.Reason, string(p.ActorID), string(c.ID)); err != nil {
		return c, ports.ErrUnavailable
	}
	if err = governanceLog(ctx, tx, p, "case."+q.Action, c.Conversation, c.ID, c.Item, decimal(number(c.Version)+1), q.Reason); err != nil {
		return c, err
	}
	c.State = q.Action
	c.Resolution = q.Reason
	c.Version = decimal(number(c.Version) + 1)
	if err = g.commit(tx, p); err != nil {
		return c, err
	}
	if q.Action == "block" && g.base.publisher != nil {
		g.base.publisher.Committed(ports.CommittedMessage{ConversationID: c.Conversation})
	}
	return c, nil
}
