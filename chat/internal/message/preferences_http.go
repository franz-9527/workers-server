package message

import (
	"bytes"
	"encoding/json"
	"io"
	"regexp"
	"unicode/utf8"

	"github.com/franz-9527/workers/chat/internal/ports"
)

var memberVersionPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

// Both transports use the same closed schema. No account/role override,
// muted/hidden flag, read_seq, batch object, duplicate key or null is accepted.
func decodePreference(raw []byte, write bool) (PreferenceRequest, error) {
	var q PreferenceRequest
	if len(raw) > 4096 || !utf8.Valid(raw) {
		return q, ports.ErrInvalid
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	token, err := d.Token()
	if err != nil || token != json.Delim('{') {
		return q, ports.ErrInvalid
	}
	seen := map[string]bool{}
	for d.More() {
		token, err = d.Token()
		key, ok := token.(string)
		if err != nil || !ok || seen[key] {
			return q, ports.ErrInvalid
		}
		seen[key] = true
		var field json.RawMessage
		if d.Decode(&field) != nil {
			return q, ports.ErrInvalid
		}
		if key == "value" && write {
			if !bytes.Equal(field, []byte("true")) && !bytes.Equal(field, []byte("false")) {
				return q, ports.ErrInvalid
			}
			q.Value = bytes.Equal(field, []byte("true"))
			continue
		}
		var value string
		if len(field) < 2 || field[0] != '"' || !validSurrogates(field) || json.Unmarshal(field, &value) != nil {
			return q, ports.ErrInvalid
		}
		switch key {
		case "conversation_id":
			q.ConversationID = ports.ID(value)
		case "member_version":
			if !write {
				return q, ports.ErrInvalid
			}
			q.MemberVersion = value
		case "field":
			if !write {
				return q, ports.ErrInvalid
			}
			q.Field = value
		case "expected_version":
			if !write {
				return q, ports.ErrInvalid
			}
			q.Expected = ports.ID(value)
		default:
			return q, ports.ErrInvalid
		}
	}
	token, err = d.Token()
	if err != nil || token != json.Delim('}') {
		return q, ports.ErrInvalid
	}
	if _, err = d.Token(); err != io.EOF || !validID(q.ConversationID) {
		return q, ports.ErrInvalid
	}
	if write && (len(seen) != 5 || !seen["value"] || !memberVersionPattern.MatchString(q.MemberVersion) || !q.Expected.Valid() || (q.Field != "pinned" && q.Field != "later")) {
		return q, ports.ErrInvalid
	}
	if !write && len(seen) != 1 {
		return q, ports.ErrInvalid
	}
	return q, nil
}
