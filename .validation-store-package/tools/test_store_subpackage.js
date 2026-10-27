const assert = require('assert')
const fs = require('fs')
const path = require('path')
const root = path.resolve(__dirname, '..')
const read = file => fs.readFileSync(path.join(root, file), 'utf8')
const app = JSON.parse(read('miniprogram/app.json'))
const legacy = ['pages/store/store', 'pages/storeRecord/detail', 'pages/storePlus/plus']
for (const page of legacy) {
  assert(!app.pages.includes(page), 'legacy page still in main package: ' + page)
  for (const platform of ['mini-ios', 'mini-android']) {
    if (app[platform] && Array.isArray(app[platform].pages)) assert(!app[platform].pages.includes(page), platform + ' still contains ' + page)
  }
}
const pkg = (app.subpackages || []).find(item => item.root === 'subpackages/store')
assert(pkg, 'store subpackage missing')
assert.deepStrictEqual(pkg.pages, ['index/index', 'record/detail', 'plus/plus'])
for (const file of [
  'miniprogram/subpackages/store/index/index.js',
  'miniprogram/subpackages/store/index/index.json',
  'miniprogram/subpackages/store/index/index.wxml',
  'miniprogram/subpackages/store/index/index.wxss',
  'miniprogram/subpackages/store/record/detail.js',
  'miniprogram/subpackages/store/record/detail.json',
  'miniprogram/subpackages/store/record/detail.wxml',
  'miniprogram/subpackages/store/record/detail.wxss',
  'miniprogram/subpackages/store/plus/plus.js',
  'miniprogram/subpackages/store/plus/plus.json',
  'miniprogram/subpackages/store/plus/plus.wxml',
  'miniprogram/subpackages/store/plus/plus.wxss'
]) assert(fs.existsSync(path.join(root, file)), 'missing: ' + file)
for (const file of ['miniprogram/pages/store/store.js','miniprogram/pages/storeRecord/detail.js','miniprogram/pages/storePlus/plus.js']) {
  assert(!fs.existsSync(path.join(root, file)), 'legacy source remains: ' + file)
}
const index = read('miniprogram/pages/index/index.js')
assert(index.includes("const STORE_HOME_PATH = '/subpackages/store/index/index'"))
assert(index.includes("next.pagesUrl === 'pages/store/store'"))
const store = read('miniprogram/subpackages/store/index/index.js')
const storeWxml = read('miniprogram/subpackages/store/index/index.wxml')
assert(store.includes("const STORE_DETAIL_PATH = '/subpackages/store/record/detail'"))
assert(store.includes("const STORE_PUBLISH_PATH = '/subpackages/store/plus/plus'"))
assert(store.includes("path === '/pages/storePlus/plus'"))
assert(!store.includes('selected: 3'))
assert(!storeWxml.includes('../../images/'), 'store subpackage must use root image paths')
const detail = read('miniprogram/subpackages/store/record/detail.js')
assert(detail.includes("url: '/pages/merchantAuth/index'"))
assert(detail.includes('sendMessagePath: STORE_DETAIL_PATH'))
const baseCloud = read('cloudfunctions/base/index.js')
const storeCloud = read('cloudfunctions/store/index.js')
assert(baseCloud.includes("pagesUrl:'pages/store/store'"))
assert(storeCloud.includes("page: '/pages/storePlus/plus'"))

// 每个分包专用的 JS 应归属该分包，避免主包携带只被分包引用的代码。
const publisher = read('miniprogram/subpackages/store/plus/plus.js')
const imports = Array.from(publisher.matchAll(/import\s+[^;\n]+?\s+from\s+['"]([^'"]+)['"]/g), match => {
  const target = path.resolve(root, 'miniprogram/subpackages/store/plus', match[1])
  return path.extname(target) ? target : target + '.js'
})
for (const target of imports) assert(fs.existsSync(target), 'publisher import missing: ' + target)
const cloudHelper = imports.find(target => path.basename(target) === 'CloudUtils.js')
assert(cloudHelper, 'store image upload helper import missing')
assert(cloudHelper.startsWith(path.join(root, 'miniprogram/subpackages/store') + path.sep), 'store-only image helper must not be placed in the main package')
const vm = require('vm')
const { genCloudFilePath } = require(cloudHelper)
let publisherPage = null
let uploadOptions = null
const uploadResult = { fileID: 'synthetic-store-image' }
vm.runInNewContext(publisher.replace(/^import\s+[^;\n]+;\s*$/gm, ''), {
  MAPCONFIG: { MAPKEY: 'synthetic-map-key' }, genCloudFilePath, genDefDate: () => '',
  getApp: () => ({ globalData: { StatusBar: 0, CustomBar: 0 } }),
  requirePlugin: () => ({}), Page: spec => { publisherPage = spec },
  wx: { cloud: { uploadFile(options) { uploadOptions = options; return uploadResult } } },
  console
}, { filename: 'store-publisher.js' })
for (const extension of ['png', 'jpg']) {
  const localPath = 'wxfile://synthetic-store.' + extension
  assert.strictEqual(publisherPage.uploadImg(localPath), uploadResult)
  assert.strictEqual(uploadOptions.filePath, localPath, 'upload must keep the original image path')
  assert(new RegExp('^upload/\\d{6}/\\d{14}\\d+\\.' + extension + '
).test(uploadOptions.cloudPath), 'cloud image path/extension changed')
}

console.log('store subpackage regression: ok')
