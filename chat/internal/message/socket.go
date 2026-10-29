package message

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/policy"
	"github.com/franz-9527/workers/chat/internal/ports"
)

// SocketCommands adapts the exact HTTP payload decoders and application use
// cases. Runtime supplies no sender until the independent release gate opens.
type SocketCommands struct {
	Sender  *Service
	History *HistoryService
}
type commandError struct {
	code  string
	retry bool
}

func (e commandError) Error() string              { return e.code }
func (e commandError) PublicCode() (string, bool) { return e.code, e.retry }
func socketError(e error) error {
	if e == nil {
		return nil
	}
	switch {
	case errors.Is(e, ports.ErrInvalid):
		return commandError{"CHAT_INVALID_REQUEST", false}
	case errors.Is(e, policy.ErrDenied), errors.Is(e, auth.ErrUnauthorized):
		return commandError{"CHAT_PERMISSION_DENIED", false}
	case errors.Is(e,ErrReference),errors.Is(e,ErrReeditLimit),errors.Is(e,ErrRecall),errors.Is(e,ErrRecallWindow),errors.Is(e, ErrPreferenceConflict), errors.Is(e,ErrPinLimit), errors.Is(e, ErrConflict), errors.Is(e, ErrExpired), errors.Is(e, ErrRestricted), errors.Is(e, ErrResync):
		return commandError{e.Error(), false}
	case errors.Is(e, ErrUnknown), errors.Is(e, ErrCheck), errors.Is(e, ports.ErrBusy):
		return commandError{e.Error(), true}
	default:
		return commandError{ports.ErrUnavailable.Error(), true}
	}
}
func (s SocketCommands) Execute(ctx context.Context, p ports.Principal, typ string, raw json.RawMessage) (string, any, error) {
	if len(raw) > 4096 && typ != "message.send" && typ != "message.status" {
		return "", nil, socketError(ports.ErrInvalid)
	}
	var out any
	var e error
	response := typ + ".result"
	switch typ {
	case "message.recall":
        if s.Sender==nil{return "",nil,commandError{"CHAT_MESSAGES_NOT_READY",false}}
        q,err:=decodeRecall(raw);if err!=nil{return "",nil,socketError(err)}
        out,e=s.Sender.Recall(ctx,p,q.ConversationID,q.TargetID)
	case "message.send", "message.status":
		if s.Sender == nil {
			return "", nil, commandError{"CHAT_MESSAGES_NOT_READY", false}
		}
		q, err := decode(raw, typ == "message.status")
		if err != nil {
			return "", nil, socketError(err)
		}
		if typ == "message.send" {
			out, e = s.Sender.Send(ctx, p, q)
			response = "message.result"
			if r, ok := out.(Result); ok && r.State == "accepted" {
				response = "message.accepted"
			}
		} else {
			out, e = s.Sender.Query(ctx, p, q.ConversationID, q.ClientMessageID)
		}
	case "conversation.bootstrap", "history.request", "sync.request", "changes.sync", "message.locate":
		if s.History == nil {
			return "", nil, commandError{"CHAT_HISTORY_NOT_READY", false}
		}
		mode := map[string]string{"conversation.bootstrap": "bootstrap", "history.request": "history", "sync.request": "sync", "changes.sync": "changes", "message.locate": "locate"}[typ]
		q, err := decodeHistory(raw)
		if err != nil {
			return "", nil, socketError(err)
		}
		out, e = s.History.Read(ctx, p, q, mode)
		if typ == "sync.request" {
			response = "sync.result"
		}
	case "conversation.preferences","conversation.preference":
  if s.History==nil{return "",nil,commandError{"CHAT_HISTORY_NOT_READY",false}}
  q,err:=decodePreference(raw,typ=="conversation.preference");if err!=nil{return "",nil,socketError(err)}
  if typ=="conversation.preference"{out,e=s.History.SetPreference(ctx,p,q)}else{out,e=s.History.Preferences(ctx,p,q.ConversationID)}
 case "receipt.read", "conversation.unread", "account.digests":
		if s.History == nil {
			return "", nil, commandError{"CHAT_HISTORY_NOT_READY", false}
		}
		q, err := decodeS09(raw)
		if err != nil {
			return "", nil, socketError(err)
		}
		switch typ {
		case "receipt.read":
			if !validID(q.ConversationID) || q.Cursor == "" || q.Limit != 0 {
				return "", nil, socketError(ports.ErrInvalid)
			}
			out, e = s.History.Acknowledge(ctx, p, q.ConversationID, q.Cursor)
		case "conversation.unread":
			if !validID(q.ConversationID) || q.Cursor != "" || q.Limit != 0 {
				return "", nil, socketError(ports.ErrInvalid)
			}
			out, e = s.History.Unread(ctx, p, q.ConversationID)
		case "account.digests":
			if q.ConversationID != "" || q.Limit < 1 || q.Limit > 20 {
				return "", nil, socketError(ports.ErrInvalid)
			}
			out, e = s.History.DigestsCursor(ctx, p, q.Cursor, q.Limit)
		}
	default:
		return "", nil, commandError{"CHAT_COMMAND_UNKNOWN", false}
	}
	return response, out, socketError(e)
}
