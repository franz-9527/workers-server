import json, os, pathlib, shutil, subprocess, sys, tempfile, unittest
from PIL import Image, PngImagePlugin
import zxingcpp

BASE = pathlib.Path(__file__).resolve().parents[1]
IMAGE = BASE / "chat/helpers/images/inspect.py"
MEDIA = BASE / "chat/helpers/media/inspect.py"
FFMPEG = shutil.which("ffmpeg")
FFPROBE = shutil.which("ffprobe")

def command(args):
    return subprocess.check_output([str(x) for x in args], timeout=60)

class Python313Media(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temp.name)
    def tearDown(self):
        self.temp.cleanup()
    def job(self, prefix):
        return pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=self.root))
    def image_run(self, job):
        raw = json.loads(command([sys.executable, IMAGE, "sanitize", job]))
        shutil.copyfile(job / "clean.input", job / "main")
        shutil.copyfile(job / "clean.input", job / "thumb")
        return raw, json.loads(command([sys.executable, IMAGE, "inspect", job]))

    def test_runtime_and_preflight(self):
        self.assertEqual(sys.version_info[:3], (3, 13, 15))
        report = json.loads(command([sys.executable, BASE / "chat/helpers/preflight.py", FFMPEG, FFPROBE]))
        self.assertEqual(report, dict(pillow=True, zxing=True, ffmpeg=True, ffprobe=True))

    def test_jpeg_png_webp_metadata_and_orientation(self):
        for kind in ("JPEG", "PNG", "WEBP"):
            with self.subTest(kind=kind):
                job = self.job("bxchat-image-")
                im = Image.new("RGB", (80, 60), "white")
                if kind == "PNG":
                    info = PngImagePlugin.PngInfo()
                    info.add_text("Comment", "private-location")
                    im.save(job / "source", format=kind, pnginfo=info)
                else:
                    exif = Image.Exif()
                    exif[270] = "private-location"
                    exif[274] = 6
                    im.save(job / "source", format=kind, exif=exif)
                raw, report = self.image_run(job)
                self.assertNotIn(b"private-location", (job / "main").read_bytes())
                self.assertEqual(report["qr_status"], "completed")
                self.assertEqual(report["qr_decision"], "clear")
                self.assertEqual((report["width"], report["height"]), (80, 60) if kind == "PNG" else (60, 80))
                self.assertEqual(raw["orientation_normalized"], kind != "PNG")

    def test_real_qr_policy(self):
        for payload, expected in (("weixin://contacts/profile/synthetic", "blocked"),
                                  ("https://materials.example.invalid/batch/123", "review"),
                                  ("6901234567890", "clear")):
            with self.subTest(expected=expected):
                job = self.job("bxchat-image-")
                barcode = zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.QRCode)
                Image.fromarray(barcode.to_image(scale=8)).save(job / "source", format="PNG")
                _, report = self.image_run(job)
                self.assertEqual(report["qr_status"], "completed")
                self.assertEqual(report["qr_decision"], expected)

    def test_voice_processing(self):
        job = self.job("bxchat-av-")
        command([FFMPEG, "-v", "error", "-f", "lavfi", "-i", "sine=frequency=440:duration=2",
                 "-ar", "16000", "-ac", "1", "-c:a", "libmp3lame", "-f", "mp3", job / "input"])
        report = json.loads(command([sys.executable, MEDIA, job, "voice", FFMPEG, FFPROBE, "inspect"]))
        self.assertEqual(report["kind"], "audio")
        self.assertGreater(report["duration_ms"], 1000)
        self.assertTrue((job / report["main"]).is_file())

    def test_video_audio_and_frames(self):
        job = self.job("bxchat-av-")
        command([FFMPEG, "-v", "error", "-f", "lavfi", "-i", "color=c=blue:s=320x240:r=15:d=2",
                 "-f", "lavfi", "-i", "sine=frequency=440:duration=2", "-c:v", "libx264", "-threads", "1",
                 "-pix_fmt", "yuv420p", "-c:a", "aac", "-shortest", "-f", "mp4", job / "input"])
        report = json.loads(command([sys.executable, MEDIA, job, "album", FFMPEG, FFPROBE, "inspect"]))
        self.assertEqual(report["kind"], "video")
        self.assertTrue(report["has_audio"])
        self.assertEqual(report["qr_decision"], "clear")
        for name in [report["main"], report["poster"], *report["segments"], *[x["file"] for x in report["frames"]]]:
            self.assertGreater((job / name).stat().st_size, 0)
        self.assertTrue(report["frames"])

    def test_invalid_image_rejected(self):
        job = self.job("bxchat-image-")
        (job / "source").write_bytes(b"not an image")
        result = subprocess.run([sys.executable, str(IMAGE), "sanitize", str(job)], capture_output=True, timeout=20)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout)["code"], "CHAT_IMAGE_CHECK_UNAVAILABLE")

if __name__ == "__main__":
    unittest.main(verbosity=2)
