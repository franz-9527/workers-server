'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')
const vm = require('vm')

const root = path.resolve(__dirname, '..')
const source = fs.readFileSync(path.join(root, 'miniprogram/components/subscription-health-banner/index.js'), 'utf8')
const iconWxss = fs.readFileSync(path.join(root, 'miniprogram/style/colorui/icon.wxss'), 'utf8')
const bannerWxml = fs.readFileSync(path.join(root, 'miniprogram/components/subscription-health-banner/index.wxml'), 'utf8')
const bannerWxss = fs.readFileSync(path.join(root, 'miniprogram/components/subscription-health-banner/index.wxss'), 'utf8')
const recordWxml = fs.readFileSync(path.join(root, 'miniprogram/pages/record/record.wxml'), 'utf8')
const recordWxss = fs.readFileSync(path.join(root, 'miniprogram/pages/record/record.wxss'), 'utf8')
const settingsWxss = fs.readFileSync(path.join(root, 'miniprogram/subpackages/account/settings/index.wxss'), 'utf8')
const orderWxss = fs.readFileSync(path.join(root, 'miniprogram/pages/orderCenter/index.wxss'), 'utf8')
const settingsWxml = fs.readFileSync(path.join(root, 'miniprogram/subpackages/account/settings/index.wxml'), 'utf8')
const settingsJs = fs.readFileSync(path.join(root, 'miniprogram/subpackages/account/settings/index.js'), 'utf8')
const orderWxml = fs.readFileSync(path.join(root, 'miniprogram/pages/orderCenter/index.wxml'), 'utf8')

function fixture() {
  let definition
  let result = { supported: true, globalEnabled: true, mainSwitch: true, items: [] }
  let deferred = null
  let fails = false
  const navigations = []
  const context = {
    Component(value) { definition = value },
    require(name) {
      assert(name.includes('SubscribeMessageUtils'), 'only subscription settings are loaded')
      return {
        getSubscriptionSettings() {
          if (deferred) return deferred.promise
          if (fails) return Promise.reject(new Error('offline'))
          return Promise.resolve(result)
        }
      }
    },
    wx: { navigateTo(options) { navigations.push(options.url) }, showToast() {} },
    console: { warn() {} }
  }
  vm.runInNewContext(source, context, { filename: 'subscription-health-banner/index.js' })
  assert(definition && definition.methods, 'component registers its methods')
  function component(audience, reviewPending) {
    const inst = {
      properties: { audience, reviewPending: !!reviewPending },
      data: Object.assign({}, definition.data),
      setData(patch, callback) {
        Object.assign(this.data, patch)
        if (callback) callback()
      }
    }
    Object.keys(definition.methods).forEach(name => { inst[name] = definition.methods[name].bind(inst) })
    return inst
  }
  return {
    component, navigations,
    setResult(next) { result = next },
    setFails(next) { fails = !!next },
    defer() {
      let resolve
      const promise = new Promise(done => { resolve = done })
      deferred = { promise, resolve }
      return deferred
    },
    release() { deferred = null }
  }
}

const subscription = remainingCount => ({
  supported: true, globalEnabled: true, mainSwitch: true,
  items: [{ key: 'reviewResult', available: true, important: true,
    subscription_type: 'one_time', remaining_count: remainingCount, rejected: false }]
})

