Component({
  options: {
    styleIsolation: 'apply-shared'
  },
  properties: {
    field: { type: Object, value: {} },
    categories: { type: Array, value: [] },
    selectedCategoryIds: { type: Array, value: [] },
    selectedCategoryName: { type: String, value: '暂不确定' },
    answers: { type: Object, value: {} }
  },
  methods: {
    emitValue(key, value) {
      if (!key) return
      this.triggerEvent('valuechange', { key, value })
    },
    tapSingle(e) {
      const field = this.properties.field || {}
      if (field.runtime_disabled) return
      const value = e.currentTarget.dataset.value
      const key = String(field.field_key || '')
      const current = (this.properties.answers || {})[key]
      const next = String(current === undefined || current === null ? '' : current) === String(value === undefined || value === null ? '' : value) ? '' : value
      this.emitValue(key, next)
    },
    checkboxChange(e) {
      const field = this.properties.field || {}
      if (field.runtime_disabled) return
      this.emitValue(String(field.field_key || ''), e.detail.value || [])
    },
    switchChange(e) {
      const field = this.properties.field || {}
      if (field.runtime_disabled) return
      this.emitValue(String(field.field_key || ''), !!e.detail.value)
    },
    inputChange(e) {
      const field = this.properties.field || {}
      if (field.runtime_disabled) return
      this.emitValue(String(field.field_key || ''), e.detail.value)
    },
    toggleCategory(e) {
      const field = this.properties.field || {}
      if (field.runtime_disabled) return
      const data = e.currentTarget.dataset || {}
      const id = Number(data.id) || 0
      const special = String(data.special || '')
      let ids = (this.properties.selectedCategoryIds || []).map(Number).filter(item => item > 0)
      if (special) ids = []
      else if (ids.indexOf(id) >= 0) ids = ids.filter(item => item !== id)
      else {
        if (ids.length >= 6) return wx.showToast({ title: '一个订单最多选择6个类目', icon: 'none' })
        ids.push(id)
      }
      const currentAnswers = this.properties.answers || {}
      const categoryMode = special === 'other' ? 'other' : (ids.length ? 'listed' : 'unknown')
      const categoryOther = categoryMode === 'other' ? String(currentAnswers.category_other || '') : ''
      this.triggerEvent('categorychange', { selectedCategoryIds: ids, categoryMode, categoryOther })
    },
    inputCategoryOther(e) {
      this.triggerEvent('categorychange', {
        selectedCategoryIds: [],
        categoryMode: 'other',
        categoryOther: e.detail.value || ''
      })
    }
  }
})
