'use strict'

// Run only on a private, authorized isolated checkout. Never upload or preview.
// The compiler is supplied by the validation environment; no runtime dependency.
const fs = require('node:fs')
const path = require('node:path')
const os = require('node:os')
const crypto = require('node:crypto')
const { execFileSync } = require('node:child_process')
const assert = require('node:assert/strict')
const acorn = require('acorn')
const { audit } = require('./check-miniprogram-main-js-ownership.js')

const protectedSettings = ['uglifyFileName', 'ignoreUploadUnusedFiles', 'ignoreDevUnusedFiles',
  'minified', 'minifyWXSS', 'minifyWXML', 'es6', 'enhance', 'useCompilerPlugins',
  'packNpmManually', 'packNpmRelationList', 'babelSetting', 'compileHotReLoad']
const hash = value => crypto.createHash('sha256').update(value).digest('hex')
const readJson = file => JSON.parse(fs.readFileSync(file, 'utf8'))
const inside = (p, d) => p === d || p.startsWith(d + '/')
const literal = n => n?.type === 'Literal' && typeof n.value === 'string' ? n.value :
  n?.type === 'TemplateLiteral' && !n.expressions.length ? n.quasis[0].value.cooked : null
const isFunction = n => ['FunctionExpression', 'ArrowFunctionExpression'].includes(n?.type)
const safePath = p => typeof p === 'string' && !!p && !p.includes('\\') && !p.includes('\0') &&
  !p.startsWith('/') && !/^[a-z]:/i.test(p) && p.split('/').every(s => s && s !== '..' && s !== '.')
