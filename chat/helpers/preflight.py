"""Offline dependency probe. Fixed binaries only; no network, media or credentials."""
import io
import json
import os
import subprocess
import sys

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

def main():
    result = dict(pillow=False, zxing=False, ffmpeg=False, ffprobe=False)
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
    if len(sys.argv) == 3:
        result["ffmpeg"] = binary_ready(sys.argv[1], "ffmpeg")
        result["ffprobe"] = binary_ready(sys.argv[2], "ffprobe")
    print(json.dumps(result))

if __name__ == "__main__":
    main()
