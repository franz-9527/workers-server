package moderation

import (
    "crypto/sha1"
    "encoding/base64"
    "encoding/hex"
    "encoding/json"
    "net/http/httptest"
    "net/url"
    "sort"
    "strconv"
    "strings"
    "testing"
    "time"
)

func TestCallbackVerificationBeforeMediaAdmission(t *testing.T) {
    token := strings.Repeat("t", 24)
    key := base64.RawStdEncoding.EncodeToString([]byte(strings.Repeat("k", 32)))
    verifier, err := NewMediaCallbackVerifier("wx0123456789abcdef", token, key)
    if err != nil { t.Fatal(err) }
    stamp := strconv.FormatInt(time.Now().Unix(), 10)
    parts := []string{token, stamp, "fixture"}
    sort.Strings(parts)
    sum := sha1.Sum([]byte(strings.Join(parts, "")))
    q := url.Values{"signature": {hex.EncodeToString(sum[:])}, "timestamp": {stamp}, "nonce": {"fixture"}, "echostr": {"ownership-check"}}
    w := httptest.NewRecorder()
    verifier.ServeHTTP(w, httptest.NewRequest("GET", "/chat/api/v1/av/callback?"+q.Encode(), nil))
    if w.Code != 200 || w.Body.String() != "ownership-check" { t.Fatalf("ownership check: %d %s", w.Code, w.Body.String()) }
    q.Set("signature", strings.Repeat("0", 40))
    w = httptest.NewRecorder()
    verifier.ServeHTTP(w, httptest.NewRequest("GET", "/chat/api/v1/av/callback?"+q.Encode(), nil))
    if w.Code != 403 { t.Fatal("forged ownership proof accepted") }
    callback, _ := callbackConfig("wx0123456789abcdef", token, key)
    signed := callbackRequest(t, callback, "encrypted-ownership", false)
    var envelope struct { Encrypt string }
    if json.NewDecoder(signed.Body).Decode(&envelope) != nil { t.Fatal("fixture") }
    q = signed.URL.Query()
    q.Set("echostr", envelope.Encrypt)
    w = httptest.NewRecorder()
    verifier.ServeHTTP(w, httptest.NewRequest("GET", "/chat/api/v1/av/callback?"+q.Encode(), nil))
    if w.Code != 200 || w.Body.String() != "encrypted-ownership" { t.Fatal("encrypted ownership check failed", w.Code) }
    for _, method := range []string{"POST", "PUT", "DELETE"} {
        w = httptest.NewRecorder()
        verifier.ServeHTTP(w, httptest.NewRequest(method, "/chat/api/v1/av/callback?"+q.Encode(), strings.NewReader("event")))
        if w.Code != 503 || strings.TrimSpace(w.Body.String()) == "success" { t.Fatal("verification-only mode acknowledged media event") }
    }
    if _, err := NewMediaCallbackVerifier("wx0123456789abcdef", token, "invalid"); err == nil { t.Fatal("invalid callback key accepted") }
}
