package ports

import "strings"

const (
	OrderScene   = "order"
	DirectScene  = "direct"
	SupportScene = "support"
)

type Scope struct {
	Scene, Layout string
	First, Second ID
}

func ParseScope(key string) (Scope, bool) {
	if len(key) > 96 {
		return Scope{}, false
	}
	parts := strings.Split(key, ":")
	positive := func(value string) bool { return value != "0" && ID(value).Valid() }
	switch {
	case len(parts) == 4 && parts[0] == OrderScene && positive(parts[1]) && parts[2] == "assignment" && positive(parts[3]):
		return Scope{OrderScene, "assignment", ID(parts[1]), ID(parts[3])}, true
	case len(parts) == 3 && parts[0] == OrderScene && positive(parts[1]) && parts[2] == "collaboration":
		return Scope{OrderScene, "collaboration", ID(parts[1]), ""}, true
	case len(parts) == 2 && parts[0] == "sandbox" && positive(parts[1]) && len(parts[1]) <= 17:
		return Scope{OrderScene, "sandbox", ID(parts[1]), ""}, true
	case len(parts) == 3 && parts[0] == DirectScene && positive(parts[1]) && positive(parts[2]):
		if len(parts[1]) < len(parts[2]) || len(parts[1]) == len(parts[2]) && parts[1] < parts[2] {
			return Scope{DirectScene, "pair", ID(parts[1]), ID(parts[2])}, true
		}
	case len(parts) == 3 && parts[0] == SupportScene && parts[1] == "platform_feedback" && positive(parts[2]):
		return Scope{SupportScene, "platform_feedback", ID(parts[2]), ""}, true
	}
	return Scope{}, false
}
