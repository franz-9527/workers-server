const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')
const crypto = require('crypto')
const root = path.resolve(__dirname, '..')
const read = p => fs.readFileSync(path.join(root, p), 'utf8')
const routes = {
  "pages/realName/index": "subpackages/account/realName/index",
  "pages/merchantAuth/index": "subpackages/account/merchantAuth/index",
  "pages/plus/plus": "subpackages/account/workerProfile/index",
  "pages/mine/examine": "subpackages/account/review/index",
  "pages/mine/settings": "subpackages/account/settings/index",
  "pages/location/location": "subpackages/account/location/index",
  "pages/help/help": "subpackages/support/help/index",
  "pages/agreementCenter/index": "subpackages/support/agreements/index",
  "pages/workerFinance/index": "subpackages/finance/worker/index",
  "pages/recharge/recharge": "subpackages/finance/wallet/index",
  "pages/pay/pay": "subpackages/finance/tip/index",
  "pages/search/search": "subpackages/discovery/search/index",
  "pages/web-view/web": "subpackages/web/view/index",
  "pages/h5/h5": "subpackages/web/h5/index"
}
const utilityMoves = {
  'miniprogram/utils/WechatOcr.js': 'miniprogram/subpackages/account/utils/WechatOcr.js',
  'miniprogram/utils/HelpApi.js': 'miniprogram/subpackages/support/utils/HelpApi.js'
}
const app = JSON.parse(read('miniprogram/app.json'))
const subpackages = app.subpackages || app.subPackages || []
const fileIndexPath = path.join(root, 'validation-file-index.json')
const index = fs.existsSync(fileIndexPath) ? JSON.parse(fs.readFileSync(fileIndexPath, 'utf8')) : null
const exists = p => index ? Object.prototype.hasOwnProperty.call(index.files, p) : fs.existsSync(path.join(root, p))
const packageOf = p => (subpackages.find(pkg => p.startsWith('miniprogram/' + pkg.root + '/')) || {}).root || 'main'
function dependency(spec, from, extension) {
  const p = path.posix.normalize(spec.startsWith('/') ? 'miniprogram' + spec : path.posix.join(path.posix.dirname(from), spec))
  if (exists(p)) return p
  if (exists(p + extension)) return p + extension
  if (exists(p + '/index' + extension)) return p + '/index' + extension
  throw new Error('Missing dependency: ' + from + ' -> ' + spec)
}
function safeDependency(from, target) {
  const sourcePackage = packageOf(from), targetPackage = packageOf(target)
  assert(targetPackage === 'main' || targetPackage === sourcePackage, 'synchronous cross-package dependency: ' + from + ' -> ' + target)
}
function inspect(p) {
  const s = read(p)
  if (p.endsWith('.js')) {
    for (const m of s.matchAll(/(?:require\s*\(\s*|import[^\n]*?\bfrom\s*)['"]([^'"]+)['"]/g)) {
      if (!m[1].startsWith('.') && !m[1].startsWith('/')) continue
      safeDependency(p, dependency(m[1], p, '.js'))
    }
  } else if (p.endsWith('.json')) {
    for (const spec of Object.values(JSON.parse(s).usingComponents || {})) {
      if (spec.startsWith('plugin://')) continue
      safeDependency(p, dependency(spec, p, '.json'))
    }
  } else if (p.endsWith('.wxss')) {
    const clean = s.replace(/\/\*[\s\S]*?\*\//g, '')
    for (const m of clean.matchAll(/@import\s+['"]([^'"]+)['"]/g)) safeDependency(p, dependency(m[1], p, '.wxss'))
  }
}
const reverseMoves = Object.fromEntries(Object.entries(utilityMoves).map(([a,b])=>[b,a]))
function canonical(source, filename) {
  let s = source.replace(/((?:require\s*\(\s*|import[^\n]*?\bfrom\s*)['"])([^'"]+)(['"])/g, (_, start, spec, end) => {
    if (!spec.startsWith('.')) return start + spec + end
    let full = path.posix.normalize(path.posix.join(path.posix.dirname(filename), spec))
    if (!full.endsWith('.js')) full += '.js'
    return start + 'source:' + (reverseMoves[full] || full) + end
  })
  for (const [oldPage,newPage] of Object.entries(routes)) s = s.split(newPage).join(oldPage)
  return s
}
function instance(spec) {
  const p = Object.assign({}, spec)
  p.data = JSON.parse(JSON.stringify(spec.data || {}))
  p.setData = (next, cb) => { Object.assign(p.data, next); if (cb) cb() }
  return p
}
async function run() {
  if (process.env.SMALL_PACKAGE_BASELINE_DIR) {
    const oldApp = JSON.parse(fs.readFileSync(path.join(process.env.SMALL_PACKAGE_BASELINE_DIR,'miniprogram/app.json'),'utf8'))
    const ordinary = value => Object.fromEntries(Object.entries(value).filter(([key])=>key!=='subpackages'&&key!=='subPackages'))
    assert.deepStrictEqual(ordinary(app),ordinary(oldApp),'startup/tab/platform/privacy/plugin settings changed')
    for (const pkg of oldApp.subpackages) assert.deepStrictEqual(subpackages.find(p=>p.root===pkg.root),pkg,'existing subpackage changed')
  }
  assert.deepStrictEqual(app.tabBar.list.map(tab=>tab.pagePath), ['pages/index/index','pages/orderHall/orderHall','pages/notificationCenter/index','pages/mine/mine'])
  let navigation = [], pending
  const context = { module: { exports: {} }, wx: { redirectTo(o) { navigation.push(o.url); pending = o } } }
  vm.runInNewContext(read('miniprogram/utils/SubpackageRedirect.js'), context)
  const createBridge = context.module.exports
  for (const [oldPage,newPage] of Object.entries(routes)) {
    assert(app.pages.includes(oldPage), 'old external route removed: ' + oldPage)
    const pkg = subpackages.find(pkg=>newPage.startsWith(pkg.root+'/'))
    assert(pkg && pkg.pages.includes(newPage.slice(pkg.root.length+1)), 'new page not registered: ' + newPage)
    assert(!app.pages.includes(newPage), 'business payload still registered in main')
    const loader = read('miniprogram/' + oldPage + '.js')
    assert(loader.includes("'/"+newPage+"'"), 'old route redirects to wrong page')
    assert(loader.includes('SubpackageRedirect'), 'legacy page retains business code')
    assert(loader.length < 180)
    for (const extension of ['.js','.json','.wxml','.wxss']) inspect('miniprogram/' + newPage + extension)
    const bridge = instance(createBridge('/' + newPage))
    const options = { id: '订单&师傅+测试', key: 'privacy%2Fnext', scene: 'a=b&x=1', empty: '', returnUrl: '/pages/mine/mine?next=a+b', zero: '0' }
    bridge.onLoad(options)
    const url = navigation[navigation.length-1]
    assert.strictEqual(url.split('?')[0], '/' + newPage)
    const parsed = Object.fromEntries(new URL('https://synthetic.invalid' + url).searchParams)
    assert.deepStrictEqual(parsed, options, 'legacy query values changed')
    const callCount = navigation.length
    bridge.openSubpackage()
    assert.strictEqual(navigation.length, callCount, 'duplicate taps must not start duplicate redirects')
    assert.strictEqual(navigation[navigation.length-1], url)
    pending.fail({ errMsg: 'offline' }); pending.complete()
    assert.strictEqual(bridge.data.redirectError, true)
    bridge.openSubpackage()
    assert.strictEqual(navigation[navigation.length-1], url, 'retry lost original query')
    assert.strictEqual(bridge.data.redirectError, false)
    const baselineDir = process.env.SMALL_PACKAGE_BASELINE_DIR
    if (baselineDir) for (const extension of ['.js','.json','.wxml','.wxss']) {
      const baseline = fs.readFileSync(path.join(baselineDir, 'miniprogram/' + oldPage + extension), 'utf8')
      const actual = read('miniprogram/' + newPage + extension)
      assert.strictEqual(extension === '.js' ? canonical(actual,'miniprogram/'+newPage+extension) : actual,
        extension === '.js' ? canonical(baseline,'miniprogram/'+oldPage+extension) : baseline, 'business payload changed: '+oldPage+extension)
    }
  }
  for (const [from,to] of Object.entries(utilityMoves)) {
    assert(!exists(from), 'subpackage-only helper remains in main')
    inspect(to)
    if (process.env.SMALL_PACKAGE_BASELINE_DIR) {
      const before = fs.readFileSync(path.join(process.env.SMALL_PACKAGE_BASELINE_DIR,from),'utf8')
      assert.strictEqual(canonical(read(to),to),canonical(before,from),'helper business logic changed')
    }
  }
  const protectedPages=['orderSubmit/index','userOrder/detail','workerOrder/detail','orderCenter/index','orderHall/orderHall','constructionOrder/index','adminWorkbench/index','adminWorkbench/detail','platformOrderShare/index']
  for (const p of protectedPages) {
    assert(app.pages.includes('pages/'+p), 'order page moved: '+p)
    assert(!read('miniprogram/pages/'+p+'.js').includes('SubpackageRedirect'), 'order page became a redirect')
  }
  if (index && index.protected) for (const [filename,sha] of Object.entries(index.protected)) {
    const data=fs.readFileSync(path.join(root,filename))
    const actual=crypto.createHash('sha1').update(Buffer.from('blob '+data.length+'\0')).update(data).digest('hex')
    assert.strictEqual(actual,sha,'protected order/runtime file changed: '+filename)
  }
  console.log('PASS all 14 old/new routes, query encoding/retry, private dependencies, original business payloads and protected order pages')
  const calls=[], api={ request(route,method,body) { calls.push({route,method,body}); return Promise.resolve({data:route==='wallet.summary'?{balance_text:'¥8.00'}:route==='wallet.ledger'?{items:[],has_more:false}:{status:'paid'}}) } }
  let walletSpec
  vm.runInNewContext(read('miniprogram/subpackages/finance/wallet/index.js'), {
    Page(p){walletSpec=p},require(){return api},wx:{showToast(){},showLoading(){},hideLoading(){},showModal(){},stopPullDownRefresh(){}},console,Date,Math,setTimeout,Promise
  })
  const wallet=instance(walletSpec)
  await wallet.refreshAll()
  assert.deepStrictEqual(calls.map(x=>x.route).sort(),['wallet.ledger','wallet.summary'])
  assert.strictEqual(calls.some(x=>x.route==='wallet.recharge.create'),false,'opening wallet must not create payment')
  calls.length=0
  wallet.data.selectedCents=100
  wallet.recharge()
  for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve))
  assert.strictEqual(calls[0].route,'wallet.recharge.create')
  assert.strictEqual(calls[0].body.amount_cents,100)
  assert.strictEqual(calls.filter(x=>x.route==='wallet.recharge.create').length,1)
  console.log('PASS migrated wallet read-only opening and explicit recharge payload (mock API only)')
}
run().catch(error=>{console.error(error);process.exitCode=1})
