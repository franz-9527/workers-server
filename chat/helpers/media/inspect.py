"""Bounded local media validation/remux. No network, cloud transcoding or model."""
import importlib.util, json, math, os, pathlib, resource, subprocess, sys
resource.setrlimit(resource.RLIMIT_AS, (1024 * 1024**2, 1024 * 1024**2))
resource.setrlimit(resource.RLIMIT_CPU, (20, 20))
resource.setrlimit(resource.RLIMIT_FSIZE, (24 * 1024**2, 24 * 1024**2))
resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
MAX_BYTES = 20 * 1024**2
# Shared FFmpeg libraries consume address space before decoding. Keep allocations
# and native thread arenas bounded independently of the address-space ceiling.
MEDIA_ENV = dict(os.environ, MALLOC_ARENA_MAX="2", OMP_NUM_THREADS="1",
                 OPENBLAS_NUM_THREADS="1", MKL_NUM_THREADS="1")

def command(binary, args, timeout=12):
    result = subprocess.run([binary, "-nostdin", "-hide_banner", "-loglevel", "error", "-max_alloc", "67108864"] + args,
                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=timeout, check=False, env=MEDIA_ENV)
    if result.returncode or len(result.stdout) > 2 * 1024**2 or len(result.stderr) > 65536:
        raise ValueError("media operation" + (": " + result.stderr.decode(errors="replace")[:1500] if os.environ.get("CHAT_AV_HELPER_TEST") == "1" else ""))
    return result.stdout

def probe(binary, path):
    # ffprobe has no -nostdin option; fixed fields omit arbitrary metadata.
    args = [binary, "-v", "error", "-protocol_whitelist", "file,pipe",
            "-format_whitelist", "mov,mp3", "-show_entries",
            "format=nb_streams,duration,size:stream=index,codec_type,codec_name,sample_rate,channels,width,height,r_frame_rate,duration:stream_side_data=rotation",
            "-of", "json", str(path)]
    result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=10, check=False, env=MEDIA_ENV)
    if result.returncode or len(result.stdout) > 65536 or len(result.stderr) > 65536:
        raise ValueError("media probe")
    info = json.loads(result.stdout)
    streams = info.get("streams", [])
    if not 1 <= len(streams) <= 2 or int(info["format"]["nb_streams"]) != len(streams):
        raise ValueError("streams")
    duration = float(info["format"]["duration"])
    if not math.isfinite(duration) or duration <= 0 or duration > 121:
        raise ValueError("duration")
    return info, duration

