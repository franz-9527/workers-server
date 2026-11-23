package policy

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/ports"
)

type feedbackAccess struct{}

func WithFeedback(ctx context.Context) context.Context {
	return context.WithValue(ctx, feedbackAccess{}, true)
}

func FeedbackOnly(ctx context.Context) bool { return ctx.Value(feedbackAccess{}) == true }

type supportStaffWire struct {
	Actor      ports.ID `json:"actor_id"`
	Generation ports.ID `json:"generation"`
	Assignment ports.ID `json:"assignment_version"`
}

func DecodeSupport(body []byte, scope string, version ports.ID) (Projection, error) {
	var wire struct {
		Schema  int               `json:"schema_version"`
		Scope   string            `json:"scope_key"`
		Service string            `json:"service_key"`
		Owner   ports.ID          `json:"owner_id"`
		Members []json.RawMessage `json:"staff"`
	}
	binding, ok := ports.ParseScope(scope)
	if !positive(version) || len(body) > 2048 || !object(body, &wire, "schema_version", "scope_key", "owner_id", "service_key", "staff") || wire.Schema != 6 || len(wire.Members) > 4 ||
		wire.Scope != scope || !positive(wire.Owner) || !ok || binding.Scene != ports.SupportScene ||
		(binding.Layout != "platform_feedback" && binding.Layout != "platform_feedback_test") || wire.Service != binding.Layout || binding.First != wire.Owner {
		return Projection{}, ErrDenied
	}
	sum := sha256.Sum256(body)
	result := Projection{ConversationType: ports.SupportScene, Mode: "support", Scope: scope, Episode: scope, State: "open", Version: version, Hash: hex.EncodeToString(sum[:])}
	member := func(actor ports.ID, role, key string) ProjectedMember {
		return ProjectedMember{ActorID: actor, Role: role, Grant: ports.Grant{ConversationType: ports.SupportScene, ScopeKey: scope, Version: version, Mode: "support", MemberRole: role, MemberKey: key, CanRead: true, CanSend: true}}
	}
	result.Members = []ProjectedMember{member(wire.Owner, "support_owner", scope+":owner")}
	seen := map[ports.ID]bool{wire.Owner: true}
	for _, raw := range wire.Members {
		var entry supportStaffWire
		if !object(raw, &entry, "actor_id", "generation", "assignment_version") || seen[entry.Actor] ||
			!positive(entry.Actor) || !positive(entry.Generation) || !positive(entry.Assignment) ||
			len(entry.Assignment) > len(version) || len(entry.Assignment) == len(version) && entry.Assignment > version {
			return Projection{}, ErrDenied
		}
		seen[entry.Actor] = true
		m := member(entry.Actor, "support_staff", scope+":staff:"+string(entry.Actor)+":"+string(entry.Generation)+":"+string(entry.Assignment))
		m.Grant.SupportStaffGeneration, m.Grant.SupportAssignmentVersion = entry.Generation, entry.Assignment
		result.Members = append(result.Members, m)
	}
	return result, nil
}

func SupportSnapshot(ctx context.Context, tx *sql.Tx, scope string) (Projection, error) {
	var body, owner, hash string
	var version ports.ID
	err := tx.QueryRowContext(ctx, `SELECT snapshot_json,CAST(owner_id AS CHAR),snapshot_hash,CAST(policy_version AS CHAR) FROM chat_support_guards WHERE scope_key=? LOCK IN SHARE MODE`, scope).Scan(&body, &owner, &hash, &version)
	if err == sql.ErrNoRows {
		return Projection{}, ErrDenied
	}
	if err != nil {
		return Projection{}, ports.ErrUnavailable
	}
	projection, err := DecodeSupport([]byte(body), scope, version)
	if err != nil || projection.Hash != hash || string(projection.Members[0].ActorID) != owner {
		return Projection{}, ErrDenied
	}
	return projection, nil
}

type supportService struct {
	name, autoReplyText       string
	revision, imageGeneration ports.ID
	enabled                   bool
	autoReplyEnabled          bool
}

func supportSettings(ctx context.Context, tx *sql.Tx) (supportService, error) {
	var settings supportService
	var autoReply string
	err := tx.QueryRowContext(ctx, `SELECT s.enabled,s.display_name,CAST(s.revision AS CHAR),CAST(s.image_generation AS CHAR),
		COALESCE((SELECT setting_value FROM system_settings WHERE setting_key='chat.support.auto_reply_enabled'),'0'),
		COALESCE((SELECT setting_value FROM system_settings WHERE setting_key='chat.support.auto_reply_text'),'')
		FROM chat_support_service s WHERE s.service_key='platform_feedback' LOCK IN SHARE MODE`).
		Scan(&settings.enabled, &settings.name, &settings.revision, &settings.imageGeneration, &autoReply, &settings.autoReplyText)
	if err != nil {
		return settings, ports.ErrUnavailable
	}
	settings.autoReplyEnabled = autoReply == "1" || strings.EqualFold(autoReply, "true")
	if !settings.enabled || !positive(settings.revision) ||
		settings.name == "" || !utf8.ValidString(settings.name) || utf8.RuneCountInString(settings.name) > 40 ||
		settings.autoReplyEnabled && (strings.TrimSpace(settings.autoReplyText) == "" || !utf8.ValidString(settings.autoReplyText) ||
			utf8.RuneCountInString(settings.autoReplyText) > 500 || len(settings.autoReplyText) > 2000) {
		return settings, ErrDenied
	}
	return settings, nil
}

