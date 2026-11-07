from pathlib import Path
import subprocess,re

root=Path(__file__).parent/'candidate'
publish=(root/'av_publish.go').read_text()
background=(root/'av_background_asr.go').read_text()

queue=re.search(r"INSERT IGNORE INTO chat_av_asr_requests\(intent_id,ordinal,request_key,state,created_at\) VALUES\(\?,0,\?,'queued',UTC_TIMESTAMP\(6\)\)",publish)
select=re.search(r"SELECT CAST\(r.intent_id AS CHAR\),r.request_key FROM chat_av_asr_requests r JOIN chat_av_jobs j ON j.intent_id=r.intent_id JOIN chat_av_uploads u ON u.id=j.upload_id WHERE r.state='queued' AND r.ordinal=0 AND j.phase='published' AND u.kind='audio' ORDER BY r.created_at,r.intent_id LIMIT 1 FOR UPDATE",background)
if not queue or not select:
    raise SystemExit('queue SQL not found')

cid=subprocess.check_output(["docker","ps","--filter","ancestor=mysql:5.7","--format","{{.ID}}"],text=True).strip().splitlines()
if len(cid)!=1: raise SystemExit('mysql service missing')
cid=cid[0]
def mysql(sql):
    return subprocess.run(["docker","exec","-i",cid,"mysql","--default-character-set=utf8mb4","-uroot","-pfixture-root","--batch","--raw","--skip-column-names"],input=sql,text=True,capture_output=True,check=True).stdout

mysql("""
DROP DATABASE IF EXISTS plugin_first_asr;
CREATE DATABASE plugin_first_asr CHARACTER SET utf8mb4;
USE plugin_first_asr;
CREATE TABLE chat_av_asr_requests(
 intent_id BIGINT UNSIGNED NOT NULL,ordinal TINYINT UNSIGNED NOT NULL,request_key CHAR(64) NOT NULL,
 state VARCHAR(16) NOT NULL,provider_request_id VARCHAR(128) NULL,text_result TEXT NULL,source_proof CHAR(64) NULL,
 metadata_json JSON NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(intent_id,ordinal),UNIQUE KEY uq_key(request_key));
CREATE TABLE chat_av_jobs(intent_id BIGINT UNSIGNED PRIMARY KEY,upload_id BIGINT UNSIGNED NOT NULL,phase VARCHAR(32) NOT NULL,principal_json JSON NOT NULL);
CREATE TABLE chat_av_uploads(id BIGINT UNSIGNED PRIMARY KEY,kind VARCHAR(16) NOT NULL,transcript TEXT NULL,asr_metadata JSON NULL);
INSERT INTO chat_av_jobs VALUES(35,8,'published','{}'),(36,9,'ready','{}'),(37,10,'published','{}');
INSERT INTO chat_av_uploads VALUES(8,'audio','插件文字',NULL),(9,'audio',NULL,NULL),(10,'video',NULL,NULL);
INSERT INTO chat_av_asr_requests(intent_id,ordinal,request_key,state,created_at) VALUES
 (35,0,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','queued',UTC_TIMESTAMP(6)),
 (36,0,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','queued',UTC_TIMESTAMP(6)),
 (37,0,'cccccccccccccccccccccccccccccccc','queued',UTC_TIMESTAMP(6));
""")
out=mysql("""USE plugin_first_asr;
SELECT r.intent_id FROM chat_av_asr_requests r JOIN chat_av_jobs j ON j.intent_id=r.intent_id JOIN chat_av_uploads u ON u.id=j.upload_id
WHERE r.state='queued' AND r.ordinal=0 AND j.phase='published' AND u.kind='audio' ORDER BY r.created_at,r.intent_id LIMIT 1 FOR UPDATE;
""").strip()
if out!='35': raise SystemExit('background selector leaked non-published/non-audio rows: '+out)
print('PLUGIN_FIRST_MYSQL57_OK')
