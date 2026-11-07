from pathlib import Path
import subprocess

ROOT=Path(__file__).parent
body=(ROOT/'candidate'/'av_worker.go').read_text()
start=body.find("UPDATE chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id JOIN chat_av_uploads u ON u.id=j.upload_id SET ")
end_marker="`, avfallback.ReviewCode)"
if start<0:
    raise SystemExit("recovery SQL start not found")
end=body.find(end_marker,start)
if end<0:
    raise SystemExit("recovery SQL end not found")
query=body[start:end]
if query.count("?")!=1:
    raise SystemExit("unexpected recovery placeholder count")
query=query.replace("?","'CHAT_AV_ASR_PROVIDER_FALLBACK_REVIEW'",1)

container=subprocess.check_output(
    ["docker","ps","--filter","ancestor=mysql:5.7","--format","{{.ID}}"],
    text=True,
).strip().splitlines()
if len(container)!=1:
    raise SystemExit("mysql service container not found")
cid=container[0]

setup=r"""
DROP DATABASE IF EXISTS voice_nonblocking_test;
CREATE DATABASE voice_nonblocking_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE voice_nonblocking_test;
CREATE TABLE chat_send_intents(
 id BIGINT UNSIGNED PRIMARY KEY,
 state VARCHAR(32) NOT NULL,
 error_code VARCHAR(96) NULL,
 lease_owner VARCHAR(64) NULL,
 lease_until DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL
);
CREATE TABLE chat_av_uploads(
 id BIGINT UNSIGNED PRIMARY KEY,
 kind VARCHAR(16) NOT NULL
);
CREATE TABLE chat_av_jobs(
 intent_id BIGINT UNSIGNED PRIMARY KEY,
 upload_id BIGINT UNSIGNED NOT NULL,
 manifest_json JSON NOT NULL,
 transcript TEXT NULL,
 phase VARCHAR(32) NOT NULL,
 raw_trace_id VARCHAR(128) NULL,
 raw_decision VARCHAR(32) NULL,
 error_code VARCHAR(96) NULL,
 next_attempt_at DATETIME(6) NOT NULL,
 lease_owner VARCHAR(64) NULL,
 lease_until DATETIME(6) NULL,
 expires_at DATETIME(6) NOT NULL,
 review_state VARCHAR(32) NOT NULL DEFAULT 'pending',
 review_version INT UNSIGNED NOT NULL DEFAULT 0,
 general_confirmed TINYINT(1) NOT NULL DEFAULT 0,
 group_confirmed TINYINT(1) NOT NULL DEFAULT 0,
 reviewer_id BIGINT UNSIGNED NULL,
 review_lease_until DATETIME(6) NULL
);
CREATE TABLE chat_av_asr_requests(
 intent_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(32) NOT NULL
);
INSERT INTO chat_send_intents VALUES
 (33,'checking','OLD','old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)),
 (34,'checking','OLD','old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)),
 (35,'review_pending','CHAT_AV_ASR_PROVIDER_FALLBACK_REVIEW','old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)),
 (36,'checking','OLD','old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6));
INSERT INTO chat_av_uploads VALUES
 (33,'audio'),(34,'audio'),(35,'audio'),(36,'video');
INSERT INTO chat_av_jobs VALUES
 (33,33,'{"client_transcript":"同声传译兜底"}',NULL,'raw_started','trace33','review',NULL,UTC_TIMESTAMP(6),'old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)+INTERVAL 1 HOUR,'claimed',7,1,1,90001,UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE),
 (34,34,'{"client_transcript":""}',NULL,'unknown','trace34','review','CHAT_AV_RESULT_UNKNOWN',UTC_TIMESTAMP(6),'old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)+INTERVAL 1 HOUR,'claimed',3,1,1,90001,UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE),
 (35,35,'{"client_transcript":"辅助文字"}',NULL,'review_pending','trace35','review','CHAT_AV_ASR_PROVIDER_FALLBACK_REVIEW',UTC_TIMESTAMP(6),'old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)+INTERVAL 1 HOUR,'claimed',5,1,1,90001,UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE),
 (36,36,'{"client_transcript":""}',NULL,'raw_started','trace36','review',NULL,UTC_TIMESTAMP(6),'old',UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE,UTC_TIMESTAMP(6)+INTERVAL 1 HOUR,'claimed',4,1,1,90001,UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE);
INSERT INTO chat_av_asr_requests VALUES (33,'failed'),(34,'unknown'),(35,'failed'),(36,'failed');
"""
def mysql(sql):
    return subprocess.run(
        ["docker","exec","-i",cid,"mysql","--default-character-set=utf8mb4","-uroot","-pfixture-root","--batch","--raw","--skip-column-names"],
        input=sql,text=True,capture_output=True,check=True,
    ).stdout

mysql(setup)
mysql("USE voice_nonblocking_test;\n"+query+";")
out=mysql("""USE voice_nonblocking_test;
SELECT CONCAT_WS('|',j.intent_id,j.phase,IFNULL(j.transcript,''),IFNULL(j.raw_trace_id,''),IFNULL(j.raw_decision,''),i.state,IFNULL(i.error_code,''),IFNULL(j.lease_owner,''),j.review_state,j.general_confirmed,j.group_confirmed,IFNULL(j.reviewer_id,''),IFNULL(j.review_lease_until,''))
FROM chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id ORDER BY j.intent_id;
""").strip().splitlines()
rows={int(line.split("|",1)[0]):line.split("|") for line in out}
for intent in (33,34,35):
    row=rows[intent]
    if row[1]!="text_ready" or row[3]!="" or row[4]!="" or row[5]!="checking" or row[6]!="" or row[7]!="" or row[8]!="pending" or row[9]!="0" or row[10]!="0" or row[11]!="" or row[12]!="":
        raise SystemExit(f"intent {intent} not recovered: {row}")
if rows[33][2]!="同声传译兜底" or rows[35][2]!="辅助文字":
    raise SystemExit("client transcript was not restored")
if rows[34][2]!="":
    raise SystemExit("pure voice fabricated transcript")
if rows[36][1]!="raw_started":
    raise SystemExit("video gate was accidentally bypassed")
print("VOICE_RECOVERY_MYSQL57_OK")