func validSupportEpochs(ctx context.Context, tx *sql.Tx, actor ports.ID, userVersion, configVersion string) (bool, error) {
	for _, epoch := range [][2]string{{"0", configVersion}, {string(actor), userVersion}} {
		var current string
		err := tx.QueryRowContext(ctx, `SELECT CAST(authority_version AS CHAR) FROM chat_staff_authority_versions WHERE user_id=? LOCK IN SHARE MODE`, epoch[0]).Scan(&current)
		if err == sql.ErrNoRows || err == nil && current != epoch[1] {
			return false, nil
		}
		if err != nil {
			return false, ports.ErrUnavailable
		}
	}
	return true, nil
}

func supportStaffGeneration(ctx context.Context, tx *sql.Tx, actor ports.ID) (ports.ID, error) {
	var generation ports.ID
	var userVersion, configVersion string
	err := tx.QueryRowContext(ctx, `SELECT CAST(generation AS CHAR),CAST(user_version AS CHAR),CAST(config_version AS CHAR)
		FROM chat_support_roster WHERE actor_id=? AND enabled=1 LOCK IN SHARE MODE`, string(actor)).Scan(&generation, &userVersion, &configVersion)
	if err == sql.ErrNoRows {
		return "", ErrDenied
	}
	if err != nil {
		return "", ports.ErrUnavailable
	}
	valid, err := validSupportEpochs(ctx, tx, actor, userVersion, configVersion)
	if err != nil {
		return "", err
	}
	if !valid || !positive(generation) {
		return "", ErrDenied
	}
	return generation, nil
}

func SupportStaffBinding(ctx context.Context, tx *sql.Tx, actor ports.ID) (ports.ID, ports.ID, error) {
	settings, err := supportSettings(ctx, tx)
	if err != nil {
		return "", "", err
	}
	generation, err := supportStaffGeneration(ctx, tx, actor)
	return generation, settings.revision, err
}

func testSupportScope(scope string) bool {
	binding, ok := ports.ParseScope(scope)
	return ok && binding.Scene == ports.SupportScene && binding.Layout == "platform_feedback_test"
}

func (r *Reader) SupportAuthority(ctx context.Context, tx *sql.Tx, scope string, actor ports.Principal) (Projection, ports.Grant, error) {
	if !r.actor(actor, time.Now()) || testSupportScope(scope) && r.environment != "test" {
		return Projection{}, ports.Grant{}, ErrDenied
	}
	if _, sandbox := ports.SandboxSource(actor.SourceID); sandbox {
		if r.environment != "test" || !ports.SandboxPrincipal(actor) || !testSupportScope(scope) {
			return Projection{}, ports.Grant{}, ErrDenied
		}
		return supportAuthority(ctx, tx, scope, actor.ActorID, false, true)
	}
	return supportAuthority(ctx, tx, scope, actor.ActorID, false, false)
}

func SupportReminderRecipient(ctx context.Context, tx *sql.Tx, scope string, actor ports.ID) (Projection, ports.Grant, error) {
	if testSupportScope(scope) {
		return Projection{}, ports.Grant{}, ErrDenied
	}
	return supportAuthority(ctx, tx, scope, actor, true, false)
}

func supportAuthority(ctx context.Context, tx *sql.Tx, scope string, actor ports.ID, reminder, sandboxOwner bool) (Projection, ports.Grant, error) {
	settings, err := supportSettings(ctx, tx)
	if err != nil {
		return Projection{}, ports.Grant{}, err
	}
	projection, err := SupportSnapshot(ctx, tx, scope)
	if err != nil {
		return Projection{}, ports.Grant{}, err
	}
	for _, member := range projection.Members {
		if member.ActorID != actor {
			continue
		}
		testScope := testSupportScope(scope)
		if sandboxOwner {
			if !testScope || member.Role != "support_owner" {
				return Projection{}, ports.Grant{}, ErrDenied
			}
		} else if testScope && member.Role == "support_owner" {
			// Test Support owners must arrive through a validated sandbox session;
			// real staff remain ordinary principals so they can handle test feedback.
			return Projection{}, ports.Grant{}, ErrDenied
		}
		if sandboxOwner {
			err = nil
		} else if reminder {
			err = activeReminderIdentity(ctx, tx, actor)
		} else {
			err = activeMiniIdentity(ctx, tx, actor)
		}
		if err != nil {
			return Projection{}, ports.Grant{}, err
		}
		grant := member.Grant
		grant.ServiceName, grant.ServiceRevision = settings.name, settings.revision
		grant.ServiceAutoReplyEnabled, grant.ServiceAutoReplyText = settings.autoReplyEnabled, settings.autoReplyText
		grant.ServiceImagesEnabled = positive(settings.imageGeneration)
		grant.ServiceImageGeneration = settings.imageGeneration
		grant.SupportHistoryThrough = "0"
		if member.Role == "support_staff" {
			generation, err := supportStaffGeneration(ctx, tx, actor)
			if err != nil {
				return Projection{}, ports.Grant{}, err
			}
			if generation != grant.SupportStaffGeneration {
				return Projection{}, ports.Grant{}, ErrDenied
			}
			err = tx.QueryRowContext(ctx, `SELECT CAST(through_seq AS CHAR) FROM chat_support_history_access
				WHERE scope_key=? AND actor_id=? AND assignment_version=? LOCK IN SHARE MODE`,
				scope, string(actor), string(grant.SupportAssignmentVersion)).Scan(&grant.SupportHistoryThrough)
			if err != nil && err != sql.ErrNoRows {
				return Projection{}, ports.Grant{}, ports.ErrUnavailable
			}
		}
		return projection, grant, nil
	}
	return Projection{}, ports.Grant{}, ErrDenied
}
