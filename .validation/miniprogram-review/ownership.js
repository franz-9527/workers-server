'use strict'

// Run only in an authorized isolated checkout. Acorn is a validation-only
// dependency, never imported by the mini program or added to its bundle.
const fs = require('node:fs')
const path = require('node:path')
const assert = require('node:assert/strict')
const acorn = require('acorn')

const shared = new Set([
  'components/admin-worker-assignment/index.js',
  'components/measurement-order-archive/index.js',
  'components/measurement-order-detail/index.js',
  'components/measurement-order-detail/detail-core.js',
  'components/order-product-field/index.js',
  'components/platform-order-pricing/index.js',
  'components/platform-order-settlement/index.js',
  'components/fullscreen-nav/index.js'
])
// Explicit user decision: retain these ColorUI components, not all of ColorUI.
const reserved = new Set(['calendar', 'canvas2d-ring', 'cu-custom', 'skeleton']
  .map(v => 'style/colorui/components/' + v + '/' + v + '.js'))
const inside = (p, dir) => p === dir || p.startsWith(dir + '/')
const normalize = p => path.posix.normalize(p.replace(/^\/+/, '').replace(/\\/g, '/')).replace(/\/$/, '')
const line = n => n.loc.start.line

function jsReferences(source) {
  const options = { ecmaVersion: 'latest', locations: true, allowHashBang: true }
  let ast
  try { ast = acorn.parse(source, { ...options, sourceType: 'module' }) }
  catch (moduleError) {
    // Legacy CommonJS can use script-only syntax.
    try { ast = acorn.parse(source, { ...options, sourceType: 'script' }) }
    catch (_) { throw moduleError }
  }
  const result = [], pending = [ast]
  while (pending.length) {
    const n = pending.pop()
    let value, kind
    if (['ImportDeclaration', 'ExportNamedDeclaration', 'ExportAllDeclaration'].includes(n.type) && n.source) {
      value = n.source; kind = 'import/export'
    } else if (n.type === 'ImportExpression') {
      value = n.source; kind = 'import()'
    } else if (n.type === 'CallExpression') {
      const c = n.callee
      if (c.type === 'Identifier' && c.name === 'require') {
        value = n.arguments[0]; kind = 'require'
      } else if (c.type === 'MemberExpression' && c.object.type === 'Identifier' &&
        c.object.name === 'require' && ((!c.computed && c.property.name === 'async') ||
          (c.computed && c.property.value === 'async'))) {
        value = n.arguments[0]; kind = 'require.async'
      }
    }
    if (kind) {
      let spec = null
      if (value?.type === 'Literal' && typeof value.value === 'string') spec = value.value
      if (value?.type === 'TemplateLiteral' && value.expressions.length === 0) spec = value.quasis[0].value.cooked
      result.push({ spec, kind, line: line(n) })
    }
    for (const value of Object.values(n)) {
      if (Array.isArray(value)) for (const child of value) { if (child?.type) pending.push(child) }
      else if (value?.type) pending.push(value)
    }
  }
  return result.sort((a, b) => a.line - b.line)
}

