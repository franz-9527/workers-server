//go:build !linux

package avpipeline

import (
	"github.com/franz-9527/workers/chat/internal/message"
	"os/exec"
)

func configureMediaProcess(cmd *exec.Cmd) error { return message.ErrMedia }
