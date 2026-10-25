// Package ports defines service boundaries, not PHP order rules or SQL owners.
package ports

import (
	"context"
	"encoding/json"
	"errors"
	"strconv"
	"time"
)

var (
	ErrUnavailable = errors.New("CHAT_DEPENDENCY_UNAVAILABLE")
	ErrBusy        = errors.New("CHAT_RESOURCE_BUSY")
	ErrInvalid     = errors.New("CHAT_CONTRACT_INVALID")
)

// ID is a canonical unsigned BIGINT carried as a decimal JSON string.
// Cursor zero is allowed; an absent optional ID must use a pointer/omitempty.
type ID string

func (id ID) Valid() bool {
	n, err := strconv.ParseUint(string(id), 10, 64)
	return err == nil && strconv.FormatUint(n, 10) == string(id)
}

func (id ID) MarshalJSON() ([]byte, error) {
	if !id.Valid() {
		return nil, ErrInvalid
	}
	return json.Marshal(string(id))
}

func (id *ID) UnmarshalJSON(data []byte) error {
	var value string
	if json.Unmarshal(data, &value) != nil || !ID(value).Valid() {
		return ErrInvalid
	}
	*id = ID(value)
	return nil
}

type Principal struct {
	ActorID                ID
	SessionID, Environment string
	Scopes                 []string
	Kind                   string
	SourceID               ID
	SessionVersion         string
	ExpiresAt              time.Time
}
type PolicyRequest struct {
	Actor          Principal
	ConversationID ID
	Action         string
}

// OrderContext is PHP-supplied display data, never an authorization input.
type OrderContext struct {
	PublicNo string `json:"public_no"`
	Title    string `json:"title"`
	Workflow string `json:"workflow"`
}

type Grant struct {
	Mode, RoleSource, DisplayRole string
	DisplayRoleVersion            ID
	OrderContext                  *OrderContext
	MemberRole                    string
	CanRead, CanSend              bool
	ScopeKey                      string
	Version                       ID
	// S05 must bind intervals to this immutable authority generation, not a
	// processing-time seq. Staff returning after revocation get a different key.
	MemberKey                            string
	StaffConfigVersion, StaffUserVersion ID
}
type TextRequest struct {
	Actor                    Principal
	ConversationID, IntentID ID
	Text, Fingerprint        string
}
type SafetyResult struct {
	Decision, RuleVersion string
	// AuthorityProof binds the real AppID/OPENID generation; never client input.
	AuthorityProof string
	Finding        *SafetyFinding
}
type SafetyFinding struct {
	Provider, Code, TraceID string
	ContextEvidence         string
	Level                   int
	Confirmed               bool
}
type MediaRequest struct {
	Actor                                  Principal
	IntentID                               ID
	ObjectKey, ObjectVersion, ManifestHash string
}
type MediaResult struct{ Decision, ObjectVersion, ManifestHash string }
type Reminder struct {
	IntentID, RecipientID, TargetSeq, ClusterVersion ID
	ConversationID                                   ID
	OperationID                                      string
}
type Receipt struct {
	OperationID, State string
	Diagnostic         string
}

type Identity interface {
	Authenticate(context.Context, string) (Principal, error)
}

// CommittedMessage is a best-effort wakeup, never a second message store.
type CommittedMessage struct{ ConversationID, Seq, PolicyVersion ID }
type MessagePublisher interface{ Committed(CommittedMessage) }
type RealtimePolicy interface {
	Policy
	MemberCandidates(context.Context, ID) ([]ID, error)
}
type Policy interface {
	Authorize(context.Context, PolicyRequest) (Grant, error)
}
type Safety interface {
	CheckText(context.Context, TextRequest) (SafetyResult, error)
}
type Media interface {
	Inspect(context.Context, MediaRequest) (MediaResult, error)
}
type Notification interface {
	Submit(context.Context, Reminder) (Receipt, error)
}
type Set struct {
	Identity     Identity
	Policy       Policy
	Safety       Safety
	Media        Media
	Notification Notification
}

// Audio cloud work is admitted only for a server-confirmed immutable media job.
type AudioInspectionRequest struct {
	Actor          Principal
	ConversationID ID
	IntentID       ID
	ObjectHash     string
	URL            string
	Segment        int
	UserAudioKey   string
}
type Transcription struct {
	Text, RequestID, AuthorityProof, ServiceID, APIName, Engine string
	Dispatched, Known, Retryable                                bool
}
type AudioTicket struct {
	TraceID, AuthorityProof      string
	Dispatched, Known, Retryable bool
}
type AudioReceipt struct{ AppID, TraceID, Decision, Code, Fingerprint string }
type AVInspector interface {
	Recognize(context.Context, AudioInspectionRequest) (Transcription, error)
	SubmitAudio(context.Context, AudioInspectionRequest) (AudioTicket, error)
	MediaAppID() string
}
