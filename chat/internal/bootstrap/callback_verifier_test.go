package bootstrap

import (
	"crypto/sha1"
	"encoding/base64"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestCallbackVerifierRequiresAnAllowedEnvironmentAndConfiguration(t *testing.T) {
	for _, env := range []string{"production", "development", "test"} {
		c := testConfig()
		c.Environment = env
		c.HTTP.Listen = "127.0.0.1:19501"
		c.HTTP.InternalListen = "127.0.0.1:19502"
		c.AV.CallbackVerification = true
		c.AV.CallbackTokenEnv = "CHAT_WECHAT_CALLBACK_TOKEN"
		c.AV.CallbackKeyEnv = "CHAT_WECHAT_CALLBACK_AES_KEY"
		fault := c.validate()
		if fault == nil || fault.Code != "CHAT_AV_NOT_READY" {
			t.Fatal("callback verifier accepted an incomplete or unsupported configuration", env, fault)
		}
	}
}

func TestProductionCallbackVerificationKeepsMediaClosed(t *testing.T) {
	c := testConfig()
	c.Environment = "production"
	c.HTTP.Listen = "127.0.0.1:9501"
	c.HTTP.InternalListen = "127.0.0.1:9502"
	c.AV.CallbackVerification = true
	c.AV.CallbackTokenEnv = "CHAT_WECHAT_CALLBACK_TOKEN"
	c.AV.CallbackKeyEnv = "CHAT_WECHAT_CALLBACK_AES_KEY"
	c.Safety.AppID = "wx0123456789abcdef"
	const token = "SyntheticToken1234"
	t.Setenv(c.AV.CallbackTokenEnv, token)
	t.Setenv(c.AV.CallbackKeyEnv, base64.RawStdEncoding.EncodeToString(make([]byte, 32)))

	if fault := c.validate(); fault != nil {
		t.Fatalf("verification-only configuration rejected: %v", fault)
	}
	handler, err := c.productionCallbackVerifier()
	if err != nil {
		t.Fatal(err)
	}
	for method, want := range map[string]int{"GET": http.StatusForbidden, "POST": http.StatusServiceUnavailable} {
		response := httptest.NewRecorder()
		handler.ServeHTTP(response, httptest.NewRequest(method, "/chat/api/v1/av/callback", nil))
		if response.Code != want {
			t.Fatalf("%s returned %d, want %d", method, response.Code, want)
		}
	}

	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	parts := []string{token, timestamp, "fixture"}
	sort.Strings(parts)
	signature := sha1.Sum([]byte(strings.Join(parts, "")))
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, httptest.NewRequest(http.MethodGet,
		"/chat/api/v1/av/callback?timestamp="+timestamp+"&nonce=fixture&echostr=proof&signature="+hex.EncodeToString(signature[:]), nil))
	if response.Code != http.StatusOK || response.Body.String() != "proof" {
		t.Fatal("signed WeChat challenge was not echoed")
	}

	c.AV.Enabled = true
	if fault := c.validate(); fault == nil || fault.Code != "CHAT_AV_NOT_READY" {
		t.Fatal("verification-only configuration opened AV", fault)
	}
}
