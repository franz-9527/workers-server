#!/usr/bin/env python3
from pathlib import Path
import hashlib, json, sys

ROOT = Path(__file__).resolve().parent
manifest = json.loads((ROOT / "manifest.json").read_text(encoding="utf-8"))

def blob_sha(path: Path) -> str:
    data = path.read_bytes()
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

for relative, expected in manifest["files"].items():
    actual = blob_sha(ROOT / relative)
    if actual != expected:
        raise AssertionError(f"fixture mismatch: {relative}: {actual} != {expected}")

def read(name):
    return (ROOT / "fixtures" / name).read_text(encoding="utf-8")

def require(text, needle, label):
    if needle not in text:
        raise AssertionError(f"missing {label}: {needle}")

def forbid(text, needle, label):
    if needle in text:
        raise AssertionError(f"unexpected {label}: {needle}")

wxml = read("index.wxml")
forbid(wxml, "回到底部", "visible bottom-jump control")
forbid(wxml, "回到顶部", "visible top-jump control")
forbid(wxml, 'bindtap="latestMessages"', "manual latest-messages action")

index_js = read("index.js")
require(index_js, "sourceType:event?.currentTarget?.dataset?.source==='camera'?['camera']:['album'],sizeType:['compressed']", "separate photo/camera source with compressed images")
require(index_js, "sourceType:['album'],sizeType:['compressed']", "album-only replacement with compressed images")
require(index_js, "f.size>3*1024*1024", "3 MiB client image limit")
require(index_js, "每张图片不超过3MB", "3 MB user-facing limit")
forbid(index_js, "每张图片不超过8MB", "old 8 MB user-facing image limit")
require(index_js, "this.refreshComposer();this.jumpBottom();this.manager.typing(false)", "text send auto-scroll")
require(index_js, "this.refreshImages();this.jumpBottom();this.setData", "image send auto-scroll")

wxss = read("index.wxss")
require(wxss, "border-top:1rpx solid rgba(127,139,143,.22)", "weak transcript separator")
require(wxss, "padding:12rpx 0 14rpx", "transcript bottom spacing")

av_js = read("av.js")
require(av_js, "this.refreshAV();this.jumpBottom()", "manual AV send auto-scroll")

experience_js = read("experience.js")
require(experience_js, "this.refreshComposer();this.jumpBottom()", "order-card send auto-scroll")

images_js = read("ChatImages.js")
require(images_js, "f.size>3*1024*1024", "ChatImages 3 MiB hard limit")
forbid(images_js, "f.size>8*1024*1024", "old ChatImages 8 MiB hard limit")

media_go = read("media.go")
require(media_go, "const MaxImageBytes = 3 << 20", "Go 3 MiB hard limit")
forbid(media_go, "const MaxImageBytes = 8 << 20", "old Go 8 MiB hard limit")

runtime_php = read("ChatGoRuntimeConfig.php")
require(runtime_php, "'max_file_bytes'=>3145728", "PHP runtime 3 MiB image limit")
forbid(runtime_php, "'max_file_bytes'=>8388608", "old PHP runtime 8 MiB image limit")

config_text = read("config.example.json")
require(config_text, '"max_file_bytes": 3145728', "example runtime 3 MiB image limit")
json.loads(config_text)

testing_client = read("chat-testing-client.js")
require(testing_client, "CHAT_IMAGE_LIMIT:'图片最多9张，单张不超过3MB'", "browser testing 3 MB message")
forbid(testing_client, "CHAT_IMAGE_LIMIT:'图片最多9张，单张不超过8MB'", "browser testing old 8 MB message")

testing_runtime = read("chat-testing-runtime.js")
require(testing_runtime, "f.size > 3 * 1024 * 1024", "browser bundle 3 MiB image limit")
require(testing_runtime, "\\u4E0D\\u8D85\\u8FC73MB", "browser bundle 3 MB message")
forbid(testing_runtime, "\\u4E0D\\u8D85\\u8FC78MB", "browser bundle old image message")

print("PASS", manifest["source_commit"], len(manifest["files"]), "source blobs verified")
print("PASS conversation jump controls removed; self-sends auto-scroll")
print("PASS album/camera separated; compressed image selection; 3 MiB limits aligned")
print("PASS voice transcript divider and spacing present")
