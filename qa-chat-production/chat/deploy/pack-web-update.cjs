const crypto = require('node:crypto');
const fs = require('node:fs');
const zlib = require('node:zlib');

const [, , binaryPath, versionPath, phpVersion, outputPath] = process.argv;
const keyPath = process.env.CHAT_WEB_SIGNING_KEY_FILE;
if (!binaryPath || !versionPath || !/^\d+\.\d+\.\d+$/.test(phpVersion) || !outputPath || !keyPath) {
  throw new Error('需要二进制、版本文件、PHP 站点版本、输出文件与签名私钥路径');
}
const version = JSON.parse(fs.readFileSync(versionPath, 'utf8'));
const binary = fs.readFileSync(binaryPath);
if (version.goos !== 'linux' || version.goarch !== 'amd64' || version.source_dirty !== 'false'
    || !/^[a-f0-9]{40}$/.test(version.source_revision) || binary.length < 64 || binary.length > 48 * 1024 * 1024
    || binary.subarray(0, 6).toString('hex') !== '7f454c460201' || binary.readUInt16LE(18) !== 62
    || !binary.includes(Buffer.from(version.source_revision))) {
  throw new Error('只接受带准确提交标识的 Linux amd64 发布二进制');
}
const compressed = zlib.gzipSync(binary);
if (compressed.length > 11 * 1024 * 1024) throw new Error('升级包超过后台支持的大小');
const metadata = Buffer.from(JSON.stringify({
  format: 1, environment: 'production', platform: 'linux-amd64', php_version: phpVersion,
  revision: version.source_revision, bytes: binary.length,
  sha256: crypto.createHash('sha256').update(binary).digest('hex'),
}));
const key = crypto.createPrivateKey(fs.readFileSync(keyPath));
if (key.asymmetricKeyType !== 'ed25519') throw new Error('发布密钥必须为 Ed25519');
const signature = crypto.sign(null, metadata, key);
const header = Buffer.from(metadata.toString('base64') + '.' + signature.toString('base64') + '\n');
fs.writeFileSync(outputPath, Buffer.concat([header, compressed]), { flag: 'wx' });
