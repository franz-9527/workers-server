package message

import (
	"encoding/json"
	"net/http"
)

// Marshal before writing: invalid IDs must never produce an empty 200 response.
func writeJSON(w http.ResponseWriter, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	body, err := json.Marshal(value)
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		_, _ = w.Write([]byte("{\"code\":\"CHAT_RESPONSE_UNAVAILABLE\",\"message\":\"响应生成失败，请刷新重试\",\"retryable\":true}"))
		return
	}
	_, _ = w.Write(append(body, '\n'))
}
