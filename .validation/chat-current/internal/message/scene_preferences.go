package message

import (
	"context"
	"database/sql"
	"math"
	"time"

	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

func validPreferenceField(field string) bool {
	switch field {
	case "pinned","later","hidden","archived","muted":
		return true
	default:
		return false
	}
}

func setScenePreference(ctx context.Context,tx *sql.Tx,p ports.Principal,q PreferenceRequest,out Preferences) (Preferences,error) {
	var slot *bool
	switch q.Field {
	case "hidden": slot=&out.Hidden
	case "archived": slot=&out.Archived
	case "muted": slot=&out.Muted
	default: return Preferences{},ports.ErrInvalid
	}
	if !time.Now().Before(p.ExpiresAt){return Preferences{},policy.ErrDenied}
	current:=number(out.OptionVersion)
	if q.Expected!=out.OptionVersion && !(*slot==q.Value && current>0 && number(q.Expected)==current-1) {
		return out,ErrPreferenceConflict
	}
	if *slot!=q.Value {
		if current==math.MaxUint64 || number(out.AccountRevision)==math.MaxUint64{return Preferences{},ports.ErrUnavailable}
		*slot=q.Value
		// Hidden and archived are mutually exclusive views of the same thread.
		if q.Value && q.Field=="hidden"{out.Archived=false}
		if q.Value && q.Field=="archived"{out.Hidden=false}
		out.OptionVersion=decimal(current+1)
		out.AccountRevision=decimal(number(out.AccountRevision)+1)
		_,err:=tx.ExecContext(ctx,`INSERT INTO chat_scene_preferences(conversation_id,actor_id,member_key_hash,hidden,archived,muted,option_version,updated_at)
			VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE member_key_hash=VALUES(member_key_hash),
			hidden=VALUES(hidden),archived=VALUES(archived),muted=VALUES(muted),option_version=VALUES(option_version),updated_at=VALUES(updated_at)`,
			string(q.ConversationID),string(p.ActorID),out.MemberVersion,out.Hidden,out.Archived,out.Muted,string(out.OptionVersion))
		if err!=nil{return Preferences{},ports.ErrUnavailable}
		if _,err=tx.ExecContext(ctx,`UPDATE chat_preference_accounts SET revision=?,updated_at=UTC_TIMESTAMP(6) WHERE actor_id=?`,
			string(out.AccountRevision),string(p.ActorID));err!=nil{return Preferences{},ports.ErrUnavailable}
	}
	if !time.Now().Before(p.ExpiresAt){return Preferences{},policy.ErrDenied}
	if tx.Commit()!=nil{return Preferences{},ports.ErrUnavailable}
	return out,nil
}
