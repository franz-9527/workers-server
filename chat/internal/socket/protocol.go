// Package socket owns the single-instance WSS transport, never business SQL.
package socket

import (
	"bytes"
	"encoding/json"
	"io"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"
)

type frame struct {
	Version   int             `json:"protocol_version"`
	RequestID string          `json:"request_id"`
	Type      string          `json:"type"`
	Payload   json.RawMessage `json:"payload"`
}

var requestID = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,64}$`)

// JSON's duplicate keys, case aliases and replacement of unpaired surrogates
// must not give different transports different interpretations of a command.
func strict(raw []byte, target any) bool {
	if !utf8.Valid(raw) || !surrogates(raw) {
		return false
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	if !unique(d, 0) {
		return false
	}
	if _, e := d.Token(); e != io.EOF {
		return false
	}
	d = json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	return d.Decode(target) == nil
}
func unique(d *json.Decoder, depth int) bool {
	if depth > 12 {
		return false
	}
	t, e := d.Token()
	if e != nil || t == nil {
		return false
	}
	delim, ok := t.(json.Delim)
	if !ok {
		return true
	}
	if delim != '{' && delim != '[' {
		return false
	}
	seen := map[string]bool{}
	for d.More() {
		if delim == '{' {
			t, e = d.Token()
			k, ok := t.(string)
			if e != nil || !ok || seen[k] || k != strings.ToLower(k) {
				return false
			}
			seen[k] = true
		}
		if !unique(d, depth+1) {
			return false
		}
	}
	end, e := d.Token()
	return e == nil && (delim == '{' && end == json.Delim('}') || delim == '[' && end == json.Delim(']'))
}
func surrogates(raw []byte) bool {
	for i := 0; i < len(raw); i++ {
		if raw[i] != '\\' {
			continue
		}
		i++
		if i >= len(raw) {
			return false
		}
		if raw[i] != 'u' {
			continue
		}
		if i+4 >= len(raw) {
			return false
		}
		n, e := strconv.ParseUint(string(raw[i+1:i+5]), 16, 16)
		if e != nil {
			return false
		}
		i += 4
		if n >= 0xdc00 && n <= 0xdfff {
			return false
		}
		if n >= 0xd800 && n <= 0xdbff {
			if i+6 >= len(raw) || raw[i+1] != '\\' || raw[i+2] != 'u' {
				return false
			}
			n, e = strconv.ParseUint(string(raw[i+3:i+7]), 16, 16)
			if e != nil || n < 0xdc00 || n > 0xdfff {
				return false
			}
			i += 6
		}
	}
	return true
}
func decode(raw []byte) (frame, bool) {
	var f frame
	ok := strict(raw, &f) && len(bytes.TrimSpace(raw)) > 0 && bytes.TrimSpace(raw)[0] == '{' && f.Version == 1 && requestID.MatchString(f.RequestID) && len(f.Type) <= 64 && len(f.Payload) > 0 && f.Payload[0] == '{'
	return f, ok
}