function audit(root, config, policy = { shared, reserved }) {
  const issues = new Set(), files = new Set(), graph = new Map(), uses = new Map()
  const fail = message => issues.add(message)
  const read = p => fs.readFileSync(path.join(root, p), 'utf8')
  const json = p => JSON.parse(read(p))
  const app = json('app.json')
  const packages = (app.subpackages || app.subPackages || []).map(p => ({ ...p, root: normalize(p.root) }))
  const owner = p => packages.find(pkg => inside(p, pkg.root))?.root || 'main'
  if (app.subpackages && app.subPackages) fail('app.json: ambiguous subpackage declarations')
  for (const pkg of packages) {
    if (!pkg.root || pkg.root === '.' || pkg.root.startsWith('../') ||
        packages.some(other => other !== pkg && inside(pkg.root, other.root))) {
      fail('app.json: invalid/overlapping subpackage root ' + pkg.root)
    }
  }
  const ignores = config.packOptions?.ignore || []
  for (const rule of ignores) {
    if (!['folder', 'file', 'suffix', 'prefix'].includes(rule.type) || typeof rule.value !== 'string' || !rule.value) {
      fail('project.config.json: unsupported packOptions.ignore rule ' + JSON.stringify(rule))
    }
  }
  function ignored(p) {
    return ignores.some(rule => {
      if (typeof rule.value !== 'string' || !rule.value) return false
      const v = normalize(rule.value)
      return rule.type === 'folder' ? inside(p, v) : rule.type === 'file' ? p === v :
        rule.type === 'suffix' ? p.endsWith(v) : rule.type === 'prefix' ? p.startsWith(v) : false
    })
  }
  function walk(dir) {
    for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, item.name)
      const p = path.relative(root, full).split(path.sep).join('/')
      if (ignored(p) || ['node_modules', 'miniprogram_npm', '.git'].includes(item.name)) continue
      if (item.isDirectory()) walk(full)
      else if (item.isFile() && /\.(?:js|json)$/.test(p)) files.add(p)
      else if (item.isSymbolicLink()) fail(p + ': symlink requires explicit review')
    }
  }
  walk(root)
  // Config nodes are distinct from ordinary imported JSON data: only a
  // registered page/component config has usingComponents semantics.
  const configNode = p => 'config:' + p
  const filename = n => n.startsWith('config:') ? n.slice(7) : n
  const display = n => filename(n)
  const externalComponent = spec => /^(?:plugin|dynamicLib):\/\//.test(spec)
  function resolve(spec, from, kind, location) {
    if (typeof spec !== 'string' || !spec) { fail(location + ': non-literal or empty ' + kind); return null }
    if (kind === 'component' && externalComponent(spec)) return null
    if (kind !== 'component' && !spec.startsWith('.') && !spec.startsWith('/')) {
      fail(location + ': unsupported bare/external module ' + spec + ' (review resolver explicitly)')
      return null
    }
    const base = path.posix.normalize(spec.startsWith('/') ? spec.slice(1) : path.posix.join(path.posix.dirname(from), spec))
    if (base === '..' || base.startsWith('../')) { fail(location + ': path escapes mini program ' + spec); return null }
    const candidates = kind === 'component' ? [base, base + '.json', base + '/index.json'].filter(p => p.endsWith('.json')) :
      [base, base + '.js', base + '.json', base + '/index.js', base + '/index.json']
    const target = candidates.find(p => files.has(p))
    if (!target) fail(location + ': missing/excluded ' + kind + ' ' + spec)
    return target || null
  }
  function boundary(from, to, location) {
    const a = owner(from), b = owner(to)
    const independent = packages.some(p => p.root === a && p.independent)
    if (a !== b && (b !== 'main' || independent)) {
      fail(location + ': forbidden package dependency ' + a + ' -> ' + b + ' (' + to + ')')
    }
  }
  function deps(node) {
    if (graph.has(node)) return graph.get(node)
    const result = new Set(), p = filename(node)
    graph.set(node, result)
    const add = (target, location) => {
      if (!target) return
      boundary(p, filename(target), location)
      result.add(target)
    }
    try {
      if (node.startsWith('config:')) {
        const data = json(p)
        const specs = Object.values(data.usingComponents || {})
        for (const generic of Object.values(data.componentGenerics || {})) {
          if (generic && typeof generic === 'object' && generic.default) specs.push(generic.default)
        }
        for (const spec of specs) {
          const target = resolve(spec, p, 'component', p)
          if (!target) continue
          const js = target.slice(0, -5) + '.js'
          add(configNode(target), p)
          if (files.has(js)) add(js, p)
          else fail(p + ': component JS missing/excluded ' + js)
        }
      } else if (p.endsWith('.js')) {
        for (const ref of jsReferences(read(p))) {
          const location = p + ':' + ref.line
          add(resolve(ref.spec, p, ref.kind, location), location)
        }
      }
    } catch (error) { fail(p + ': ' + error.message) }
    return result
  }
  const entries = new Map()
  function addEntry(consumer, node, required = true) {
    if (!files.has(filename(node))) {
      if (required) fail('missing/excluded entry ' + filename(node) + ' (' + consumer + ')')
      return
    }
    if (owner(filename(node)) !== consumer) {
      fail('wrong entry owner ' + filename(node) + ' (' + consumer + ')')
      return
    }
    if (!entries.has(consumer)) entries.set(consumer, new Set())
    entries.get(consumer).add(node)
  }
  function page(consumer, p) {
    addEntry(consumer, p + '.js')
    addEntry(consumer, configNode(p + '.json'), false)
  }
  addEntry('main', 'app.js')
  addEntry('main', configNode('app.json'))
  const mainPages = new Set(app.pages || [])
  for (const [key, platform] of Object.entries(app)) {
    if (!key.startsWith('mini-') || !platform || typeof platform !== 'object') continue
    for (const p of platform.pages || []) mainPages.add(p)
    if (platform.subpackages || platform.subPackages) fail('app.json: review platform-specific subpackages ' + key)
  }
  for (const p of mainPages) page('main', normalize(p))
  for (const item of app.tabBar?.list || []) {
    if (!mainPages.has(item.pagePath)) fail('tabBar page not registered in main: ' + item.pagePath)
  }
  if (app.tabBar?.custom) page('main', 'custom-tab-bar/index')
  // Explicit compiler static entries (currently platform login). Never treat
  // arbitrary packOptions.include assets or every physical page as an entry.
  for (const item of app.static || []) {
    const pattern = normalize(item.pattern || '')
    if (pattern.endsWith('.*') && !/[*?[\]{}]/.test(pattern.slice(0, -2))) {
      const p = pattern.slice(0, -2)
      page(owner(p), p)
    } else if (/\.(js|json)$/.test(pattern) && !/[*?[\]{}]/.test(pattern)) {
      if (pattern.endsWith('.js')) addEntry(owner(pattern), pattern)
      else addEntry(owner(pattern), configNode(pattern))
    } else fail('app.json: unsupported static entry pattern ' + pattern)
  }
  if (app.workers) fail('app.json: workers entry requires an explicit worker dependency policy')
  for (const pkg of packages) for (const p of pkg.pages || []) page(pkg.root, normalize(pkg.root + '/' + p))

  // Breadth-first traversal preserves a shortest witness chain from a real
  // entry for each consumer, including indirect dependencies and cycles.
  for (const [consumer, roots] of entries) {
    const parents = new Map([...roots].map(node => [node, null]))
    const queue = [...roots]
    for (let i = 0; i < queue.length; i++) {
      const node = queue[i], p = filename(node)
      if (p.endsWith('.js')) {
        if (!uses.has(p)) uses.set(p, new Map())
        const chain = []
        for (let cursor = node; cursor !== null; cursor = parents.get(cursor)) chain.push(display(cursor))
        uses.get(p).set(consumer, chain.reverse())
      }
      for (const dep of deps(node)) {
        if (parents.has(dep)) continue
        parents.set(dep, node)
        queue.push(dep)
      }
    }
  }
  const report = { mainUsed: [], shared: [], reserved: [], unexpected: [], issues: [] }
  for (const file of [...files].sort()) {
    if (!file.endsWith('.js') || owner(file) !== 'main') continue
    const byConsumer = uses.get(file) || new Map()
    const consumers = [...byConsumer.keys()].sort()
    const item = { file, consumers, chains: Object.fromEntries(consumers.map(c => [c, byConsumer.get(c)])) }
    if (consumers.includes('main')) report.mainUsed.push(item)
    else if (policy.shared.has(file) && consumers.length >= 2) report.shared.push(item)
    else if (policy.reserved.has(file)) report.reserved.push(item)
    else {
      report.unexpected.push({ ...item, reason: consumers.length === 0 ? 'unreachable' :
        consumers.length === 1 ? 'subpackage-only' : 'unapproved-shared' })
      fail(file + ': no main consumer (' + (consumers.join(', ') || 'unreachable') + ')')
    }
  }
  report.issues = [...issues].sort()
  return report
}