async function run() {
  const f = fixture()
  f.setResult(subscription(3))
  const clear = f.component('worker_application', false)
  await clear.refreshHealth()
  assert.strictEqual(clear.data.visible, false, 'no review and sufficient quota => no banner')
  assert.strictEqual(clear.data.notices.length, 0)

  const pending = f.component('worker_application', true)
  await pending.refreshHealth()
  assert.strictEqual(pending.data.notices.length, 1, 'review-only uses one banner')
  assert.strictEqual(pending.data.notices[0].kind, 'review')
  assert.strictEqual(pending.data.visible, true)

  f.setResult(subscription(0))
  const health = f.component('worker_application', false)
  await health.refreshHealth()
  assert.strictEqual(health.data.notices.length, 1, 'quota-only uses one banner')
  assert.strictEqual(health.data.notices[0].kind, 'health')

  const combined = f.component('worker_application', true)
  await combined.refreshHealth()
  assert.deepStrictEqual(Array.from(combined.data.notices, n => n.kind), ['review', 'health'],
    'pending review and quota shortage share a rotating banner')
  combined.openNotice({ currentTarget: { dataset: { kind: 'review' } } })
  assert.strictEqual(f.navigations.length, 0, 'review reminder must not open notification settings')
  combined.openNotice({ currentTarget: { dataset: { kind: 'health' } } })
  assert(f.navigations[0].includes('/subpackages/account/settings/index?section=notifications&audience=worker'),
    'quota reminder opens worker notification settings')
  combined.properties.reviewPending = false
  combined.syncNotices()
  assert.strictEqual(combined.data.notices.length, 1, 'review completion keeps quota reminder')
  assert.strictEqual(combined.data.notices[0].kind, 'health')

  f.setFails(true)
  const offline = f.component('worker_application', true)
  await offline.refreshHealth()
  assert.strictEqual(offline.data.notices.length, 1, 'failed health lookup keeps review banner')
  assert.strictEqual(offline.data.notices[0].kind, 'review')
  f.setFails(false)

  const late = f.defer()
  const outdated = f.component('worker_application', true)
  const first = outdated.refreshHealth()
  const second = outdated.refreshHealth()
  late.resolve(subscription(0))
  await Promise.all([first, second])
  assert.strictEqual(outdated.data.notices.length, 2, 'latest refresh keeps both notices')
  f.release()

  assert(recordWxml.includes('review-pending="{{reviewPending}}"'),
    'record page passes its review state to the shared banner')
  assert(!recordWxml.includes('review-waiting-card') && !recordWxss.includes('.review-waiting-card'),
    'standalone review banner is removed from the record page')
  assert(bannerWxml.includes('class="review-waiting-card"') && bannerWxml.includes('class="review-waiting-icon"'),
    'the original review card shape and icon are restored inside the carousel')
  assert(bannerWxss.includes('.review-waiting-card{display:flex;align-items:flex-start;gap:18rpx;') &&
    bannerWxss.includes('border-radius:22rpx;background:#eef9f3') &&
    bannerWxss.includes('width:58rpx;height:58rpx;border-radius:18rpx') &&
    bannerWxss.includes('font-size:26rpx;font-weight:700;color:#1b6749'),
    'review card keeps its original green background, square icon, and typography')
  assert(bannerWxss.includes('.review-waiting-card{border-color:#2e5a47;background:#173126'),
    'original review card dark mode is restored')
  assert(pending.data.notices[0].description.includes('不会重复催促管理员'),
    'review copy remains unchanged from the original page')
  assert(bannerWxml.includes('vertical="{{notices.length > 1}}"') &&
    bannerWxml.includes('autoplay="{{notices.length > 1}}"') &&
    bannerWxml.includes('circular="{{notices.length > 1}}"') &&
    bannerWxml.includes('interval="3000"'),
    'vertical auto-rotation is active only when both notices exist')
  assert(bannerWxml.includes('wx:if="{{item.kind === \'review\'}}"') &&
    bannerWxml.includes('class="subscription-health-banner {{level}} onboarding-health-banner"'),
    'review and health slides have distinct original card shapes')
  assert(bannerWxml.includes('catchtap="openNotificationSettings"') &&
    bannerWxml.includes('role="button" aria-label="前往通知设置"'),
    'compact settings action is accessible and does not double-trigger card tap')
  assert(bannerWxml.includes("visible && audience !== 'worker_application'") &&
    bannerWxml.includes('{{title}}') && bannerWxml.includes('{{description}}'),
    'other subscription banner consumers retain the original static layout and data')
  const general = f.component('worker', false)
  f.setResult({ supported: true, globalEnabled: true, mainSwitch: true,
    items: [{ key: 'worker_order_update', available: true, important: true,
      subscription_type: 'one_time', remaining_count: 0, rejected: false }] })
  await general.refreshHealth()
  assert.strictEqual(general.data.title, '通知提醒次数不足',
    'ordinary worker subscriptions still have their normal headline')
  assert.strictEqual(general.data.notices.length, 1)

  assert(bannerWxss.includes('height:152rpx') && bannerWxss.includes('font-size:23rpx') &&
    bannerWxss.includes('background:rgba(215,119,53,.1)'),
    'swiping cards fit original copy and keep compact orange action background')
  assert(bannerWxss.includes('.onboarding-notice-swiper .onboarding-health-banner') &&
    bannerWxss.includes('width:100%;height:100%;margin:0'),
    'embedded health cards remove the extra horizontal margins on record page')
  assert(settingsWxss.includes('font-size: 24rpx') &&
    settingsWxss.includes('background: #eff9fa;') &&
    settingsWxss.includes('border: 1rpx solid #b6dce2') &&
    settingsWxss.includes('background: #007889;') &&
    settingsWxss.includes('background: #087b8b;'),
    'batch uses solid background and single uses a lighter outlined treatment in both themes')
  assert(orderWxml.includes('<view class="order-search-button" role="button"') &&
    !orderWxml.includes('<button class="order-search-button"'),
    'search action is not stretched by WeChat native button defaults')
  assert(orderWxss.includes('height:68rpx;min-height:0') &&
    orderWxss.includes('background:#e6f8fa'),
    'entire search field and action are genuinely smaller and keep a clear tap area')

  // Exact-size contracts: prevent search and notification actions from diverging.
  const rule = (css, selector) => {
    const start = css.indexOf(selector + '{') >= 0
      ? css.indexOf(selector + '{') : css.indexOf(selector + ' {')
    assert(start >= 0, 'CSS rule missing: ' + selector)
    const open = css.indexOf('{', start)
    const declarations = css.slice(open + 1, css.indexOf('}', open))
    const properties = {}
    declarations.split(';').forEach(entry => {
      const colon = entry.indexOf(':')
      if (colon > 0) properties[entry.slice(0, colon).trim()] = entry.slice(colon + 1).trim()
    })
    return properties
  }
  const search = rule(orderWxss, '.order-search-button')
  const noticeAction = rule(bannerWxss, '.subscription-health-action')
  for (const key of ['width', 'height', 'font-size', 'border-radius', 'padding']) {
    assert.strictEqual(search[key], noticeAction[key],
      'search and notification buttons must match ' + key)
  }
  assert.strictEqual(search.width, '84rpx')
  assert.strictEqual(search.height, '50rpx')
  assert.strictEqual(search['font-size'], '23rpx')
  assert.strictEqual(rule(orderWxss, '.order-search-wrap').padding, '4rpx 24rpx 12rpx')
  assert.strictEqual(rule(orderWxss, '.order-search-box').padding, '0 12rpx 0 16rpx')
  assert.strictEqual(rule(bannerWxss, '.subscription-health-banner').margin, '16rpx 24rpx')
  assert.strictEqual(rule(bannerWxss, '.subscription-health-banner').padding,
    '16rpx 12rpx 16rpx 18rpx',
    'search and settings actions must have matching right gutters')

  const bulk = rule(settingsWxss, '.subscribe-batch-btn')
  const single = rule(settingsWxss, '.subscribe-mini-btn')
  for (const key of ['height', 'font-size', 'border-radius']) {
    assert.strictEqual(bulk[key], single[key], 'both receive buttons must match ' + key)
  }
  assert.strictEqual(bulk['font-size'], '24rpx')
  assert.strictEqual(bulk['display'], 'inline-flex')
  assert.strictEqual(single['display'], 'inline-flex')
  assert.strictEqual(bulk.width, 'auto')
  assert.strictEqual(single.width, 'auto')
  assert.strictEqual(bulk['flex'], '0 0 auto')
  assert.strictEqual(single['flex'], '0 0 auto')
  assert.strictEqual(bulk.padding, '0 16rpx')
  assert.strictEqual(single.padding, '0 14rpx')
  assert.strictEqual(bulk.background, '#007889')
  assert.strictEqual(single.background, '#eff9fa')
  assert.strictEqual(rule(settingsWxss, '.subscribe-group-head').padding, '16rpx 16rpx 11rpx 28rpx')
  assert.strictEqual(rule(settingsWxss, '.subscribe-credit-row').margin, '0 16rpx 0 28rpx')
  assert.strictEqual(rule(settingsWxss, '.subscribe-credit-side').width, 'auto')
  assert.strictEqual((settingsWxml.match(/<view[^>]*class="subscribe-batch-btn/g) || []).length, 2)
  assert.strictEqual((settingsWxml.match(/<view[^>]*class="subscribe-mini-btn/g) || []).length, 2)
  assert(!/<button[^>]*class="subscribe-(?:batch|mini)-btn/.test(settingsWxml),
    'subscription actions must not use intrinsic native button minimum width')
  assert(settingsWxml.includes("is-disabled") && settingsWxml.includes("aria-disabled"),
    'custom button preserves disabled and accessible state')
  assert(settingsJs.includes("if (audience === 'worker' ? !this.data.workerBatchAvailable : !this.data.userBatchAvailable) return"),
    'the batch handler rejects a disabled audience on the actual request path')

  const dark = bannerWxss.slice(bannerWxss.indexOf('@media (prefers-color-scheme:dark)'))
  for (const state of ['.subscription-health-banner,',
    '.subscription-health-banner.warning,', '.subscription-health-banner.attention{']) {
    assert(dark.includes(state), 'dark background must override all notice levels: ' + state)
  }
  assert(dark.includes('background:#30271f') && dark.includes('color:#ffe0b8') &&
    dark.includes('color:#f5cda7') && dark.includes('color:#ffe0b8'),
    'dark notification text and backgrounds must have explicit high contrast')
  assert.strictEqual(rule(bannerWxss, '.onboarding-notice-swiper').height, '152rpx')
  assert(source.includes("styleIsolation: 'apply-shared'") &&
    bannerWxml.includes('class="cuIcon-timefill"') &&
    iconWxss.includes('cuIcon-timefill'),
    'ColorUI clock icon must be inherited in the component')

  // With native buttons replaced by content-sized views, confirm the real page
  // handlers still reject disabled taps and preserve one-request-at-a-time.
  let settingsPage
  let requests = 0
  vm.runInNewContext(settingsJs, {
    getApp: () => ({ globalData: {} }),
    Page: definition => { settingsPage = definition },
    require: () => ({
      requestSubscriptionScenes: () => { requests++; return Promise.resolve({ acceptedCount: 0, items: [] }) },
      requestSingleTopUp: () => { requests++; return Promise.resolve({ acceptedCount: 0, items: [] }) }
    }),
    wx: { showToast() {} },
    console: { warn() {} },
    Promise, Date, setTimeout
  }, { filename: 'pages/mine/settings.js' })
  const subscriptionRow = { key: 'worker_order_update', available: true, important: true,
    subscription_type: 'one_time', remaining_count: 0, total_authorized_count: 0 }
  const ui = Object.assign({}, settingsPage, {
    data: Object.assign({}, settingsPage.data, {
      userBatchAvailable: false, workerBatchAvailable: false,
      userSubscribeItems: [subscriptionRow], workerSubscribeItems: [subscriptionRow],
      subscribeBatchBusy: false, subscribeSceneBusyKey: ''
    }),
    setData(patch) { Object.assign(this.data, patch) },
    applyTopUpResult() {},
    findSubscribeItem() { return subscriptionRow }
  })
  ui.topUpImportant({ currentTarget: { dataset: { audience: 'user' } } })
  ui.topUpImportant({ currentTarget: { dataset: { audience: 'worker' } } })
  assert.strictEqual(requests, 0, 'disabled user and worker bulk controls do not request WeChat authorization')
  ui.data.userBatchAvailable = true
  ui.topUpImportant({ currentTarget: { dataset: { audience: 'user' } } })
  assert.strictEqual(requests, 1, 'enabled batch view invokes its original request')
  ui.topUpScene({ currentTarget: { dataset: { key: subscriptionRow.key } } })
  assert.strictEqual(requests, 1, 'busy batch blocks concurrent single top-ups')
  for (let i = 0; i < 8; i++) await Promise.resolve()
  assert.strictEqual(ui.data.subscribeBatchBusy, false, 'bulk busy state clears after request')
  ui.topUpScene({ currentTarget: { dataset: { key: subscriptionRow.key } } })
  assert.strictEqual(requests, 2, 'single receive view invokes its original request')
  for (let i = 0; i < 8; i++) await Promise.resolve()
  assert.strictEqual(ui.data.subscribeSceneBusyKey, '', 'single receive busy state clears')

  console.log('V2.6.0 compact search, settings buttons, and combined onboarding notices: OK')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
