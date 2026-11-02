package message

import (
	"context"
	"crypto/md5"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"github.com/franz-9527/workers/chat/internal/ports"
	"time"
)

const AVRule = "av-message-v1"
const AVPartBytes = 2 << 20
const MaxVideoBytes = 20 << 20
const MaxRecordingBytes = 8 << 20
const MaxVoiceBytes = 1 << 20

var ErrAVLimit = errors.New("CHAT_AV_LIMIT_EXCEEDED")
var ErrAVUnknown = errors.New("CHAT_AV_CHECK_RESULT_UNKNOWN")

type AVStorage interface {
	ObjectKeeper
	Read(context.Context, string, int64) ([]byte, error)
	Sign(context.Context, string, time.Duration) (string, error)
	InspectionURL(context.Context, string) (string, error)
	Delete(context.Context, string) error
	GrantAVPart(context.Context, string, int64) (string, string, error)
	PutAV(context.Context, string, []byte, string) error
	StatAV(context.Context, string) (int64, error)
}
type AVFrameArtifact struct {
	Body []byte
	AtMS int
}
type avFrameRecord struct {
	Key  string `json:"key"`
	Hash string `json:"hash"`
	AtMS int    `json:"at_ms"`
}
type AVFrameView struct {
	Ordinal int `json:"ordinal"`
	AtMS    int `json:"at_ms"`
}
type AVArtifact struct {
	Frames           []AVFrameArtifact
	Main, Poster     []byte
	Segments         [][]byte
	DurationMS       int
	HasAudio         bool
	MIME, QRDecision string
}
type AVProcessor interface {
	Process(context.Context, []byte, string, string) (AVArtifact, error)
}
type AVService struct {
	base                       *Service
	storage                    AVStorage
	processor                  AVProcessor
	inspector                  ports.AVInspector
	environment, configuration string
	audio, video               bool
	dailyASR                   int
}
type AVUpload struct {
	AttachmentID  ports.ID  `json:"attachment_id"`
	ObjectVersion string    `json:"object_version"`
	State         string    `json:"state"`
	PartCount     int       `json:"part_count"`
	PartBytes     int       `json:"part_bytes"`
	ExpiresAt     time.Time `json:"expires_at"`
}
type AVPartGrant struct {
	UploadGrant
	Ordinal int   `json:"ordinal"`
	Size    int64 `json:"size_bytes"`
}
type AVView struct {
	Frames       []AVFrameView `json:"frames,omitempty"`
	AttachmentID ports.ID      `json:"attachment_id"`
	DurationMS   int           `json:"duration_ms"`
	Transcript   *string       `json:"transcript,omitempty"`
	Heard        bool          `json:"heard"`
	HasAudio     bool          `json:"has_audio"`
}
type AVStatus struct {
	IntentID      ports.ID `json:"intent_id,omitempty"`
	ReviewVersion ports.ID `json:"review_version,omitempty"`
	Result
	Phase         string    `json:"phase"`
	PartCount     int       `json:"part_count"`
	Uploaded      []int     `json:"uploaded_parts"`
	AttachmentID  ports.ID  `json:"attachment_id,omitempty"`
	ObjectVersion string    `json:"object_version,omitempty"`
	ExpiresAt     time.Time `json:"expires_at"`
}

func NewAVService(base *Service, storage AVStorage, processor AVProcessor, inspector ports.AVInspector, env, configuration string, audio, video bool, dailyASR int) (*AVService, error) {
	if base == nil || storage == nil || processor == nil || inspector == nil || (!audio && !video) || !isMediaHash(configuration) || (env != "production" && env != "test" && env != "development") || dailyASR < 1 || dailyASR > 20000 {
		return nil, ports.ErrInvalid
	}
	return &AVService{base, storage, processor, inspector, env, configuration, audio, video, dailyASR}, nil
}
func (s *Service) AttachAV(a *AVService) error {
	if a == nil || s.av != nil {
		return ports.ErrInvalid
	}
	s.av = a
	return nil
}
func isMediaHash(v string) bool {
	if len(v) != 64 {
		return false
	}
	b, e := hex.DecodeString(v)
	return e == nil && len(b) == 32 && v == hex.EncodeToString(b)
}
func fileMD5(b []byte) string { v := md5.Sum(b); return hex.EncodeToString(v[:]) }
func avKind(source string) string {
	if source == "voice" {
		return "audio"
	}
	return "video"
}
func avLimits(source string) (int64, int) {
	switch source {
	case "voice":
		return MaxVoiceBytes, 60000
	case "camera":
		return MaxRecordingBytes, 30000
	case "album":
		return MaxVideoBytes, 120000
	}
	return 0, 0
}
func avParts(size int64) int {
	if size <= 4<<20 {
		return 1
	}
	return int((size + AVPartBytes - 1) / AVPartBytes)
}
func avPartSize(size int64, ordinal int) int64 {
	n := avParts(size)
	if ordinal < 1 || ordinal > n {
		return 0
	}
	if n == 1 {
		return size
	}
	return min(AVPartBytes, size-int64(ordinal-1)*AVPartBytes)
}
func avFingerprint(q Request) (string, error) {
	if !validID(q.ConversationID) || !validClient(q.ClientMessageID) || q.Text != "" || q.EditedFrom != "" || q.Revision < 1 || q.Revision > 10000 || len(q.Items) != 1 || (q.MessageType != "audio" && q.MessageType != "video") || q.ReplyTo != "" && !validID(q.ReplyTo) || q.ReplyItem != "" && (!validID(q.ReplyItem) || q.ReplyTo == "") {
		return "", ports.ErrInvalid
	}
	v := q.Items[0]
	if !validID(v.AttachmentID) || !isMediaHash(v.ObjectVersion) || v.Ordinal != 0 || v.ItemID != "" {
		return "", ports.ErrInvalid
	}
	raw, _ := json.Marshal(struct {
		Schema           int
		Type             string
		Revision         int
		Items            []MediaItem
		Reply, ReplyItem string
	}{4, q.MessageType, q.Revision, q.Items, string(q.ReplyTo), string(q.ReplyItem)})
	return mediaHash(raw), nil
}
func (a *AVService) enabled(kind string) bool {
	return kind == "audio" && a.audio || kind == "video" && a.video
}
func (s *Service) keepAVObjects(ctx context.Context, id ports.ID, kind string) error {
	if s.av == nil {
		return ErrMedia
	}
	rows, e := s.db.QueryContext(ctx, `SELECT u.main_key,u.poster_key FROM chat_av_items i JOIN chat_av_uploads u ON u.id=i.upload_id AND u.object_version=i.object_version WHERE i.message_id=?`, string(id))
	if e != nil {
		return ports.ErrUnavailable
	}
	keys := []string{}
	for rows.Next() {
		var main string
		var poster sql.NullString
		if rows.Scan(&main, &poster) != nil {
			rows.Close()
			return ErrMedia
		}
		keys = append(keys, main)
		if poster.Valid && poster.String != "" {
			keys = append(keys, poster.String)
		}
	}
	e = rows.Err()
	rows.Close()
	if e != nil {
		return ports.ErrUnavailable
	}
	if len(keys) == 0 {
		return ErrObjectMissing
	}
	for _, key := range keys {
		if e = s.av.storage.Keep(ctx, key); e != nil {
			return e
		}
	}
	return nil
}
