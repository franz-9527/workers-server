package message

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/franz-9527/workers/chat/internal/ports"
)

var ErrMedia = errors.New("CHAT_MEDIA_UNAVAILABLE")
var ErrMediaVersion = errors.New("CHAT_MEDIA_VERSION_CONFLICT")

const ImageRule = "images-manual-v1"
const MediaTTL = time.Hour
const MaxImageBytes = 8 << 20
const MaxImagePixels = 20000000

// Storage keys are generated here, never supplied by a caller or callback.
type UploadGrant struct {
	AttachmentID  ports.ID `json:"attachment_id"`
	ObjectVersion string   `json:"object_version"`
	Key           string   `json:"key"`
	Token         string   `json:"token"`
	Host          string   `json:"host"`
	ExpiresAt     int64    `json:"expires_at"`
	State         string   `json:"state"`
}
type ImageStorage interface {
	Grant(context.Context, string, int64) (string, string, error)
	Read(context.Context, string, int64) ([]byte, error)
	Put(context.Context, string, []byte, string) error
	Sign(context.Context, string, time.Duration) (string, error)
	Delete(context.Context, string) error
}
type ProcessedImage struct {
	Main, Thumb                                              []byte
	MIME, Method, QRStatus, QRDecision, QRVersion, OCRStatus string
	Width, Height                                            int
}
type ImageProcessor interface {
	Process(context.Context, []byte) (ProcessedImage, error)
}
type MediaItem struct {
	AttachmentID  ports.ID `json:"attachment_id"`
	ObjectVersion string   `json:"object_version"`
	Ordinal       int      `json:"ordinal"`
	ItemID        ports.ID `json:"item_id,omitempty"`
}
type ImageView struct {
	ItemID  ports.ID `json:"item_id"`
	Ordinal int      `json:"ordinal"`
	Width   int      `json:"width"`
	Height  int      `json:"height"`
}
type MediaStatus struct {
	IntentID      ports.ID `json:"intent_id,omitempty"`
	ReviewVersion ports.ID `json:"review_version,omitempty"`
	Result
	Revision     int               `json:"revision"`
	ManifestHash string            `json:"manifest_hash"`
	Items        []MediaItemStatus `json:"items"`
	ExpiresAt    time.Time         `json:"expires_at"`
}
type MediaItemStatus struct {
	MediaItem
	State string `json:"state"`
	Code  string `json:"code,omitempty"`
}
type MediaService struct {
	base        *Service
	storage     ImageStorage
	processor   ImageProcessor
	environment string
}

func NewMediaService(base *Service, storage ImageStorage, processor ImageProcessor, env string) (*MediaService, error) {
	if base == nil || storage == nil || processor == nil || (env != "test" && env != "development" && env != "production") {
		return nil, ports.ErrInvalid
	}
	return &MediaService{base, storage, processor, env}, nil
}
func (s *Service) WithMedia(m *MediaService) *Service { copy := *s; copy.media = m; return &copy }
func randomMedia() string {
	var b [32]byte
	if _, e := rand.Read(b[:]); e != nil {
		return ""
	}
	return hex.EncodeToString(b[:])
}
func mediaHash(b []byte) string { v := sha256.Sum256(b); return hex.EncodeToString(v[:]) }
func mediaFingerprint(q Request) (string, error) {
	if !validID(q.ConversationID) || !validClient(q.ClientMessageID) || q.Text != "" || q.EditedFrom != "" || q.Revision < 1 || q.Revision > 10000 || len(q.Items) < 1 || len(q.Items) > 9 ||
		(len(q.Items) == 1 && q.MessageType != "image") || (len(q.Items) > 1 && q.MessageType != "image_album") || q.ReplyTo != "" && !validID(q.ReplyTo) || q.ReplyItem != "" && (!validID(q.ReplyItem) || q.ReplyTo == "") {
		return "", ports.ErrInvalid
	}
	seen := map[ports.ID]bool{}
	for i, v := range q.Items {
		if !validID(v.AttachmentID) || len(v.ObjectVersion) != 64 || v.Ordinal != i || v.ItemID != "" || seen[v.AttachmentID] {
			return "", ports.ErrInvalid
		}
		if _, e := hex.DecodeString(v.ObjectVersion); e != nil {
			return "", ports.ErrInvalid
		}
		seen[v.AttachmentID] = true
	}
	b, err := json.Marshal(struct {
		Schema    int
		Type      string
		Revision  int
		Items     []MediaItem
		Reply     ports.ID `json:"reply,omitempty"`
		ReplyItem ports.ID `json:"reply_item,omitempty"`
	}{3, q.MessageType, q.Revision, q.Items, q.ReplyTo, q.ReplyItem})
	if err != nil {
		return "", ports.ErrInvalid
	}
	return mediaHash(b), nil
}