const moduleId = raw => {
  if (typeof raw !== 'string') return null
  const id = raw.replace(/^\//, '')
  return safePath(id) && /\.(?:js|json)$/.test(id) ? id : null
}
function visit(node, fn) {
  if (!node?.type || fn(node) === false) return
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) { for (const child of value) visit(child, fn) }
    else if (value?.type) visit(value, fn)
  }
}
function checkConfig(project, local) {
  const issues = []
  for (const key of protectedSettings.slice(0, 3)) {
    if (project.setting?.[key] !== false) issues.push('project.setting.' + key + ' must be false')
  }
  // The clean compiler snapshot excludes private configuration entirely.
  // Refuse risky local overrides rather than rewriting developer preferences.
  for (const key of protectedSettings) {
    if (Object.hasOwn(local?.setting || {}, key) &&
        JSON.stringify(local.setting[key]) !== JSON.stringify(project.setting?.[key])) {
      issues.push('project.private.config.json overrides setting.' + key)
    }
  }
  for (const key of ['miniprogramRoot', 'srcMiniprogramRoot', 'packOptions', 'appid', 'compileType']) {
    if (Object.hasOwn(local || {}, key) && JSON.stringify(local[key]) !== JSON.stringify(project[key])) {
      issues.push('project.private.config.json overrides ' + key)
    }
  }
  return issues
}
function resolveRequire(from, spec, ids) {
  if (!spec || spec.includes('\\') || spec.includes('\0') || /^[a-z][a-z0-9+.-]*:/i.test(spec)) return null
  const base = path.posix.normalize(spec.startsWith('/') ? spec.slice(1) :
    spec.startsWith('.') ? path.posix.join(path.posix.dirname(from), spec) : spec)
  if (!safePath(base)) return null
  return [base, base + '.js', base + '.json', base + '/index.js', base + '/index.json'].find(id => ids.has(id)) || null
}
function inspectCompiled(result, app, sourceReport) {
  const issues = [], modules = new Map(), artifactFiles = [], links = [], parsedBundles = []
  const packages = app.subpackages || app.subPackages || []
  const owner = id => packages.find(p => inside(id, p.root))?.root || 'main'
  const independent = id => packages.some(p => p.root === id && p.independent)
  const forbidden = p => ['subpackages/chat/call', 'audio/chat'].some(dir => inside(p, dir))
  const contentBytes = {}
  if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error('Unsupported compiler result; expected file record')
  for (const [file, raw] of Object.entries(result).sort(([a], [b]) => a.localeCompare(b))) {
    if (!safePath(file) || !(typeof raw === 'string' || Buffer.isBuffer(raw))) {
      issues.push('Unsupported compiler file record: ' + file); continue
    }
    const bytes = Buffer.isBuffer(raw) ? raw : Buffer.from(raw)
    artifactFiles.push({ file, bytes: bytes.length, sha256: hash(bytes) })
    const pkg = owner(file)
    contentBytes[pkg] = (contentBytes[pkg] || 0) + bytes.length
    if (forbidden(file)) issues.push('Excluded RTC file in compiler output: ' + file)
    if (!/(?:^|\/)(?:app-service|appservice|chunk_[^/]+\.appservice)\.js$/.test(file)) continue
    parsedBundles.push(file)
    let ast
    try { ast = acorn.parse(bytes.toString('utf8'), { ecmaVersion: 'latest', sourceType: 'script', locations: true }) }
    catch (error) { issues.push(file + ': cannot parse compiled service: ' + error.message); continue }
    visit(ast, n => {
      if (n.type !== 'CallExpression' || n.callee.type !== 'Identifier' || n.callee.name !== 'define') return
      const id = moduleId(literal(n.arguments[0])), factory = n.arguments[1]
      if (!id || !isFunction(factory)) { issues.push(file + ': unsupported define at line ' + n.loc.start.line); return false }
      if (forbidden(id)) issues.push('Excluded RTC module in compiler output: ' + id)
      if (modules.has(id)) issues.push('Duplicate module definition: ' + id + ' in ' + file + ' and ' + modules.get(id).file)
      else modules.set(id, { id, file, pkg, factory })
      if (owner(id) !== pkg) issues.push('Module packaged under wrong owner: ' + id + ' in ' + file)
      return false
    })
  }
  if (!parsedBundles.length || !modules.size) issues.push('Unsupported compiled layout: no named app-service module definitions; do not substitute source JS or source maps')
  for (const { id, pkg, file, factory } of modules.values()) {
    // WeChat factories receive require as the first argument, possibly renamed.
    // Unknown parameter shape is blocked, not silently treated as no dependencies.
    const first = factory.params[0]
    if (first && first.type !== 'Identifier') { issues.push(id + ': unsupported require parameter'); continue }
    const name = first?.name || 'require'
    visit(factory.body, n => {
      if (n.type !== 'CallExpression') return
      const callee = n.callee
      const direct = callee.type === 'Identifier' && callee.name === name
      const async = callee.type === 'MemberExpression' && callee.object.type === 'Identifier' &&
        callee.object.name === name && (callee.computed ? literal(callee.property) === 'async' : callee.property.name === 'async')
      if (!direct && !async) return
      if (async) { issues.push(id + ': asynchronous compiled require needs explicit loader review'); return }
      const spec = literal(n.arguments[0])
      if (spec === null) { issues.push(id + ': computed require at ' + file + ':' + n.loc.start.line); return }
      const target = resolveRequire(id, spec, modules)
      if (!target) { issues.push(id + ': module not defined: ' + spec + ' (' + file + ':' + n.loc.start.line + ')'); return }
      const targetPkg = modules.get(target).pkg
      if (pkg !== targetPkg && (targetPkg !== 'main' || independent(pkg))) {
        issues.push(id + ': forbidden compiled package edge to ' + target)
      }
      links.push({ from: id, to: target })
    })
  }
  const expected = new Set([...sourceReport.mainUsed, ...sourceReport.shared].map(x => x.file))
  for (const entry of sourceReport.entrypoints) for (const id of [...entry.ownedJs, ...entry.mainJs]) expected.add(id)
  for (const id of expected) if (!modules.has(id)) issues.push('Source module has no same-name compiled definition: ' + id)
  for (const pkg of ['main', ...packages.map(p => p.root)]) if (!parsedBundles.some(file => owner(file) === pkg)) {
    issues.push('Missing service bundle for package: ' + pkg)
  }
  const digest = hash(JSON.stringify(artifactFiles))
  return { status: issues.length ? 'blocked' : 'module_integrity_passed_size_and_device_pending',
    artifactDigest: digest, artifactFiles, contentBytes, parsedBundles, expectedModuleCount: expected.size,
    definedModuleCount: modules.size, checkedRequireCount: links.length, issues: [...new Set(issues)].sort() }
}
function checkSizes(app, report, evidence, sourceCommit) {
  const issues = []
  if (report.sourceCommit !== sourceCommit || report.status !== 'module_integrity_passed_size_and_device_pending' ||
      !/^[a-f0-9]{64}$/.test(report.artifactDigest || '') || report.issues?.length !== 0) {
    issues.push('Missing passing module evidence for this source commit')
  }
  if (evidence.sourceCommit !== sourceCommit || evidence.artifactDigest !== report.artifactDigest ||
      typeof evidence.toolVersion !== 'string' || !evidence.toolVersion.trim() ||
      typeof evidence.previewVersion !== 'string' || !evidence.previewVersion.trim()) {
    issues.push('Size evidence must identify the same candidate, artifact digest, tool and preview version')
  }
  const packages = app.subpackages || app.subPackages || []
  const expected = new Map([['__APP__', 1500000], ['__FULL__', null],
    ...packages.map(p => [p.name || p.root, 2000000])])
  const seen = new Map()
  for (const row of Array.isArray(evidence.subPackageInfo) ? evidence.subPackageInfo : []) {
    if (!row || !expected.has(row.name) || seen.has(row.name) || !Number.isSafeInteger(row.size) || row.size <= 0) {
      issues.push('Invalid, duplicate or unknown official package size entry'); continue
    }
    seen.set(row.name, row.size)
    const limit = expected.get(row.name)
    if (limit !== null && row.size > limit) issues.push(row.name + ': exceeds internal compiled budget ' + limit)
  }
  for (const name of expected.keys()) if (!seen.has(name)) issues.push('Missing official package size: ' + name)
  const full = seen.get('__FULL__')
  const subtotal = [...seen].filter(([name]) => name !== '__FULL__').reduce((sum, [,size]) => sum + size, 0)
  if (full !== undefined && full < subtotal) issues.push('Total package size smaller than package subtotal')
  return { status: issues.length ? 'blocked' : 'size_evidence_checked_device_pending',
    sourceCommit, artifactDigest: report.artifactDigest, sizes: Object.fromEntries(seen), issues,
    limitation: 'Evidence is operator-linked; this does not independently prove the preview was built from the inspected artifact or validate device behavior.' }
}
function selfTest() {
  const app = { subpackages: [{ root: 'subpackages/admin' }, { root: 'subpackages/order' }] }
  const source = { mainUsed: [{ file: 'utils/OrderApi.js' }], shared: [], entrypoints: [
    { ownedJs: ['subpackages/admin/workbench/index.js'], mainJs: ['utils/OrderApi.js'] },
    { ownedJs: ['subpackages/order/index.js'], mainJs: [] }] }
  const result = {
    'app-service.js': 'define("utils/OrderApi.js",function(r,m,e){ });',
    'subpackages/admin/chunk_4.appservice.js': 'define("subpackages/admin/workbench/index.js",function(r,m,e){r("../../../utils/OrderApi.js")});',
    'subpackages/order/appservice.js': 'define("subpackages/order/index.js",function(require,module,exports){});'
  }
  assert.equal(inspectCompiled(result, app, source).status, 'module_integrity_passed_size_and_device_pending')
  const broken = { ...result, 'subpackages/admin/chunk_4.appservice.js':
    'define("subpackages/admin/workbench/index.js",function(r){r("../../../76C2FAA58D208ACF10A492A27A574136.js");r("../../order/index.js");r(name)});' }
  const problems = inspectCompiled(broken, app, source).issues
  assert(problems.some(x => x.includes('module not defined: ../../../76C2')))
  assert(problems.some(x => x.includes('forbidden compiled package edge')))
  assert(problems.some(x => x.includes('computed require')))
  assert(inspectCompiled({ 'utils/OrderApi.js': '' }, app, source).issues.some(x => x.includes('Unsupported compiled layout')))
  assert(inspectCompiled({ ...result, 'subpackages/admin/chunk_5.appservice.js': result['subpackages/admin/chunk_4.appservice.js'] }, app, source).issues.some(x => x.includes('Duplicate module')))
  const project = { setting: { uglifyFileName: false, ignoreUploadUnusedFiles: false, ignoreDevUnusedFiles: false } }
  assert.deepEqual(checkConfig(project, {}), [])
  assert(checkConfig(project, { setting: { uglifyFileName: true } }).length)
  const sourceCommit = 'a'.repeat(40)
  const report = { ...inspectCompiled(result, app, source), sourceCommit }
  const evidence = { sourceCommit, artifactDigest: report.artifactDigest, toolVersion: 'fixture', previewVersion: 'fixture',
    subPackageInfo: [{name:'__APP__',size:100},{name:'__FULL__',size:300},
      {name:'subpackages/admin',size:100},{name:'subpackages/order',size:100}] }
  assert.equal(checkSizes(app, report, evidence, sourceCommit).status, 'size_evidence_checked_device_pending')
  assert(checkSizes(app, report, {...evidence, subPackageInfo: evidence.subPackageInfo.slice(0,-1)}, sourceCommit).issues.length)
  assert(checkSizes(app, report, {...evidence, sourceCommit: 'b'.repeat(40)}, sourceCommit).issues.length)
  assert(checkSizes(app, report, {...evidence, subPackageInfo: evidence.subPackageInfo.map(row=>row.name==='__FULL__'?{...row,size:299}:row)}, sourceCommit).issues.some(x=>x.includes('subtotal')))
  assert(inspectCompiled({...result,'subpackages/admin/chunk_4.appservice.js':'define("subpackages/admin/workbench/index.js",function(r){r.async("../../../utils/OrderApi.js")});'},app,source).issues.some(x=>x.includes('asynchronous compiled require')))
  console.log('PASS synthetic compiled module integrity self-test (not WeChat compilation)')
}
const git = (repo, args, encoding = 'utf8') => execFileSync('git', args, {
  cwd: repo, encoding, maxBuffer: 128 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe']
})
async function main() {
  const args = process.argv.slice(2)
  if (args.length === 1 && args[0] === '--self-test') return selfTest()
  const valid = (args[0] === '--preflight' && args.length === 2) ||
    (args[0] === '--compile' && (args.length === 2 || args.length === 3)) ||
    (args[0] === '--size-report' && args.length === 4)
  if (!valid || !/^[a-f0-9]{40}$/.test(args[1])) {
    throw new Error('Usage: --preflight <sha> | --compile <sha> [private-report.json] | --size-report <sha> <compiled-report.json> <official-size-evidence.json>')
  }
  const repo = path.resolve(__dirname, '..')
  const sourceCommit = git(repo, ['rev-parse', 'HEAD']).trim()
  assert.equal(sourceCommit, args[1], 'Checkout must match exact candidate')
  assert(!git(repo, ['status', '--porcelain', '--untracked-files=all', '--', 'project.config.json', 'miniprogram', 'tools/check-miniprogram-compiled-integrity.js', 'tools/check-miniprogram-main-js-ownership.js']).trim(), 'Candidate inputs are modified')
  const project = readJson(path.join(repo, 'project.config.json'))
  const privatePath = path.join(repo, 'project.private.config.json')
  const local = fs.existsSync(privatePath) ? readJson(privatePath) : {}
  const issues = checkConfig(project, local)
  assert(!issues.length, issues.join('\n'))
  assert.equal(project.miniprogramRoot, 'miniprogram/', 'Review changed project root before compiling')
  if (args[0] === '--preflight') {
    console.log(JSON.stringify({ status: 'preflight_only', sourceCommit, privateConfigPresent: fs.existsSync(privatePath) }, null, 2))
    return
  }
  if (args[0] === '--size-report') {
    const report = checkSizes(readJson(path.join(repo, 'miniprogram/app.json')), readJson(args[2]), readJson(args[3]), sourceCommit)
    console.log(JSON.stringify(report, null, 2))
    if (report.issues.length) process.exitCode = 1
    return
  }
  const key = process.env.WECHAT_CI_PRIVATE_KEY_PATH
  assert(key && fs.statSync(key).isFile(), 'An authorized CI key file outside the project is required')
  const outsideRepo = file => {
    const relative = path.relative(fs.realpathSync(repo), file)
    return path.isAbsolute(relative) || relative === '..' || relative.startsWith('..' + path.sep)
  }
  assert(outsideRepo(fs.realpathSync(key)), 'CI key must be outside project')
  const output = args[2] ? path.join(fs.realpathSync(path.dirname(path.resolve(args[2]))), path.basename(args[2])) : null
  if (output) assert(outsideRepo(output) && !fs.existsSync(output), 'Report must be a new file outside project')
  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'mini-compiled-'))
  try {
    // Build from tracked bytes of the exact commit in a unique directory.
    // No developer cache, ignored files, generated chunks, or private config is copied.
    const tar = git(repo, ['archive', '--format=tar', sourceCommit, 'project.config.json', 'miniprogram', 'cloudfunctions', 'cloudbaserc.json'], null)
    execFileSync('tar', ['-xf', '-', '-C', stage], { input: tar, stdio: ['pipe', 'pipe', 'pipe'] })
    const app = readJson(path.join(stage, 'miniprogram/app.json'))
    const sourceReport = audit(path.join(stage, 'miniprogram'), project)
    assert(!sourceReport.issues.length, sourceReport.issues.join('\n'))
    const ci = require('miniprogram-ci')
    const compilerVersion = require('miniprogram-ci/package.json').version
    assert.equal(typeof ci.getCompiledResult, 'function', 'Installed compiler does not support getCompiledResult')
    let compiled
    try {
      const buildProject = new ci.Project({ appid: project.appid, type: 'miniProgram',
        projectPath: stage, privateKeyPath: path.resolve(key), ignores: ['node_modules/**/*'] })
      compiled = await ci.getCompiledResult({ project: buildProject, desc: 'isolated module integrity check',
        setting: { useProjectConfig: true } })
    } catch (_) { throw new Error('WeChat getCompiledResult failed; inspect authorized compiler environment privately') }
    const report = inspectCompiled(compiled, app, sourceReport)
    const json = JSON.stringify({ sourceCommit, compilerVersion, projectConfigSha256: hash(fs.readFileSync(path.join(stage, 'project.config.json'))),
      ...report, sizeEvidence: 'pending: contentBytes is uncompressed compiler file inventory, not official package size',
      deviceEvidence: 'pending: no preview, upload or device test performed' }, null, 2)
    if (output) fs.writeFileSync(output, json + '\n', { flag: 'wx', mode: 0o600 })
    console.log(json)
    if (report.issues.length) process.exitCode = 1
  } finally { fs.rmSync(stage, { recursive: true, force: true }) }
}
if (require.main === module) main().catch(error => { console.error(error.message); process.exitCode = 1 })
module.exports = { inspectCompiled, checkConfig, resolveRequire, checkSizes }
