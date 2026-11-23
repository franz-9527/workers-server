package dependencies

import (
	"context"
	"github.com/franz-9527/workers/chat/internal/message"
	"github.com/franz-9527/workers/chat/internal/ports"
	"github.com/franz-9527/workers/chat/internal/socket"
)

func (p publicationRelay) ProbeCall(ctx context.Context, actor ports.Principal, cid, peer ports.ID, media, operation string) (ports.CallReadyProof,error) {
	if p.runtime.Socket==nil{return ports.CallReadyProof{},ports.ErrUnavailable}
	return p.runtime.Socket.ProbeCall(ctx,actor,cid,peer,media,operation)
}

func (p publicationRelay) CallReachable(ctx context.Context,cid,peer ports.ID,environment,instance string) (string,bool) {
	if p.runtime.Socket==nil{return "unknown",false}
	return p.runtime.Socket.CallReachable(ctx,cid,peer,environment,instance)
}
func (p publicationRelay) CallInstanceReady(actor ports.ID,instance string) bool {
	return p.runtime.Socket!=nil && p.runtime.Socket.CallInstanceReady(actor,instance)
}

func (r *Runtime) EnableSocket(c socket.Config) error {
	if !c.Enabled {
		return nil
	}
	if r.Identity == nil || r.redis == nil || r.sql == nil || r.mode != "disabled" {
		return ports.ErrInvalid
	}
	var e error
	if r.Conversations != nil {
		r.Socket, e = socket.NewWithPolicy(r.Identity, guardedCommands{r, callCommands{r, message.SocketCommands{Sender: r.Messages, History: r.History}}}, guardedPolicy{r, r.Conversations})
	} else {
		r.Socket, e = socket.New(r.Identity, guardedCommands{r, callCommands{r, message.SocketCommands{History: r.History}}})
	}
	if e == nil && r.History != nil && r.Socket != nil {
		r.History.SetReceiptPublisher(r.Socket)
	}
	return e
}