// Allocation is idempotent by a timestamped upload action. No allocation or
// upload implies a send. Per-actor quota is serialized by the restriction row.
func (m *MediaService) Upload(ctx context.Context, p ports.Principal, cid ports.ID, uploadID string, size int64) (UploadGrant, error) {
	if !validID(cid) || !validClient(uploadID) || !active(uploadID, time.Now()) || size < 1 || size > MaxImageBytes {
		return UploadGrant{}, ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return UploadGrant{}, e
	}
	defer tx.Rollback()
	if _, e = m.base.authorize(ctx, tx, p, cid); e != nil {
		return UploadGrant{}, e
	}
	var id ports.ID
	var version, key string
	var declared int64
	var live bool
	var state string
	e = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR),object_version,source_key,declared_bytes,state,expires_at>UTC_TIMESTAMP(6) FROM chat_image_uploads WHERE actor_id=? AND conversation_id=? AND upload_id=? FOR UPDATE`, string(p.ActorID), string(cid), uploadID).Scan(&id, &version, &key, &declared, &state, &live)
	if e == sql.ErrNoRows {
		var count int
		var bytes int64
		if tx.QueryRowContext(ctx, `SELECT COUNT(*),COALESCE(SUM(declared_bytes),0) FROM chat_image_uploads WHERE actor_id=? AND expires_at>UTC_TIMESTAMP(6)`, string(p.ActorID)).Scan(&count, &bytes) != nil {
			return UploadGrant{}, ports.ErrUnavailable
		}
		if count >= 18 || bytes+size > 72<<20 {
			return UploadGrant{}, ports.ErrBusy
		}
		version = randomMedia()
		if version == "" {
			return UploadGrant{}, ports.ErrUnavailable
		}
		key = "chat/" + m.environment + "/" + string(p.ActorID) + "/" + version + "/source"
		res, err := tx.ExecContext(ctx, `INSERT INTO chat_image_uploads(actor_id,conversation_id,upload_id,object_version,source_key,declared_bytes,state,expires_at,created_at) VALUES(?,?,?,?,?,?,'uploading',?,UTC_TIMESTAMP(6))`, string(p.ActorID), string(cid), uploadID, version, key, size, expires(uploadID))
		if err != nil {
			return UploadGrant{}, ports.ErrUnavailable
		}
		n, err := res.LastInsertId()
		if err != nil {
			return UploadGrant{}, ports.ErrUnavailable
		}
		id = ports.ID(fmt.Sprint(n))
	} else if e != nil {
		return UploadGrant{}, ports.ErrUnavailable
	} else if !live {
		return UploadGrant{}, ErrExpired
	} else if declared != size {
		return UploadGrant{}, ErrConflict
	}
	if tx.Commit() != nil {
		return UploadGrant{}, ports.ErrUnavailable
	}
	if state == "processed" || state == "blocked" {
		return UploadGrant{AttachmentID: id, ObjectVersion: version, State: state}, nil
	}
	token, host, e := m.storage.Grant(ctx, key, size)
	if e != nil {
		return UploadGrant{}, ErrMedia
	}
	return UploadGrant{AttachmentID: id, ObjectVersion: version, Key: key, Token: token, Host: host, ExpiresAt: time.Now().Add(5 * time.Minute).Unix(), State: "uploading"}, nil
}
func (m *MediaService) Uploaded(ctx context.Context, p ports.Principal, cid, id ports.ID) (MediaItemStatus, error) {
	if !validID(cid) || !validID(id) {
		return MediaItemStatus{}, ports.ErrInvalid
	}
	tx, e := m.base.begin(ctx)
	if e != nil {
		return MediaItemStatus{}, e
	}
	defer tx.Rollback()
	if _, e = m.base.authorize(ctx, tx, p, cid); e != nil {
		return MediaItemStatus{}, e
	}
	var v MediaItemStatus
	v.AttachmentID = id
	var live bool
	var attempts int
	e = tx.QueryRowContext(ctx, `SELECT object_version,state,expires_at>UTC_TIMESTAMP(6),attempts FROM chat_image_uploads WHERE id=? AND actor_id=? AND conversation_id=? FOR UPDATE`, string(id), string(p.ActorID), string(cid)).Scan(&v.ObjectVersion, &v.State, &live, &attempts)
	if e != nil {
		return v, ErrMedia
	}
	if !live {
		return v, ErrExpired
	}
	if v.State == "uploading" || v.State == "check_unavailable" {
		if attempts >= 8 {
			return v, ErrCheck
		}
		if _, e = tx.ExecContext(ctx, `UPDATE chat_image_uploads SET state='queued',error_code=NULL WHERE id=?`, string(id)); e != nil {
			return v, ports.ErrUnavailable
		}
		v.State = "queued"
	}
	if tx.Commit() != nil {
		return v, ports.ErrUnavailable
	}
	return v, nil
}
func (m *MediaService) Send(ctx context.Context, p ports.Principal, q Request) (Result, error) {
	fp, e := mediaFingerprint(q)
	if e != nil {
		return Result{}, e
	}
	// Same source ID cannot collide with a text action, even after ban or expiry.
	tx, e := m.base.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	if _, e = m.base.conversations.AuthorizeLocked(ctx, tx, ports.PolicyRequest{Actor: p, ConversationID: q.ConversationID, Action: "read"}); e != nil {
		return Result{}, e
	}
	old, e := m.base.lookup(ctx, tx, p, q.ConversationID, q.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if old != nil {
		if old.result.State == "canceled" {
			if tx.Commit() != nil {
				return Result{}, ports.ErrUnavailable
			}
			return old.result, nil
		}
		if old.fingerprint != fp {
			return Result{}, ErrConflict
		}
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return old.result, nil
	}
	tx.Rollback()
	if !active(q.ClientMessageID, time.Now()) {
		return Result{}, ErrExpired
	}
	tx, e = m.base.begin(ctx)
	if e != nil {
		return Result{}, e
	}
	defer tx.Rollback()
	g, e := m.base.authorize(ctx, tx, p, q.ConversationID)
	if e != nil {
		return Result{}, e
	}
	old, e = m.base.lookup(ctx, tx, p, q.ConversationID, q.ClientMessageID)
	if e != nil {
		return Result{}, e
	}
	if old != nil {
		if old.fingerprint != fp {
			return Result{}, ErrConflict
		}
		if tx.Commit() != nil {
			return Result{}, ports.ErrUnavailable
		}
		return old.result, nil
	}
	if _, e = validateReferences(ctx, tx, p, q, g); e != nil {
		return Result{}, e
	}
	var pending int
	if tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM chat_send_intents WHERE sender_id=? AND state IN ('checking','review_pending','retryable') AND action_expires_at>UTC_TIMESTAMP(6)`, string(p.ActorID)).Scan(&pending) != nil {
		return Result{}, ports.ErrUnavailable
	}
	if pending >= 4 {
		return Result{}, ports.ErrBusy
	}
	earliest := time.Now().UTC().Add(MediaTTL)
	for _, v := range q.Items {
		var version, state string
		var live bool
		var expiry time.Time
		e = tx.QueryRowContext(ctx, `SELECT object_version,state,expires_at>UTC_TIMESTAMP(6),expires_at FROM chat_image_uploads WHERE id=? AND actor_id=? AND conversation_id=? LOCK IN SHARE MODE`, string(v.AttachmentID), string(p.ActorID), string(q.ConversationID)).Scan(&version, &state, &live, &expiry)
		if e != nil || !live || version != v.ObjectVersion {
			return Result{}, ErrMedia
		}
		if state == "blocked" {
			return Result{}, ErrBlocked
		}
		if expiry.Before(earliest) {
			earliest = expiry
		}
	}
	deadline := earliest
	if end := expires(q.ClientMessageID); end.Before(deadline) {
		deadline = end
	}
	res, e := tx.ExecContext(ctx, `INSERT INTO chat_send_intents(sender_id,conversation_id,client_message_id,fingerprint,state,action_expires_at,retain_until,created_at,updated_at) VALUES(?,?,?,?,'checking',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))`, string(p.ActorID), string(q.ConversationID), q.ClientMessageID, fp, deadline, expires(q.ClientMessageID).Add(MinimumRetention))
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	n, e := res.LastInsertId()
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	principal, _ := json.Marshal(p)
	manifest, _ := json.Marshal(q)
	_, e = tx.ExecContext(ctx, `INSERT INTO chat_image_bundles(intent_id,principal_json,manifest_json,manifest_hash,revision,rule_version,review_state,review_version,submitted_at,expires_at) VALUES(?,?,?,?,?,?,'waiting',1,UTC_TIMESTAMP(6),?)`, n, string(principal), string(manifest), fp, q.Revision, ImageRule, deadline)
	if e != nil {
		return Result{}, ports.ErrUnavailable
	}
	if tx.Commit() != nil {
		return Result{}, ports.ErrUnavailable
	}
	return Result{ClientMessageID: q.ClientMessageID, State: "checking"}, nil
}