def qr_module():
    path = pathlib.Path(__file__).resolve().parents[1] / "images" / "inspect.py"
    spec = importlib.util.spec_from_file_location("chat_image_checks", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def main():
    if len(sys.argv) != 6:
        raise ValueError("arguments")
    root = pathlib.Path(sys.argv[1]).resolve()
    source = sys.argv[2]
    ffmpeg, ffprobe = sys.argv[3], sys.argv[4]
    mode = sys.argv[5]
    if not root.is_dir() or not root.name.startswith("bxchat-av-") or source not in ("voice", "camera", "album") or mode != "inspect":
        raise ValueError("job")
    for binary in (ffmpeg, ffprobe):
        if not os.path.isabs(binary) or not pathlib.Path(binary).is_file():
            raise ValueError("binary")
    path = root / "input"
    limit = (1 if source == "voice" else 8 if source == "camera" else 20) * 1024**2
    if not 1 <= path.stat().st_size <= limit:
        raise ValueError("bytes")
    info, duration = probe(ffprobe, path)
    streams = info["streams"]
    audio = [s for s in streams if s.get("codec_type") == "audio"]
    video = [s for s in streams if s.get("codec_type") == "video"]
    if len(audio) > 1 or len(video) > 1 or len(audio) + len(video) != len(streams):
        raise ValueError("unsupported stream")
    maximum = 60 if source == "voice" else 30 if source == "camera" else 120
    if duration > maximum + 0.25 or source == "voice" and duration < 1:
        raise ValueError("duration limit")
    base = ["-threads", "1", "-filter_threads", "1", "-protocol_whitelist", "file,pipe",
            "-format_whitelist", "mov,mp3", "-i", str(path), "-threads", "1", "-map_metadata", "-1", "-map_chapters", "-1", "-y"]
    if source == "voice":
        if video or len(audio) != 1 or audio[0]["codec_name"] != "mp3":
            raise ValueError("voice codec")
        sample = int(audio[0].get("sample_rate", 0)); channels = int(audio[0].get("channels", 0))
        if sample not in (8000, 16000) or channels != 1:
            raise ValueError("voice profile")
        pcm = command(ffmpeg, base + ["-t", "60.01", "-vn", "-map", "0:a:0", "-ar", "16000", "-ac", "1", "-f", "s16le", "pipe:1"])
        duration = len(pcm) / 32000
        if not 1 <= duration <= 60:
            raise ValueError("voice duration")
        encoding = ["-c:a", "copy"] if sample == 16000 else ["-c:a", "libmp3lame", "-ar", "16000", "-ac", "1", "-b:a", "48k"]
        command(ffmpeg, base + ["-vn", "-map", "0:a:0"] + encoding + ["-write_id3v1", "0", "-id3v2_version", "0", str(root / "main.mp3")])
        output = {"kind": "audio", "mime": "audio/mpeg", "main": "main.mp3", "poster": "", "segments": ["main.mp3"],
                  "duration_ms": int(math.ceil(duration * 1000)), "has_audio": True, "qr_decision": "clear", "frames": []}
        if (root / "main.mp3").stat().st_size > 1024**2:
            raise ValueError("voice bytes")
    else:
        if len(video) != 1 or video[0]["codec_name"] != "h264" or audio and audio[0]["codec_name"] != "aac":
            raise ValueError("video codec")
        duration = float(video[0].get("duration", duration))
        if not math.isfinite(duration) or not 0 < duration <= maximum:
            raise ValueError("video duration")
        width, height = int(video[0]["width"]), int(video[0]["height"])
        if min(width, height) < 1 or min(width, height) > 720 or max(width, height) > 1280 or width * height > 921600:
            raise ValueError("video profile")
        rate = str(video[0].get("r_frame_rate", "0/1")).split("/")
        fps = float(rate[0]) / float(rate[1]) if len(rate) == 2 and float(rate[1]) else 0
        if not 0 < fps <= 30:
            raise ValueError("video rate")
        command(ffmpeg, base + ["-t", str(duration), "-map", "0:v:0", "-map", "0:a:0?", "-c", "copy", "-movflags", "+faststart", str(root / "main.mp4")])
        checked, actual = probe(ffprobe, root / "main.mp4")
        if actual > maximum + 0.25 or (root / "main.mp4").stat().st_size > limit:
            raise ValueError("delivery size")
        command(ffmpeg, base + ["-map", "0:v:0", "-frames:v", "1", "-vf", "scale=480:480:force_original_aspect_ratio=decrease", "-q:v", "5", str(root / "poster.jpg")])
        if (root / "poster.jpg").stat().st_size > 65536:
            raise ValueError("poster size")
        segments = []
        if audio:
            for ordinal in range(int(math.ceil(duration / 60))):
                name = "check-" + str(ordinal) + ".mp3"
                command(ffmpeg, ["-ss", str(ordinal * 60)] + base + ["-t", str(min(60, duration - ordinal * 60)),
                        "-vn", "-map", "0:a:0", "-c:a", "libmp3lame", "-ar", "16000", "-ac", "1", "-b:a", "48k",
                        "-write_id3v1", "0", "-id3v2_version", "0", str(root / name)])
                if not 1 <= (root / name).stat().st_size <= 1024**2:
                    raise ValueError("segment")
                segments.append(name)
        checks = qr_module()
        decisions = []
        retained = []
        # A bounded triage only. Full clip + audio human prereview is mandatory.
        frame_count = min(8, max(1, int(math.ceil(duration / 15))))
        for index in range(frame_count):
            frame = root / ("frame-" + str(index) + ".jpg")
            second = min(max(0, duration - 0.1), index * duration / max(1, frame_count - 1))
            command(ffmpeg, ["-ss", str(second)] + base + ["-map", "0:v:0", "-frames:v", "1",
                    "-vf", "scale=1280:720:force_original_aspect_ratio=decrease", "-q:v", "3", str(frame)])
            _, decision = checks.qr(frame); decisions.append(decision)
            name = "retained-" + str(index) + ".jpg"
            with checks.Image.open(frame) as picture:
                picture.thumbnail((480, 480))
                picture.info.clear()
                for quality in (70, 50, 35):
                    picture.convert("RGB").save(root / name, format="JPEG", quality=quality)
                    if (root / name).stat().st_size <= 65536:
                        break
                if (root / name).stat().st_size > 65536:
                    raise ValueError("retained picture bytes")
            retained.append({"file": name, "at_ms": int(second * 1000)})
            frame.unlink(missing_ok=True)
        decision = "blocked" if "blocked" in decisions else "review" if "review" in decisions else "clear"
        output = {"kind": "video", "mime": "video/mp4", "main": "main.mp4", "poster": "poster.jpg", "segments": segments,
                  "duration_ms": int(math.ceil(duration * 1000)), "has_audio": bool(audio), "qr_decision": decision, "frames": retained}
    print(json.dumps(output, separators=(",", ":")))

if __name__ == "__main__":
    try:
        main()
    except Exception:
        if os.environ.get("CHAT_AV_HELPER_TEST") == "1":
            import traceback
            traceback.print_exc()
        print('{"code":"CHAT_AV_CHECK_UNAVAILABLE"}')
        sys.exit(1)
