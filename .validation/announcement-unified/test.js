const assert = require('node:assert/strict')
const fs = require('fs')
const vm = require('vm')
const source = fs.readFileSync(__dirname + '/source/miniprogram/utils/AnnouncementLoader.js', 'utf8')

function createHarness() {
  let selected = 'testing', revision = 1, offline = false
  const requests = [], storage = new Map()
  const environment = {
    capture: () => ({ key: selected, revision }),
    assertCurrent(context) {
      if (context.key !== selected || context.revision !== revision) throw new Error('site changed')
    },
    storageKey(key, context) { return context.key === 'testing' ? 'api_testing:' + key : key },
    onChange() { return () => {} }
  }
  const api = {
    publicRequest(route, query) {
      if (offline) return Promise.reject(Object.assign(new Error('offline'), { code: 'NETWORK_ERROR' }))
      return new Promise(resolve => requests.push({ route, query, resolve }))
    }
  }
  const wx = {
    getStorageSync: key => storage.get(key),
    setStorageSync: (key, value) => storage.set(key, value),
    removeStorageSync: key => storage.delete(key)
  }
  const loadModule = () => {
    const module = { exports: {} }
    vm.runInNewContext(source, { module, require: p => p.includes('OrderApi') ? api : environment, wx, Date, console })
    return module.exports
  }
  return { requests, storage, loadModule, offline: value => { offline = value }, switchTo(key) { selected = key; revision++ } }
}

;(async () => {
  const h = createHarness()
  const loader = h.loadModule()
  const first = loader.load()
  assert.equal(first, loader.load())
  assert.equal(h.requests.length, 1)
  assert.equal(h.requests[0].route, 'announcement.current')
  assert.equal(h.requests[0].query, undefined)
  h.requests[0].resolve({ data: { environment: 'testing', enabled: true, text: '统一公告', link: '/pages/index/index' } })
  assert.equal((await first).text, '统一公告')
  assert.equal(h.storage.has('api_testing:worker_public_announcement_v2'), true)

  h.offline(true)
  const cold = h.loadModule()
  const cached = await cold.load()
  assert.equal(cached.text, '统一公告')
  assert.equal(cached.stale, true)

  h.switchTo('production')
  await assert.rejects(cold.load(), /offline/)
  console.log('JS unified announcement loader: OK')
})().catch(error => { console.error(error); process.exit(1) })
