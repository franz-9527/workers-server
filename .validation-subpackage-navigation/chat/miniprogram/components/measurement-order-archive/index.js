const OrderApi = require('../../utils/OrderApi.js')

const PURPOSE = { measurement_sheet: '手写测量单', measurement_photo: '现场照', measurement_annotated: '标注图' }

Component({
  properties: {
    orderId: {
      type: String,
      value: '',
      observer(value) {
        const id = String(value || '')
        if (id && this.__attached) this.load(id)
      }
    }
  },
  data: { visible: false, spaces: [], loading: false },
  lifetimes: {
    attached() {
      this.__attached = true
      const id = String(this.properties.orderId || '')
      if (id) this.load(id)
    },
    detached() { this.__requestSeq = (this.__requestSeq || 0) + 1 }
  },
  methods: {
    load(id) {
      const seq = (this.__requestSeq || 0) + 1
      this.__requestSeq = seq
      this.setData({ loading: true })
      return OrderApi.request('orders.detail', 'GET', null, { id }).then(res => {
        if (seq !== this.__requestSeq || String(this.properties.orderId || '') !== id) return
        const detail = res.data || {}
        const order = detail.order || {}
        const measurement = detail.measurement || null
        const visible = !!(order.is_platform_order && order.workflow_profile !== 'measurement' && measurement && measurement.platform_stage)
        const spaces = visible ? (measurement.spaces || []).map(space => Object.assign({}, space, {
          media: (space.media || space.images || []).map(file => Object.assign({}, file, { purpose_text: PURPOSE[file.purpose] || '测量图片' }))
        })) : []
        this.setData({ visible, spaces, loading: false })
      }).catch(() => {
        if (seq === this.__requestSeq) this.setData({ visible: false, spaces: [], loading: false })
      })
    },
    preview(e) {
      const space = this.data.spaces.find(item => String(item.id) === String(e.currentTarget.dataset.space))
      if (!space) return
      const urls = (space.media || []).map(item => item.url).filter(Boolean)
      if (urls.length) wx.previewImage({ current: e.currentTarget.dataset.url, urls })
    }
  }
})
