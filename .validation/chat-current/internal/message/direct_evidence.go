package message

import (
	"context"
	"database/sql"
	"slices"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

func (m *MediaService) DirectReportImage(ctx context.Context,p ports.Principal,report,item ports.ID) (any,error) {
	if !validID(report)||!validID(item){return nil,ports.ErrInvalid}
	if p.Environment!=m.environment || !slices.Contains(p.Scopes,"chat:evidence"){return nil,auth.ErrUnauthorized}
	tx,err:=m.base.begin(ctx)
	if err!=nil{return nil,err}
	defer tx.Rollback()
	admin,err:=auth.NewMySQLSource(m.base.db)
	if err!=nil{return nil,err}
	if err=admin.ValidateMediaAdminLocked(ctx,tx,p);err!=nil{return nil,err}
	var reviewer string
	err=tx.QueryRowContext(ctx,`SELECT COALESCE(CAST(report_reviewer_admin_id AS CHAR),'') FROM chat_direct_release WHERE id=1
		LOCK IN SHARE MODE`).Scan(&reviewer)
	if err!=nil{return nil,ports.ErrUnavailable}
	if reviewer!=string(p.ActorID){return nil,auth.ErrUnauthorized}
	var key,hash string
	var intent,version ports.ID
	err=tx.QueryRowContext(ctx,`SELECT u.thumb_key,u.thumb_sha256,CAST(b.intent_id AS CHAR),CAST(m.state_version AS CHAR)
		FROM chat_direct_reports r JOIN chat_messages m ON m.id=r.message_id AND m.conversation_id=r.conversation_id
		JOIN chat_conversations c ON c.id=r.conversation_id AND c.conversation_type='direct'
		JOIN chat_send_intents b ON b.message_id=m.id AND b.state='accepted'
		JOIN chat_image_items i ON i.message_id=m.id JOIN chat_image_uploads u ON u.id=i.item_id AND u.object_version=i.object_version
		JOIN chat_image_objects o ON o.object_key=u.thumb_key AND o.deleted_at IS NULL
		WHERE r.id=? AND i.item_id=? AND r.created_at>=UTC_TIMESTAMP(6)-INTERVAL 30 DAY
		AND m.message_type IN ('image','image_album') AND m.state IN ('published','recalled') AND `+retainedTarget,
		string(report),string(item)).Scan(&key,&hash,&intent,&version)
	if err!=nil{return nil,ErrMedia}
	ttl:=mediaReadTTL(p,sql.NullTime{})
	if ttl<time.Second{return nil,auth.ErrUnauthorized}
	url,err:=m.storage.Sign(ctx,key,ttl)
	if err!=nil{return nil,ErrMedia}
	if err=imageAudit(ctx,tx,intent,p,"direct_report_view","私聊举报 "+string(report),hash,version,item);err!=nil{return nil,err}
	if !time.Now().Before(p.ExpiresAt) || tx.Commit()!=nil{return nil,ports.ErrUnavailable}
	return map[string]any{"url":url,"expires_at":time.Now().Add(ttl).Unix()},nil
}
