'use strict'
const assert = require('assert')

const requests = []
const page = {
  data: { status: 'all', keyword: '', searchText: '', items: [], page: 1, hasMore: false },
  setData(next) { Object.assign(this.data, next) },
  load(reset) {
    const pageNo = reset ? 1 : this.data.page
    requests.push({ kind: 'mine', status: this.data.status, page: pageNo, keyword: this.data.keyword })
  },
  submitSearch() {
    const keyword = String(this.data.searchText || '').trim().substring(0, 80)
    this.setData({ keyword, searchText: keyword, items: [], page: 1, hasMore: false })
    this.load(true)
  },
  selectTab(key) {
    this.setData({ status: key, items: [], page: 1, hasMore: false })
    this.load(true)
  },
  clearSearch() {
    this.setData({ keyword: '', searchText: '', items: [], page: 1, hasMore: false })
    this.load(true)
  }
}

page.data.searchText = '  浦东花园  '
page.submitSearch()
assert.deepStrictEqual(requests[0], { kind: 'mine', status: 'all', page: 1, keyword: '浦东花园' })
page.selectTab('completed')
assert.deepStrictEqual(requests[1], { kind: 'mine', status: 'completed', page: 1, keyword: '浦东花园' })
page.clearSearch()
assert.deepStrictEqual(requests[2], { kind: 'mine', status: 'completed', page: 1, keyword: '' })

console.log('V2.5.0 order center UI search state smoke OK')
