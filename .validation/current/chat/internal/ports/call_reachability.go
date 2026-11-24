package ports

import (
	"context"
	"errors"
)

var ErrCallPeerOffline = errors.New("CALL_PEER_OFFLINE")
var ErrCallPeerNotReady = errors.New("CALL_PEER_NOT_READY")

type CallReachability interface {
	ProbeCall(context.Context, Principal, ID, ID, string, string) (CallReadyProof, error)
	CallReachable(context.Context, ID, ID, string, string) (string, bool)
	CallInstanceReady(ID,string) bool
}

// Valid checks in-process connection state only. The call transaction still
// locks both business authorities and must never wait for network IO here.
type CallReadyProof struct {
	Instance string
	Valid    func() bool
}
