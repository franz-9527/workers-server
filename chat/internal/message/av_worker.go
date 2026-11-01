package message

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"github.com/franz-9527/workers/chat/internal/ports"
	"strings"
	"time"
)

type avSegment struct {
	Key  string `json:"key"`
	Hash string `json:"hash"`
}
type avJob struct {
	ID, Upload                                                                                                                ports.ID
	Principal                                                                                                                 ports.Principal
	Request                                                                                                                   Request
	Hash, Phase, Owner, Source, Version, Transcript, Proof, TextRule, TextProof, RawTrace, RawDecision, Review, Configuration string
	Segments                                                                                                                  []avSegment
	Ordinal                                                                                                                   int
	Main, MainHash, Poster, PosterHash, MIME                                                                                  string
	Size                                                                                                                      int64
	Duration                                                                                                                  int
	HasAudio                                                                                                                  bool
}

func (a *AVService) job(ctx context.Context, id ports.ID) (avJob, error) {
	v := avJob{ID: id}
	var principal, manifest, segments string
	e := a.base.db.QueryRowContext(ctx, `SELECT CAST(j.upload_id AS CHAR),j.principal_json,j.manifest_json,j.manifest_hash,j.phase,COALESCE(j.lease_owner,''),u.source,u.object_version,COALESCE(j.transcript,''),COALESCE(j.source_proof,''),COALESCE(j.text_rule,''),COALESCE(j.text_proof,''),COALESCE(j.raw_trace_id,''),COALESCE(j.raw_decision,''),j.review_state,j.configuration_hash,COALESCE(j.segments_json,'[]'),j.asr_ordinal,COALESCE(u.main_key,''),COALESCE(u.main_sha256,''),COALESCE(u.poster_key,''),COALESCE(u.poster_sha256,''),COALESCE(u.mime_type,''),u.declared_bytes,u.duration_ms,u.has_audio FROM chat_av_jobs j JOIN chat_av_uploads u ON u.id=j.upload_id WHERE j.intent_id=?`, string(id)).Scan(&v.Upload, &principal, &manifest, &v.Hash, &v.Phase, &v.Owner, &v.Source, &v.Version, &v.Transcript, &v.Proof, &v.TextRule, &v.TextProof, &v.RawTrace, &v.RawDecision, &v.Review, &v.Configuration, &segments, &v.Ordinal, &v.Main, &v.MainHash, &v.Poster, &v.PosterHash, &v.MIME, &v.Size, &v.Duration, &v.HasAudio)
	if e != nil || json.Unmarshal([]byte(principal), &v.Principal) != nil || json.Unmarshal([]byte(manifest), &v.Request) != nil || json.Unmarshal([]byte(segments), &v.Segments) != nil || v.Principal.Kind != "mini" || v.Principal.Environment != a.environment {
		return v, ErrMedia
	}
	var expiry int64
	if a.base.db.QueryRowContext(ctx, `SELECT UNIX_TIMESTAMP(expires_at) FROM login_sessions WHERE id=? AND user_id=? AND status='active'`, string(v.Principal.SourceID), string(v.Principal.ActorID)).Scan(&expiry) != nil {
		return v, ErrRestricted
	}
	v.Principal.ExpiresAt = time.Unix(expiry, 0)
	return v, nil
}
func (a *AVService) Run(ctx context.Context) {
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	for {
		if ctx.Err() != nil {
			return
		}
		pass, cancel := context.WithTimeout(ctx, 80*time.Second)
		_ = a.ProcessOne(pass)
		_ = a.CleanOne(pass)
		cancel()
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
	}
}
func (a *AVService) ProcessOne(ctx context.Context) error {
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	var id ports.ID
	var phase string
	e = tx.QueryRowContext(ctx, `SELECT CAST(j.intent_id AS CHAR),j.phase FROM chat_av_jobs j JOIN chat_send_intents i ON i.id=j.intent_id WHERE i.state IN ('checking','review_pending') AND j.phase NOT IN ('uploading','review_pending','failed','published','unknown') AND j.expires_at>UTC_TIMESTAMP(6) AND j.next_attempt_at<=UTC_TIMESTAMP(6) AND (j.lease_until IS NULL OR j.lease_until<UTC_TIMESTAMP(6)) ORDER BY j.intent_id LIMIT 1 FOR UPDATE`).Scan(&id, &phase)
	if e == sql.ErrNoRows {
		return nil
	}
	if e != nil {
		return ports.ErrUnavailable
	}
	owner := randomMedia()
	if owner == "" {
		return ports.ErrUnavailable
	}
	owner = owner[:32]
	if phase == "asr_started" || phase == "raw_started" {
		if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='unknown',error_code=?,lease_owner=NULL,lease_until=NULL WHERE intent_id=?`, ErrAVUnknown.Error(), string(id)); e != nil {
			return ports.ErrUnavailable
		}
		_, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET error_code=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?`, ErrAVUnknown.Error(), string(id))
		if e != nil || tx.Commit() != nil {
			return ports.ErrUnavailable
		}
		return ErrAVUnknown
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_jobs SET lease_owner=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 90 SECOND,attempts=LEAST(attempts+1,255) WHERE intent_id=?`, owner, string(id)); e != nil {
		return ports.ErrUnavailable
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET lease_owner=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 90 SECOND WHERE id=?`, owner, string(id)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	v, e := a.job(ctx, id)
	if e != nil {
		a.fail(ctx, id, owner, e.Error())
		return e
	}
	if v.Owner != owner || v.Configuration != a.configuration {
		a.fail(ctx, id, owner, ErrMediaVersion.Error())
		return ErrMediaVersion
	}
	auth, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	_, e = a.base.authorize(ctx, auth, v.Principal, v.Request.ConversationID)
	auth.Rollback()
	if e != nil {
		a.fail(ctx, id, owner, e.Error())
		return e
	}
	switch phase {
	case "prepare":
		e = a.prepare(ctx, v)
	case "asr_ready":
		e = a.recognize(ctx, v)
	case "text_ready":
		e = a.checkText(ctx, v)
	case "raw_ready":
		e = a.submitRaw(ctx, v)
	case "raw_wait":
		e = a.rawResult(ctx, v)
	case "ready":
		_, e = a.Publish(ctx, id)
	default:
		e = ErrMedia
	}
	if e != nil && e != ErrAVUnknown && e != ErrBlocked {
		// Local/provider failures release only the lease. Durable started phases
		// stay started, so a process loss cannot silently repeat a billable call.
		_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET error_code=?,next_attempt_at=UTC_TIMESTAMP(6)+INTERVAL 30 SECOND,lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND lease_owner=?`, ErrCheck.Error(), string(id), owner)
	}
	return e
}
func (a *AVService) fail(ctx context.Context, id ports.ID, owner, code string) {
	tx, e := a.base.begin(ctx)
	if e != nil {
		return
	}
	defer tx.Rollback()
	r, e := tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='failed',error_code=?,lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6)`, code, string(id), owner)
	if e != nil {
		return
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='rejected',error_code=?,lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state IN ('checking','review_pending')`, code, string(id)); e == nil {
		tx.Commit()
	}
}
func (a *AVService) advance(ctx context.Context, v avJob, phase string) error {
	r, e := a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET phase=?,error_code=NULL,next_attempt_at=UTC_TIMESTAMP(6),lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, phase, string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	return nil
}
func (a *AVService) prepare(ctx context.Context, v avJob) error {
	rows, e := a.base.db.QueryContext(ctx, `SELECT object_key,size_bytes FROM chat_av_parts WHERE upload_id=? AND state='present' ORDER BY ordinal`, string(v.Upload))
	if e != nil {
		return ports.ErrUnavailable
	}
	keys := []string{}
	sizes := []int64{}
	for rows.Next() {
		var key string
		var size int64
		if rows.Scan(&key, &size) != nil {
			rows.Close()
			return ErrMedia
		}
		keys = append(keys, key)
		sizes = append(sizes, size)
	}
	e = rows.Err()
	rows.Close()
	if e != nil || len(keys) != avParts(v.Size) {
		return ErrMedia
	}
	raw := make([]byte, 0, v.Size)
	for i, key := range keys {
		b, e := a.storage.Read(ctx, key, sizes[i])
		if e != nil || int64(len(b)) != sizes[i] {
			return ErrMedia
		}
		raw = append(raw, b...)
	}
	var digest string
	if a.base.db.QueryRowContext(ctx, `SELECT source_md5 FROM chat_av_uploads WHERE id=?`, string(v.Upload)).Scan(&digest) != nil || fileMD5(raw) != digest {
		a.fail(ctx, v.ID, v.Owner, ErrMediaVersion.Error())
		return ErrMediaVersion
	}
	out, e := a.processor.Process(ctx, raw, v.Request.MessageType, v.Source)
	if e != nil {
		a.fail(ctx, v.ID, v.Owner, ErrCheck.Error())
		return e
	}
	if out.QRDecision == "blocked" {
		a.fail(ctx, v.ID, v.Owner, ErrBlocked.Error())
		return ErrBlocked
	}
	prefix := "chatav/" + a.environment + "/" + string(v.Principal.ActorID) + "/" + v.Version + "/"
	put := func(bytes []byte, role, mime string) (string, string, error) {
		hash := mediaHash(bytes)
		key := prefix + hash + "/" + role
		if _, e := a.base.db.ExecContext(ctx, `INSERT INTO chat_av_objects(object_key,upload_id,sha256,size_bytes,role,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE object_key=object_key`, key, string(v.Upload), hash, len(bytes), role); e != nil {
			return "", "", ports.ErrUnavailable
		}
		e := a.storage.PutAV(ctx, key, bytes, mime)
		return key, hash, e
	}
	main, mh, e := put(out.Main, "main", out.MIME)
	if e != nil {
		return e
	}
	poster, ph := "", ""
	if len(out.Poster) > 0 {
		poster, ph, e = put(out.Poster, "poster", "image/jpeg")
		if e != nil {
			return e
		}
	}
	frames := []avFrameRecord{}
	for i, frame := range out.Frames {
		if i >= 8 || len(frame.Body) > 65536 || frame.AtMS < 0 || frame.AtMS >= out.DurationMS {
			return ErrMedia
		}
		if i == 0 && frame.AtMS == 0 && poster != "" {
			frames = append(frames, avFrameRecord{Key: poster, Hash: ph, AtMS: 0})
			continue
		}
		key, hash, err := put(frame.Body, fmt.Sprintf("frame-%d", i), "image/jpeg")
		if err != nil {
			return err
		}
		frames = append(frames, avFrameRecord{Key: key, Hash: hash, AtMS: frame.AtMS})
	}
	frameJSON, _ := json.Marshal(frames)
	segments := []avSegment{}
	for i, b := range out.Segments {
		key, hash := main, mh
		if v.Request.MessageType != "audio" {
			key, hash, e = put(b, fmt.Sprintf("check-%d", i), "audio/mpeg")
			if e != nil {
				return e
			}
		}
		segments = append(segments, avSegment{key, hash})
	}
	encoded, _ := json.Marshal(segments)
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	r, e := tx.ExecContext(ctx, `UPDATE chat_av_jobs SET segments_json=?,phase='asr_ready',lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND phase='prepare' AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, string(encoded), string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_av_uploads SET state='prepared',main_key=?,main_sha256=?,poster_key=NULLIF(?,''),poster_sha256=NULLIF(?,''),mime_type=?,duration_ms=?,has_audio=?,frames_json=? WHERE id=?`, main, mh, poster, ph, out.MIME, out.DurationMS, out.HasAudio, string(frameJSON), string(v.Upload)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	for _, key := range keys {
		_ = a.storage.Delete(ctx, key)
	}
	return nil
}
func (a *AVService) reviewPending(ctx context.Context, v avJob) error {
	tx, e := a.base.begin(ctx)
	if e != nil {
		return e
	}
	defer tx.Rollback()
	r, e := tx.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='review_pending',review_state='pending',review_version=review_version+1,lease_owner=NULL,lease_until=NULL WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)`, string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	if _, e = tx.ExecContext(ctx, `UPDATE chat_send_intents SET state='review_pending',lease_owner=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND state='checking'`, string(v.ID)); e != nil || tx.Commit() != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (a *AVService) checkText(ctx context.Context, v avJob) error {
	var checked ports.SafetyResult
	var e error
	if strings.TrimSpace(v.Transcript) == "" {
		if v.Request.MessageType == "audio" {
			a.fail(ctx, v.ID, v.Owner, "CHAT_AUDIO_NO_RECOGNIZABLE_TEXT")
			return ErrCheck
		}
		proof, ok := a.base.safety.(interface {
			MediaProof(context.Context, ports.Principal) (ports.SafetyResult, error)
		})
		if !ok {
			return ErrCheck
		}
		checked, e = proof.MediaProof(ctx, v.Principal)
	} else {
		checked, e = a.base.safety.CheckText(ctx, ports.TextRequest{Actor: v.Principal, IntentID: v.ID, ConversationID: v.Request.ConversationID, Fingerprint: v.Hash, Text: v.Transcript})
	}
	if e != nil {
		return e
	}
	if v.Proof != "" && checked.AuthorityProof != v.Proof {
		a.fail(ctx, v.ID, v.Owner, ErrMediaVersion.Error())
		return ErrMediaVersion
	}
	if checked.Decision == "blocked" || checked.Decision == "review" {
		q := v.Request
		q.Text = v.Transcript
		_, e = a.base.reject(ctx, v.Principal, q, v.Hash, v.ID, v.Owner, checked)
		if e == nil {
			_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_uploads SET legal_hold=1 WHERE id=?`, string(v.Upload))
			_, _ = a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET phase='failed',lease_owner=NULL,lease_until=NULL WHERE intent_id=?`, string(v.ID))
		}
		return e
	}
	if checked.Decision != "passed" && checked.Decision != "media_identity" {
		return ErrCheck
	}
	r, e := a.base.db.ExecContext(ctx, `UPDATE chat_av_jobs SET text_rule=?,text_proof=?,source_proof=?,error_code=NULL WHERE intent_id=? AND lease_owner=? AND lease_until>UTC_TIMESTAMP(6)`, checked.RuleVersion, checked.AuthorityProof, checked.AuthorityProof, string(v.ID), v.Owner)
	if e != nil {
		return ports.ErrUnavailable
	}
	n, _ := r.RowsAffected()
	if n != 1 {
		return ports.ErrBusy
	}
	if v.Request.MessageType == "video" {
		return a.reviewPending(ctx, v)
	}
	return a.advance(ctx, v, "raw_ready")
}
