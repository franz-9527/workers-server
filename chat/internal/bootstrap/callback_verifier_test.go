package bootstrap

import "testing"

func TestCallbackVerifierIsConfinedToRealTestMessages(t *testing.T) {
    for _, env := range []string{"production", "development", "test"} {
        c := testConfig()
        c.Environment = env
        c.AV.CallbackVerification = true
        c.AV.CallbackTokenEnv = "CHAT_WECHAT_CALLBACK_TOKEN"
        c.AV.CallbackKeyEnv = "CHAT_WECHAT_CALLBACK_AES_KEY"
        fault := c.validate()
        if fault == nil || fault.Code != "CHAT_AV_NOT_READY" { t.Fatal("callback verifier escaped test real-message gate", env, fault) }
    }
}
