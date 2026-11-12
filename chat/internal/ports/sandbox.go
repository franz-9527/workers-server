package ports

import (
	"strconv"
	"strings"
)

const SandboxSourceBase uint64 = 9000000000000000000
const SandboxBindingBase uint64 = 8000000000000000000
const sandboxMiniAdminBase uint64 = 6000000000000000000

func SandboxMiniAdminUser(key string, wire ID) (ID, bool) {
	user, ok := strings.CutPrefix(key, "mini-admin:")
	if !ok || !ID(user).Valid() || user == "0" || len(user) > 18 {
		return "", false
	}
	n, err := strconv.ParseUint(user, 10, 64)
	return ID(user), err == nil && string(wire) == strconv.FormatUint(sandboxMiniAdminBase+n, 10)
}

func SandboxSource(id ID) (uint64, bool) {
	n, e := strconv.ParseUint(string(id), 10, 64)
	return n - SandboxSourceBase, e == nil && n > SandboxSourceBase && n < SandboxSourceBase+100000000000000000
}
func SandboxPrincipal(p Principal) bool {
	_, ok := SandboxSource(p.SourceID)
	return ok && p.Environment == "test" && p.Kind == "mini"
}
