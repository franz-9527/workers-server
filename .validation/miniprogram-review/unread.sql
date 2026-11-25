-- Synthetic data only; exact unread visibility fragments from source 01c0f07be9374805c89ad82df570ca481aa82fe5
CREATE DATABASE IF NOT EXISTS review_fixture;
USE review_fixture;
CREATE TABLE chat_conversations(id INT PRIMARY KEY,policy_version INT);
CREATE TABLE chat_members(conversation_id INT,actor_id INT,actor_kind VARCHAR(10),member_key VARCHAR(50));
CREATE TABLE chat_messages(id INT PRIMARY KEY,conversation_id INT,sender_id INT,seq INT,state VARCHAR(20),policy_version INT);
CREATE TABLE chat_member_intervals(conversation_id INT,actor_id INT,member_key VARCHAR(50),start_version INT,end_version INT NULL);
CREATE TABLE fixture_reads(conversation_id INT,read_seq INT);
CREATE TABLE fixture_handoff(conversation_id INT,through_seq INT);
CREATE TABLE fixture_preferences(conversation_id INT,later INT,hidden INT);
INSERT INTO chat_conversations VALUES (1,4),(2,4),(3,4),(4,4),(5,4),(6,4),(7,4),(8,4),(9,4),(10,4);
INSERT INTO chat_members SELECT id,7,'mini',CONCAT('k',id) FROM chat_conversations;
INSERT INTO chat_members VALUES (3,8,'mini','other'),(7,7,'mini','old');
INSERT INTO chat_messages VALUES
(1,1,9,2,'published',1),(2,1,9,3,'published',1),
(3,2,9,2,'published',2),(4,2,9,3,'published',4),
(5,3,9,2,'published',2),(6,4,9,2,'published',2),
(7,5,7,2,'published',2),(8,5,9,3,'rejected',2),
(9,6,9,2,'published',5),(10,7,9,2,'published',2),
(11,8,9,2,'published',2),(12,10,9,2,'published',2);
INSERT INTO chat_member_intervals VALUES (2,7,'k2',2,NULL),(3,8,'other',1,NULL),
(4,7,'k4',1,NULL),(5,7,'k5',1,NULL),(6,7,'k6',1,NULL),
(7,7,'k7',1,3),(7,7,'old',1,NULL),(8,7,'k8',1,NULL),(10,7,'k10',1,NULL);
INSERT INTO fixture_reads VALUES (4,10);
INSERT INTO fixture_handoff VALUES (1,4);
INSERT INTO fixture_preferences VALUES (8,1,0),(9,1,0),(10,0,1);
CREATE VIEW fixture_outer AS SELECT c.id FROM chat_conversations c;

SELECT CONCAT('predicate=',GROUP_CONCAT(c.id ORDER BY c.id)) FROM chat_conversations c JOIN chat_members m ON m.conversation_id=c.id
LEFT JOIN fixture_reads r ON r.conversation_id=c.id
LEFT JOIN fixture_handoff handoff ON handoff.conversation_id=c.id
LEFT JOIN fixture_preferences pref ON pref.conversation_id=c.id WHERE m.actor_id=7 AND m.actor_kind='mini' AND m.member_key=CONCAT('k',c.id) AND EXISTS(SELECT 1 FROM chat_messages unread_filter
	JOIN chat_members unread_filter_member ON unread_filter_member.conversation_id=unread_filter.conversation_id
	LEFT JOIN chat_member_intervals unread_filter_interval
		ON unread_filter_interval.conversation_id=unread_filter.conversation_id
		AND unread_filter_interval.actor_id=unread_filter_member.actor_id
		AND unread_filter_interval.member_key=unread_filter_member.member_key
		AND unread_filter_interval.start_version<=unread_filter.policy_version AND unread_filter_interval.end_version IS NULL
	WHERE unread_filter.conversation_id=c.id
		AND unread_filter_member.actor_id=m.actor_id AND unread_filter_member.actor_kind=m.actor_kind
		AND unread_filter_member.member_key=m.member_key
		AND unread_filter.sender_id<>m.actor_id AND unread_filter.state='published'
		AND unread_filter.seq>COALESCE(r.read_seq,0) AND unread_filter.policy_version<=c.policy_version
		AND (unread_filter.seq<=COALESCE(handoff.through_seq,0) OR unread_filter_interval.actor_id IS NOT NULL));
