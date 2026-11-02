"""Fixed local image helper. No network, OCR, shell, user URL or model download."""
import json, os, pathlib, re, resource, struct, sys, warnings
from urllib.parse import unquote, urlsplit
if __name__ == "__main__":
    resource.setrlimit(resource.RLIMIT_AS, (512 * 1024**2, 512 * 1024**2))
    resource.setrlimit(resource.RLIMIT_CPU, (20, 20))
from PIL import Image, ImageOps
Image.MAX_IMAGE_PIXELS = 20_000_000
warnings.simplefilter("error", Image.DecompressionBombWarning)

def inspect(path):
    with Image.open(path) as im:
        if im.format not in ("JPEG", "PNG", "WEBP") or getattr(im, "n_frames", 1) != 1:
            raise ValueError("unsupported image")
        if im.width * im.height > 20_000_000 or min(im.size) < 1:
            raise ValueError("pixels")
        im.load()
        return im.format, im.size

def strip(data, kind):
    # Strip metadata containers without re-encoding image pixels.
    if kind == "JPEG":
        out, p = bytearray(data[:2]), 2
        while p < len(data):
            if data[p] != 255: raise ValueError("jpeg")
            marker = data[p+1]
            if marker == 218: out.extend(data[p:]); return bytes(out)
            length = int.from_bytes(data[p+2:p+4], "big")
            if length < 2 or p+2+length > len(data): raise ValueError("jpeg")
            if marker not in (225, 237, 254): out.extend(data[p:p+2+length])
            p += 2 + length
        raise ValueError("jpeg")
    if kind == "PNG":
        out, p = bytearray(data[:8]), 8
        while p < len(data):
            length = int.from_bytes(data[p:p+4], "big")
            if p+12+length > len(data): raise ValueError("png")
            tag = data[p+4:p+8]
            if tag not in (b"eXIf", b"tEXt", b"zTXt", b"iTXt", b"tIME"):
                out.extend(data[p:p+12+length])
            p += 12+length
            if tag == b"IEND": break
        return bytes(out)
    out, p = bytearray(b"WEBP"), 12
    while p < len(data):
        tag = data[p:p+4]; length = int.from_bytes(data[p+4:p+8], "little")
        end = p + 8 + length + length % 2
        if end > len(data): raise ValueError("webp")
        chunk = bytearray(data[p:end])
        if tag == b"VP8X": chunk[8] &= ~12
        if tag not in (b"EXIF", b"XMP "): out.extend(chunk)
        p = end
    return b"RIFF" + struct.pack("<I", len(out)) + out

def contact_payload(text):
    # Schemes alone are insufficient: mini-program/business QR links are not
    # automatically a personal profile. Decode once without following any URL.
    text = unquote(text[:4096]).strip().lower()
    parsed = urlsplit(text)
    if parsed.scheme in ("wxp", "alipayqr"):
        return True
    if parsed.scheme == "weixin":
        return (parsed.netloc == "contacts" and parsed.path.startswith("/profile/") or
                (parsed.netloc == "dl" and (parsed.path.rstrip("/") in ("/profile", "/addfriend") or parsed.path.startswith(("/profile/", "/addfriend/")))) or
                parsed.netloc == "wxpay")
    if parsed.scheme in ("http", "https") and not parsed.username and not parsed.password:
        if parsed.hostname == "weixin.qq.com" and parsed.path.startswith("/r/"):
            return True
        if parsed.hostname in ("u.wechat.com", "qr.alipay.com"):
            return True
    if parsed.scheme == "tel" and re.fullmatch(r"(?:\+?86)?1[3-9][0-9]{9}", re.sub(r"[\s()-]", "", parsed.path)):
        return True
    return bool(re.search(r"(?:^|[;\r\n])tel(?:;[^:\r\n]*)?:\s*(?:\+?86)?1[3-9][0-9]{9}(?:[;\r\n]|$)", text))

def qr(path):
    try:
        import zxingcpp
        with Image.open(path) as im:
            codes = zxingcpp.read_barcodes(im)
        decision = "clear"
        for code in codes[:64]:
            text = code.text[:4096].strip()
            if contact_payload(text):
                return "completed", "blocked"
            # Unknown links/codes remain leads for existing image safety review,
            # never confirmed violations. Ordinary numeric/material barcodes pass.
            if "://" in text or re.fullmatch(r"\+?(?:86)?[0-9]{11}", text):
                decision = "review"
        return "completed", decision
    except Exception:
        return "unavailable", "review"

def main():
    job = pathlib.Path(sys.argv[2]).resolve()
    if not job.is_dir() or not job.name.startswith("bxchat-image-"): raise ValueError("job")
    if sys.argv[1] == "sanitize":
        source = job / "source"
        if source.stat().st_size > 8*1024**2: raise ValueError("bytes")
        kind, _ = inspect(source)
        with Image.open(source) as im:
            orientation = int(im.getexif().get(274, 1))
            rotated = orientation in range(2, 9)
            if rotated:
                normalized = ImageOps.exif_transpose(im)
                normalized.info.clear()
                options = {"quality": 100} if kind == "JPEG" else {"lossless": True} if kind == "WEBP" else {}
                normalized.save(job / "clean.input", format=kind, **options)
            else:
                (job / "clean.input").write_bytes(strip(source.read_bytes(), kind))
        inspect(job / "clean.input")
        print(json.dumps({"ok": True, "orientation_normalized": rotated}))
    elif sys.argv[1] == "inspect":
        kind, (width, height) = inspect(job / "main")
        inspect(job / "thumb")
        a, b = qr(job / "main"); c, d = qr(job / "thumb")
        decision = "blocked" if "blocked" in (b,d) else "review" if "review" in (b,d) else "clear"
        print(json.dumps({"mime": {"JPEG":"image/jpeg","PNG":"image/png","WEBP":"image/webp"}[kind],
                          "width":width, "height":height,
                          "qr_status":"completed" if a == c == "completed" else "unavailable",
                          "qr_decision":decision, "qr_version":"zxing-cpp-3.1.1",
                          "ocr_status":"not_run"}))
    else: raise ValueError("mode")
if __name__ == "__main__":
    try: main()
    except Exception:
        print('{"code":"CHAT_IMAGE_CHECK_UNAVAILABLE"}')
        sys.exit(1)