function selfTest() {
  const os = require('node:os')
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'mini-js-owner-'))
  function write(p, content) {
    fs.mkdirSync(path.dirname(path.join(root, p)), { recursive: true })
    fs.writeFileSync(path.join(root, p), typeof content === 'string' ? content : JSON.stringify(content))
  }
  try {
    write('app.json', { pages: ['home/index'], 'mini-ios': { pages: ['ios/index'] },
      static: [{ pattern: 'login/index.*' }], tabBar: { custom: true },
      subpackages: [{ root: 'sub/a', pages: ['index'] }, { root: 'sub/b', pages: ['index'] }] })
    write('app.js', "import './core'; // require('./ghost')\nconst note = \"require('./ghost')\"")
    write('core.js', "export {\n x\n} from './cycle'; export const y = 1")
    write('cycle.js', "import './core'; export const x = 1")
    write('home/index.js', "require('../core')")
    write('home/index.json', { usingComponents: { card: '/components/card/index' } })
    write('components/card/index.json', { usingComponents: { inner: '../inner/index' } })
    write('components/card/index.js', '')
    write('components/inner/index.json', {})
    write('components/inner/index.js', "require('../../nested')")
    write('nested.js', '')
    write('ios/index.js', '')
    write('login/index.js', '')
    write('custom-tab-bar/index.js', '')
    write('sub/a/index.js', "require('../../shared'); require('../../only')")
    write('sub/b/index.js', "require('../../shared')")
    for (const p of ['shared.js', 'only.js', 'orphan.js', 'reserved.js', 'ignored/file.js']) write(p, '')
    const config = { packOptions: { ignore: [{ type: 'folder', value: 'ignored' }] } }
    const policy = { shared: new Set(['shared.js']), reserved: new Set(['reserved.js']) }
    let report = audit(root, config, policy)
    assert.deepEqual(report.unexpected.map(x => [x.file, x.reason]), [['only.js', 'subpackage-only'], ['orphan.js', 'unreachable']])
    assert.deepEqual(report.shared[0].consumers, ['sub/a', 'sub/b'])
    assert.deepEqual(report.mainUsed.find(x => x.file === 'nested.js').chains.main,
      ['home/index.json', 'components/card/index.json', 'components/inner/index.js', 'nested.js'])
    for (const p of ['cycle.js', 'ios/index.js', 'login/index.js', 'custom-tab-bar/index.js']) assert(report.mainUsed.some(x => x.file === p))
    assert.equal(report.reserved[0].file, 'reserved.js')
    assert.equal(report.issues.length, 2)
    write('sub/b/index.js', "require('../a/index'); require(name); import('../../missing'); require('../../ignored/file')")
    report = audit(root, config, policy)
    for (const message of ['forbidden package dependency', 'non-literal', 'missing/excluded import()', 'missing/excluded require']) {
      assert(report.issues.some(x => x.includes(message)), message)
    }
    assert(report.unexpected.some(x => x.file === 'shared.js' && x.reason === 'subpackage-only') === false)
    // Cross-package paths remain errors even if they make a shared file reachable.
    write('sub/b/index.js', '')
    report = audit(root, config, policy)
    assert(report.unexpected.some(x => x.file === 'shared.js' && x.reason === 'subpackage-only'))
    write('home/index.js', "require('../broken')")
    write('broken.js', 'const = ;')
    fs.unlinkSync(path.join(root, 'ios/index.js'))
    report = audit(root, config, policy)
    assert(report.issues.some(x => x.startsWith('broken.js:')))
    assert(report.issues.some(x => x.includes('missing/excluded entry ios/index.js')))
    console.log('PASS main JS ownership self-test')
  } finally { fs.rmSync(root, { recursive: true, force: true }) }
}

if (require.main === module) {
  try {
    if (process.argv.includes('--self-test')) selfTest()
    else {
      const repo = path.resolve(__dirname, '..')
      const config = JSON.parse(fs.readFileSync(path.join(repo, 'project.config.json'), 'utf8'))
      const report = audit(path.resolve(repo, config.miniprogramRoot || 'miniprogram/'), config)
      console.log(JSON.stringify(report, null, 2))
      if (report.issues.length) {
        console.error(report.issues.join('\n'))
        process.exitCode = 1
      } else console.log('PASS main JS ownership (static dependency graph; WeChat compile still required)')
    }
  } catch (error) { console.error(error.message); process.exitCode = 1 }
}
module.exports = { audit, jsReferences }
