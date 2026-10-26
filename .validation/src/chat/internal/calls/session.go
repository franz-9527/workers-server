package calls

import (
    "math"

    "github.com/franz-9527/workers/chat/internal/ports"
)

type State string
const (
    Ringing State = "ringing"
    Preparing State = "preparing"
    Connecting State = "connecting"
    Active State = "active"
    Stopping State = "stopping"
    Rejected State = "rejected"
    Cancelled State = "cancelled"
    Missed State = "missed"
    Ended State = "ended"
    Failed State = "failed"
)

func (s State) Terminal() bool {
    return s == Rejected || s == Cancelled || s == Missed || s == Ended || s == Failed
}

// Session is a value model, not a concurrent session store. The future owner
// must lock both actors, persist CAS+operation idempotency, and reauthorize both
// members on each action. A ResourceDecision never authorizes media_start.
type Session struct {
    CallID, ConversationID, CallerID, CalleeID ports.ID
    Provider Provider
    Media Media
    Epoch, Revision uint64
    State State
    RingExpiresAt, LastEventAt int64
    FallbackUsed bool
}

func NewSession(call, conversation, caller, callee ports.ID, provider Provider, media Media, now, ringExpires int64) (Session, error) {
    if !positive(call) || !positive(conversation) || !positive(caller) || !positive(callee) ||
        caller == callee || !provider.valid() || (media != Audio && media != Video) || now < 1 || ringExpires <= now {
        return Session{}, ErrInvalid
    }
    return Session{CallID:call, ConversationID:conversation, CallerID:caller, CalleeID:callee,
        Provider:provider, Media:media, Epoch:1, Revision:1, State:Ringing, RingExpiresAt:ringExpires, LastEventAt:now}, nil
}

type Event struct {
    Name string
    // Empty actor means a trusted server event. HTTP input must never choose it.
    ActorID ports.ID
    Epoch, ExpectedRevision uint64
    At int64
    NextProvider Provider
}

// Apply is immutable on failure. Server events are trusted facts collected by
// the future adapter, not SDK/client assertions. Unknown media results stay
// Connecting/Stopping and retain reservations until verified media_stopped.
func (s Session) Apply(e Event) (Session, error) {
    if e.Epoch != s.Epoch || e.ExpectedRevision != s.Revision { return s, ErrConflict }
    if !s.valid() { return s, ErrInvalid }
    if s.State.Terminal() || e.At < s.LastEventAt || s.Revision == math.MaxUint64 { return s, ErrConflict }
    server := e.ActorID == ""
    caller := e.ActorID == s.CallerID
    callee := e.ActorID == s.CalleeID
    if !server && !caller && !callee { return s, ErrInvalid }
    next := s
    switch e.Name {
    case "accept":
        if s.State != Ringing || !callee { return s, ErrConflict }
        if e.At >= s.RingExpiresAt { return s, ErrExpired }
        next.State = Preparing
    case "reject":
        if s.State != Ringing || !callee { return s, ErrConflict }
        if e.At >= s.RingExpiresAt { return s, ErrExpired }
        next.State = Rejected
    case "cancel":
        if s.State != Ringing || !caller { return s, ErrConflict }
        if e.At >= s.RingExpiresAt { return s, ErrExpired }
        next.State = Cancelled
    case "ring_timeout":
        if s.State != Ringing || !server || e.At < s.RingExpiresAt { return s, ErrConflict }
        next.State = Missed
    case "media_start":
        // Must follow the future PHP durable reserve ACK + current authority
        // check. This pure model has no route or credential issuing capability.
        if s.State != Preparing || !server { return s, ErrConflict }
        next.State = Connecting
    case "media_ready":
        if s.State != Connecting || !server { return s, ErrConflict }
        next.State = Active
    case "fallback":
        // Only BEFORE any create/join submission. After media_start, even a
        // timeout may mean one leg is consuming resources: never auto-switch.
        if s.State != Preparing || !server || s.FallbackUsed || !e.NextProvider.valid() ||
            e.NextProvider == s.Provider || s.Epoch == math.MaxUint64 { return s, ErrConflict }
        next.Provider, next.Epoch, next.FallbackUsed = e.NextProvider, s.Epoch+1, true
    case "end", "revoke", "resource_stop", "media_failure":
        if (e.Name != "end" && !server) || (e.Name == "end" && !caller && !callee) { return s, ErrConflict }
        switch s.State {
        case Ringing:
            if !server { return s, ErrConflict }
            next.State = Failed
        case Preparing:
            next.State = Ended
            if e.Name == "media_failure" { next.State = Failed }
        case Connecting, Active:
            next.State = Stopping
        default: return s, ErrConflict
        }
    case "media_stopped":
        if s.State != Stopping || !server { return s, ErrConflict }
        next.State = Ended
    default:
        return s, ErrInvalid
    }
    if e.Name != "fallback" && e.NextProvider != "" { return s, ErrInvalid }
    next.Revision++
    next.LastEventAt = e.At
    return next, nil
}

func (s Session) valid() bool {
    if !positive(s.CallID) || !positive(s.ConversationID) || !positive(s.CallerID) ||
        !positive(s.CalleeID) || s.CallerID == s.CalleeID || !s.Provider.valid() ||
        (s.Media != Audio && s.Media != Video) || s.Epoch == 0 || s.Revision < s.Epoch ||
        s.LastEventAt < 1 || s.RingExpiresAt < 1 || s.Epoch > 2 || s.FallbackUsed != (s.Epoch == 2) { return false }
    switch s.State {
    case Ringing, Preparing, Connecting, Active, Stopping, Rejected, Cancelled, Missed, Ended, Failed: return true
    default: return false
    }
}