SELECT CONCAT('join=',GROUP_CONCAT(DISTINCT c.id ORDER BY c.id)) FROM chat_conversations c JOIN chat_members m ON m.conversation_id=c.id
LEFT JOIN fixture_reads r ON r.conversation_id=c.id
LEFT JOIN fixture_handoff handoff ON handoff.conversation_id=c.id
LEFT JOIN fixture_preferences pref ON pref.conversation_id=c.id
	LEFT JOIN chat_messages unread_msg ON unread_msg.conversation_id=c.id
		AND unread_msg.sender_id<>m.actor_id AND unread_msg.state='published'
		AND unread_msg.seq>COALESCE(r.read_seq,0) AND unread_msg.policy_version<=c.policy_version
	LEFT JOIN chat_member_intervals unread_interval ON unread_interval.conversation_id=unread_msg.conversation_id
		AND unread_interval.actor_id=m.actor_id AND unread_interval.member_key=m.member_key
		AND unread_interval.start_version<=unread_msg.policy_version AND unread_interval.end_version IS NULL WHERE m.actor_id=7 AND m.actor_kind='mini' AND m.member_key=CONCAT('k',c.id) AND (unread_msg.id IS NOT NULL AND
	(unread_msg.seq<=COALESCE(handoff.through_seq,0) OR unread_interval.actor_id IS NOT NULL));
SELECT CONCAT('active_unread=',COUNT(*)) FROM chat_conversations c JOIN chat_members m ON m.conversation_id=c.id
LEFT JOIN fixture_reads r ON r.conversation_id=c.id
LEFT JOIN fixture_handoff handoff ON handoff.conversation_id=c.id
LEFT JOIN fixture_preferences pref ON pref.conversation_id=c.id WHERE m.actor_id=7 AND m.actor_kind='mini' AND m.member_key=CONCAT('k',c.id) AND COALESCE(pref.hidden,0)=0 AND EXISTS(SELECT 1 FROM chat_messages unread_filter
	JOIN chat_members unread_filter_member ON unread_filter_member.conversation_id=unread_filter.conversation_id
	LEFT JOIN chat_member_intervals unread_filter_interval
		ON unread_filter_interval.conversation_id=unread_filter.conversation_id
		AND unread_filter_interval.actor_id=unread_filter_member.actor_id
		AND unread_filter_interval.member_key=unread_filter_member.member_key
		AND unread_filter_interval.start_version<=unread_filter.policy_version AND unread_filter_interval.end_version IS NULL
	WHERE unread_filter.conversation_id=c.id
		AND unread_filter_member.actor_id=m.actor_id AND unread_filter_member.actor_kind=m.actor_kind
		AND unread_filter_member.member_key=m.member_key
		AND unread_filter.sender_id<>m.actor_id AND unread_filter.state='published'
		AND unread_filter.seq>COALESCE(r.read_seq,0) AND unread_filter.policy_version<=c.policy_version
		AND (unread_filter.seq<=COALESCE(handoff.through_seq,0) OR unread_filter_interval.actor_id IS NOT NULL));
SELECT CONCAT('later=',COUNT(*)) FROM chat_conversations c JOIN chat_members m ON m.conversation_id=c.id
LEFT JOIN fixture_reads r ON r.conversation_id=c.id
LEFT JOIN fixture_handoff handoff ON handoff.conversation_id=c.id
LEFT JOIN fixture_preferences pref ON pref.conversation_id=c.id WHERE m.actor_id=7 AND m.actor_kind='mini' AND m.member_key=CONCAT('k',c.id) AND COALESCE(pref.hidden,0)=0 AND COALESCE(pref.later,0)=1;
SELECT CONCAT('unread_not_later=',COUNT(*)) FROM chat_conversations c JOIN chat_members m ON m.conversation_id=c.id
LEFT JOIN fixture_reads r ON r.conversation_id=c.id
LEFT JOIN fixture_handoff handoff ON handoff.conversation_id=c.id
LEFT JOIN fixture_preferences pref ON pref.conversation_id=c.id WHERE m.actor_id=7 AND m.actor_kind='mini' AND m.member_key=CONCAT('k',c.id) AND COALESCE(pref.hidden,0)=0 AND COALESCE(pref.later,0)=0 AND EXISTS(SELECT 1 FROM chat_messages unread_filter
	JOIN chat_members unread_filter_member ON unread_filter_member.conversation_id=unread_filter.conversation_id
	LEFT JOIN chat_member_intervals unread_filter_interval
		ON unread_filter_interval.conversation_id=unread_filter.conversation_id
		AND unread_filter_interval.actor_id=unread_filter_member.actor_id
		AND unread_filter_interval.member_key=unread_filter_member.member_key
		AND unread_filter_interval.start_version<=unread_filter.policy_version AND unread_filter_interval.end_version IS NULL
	WHERE unread_filter.conversation_id=c.id
		AND unread_filter_member.actor_id=m.actor_id AND unread_filter_member.actor_kind=m.actor_kind
		AND unread_filter_member.member_key=m.member_key
		AND unread_filter.sender_id<>m.actor_id AND unread_filter.state='published'
		AND unread_filter.seq>COALESCE(r.read_seq,0) AND unread_filter.policy_version<=c.policy_version
		AND (unread_filter.seq<=COALESCE(handoff.through_seq,0) OR unread_filter_interval.actor_id IS NOT NULL));
