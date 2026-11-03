package ports

import "strconv"

const SandboxSourceBase uint64 = 9000000000000000000
const SandboxBindingBase uint64 = 8000000000000000000
func SandboxSource(id ID) (uint64, bool) {
 n,e:=strconv.ParseUint(string(id),10,64)
 return n-SandboxSourceBase,e==nil&&n>SandboxSourceBase&&n<SandboxSourceBase+100000000000000000
}
func SandboxPrincipal(p Principal) bool { _,ok:=SandboxSource(p.SourceID);return ok&&p.Environment=="test"&&p.Kind=="mini" }
