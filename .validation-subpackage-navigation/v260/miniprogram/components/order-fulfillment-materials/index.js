const LABELS = { fulfillment_document: '履约补充资料', collection_material: '用量 / 收款材料（仅留存）', repair_result: '维修结果照片' }

Component({
  properties: { media: { type: Array, value: [] } },
  data: { items: [] },
  observers: {
    media(rows) { this.setData({ items: (rows || []).filter(row => LABELS[row.purpose] && row.url).map(row => Object.assign({}, row, { label: LABELS[row.purpose] })) }) }
  },
  methods: {
    preview(e) {
      const item=this.data.items[Number(e.currentTarget.dataset.index)]
      if (item) wx.previewImage({ current: item.url, urls: this.data.items.map(row => row.url) })
    }
  }
})
