const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')

const source = fs.readFileSync(path.join(__dirname, '../miniprogram/subpackages/account/realName/index.js'), 'utf8')
const clone = value => JSON.parse(JSON.stringify(value))
const flush = async () => { for (let i = 0; i < 12; i++) await new Promise(resolve => setImmediate(resolve)) }

function fixture(verification) {
  const state = { verification: clone(verification), calls: [], modals: [], chooseCount: 0 }
  let definition
  const api = {
    request: async (route, method, body) => {
      state.calls.push({ route, method, body })
      if (route === 'finance.summary') return { data: { real_name: clone(state.verification) } }
      if (route === 'finance.realname.reauth-policy') return { data: { can_self_reauth: true, self_reauth_remaining: 1 } }
      if (route === 'finance.realname.restart') return { data: { status: 'approved' } }
      if (route === 'finance.realname.draft') {
        if (state.blockSave) await state.blockSave
        state.verification.draft = Object.assign(state.verification.draft || { status: 'draft', media: {} }, body)
        return { data: { saved: true } }
      }
      if (route === 'finance.realname.submit') {
        if (state.failSubmit) throw new Error('模拟提交失败')
        state.verification = { status: 'pending', media: {}, real_name_masked: '新**' }
        return { message: '已提交' }
      }
      throw new Error('Unexpected route ' + route)
    }
  }
  vm.runInNewContext(source, {
    Page: page => { definition = page },
    require: name => name.includes('OrderApi') ? api : {},
    wx: {
      showModal: options => { state.modals.push(options); if (options.success) options.success({ confirm: true }) },
      showLoading() {}, hideLoading() {}, showToast() {},
      chooseMedia: () => { state.chooseCount++ }
    },
    setTimeout, clearTimeout, console
  })
  const page = Object.assign({}, definition, { data: clone(definition.data), setData(patch) { Object.assign(this.data, patch) } })
  return { state, page }
}

async function main() {
  const current = { status: 'approved', real_name_masked: '原**', media: { id_front: { id: 'original-front' }, id_back: { id: 'original-back' } } }
  const first = fixture(current)
  await first.page.load()
  assert.equal(first.page.data.verification.status, 'approved')
  first.page.restartVerification()
  await flush()
  assert.equal(first.page.data.editingReauth, true)
  assert.equal(first.page.data.verification.status, 'draft')
  assert.deepEqual(Object.keys(first.page.data.verification.media), [])
  first.page.chooseIdImage({ currentTarget: { dataset: { purpose: 'id_front' } } })
  assert.equal(first.state.chooseCount, 1, 'Fresh editor must allow selecting photos')
  first.page.saveDraft()
  assert.equal(first.state.calls.filter(call => call.route === 'finance.realname.draft').length, 0, 'Focusing untouched fields must not create an empty draft')
  const revisit = fixture(first.state.verification)
  await revisit.page.load()
  assert.equal(revisit.page.data.editingReauth, false)
  assert.equal(revisit.page.data.verification.status, 'approved', 'Leaving without drafts must keep current approval')

  const draft = { status: 'draft', real_name: '修正姓名', id_number: '110101199202020022', ocr_front_ok: true, ocr_back_ok: true, media: { id_front: { id: 'draft-front' }, id_back: { id: 'draft-back' } } }
  const resumed = fixture(Object.assign({}, current, { draft }))
  await resumed.page.load()
  assert.equal(resumed.page.data.editingReauth, true)
  assert.equal(resumed.page.data.realName, draft.real_name)
  assert.equal(resumed.page.data.idNumber, draft.id_number)
  assert.equal(resumed.page.data.ocrFrontOk && resumed.page.data.ocrBackOk, true)
  assert.equal(resumed.page.data.verification.media.id_front.id, 'draft-front', 'Editor must show draft photos instead of original photos')
  resumed.page.nameInput({ detail: { value: '再次修正' } })
  resumed.page.onUnload()
  await resumed.page._draftSavePromise
  assert.equal(resumed.state.verification.draft.real_name, '再次修正', 'Leaving immediately must flush edited text')

  const initial = fixture(Object.assign({}, draft, { draft }))
  await initial.page.load()
  assert.equal(initial.page.data.editingReauth, false)
  assert.equal(initial.page.data.realName, draft.real_name, 'Initial certification draft should also resume')

  let release
  resumed.state.blockSave = new Promise(resolve => { release = resolve })
  resumed.page.nameInput({ detail: { value: '待提交姓名' } })
  resumed.page.saveDraft()
  resumed.page.submit()
  await flush()
  assert.equal(resumed.state.calls.filter(call => call.route === 'finance.realname.submit').length, 0, 'Submission must wait for in-flight autosave')
  release()
  await flush()
  assert.equal(resumed.page.data.verification.status, 'pending')
  assert.equal(resumed.state.calls.filter(call => call.route === 'finance.realname.submit').length, 1)

  const failed = fixture(Object.assign({}, current, { draft }))
  await failed.page.load()
  failed.state.failSubmit = true
  failed.page.submit()
  await flush()
  assert.equal(failed.page.data.realName, draft.real_name)
  assert.equal(failed.page.data.verification.media.id_back.id, 'draft-back', 'Failed submit must retain editable draft')
  assert.equal(failed.page.data.submitting, false)
  assert.equal(failed.state.modals.at(-1).title, '提交失败')
  console.log('Real-name page draft, resume, autosave and submission regression passed.')
}
main().catch(error => { console.error(error); process.exitCode = 1 })
