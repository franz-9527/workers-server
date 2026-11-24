package message

import (
 "context"
 "database/sql"
 "fmt"
 "os"
 "strings"
 "testing"
 "time"
 "github.com/franz-9527/workers/chat/internal/ports"
 _ "github.com/go-sql-driver/mysql"
)
// Minimal synthetic SQL fixture exercises the actual CleanSandboxOne method,
// not the full production schema, authorization or media storage pipeline.
type Service struct{db *sql.DB;sandboxCleanupAfter ports.ID}
func(s *Service)begin(ctx context.Context)(*sql.Tx,error){return s.db.BeginTx(ctx,&sql.TxOptions{Isolation:sql.LevelReadCommitted})}
func TestSandboxCleanupNoRepeatWrites(t *testing.T){
 addr:=os.Getenv("CHAT_INTEGRATION_MYSQL_ADDR");if !strings.HasPrefix(addr,"127.0.0.1:"){t.Fatal("fixture required")}
 pw:=os.Getenv("CHAT_INTEGRATION_ADMIN_PASSWORD")
 db,e:=sql.Open("mysql",fmt.Sprintf("root:%s@tcp(%s)/redis_review?parseTime=true",pw,addr));if e!=nil{t.Fatal(e)};defer db.Close()
 ctx,cancel:=context.WithTimeout(context.Background(),30*time.Second);defer cancel()
 exec:=func(q string){t.Helper();if _,e:=db.ExecContext(ctx,q);e!=nil{t.Fatalf("%s: %v",q,e)}}
 ddl:=[]string{
 "CREATE TABLE chat_conversations(id BIGINT PRIMARY KEY,scope_key VARCHAR(96),state VARCHAR(32),last_message_id BIGINT NULL,last_message_at DATETIME(6) NULL)",
 "CREATE TABLE chat_sandbox_rooms(id BIGINT PRIMARY KEY)",
 "CREATE TABLE chat_call_sessions(conversation_id BIGINT,state VARCHAR(32))",
 "CREATE TABLE chat_members(conversation_id BIGINT,can_read INT,can_send INT)",
 "CREATE TABLE chat_send_intents(id BIGINT PRIMARY KEY,conversation_id BIGINT,state VARCHAR(32),error_code VARCHAR(64),lease_owner VARCHAR(64),lease_until DATETIME(6),message_id BIGINT NULL)",
 "CREATE TABLE chat_messages(id BIGINT PRIMARY KEY,conversation_id BIGINT,state VARCHAR(32),text_body TEXT)",
 "CREATE TABLE chat_image_items(message_id BIGINT)",
 "CREATE TABLE chat_av_items(message_id BIGINT)",
 "CREATE TABLE chat_text_checks(conversation_id BIGINT)",
 "CREATE TABLE chat_av_asr_requests(intent_id BIGINT,text_result TEXT,metadata_json TEXT)",
 "CREATE TABLE chat_image_bundles(intent_id BIGINT,principal_json TEXT,review_state VARCHAR(32),expires_at DATETIME(6))",
 "CREATE TABLE chat_image_uploads(id BIGINT PRIMARY KEY,conversation_id BIGINT,legal_hold INT,lease_until DATETIME(6),lease_owner VARCHAR(64),expires_at DATETIME(6),state VARCHAR(32),source_cleaned INT)",
 "CREATE TABLE chat_av_uploads(id BIGINT PRIMARY KEY,conversation_id BIGINT,legal_hold INT,expires_at DATETIME(6),state VARCHAR(32))",
 "CREATE TABLE chat_av_jobs(upload_id BIGINT,phase VARCHAR(32),expires_at DATETIME(6),lease_until DATETIME(6),transcript TEXT,principal_json TEXT,segments_json TEXT)",
 "CREATE TABLE chat_image_objects(upload_id BIGINT,created_at DATETIME(6))",
 "CREATE TABLE chat_av_objects(upload_id BIGINT,created_at DATETIME(6))",
 "CREATE TABLE chat_av_parts(upload_id BIGINT,state VARCHAR(32))",
 "CREATE TABLE chat_message_changes(conversation_id BIGINT)",
 "CREATE TABLE chat_av_listens(message_id BIGINT)",
 "CREATE TABLE chat_image_reports(message_id BIGINT)",
 "CREATE TABLE chat_user_preferences(conversation_id BIGINT)",
 "CREATE TABLE chat_member_reads(conversation_id BIGINT)",
 "INSERT INTO chat_conversations(id,scope_key,state) VALUES(1,'sandbox:1','active'),(2,'sandbox:2','active'),(3,'order:3:assignment:3','active')",
 "INSERT INTO chat_image_uploads VALUES(1,1,0,NULL,NULL,UTC_TIMESTAMP(6)+INTERVAL 1 DAY,'queued',0),(2,2,0,NULL,NULL,UTC_TIMESTAMP(6)+INTERVAL 1 DAY,'queued',0),(3,3,0,NULL,NULL,UTC_TIMESTAMP(6)+INTERVAL 1 DAY,'queued',0)",
 "INSERT INTO chat_av_uploads VALUES(1,1,0,UTC_TIMESTAMP(6)+INTERVAL 1 DAY,'uploaded'),(2,2,0,UTC_TIMESTAMP(6)+INTERVAL 1 DAY,'uploaded')",
 "INSERT INTO chat_av_jobs VALUES(1,'prepare',UTC_TIMESTAMP(6)+INTERVAL 1 DAY,NULL,'fixture','{\"actor\":1}','[]'),(2,'prepare',UTC_TIMESTAMP(6)+INTERVAL 1 DAY,NULL,'fixture','{\"actor\":2}','[]')",
 "INSERT INTO chat_send_intents(id,conversation_id,state) VALUES(1,1,'checking'),(2,2,'checking')",
 "INSERT INTO chat_image_bundles VALUES(1,'{\"actor\":1}','waiting',UTC_TIMESTAMP(6)+INTERVAL 1 DAY),(2,'{\"actor\":2}','waiting',UTC_TIMESTAMP(6)+INTERVAL 1 DAY)",
 "INSERT INTO chat_av_parts VALUES(1,'present'),(2,'present')",
 "INSERT INTO chat_image_objects VALUES(1,UTC_TIMESTAMP(6)),(2,UTC_TIMESTAMP(6))",
 "INSERT INTO chat_av_objects VALUES(1,UTC_TIMESTAMP(6)),(2,UTC_TIMESTAMP(6))",
 }
 for _,q:=range ddl{exec(q)}
 service:=&Service{db:db}
 if e=service.CleanSandboxOne(ctx);e!=nil{t.Fatal(e)}
 if e=service.CleanSandboxOne(ctx);e!=nil{t.Fatal(e)}
 var blocked int
 if e=db.QueryRowContext(ctx,"SELECT COUNT(*) FROM chat_image_uploads WHERE state='blocked'").Scan(&blocked);e!=nil||blocked!=2{t.Fatal("rotation or scope",blocked,e)}
 rowsUpdated:=func()uint64{var name string;var n uint64;if e:=db.QueryRowContext(ctx,"SHOW GLOBAL STATUS LIKE 'Innodb_rows_updated'").Scan(&name,&n);e!=nil{t.Fatal(e)};return n}
 before:=rowsUpdated()
 time.Sleep(1100*time.Millisecond)
 for i:=0;i<6;i++{if e=service.CleanSandboxOne(ctx);e!=nil{t.Fatal(e)}}
 after:=rowsUpdated()
 if after!=before{t.Fatalf("repeat cleanup changed %d rows",after-before)}
 t.Logf("repeat cleanup updated rows: %d; orphan rotation and normal conversation isolation verified",after-before)
}
