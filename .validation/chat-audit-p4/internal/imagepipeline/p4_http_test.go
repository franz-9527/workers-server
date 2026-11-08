package imagepipeline

import (
	"strings"
	"testing"
)

func TestP4PublicHTTPBackendRequiresHTTPSSiteProxy(t *testing.T) {
	cfg := QiniuConfig{
		Bucket: "chatbucket", AccessKey: "ak", SecretKey: "sk",
		DownloadOrigin: "http://cdn.example.com", UploadOrigin: "https://upload.qiniup.com",
		Environment: "production", Namespace: "chatmedia",
		ProxyOrigin: "https://worker.example.com", ProxyKey: strings.Repeat("k", 32),
	}
	if _, err := NewQiniu(cfg); err != nil {
		t.Fatalf("public HTTP backend with HTTPS proxy rejected: %v", err)
	}
	cfg.ProxyOrigin = ""
	if _, err := NewQiniu(cfg); err == nil {
		t.Fatal("public HTTP backend accepted without HTTPS site proxy")
	}
	cfg.Private = true
	cfg.Namespace = ""
	cfg.ProxyOrigin = ""
	if _, err := NewQiniu(cfg); err == nil {
		t.Fatal("private bucket accepted HTTP download origin")
	}
	cfg.DownloadOrigin = "https://cdn.example.com"
	if _, err := NewQiniu(cfg); err != nil {
		t.Fatalf("private HTTPS origin rejected: %v", err)
	}
}
