"""Offline dependency probe. Fixed binaries only; no network, media or credentials."""
import base64
import io
import json
import os
import pathlib
import subprocess
import sys
import tempfile

def binary_ready(path, name):
    if not os.path.isabs(path) or not os.path.isfile(path) or not os.access(path, os.X_OK):
        return False
    try:
        result = subprocess.run([path, "-version"], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=2, check=False)
        return result.returncode == 0 and result.stdout.startswith((name + " version ").encode())
    except (OSError, subprocess.TimeoutExpired):
        return False

def voice_pipeline(python, helper, ffmpeg, ffprobe):
    if not pathlib.Path(helper).is_file():
        return False, "CHAT_AV_HELPER_RUNTIME_FAILED"
    fixture = pathlib.Path(helper).with_name("voice-fixture.b64")
    if not fixture.is_file() or fixture.stat().st_size > 16384:
        return False, "CHAT_AV_HELPER_RUNTIME_FAILED"
    with tempfile.TemporaryDirectory(prefix="bxchat-preflight-") as outer:
        root = pathlib.Path(outer) / "bxchat-av-preflight"
        root.mkdir(mode=0o700)
        try:
            sample = base64.b64decode(fixture.read_bytes(), validate=True)
        except Exception:
            return False, "CHAT_AV_HELPER_RUNTIME_FAILED"
        if not 512 <= len(sample) <= 65536:
            return False, "CHAT_AV_HELPER_RUNTIME_FAILED"
        (root / "input").write_bytes(sample)
        run = subprocess.run([python, helper, str(root), "voice", ffmpeg, ffprobe, "inspect"],
                             stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                             stderr=subprocess.DEVNULL, timeout=8, check=False)
        if run.returncode == 0:
            try:
                payload = json.loads(run.stdout)
                if payload.get("kind") == "audio" and payload.get("main") == "main.mp3":
                    return True, ""
            except Exception:
                pass
            return False, "CHAT_AV_OUTPUT_INVALID"
        try:
            payload = json.loads(run.stdout)
            code = payload.get("code", "")
            if isinstance(code, str) and code.startswith("CHAT_AV_") and len(code) <= 72:
                return False, code
        except Exception:
            pass
        return False, "CHAT_AV_HELPER_RUNTIME_FAILED"

def main():
    result = dict(pillow=False, zxing=False, ffmpeg=False, ffprobe=False,
                  voice_pipeline=False, voice_error="")
    try:
        from PIL import Image
        # Exercise the decoders/encoders used by the real helper, not just module imports.
        for kind in ("JPEG", "PNG", "WEBP"):
            output = io.BytesIO()
            Image.new("RGB", (8, 8), "white").save(output, format=kind)
            output.seek(0)
            with Image.open(output) as image:
                image.load()
        result["pillow"] = True
    except Exception:
        pass
    try:
        import zxingcpp
        from PIL import Image
        zxingcpp.read_barcodes(Image.new("RGB", (8, 8), "white"))
        result["zxing"] = True
    except Exception:
        pass
    if len(sys.argv) in (3, 4):
        result["ffmpeg"] = binary_ready(sys.argv[1], "ffmpeg")
        result["ffprobe"] = binary_ready(sys.argv[2], "ffprobe")
        if len(sys.argv) == 4 and result["ffmpeg"] and result["ffprobe"]:
            try:
                result["voice_pipeline"], result["voice_error"] = voice_pipeline(
                    sys.executable, sys.argv[3], sys.argv[1], sys.argv[2])
            except (OSError, subprocess.TimeoutExpired):
                result["voice_error"] = "CHAT_AV_PROCESS_TIMEOUT"
    print(json.dumps(result))

if __name__ == "__main__":
    main()
