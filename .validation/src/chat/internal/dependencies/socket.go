package dependencies

import (
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
)

func (r *Runtime) EnableSocket(c socket.Config) error {
	if !c.Enabled {
		return nil
	}
	if r.Identity == nil || r.redis == nil || r.sql == nil || r.mode != "disabled" {
		return ports.ErrInvalid
	}
	var e error
	if r.Conversations != nil {
		r.Socket, e = socket.NewWithPolicy(r.Identity, guardedCommands{r, callCommands{r,message.SocketCommands{Sender: r.Messages, History: r.History}}}, guardedPolicy{r, r.Conversations})
	} else {
		r.Socket, e = socket.New(r.Identity, guardedCommands{r, callCommands{r,message.SocketCommands{History: r.History}}})
	}
	return e
}