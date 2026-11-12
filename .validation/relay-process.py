import base64
import hashlib
import hmac
import http.server
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import tempfile
import threading
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

APP = "wx0123456789abcdef"
TOKEN = "SyntheticToken1234"
WECHAT_KEY = bytes(range(32))
RELAY_KEY = bytes(range(32, 64))
RELAY_PATH = "/chat/internal/v1/av/receipt"
TRACE = "synthetic-late-test-trace"
claimed = threading.Event()
delivered = threading.Event()
attempted = threading.Event()

class TestRelayEndpoint(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        body = self.rfile.read(int(self.headers["Content-Length"]))
        stamp = self.headers.get("X-Chat-Relay-Time", "")
        signature = hmac.new(RELAY_KEY, f"chat-media-receipt-v1\n{RELAY_PATH}\n{stamp}\n".encode() + body, "sha256").hexdigest()
        if self.path != RELAY_PATH or not hmac.compare_digest(signature, self.headers.get("X-Chat-Relay-Signature", "")):
            self.send_response(401)
        elif json.loads(body).get("TraceID") != TRACE:
            self.send_response(400)
        else:
            attempted.set()
            if claimed.is_set():
                delivered.set()
                self.send_response(204)
            else:
                self.send_response(404)
        self.end_headers()

def wait(predicate, timeout=12):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            if predicate():
                return
        except OSError:
            pass
        time.sleep(.15)
    raise AssertionError("condition not reached")

def local_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]

def stop(proc):
    if proc.poll() is None:
        proc.terminate()
    proc.wait(timeout=25)

root = Path.cwd()
with tempfile.TemporaryDirectory(prefix="media-relay-linux-") as name, http.server.ThreadingHTTPServer(("127.0.0.1", 19502), TestRelayEndpoint) as test:
    temp = Path(name)
    binary = temp / "chatd"
    subprocess.run(["go", "build", "-o", str(binary), "./cmd/chatd"], cwd=root / "chat", check=True)
    spool = temp / "spool"
    spool.mkdir(mode=0o700)
    config = json.loads((root / "chat/config.example.json").read_text())
    http_port, private_port = local_port(), local_port()
    while http_port == private_port:
        private_port = local_port()
    config.update(env="production", http={"listen": f"127.0.0.1:{http_port}", "internal_listen": f"127.0.0.1:{private_port}"})
    config["av"].update(callback_app_id=APP, callback_relay={"mode": "dispatch", "key_env": "CHAT_MEDIA_RELAY_KEY", "spool": str(spool)})
    config_path = temp / "chat.json"
    config_path.write_text(json.dumps(config))
    env = os.environ | {"CHAT_WECHAT_CALLBACK_TOKEN": TOKEN, "CHAT_WECHAT_CALLBACK_AES_KEY": base64.b64encode(WECHAT_KEY).decode().rstrip("="), "CHAT_MEDIA_RELAY_KEY": RELAY_KEY.hex()}
    thread = threading.Thread(target=test.serve_forever, daemon=True)
    thread.start()
    serve = subprocess.Popen([str(binary), "serve", "--config", str(config_path)], env=env)
    worker = None
    try:
        origin = f"http://127.0.0.1:{http_port}"
        wait(lambda: urlopen(origin + "/livez", timeout=1).status == 200)
        callback = origin + "/chat/api/v1/av/callback"
        stamp = str(int(time.time()))
        nonce = "relay-fixture"
        signed = hashlib.sha1("".join(sorted([TOKEN, stamp, nonce])).encode()).hexdigest()
        challenge = urlencode(dict(timestamp=stamp, nonce=nonce, signature=signed, echostr="proof"))
        with urlopen(callback + "?" + challenge, timeout=3) as response:
            assert response.read() == b"proof"
        event = json.dumps({"appid": APP, "Event": "wxa_media_check", "trace_id": TRACE, "version": 2, "errcode": 0, "result": {"suggest": "pass"}}).encode()
        plaintext = bytes(16) + struct.pack(">I", len(event)) + event + APP.encode()
        padding = 32 - len(plaintext) % 32
        plaintext += bytes([padding]) * padding
        encrypted = subprocess.check_output(["openssl", "enc", "-aes-256-cbc", "-nopad", "-K", WECHAT_KEY.hex(), "-iv", WECHAT_KEY[:16].hex()], input=plaintext)
        encoded = base64.b64encode(encrypted).decode()
        sig = hashlib.sha1("".join(sorted([TOKEN, stamp, nonce, encoded])).encode()).hexdigest()
        qs = urlencode(dict(timestamp=stamp, nonce=nonce, msg_signature=sig, encrypt_type="aes"))
        payload = json.dumps({"Encrypt": encoded}).encode()
        with urlopen(Request(callback + "?" + qs, data=payload, method="POST"), timeout=3) as response:
            assert response.read() == b"success"
        assert len(list(spool.glob("*.json"))) == 1
        try:
            urlopen(Request(origin + RELAY_PATH, data=b"{}", method="POST"), timeout=3)
        except HTTPError as error:
            assert error.code == 404
        else:
            raise AssertionError("internal receipt route was public")
        worker = subprocess.Popen([str(binary), "worker", "--config", str(config_path)], env=env)
        assert attempted.wait(8), "worker never attempted delivery"
        assert not delivered.is_set()
        assert len(list(spool.glob("*.json"))) == 1
        claimed.set()
        assert delivered.wait(8), "worker did not retry to the owning site"
        wait(lambda: not list(spool.glob("*.json")))
        print("PASS: real formal Go serve/worker verifies WeChat and retries signed internal delivery after trace registration")
    finally:
        if worker is not None:
            stop(worker)
        stop(serve)
        test.shutdown()
