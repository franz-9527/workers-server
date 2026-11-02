import json, pathlib, shutil, subprocess, sys, tempfile, unittest
from PIL import Image, PngImagePlugin
import zxingcpp
ROOT = pathlib.Path(__file__).resolve().parents[1]
HELPER = ROOT / "chat/helpers/images/inspect.py"
class Centos7Compatibility(unittest.TestCase):
    def test_version_libc_and_formats(self):
        self.assertEqual(sys.version_info[:2], (3, 13))
        self.assertEqual(subprocess.check_output(["getconf", "GNU_LIBC_VERSION"], text=True).strip(), "glibc 2.17")
        result = json.loads(subprocess.check_output([sys.executable, str(ROOT / "chat/helpers/preflight.py")]))
        self.assertTrue(result["pillow"])
        self.assertTrue(result["zxing"])
    def test_cli_images_and_qr(self):
        for kind in ("JPEG", "PNG", "WEBP"):
            for payload, expected in (("weixin://contacts/profile/synthetic", "blocked"), ("6901234567890", "clear")):
                with self.subTest(kind=kind, expected=expected), tempfile.TemporaryDirectory(prefix="bxchat-image-") as path:
                    job = pathlib.Path(path)
                    image = Image.fromarray(zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.QRCode).to_image(scale=8)).convert("RGB")
                    image.save(job / "source", format=kind)
                    subprocess.check_output([sys.executable, str(HELPER), "sanitize", str(job)])
                    shutil.copyfile(job / "clean.input", job / "main")
                    shutil.copyfile(job / "clean.input", job / "thumb")
                    result = json.loads(subprocess.check_output([sys.executable, str(HELPER), "inspect", str(job)]))
                    self.assertEqual(result["qr_status"], "completed")
                    self.assertEqual(result["qr_decision"], expected)
if __name__ == "__main__":
    unittest.main(verbosity=2)
