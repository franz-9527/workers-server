const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

const WORKER_SCENES = {
  reviewResult: true,
  order_progress: true,
  worker_order_update: true,
  worker_repair: true,
  worker_rework: true,
  worker_commission_due: true
}

function isWorkerScene(key) {
  return !!WORKER_SCENES[String(key || '')]
}

function relevantItems(items, audience) {
  const rows = Array.isArray(items) ? items : []
  // The PHP response already filters scenes by account role; onboarding stays on its own page.
  if (audience === 'all') return rows.filter(item => String(item.key || '') !== 'reviewResult')
  if (audience === 'worker_application') return rows.filter(item => String(item.key || '') === 'reviewResult')
  if (audience === 'worker') return rows.filter(item => (item.scene_keys || [item.key]).some(key => isWorkerScene(key) && key !== 'reviewResult'))
  return rows.filter(item => item.key === 'order_progress' || (item.scene_keys || [item.key]).some(key => !isWorkerScene(key)))
}

function buildHealth(result, audience) {
  const related = relevantItems(result && result.items, audience)
  if (!related.length) return null
  if (!result || result.supported === false) {
    return { level: 'warning', title: '当前微信不支持订阅消息', description: '重要消息可能无法及时送达，请更新微信后再设置。' }
  }
  if (result.globalEnabled === false) return null
  if (!result.mainSwitch) {
    return { level: 'warning', title: '微信通知未开启', description: '重要消息可能只能在消息中心看到，建议开启订阅通知。' }
  }

  const focus = related.filter(item => item.available && (item.important || audience === 'worker_application'))
  if (!focus.length) return null
  const rejected = focus.filter(item => item.rejected)
  if (rejected.length) {
    return { level: 'warning', title: '开启微信提醒', description: rejected.length + ' 类通知已被微信拒绝或禁止，可能无法及时提醒。' }
  }
  const zero = focus.filter(item => item.subscription_type === 'one_time' && (Number(item.remaining_count) || 0) < 1 && !item.rejected)
  const longTermMissing = focus.filter(item => item.subscription_type === 'long_term' && item.needs_authorization && !item.rejected)
  if (zero.length || longTermMissing.length) {
    return { level: 'attention', title: '接收微信提醒', description: (zero.length + longTermMissing.length) + ' 类重要通知当前无法通过微信及时送达。' }
  }

  return null
}

Component({
  // 让 ColorUI 的 cuIcon-timefill 字形进入组件，恢复旧版审核卡片时钟图标。
  options: { styleIsolation: 'apply-shared' },
  properties: {
    audience: { type: String, value: 'user' },
    compact: { type: Boolean, value: true },
    active: { type: Boolean, value: true },
    // Optional onboarding notice: the worker review and subscription reminders share one strip.
    reviewPending: { type: Boolean, value: false }
  },
  data: {
    visible: false,
    level: 'attention',
    title: '',
    description: '',
    healthNotice: null,
    notices: []
  },
  lifetimes: {
    attached() { this.refreshHealth() },
    detached() { this._refreshToken = (this._refreshToken || 0) + 1; this._refreshPromise = null }
  },
  pageLifetimes: {
    show() { this.refreshHealth() }
  },
  observers: {
    audience() { this.refreshHealth() },
    active(value) {
      if (value !== false) return this.refreshHealth()
      this._refreshToken = (this._refreshToken || 0) + 1
      this._refreshPromise = null
      this.setData({ healthNotice: null, title: '', description: '' }, () => this.syncNotices())
    },
    reviewPending() { this.syncNotices() }
  },
  methods: {
    refreshHealth() {
      if (this.properties.active === false) return Promise.resolve()
      const audience = String(this.properties.audience || 'user')
      if (this._refreshPromise && this._refreshAudience === audience) return this._refreshPromise
      this._refreshAudience = audience
      const token = (this._refreshToken || 0) + 1
      this._refreshToken = token
      const promise = SubscribeMessage.getSubscriptionSettings(true, { includeApplication: audience === 'worker_application' })
        .then(result => {
          if (token !== this._refreshToken) return
          const health = buildHealth(result, audience)
          this.setData({
            healthNotice: health,
            title: health ? (health.title || '微信通知需要设置') : '',
            description: health ? (health.description || '重要消息可能无法及时送达。') : ''
          }, () => this.syncNotices())
        })
        .catch(error => {
          if (token !== this._refreshToken) return
          console.warn('[订阅提醒横幅] 状态读取失败', error)
          const health = this.properties.compact ? { level: 'attention', title: '通知状态暂未更新', description: '可进入通知设置核对。' } : null
          this.setData({ healthNotice: health, title: health ? health.title : '', description: health ? health.description : '' }, () => this.syncNotices())
        })
        .finally(() => { if (this._refreshPromise === promise) this._refreshPromise = null })
      this._refreshPromise = promise
      return promise
    },
    syncNotices() {
      const notices = []
      if (this.properties.reviewPending) {
        notices.push({
          key: 'review', kind: 'review',
          title: '已进入审核，请耐心等待',
          description: '审核期间仍可修改资料；再次保存会更新本次审核资料，不会重复催促管理员。'
        })
      }
      const health = this.data.healthNotice
      if (health) {
        notices.push({
          key: 'health', kind: 'health',
          title: health.title || '微信通知需要设置',
          description: health.description || '重要消息可能无法及时送达。'
        })
      }
      this.setData({
        notices,
        visible: notices.length > 0,
        level: health ? (health.level || 'attention') : 'review'
      })
    },
    openNotice(e) {
      if (e.currentTarget.dataset.kind === 'health') this.openNotificationSettings()
    },
    openNotificationSettings() {
      const selected = String(this.properties.audience || 'user')
      const audience = selected === 'all' ? '' : '&audience=' + (selected === 'user' ? 'user' : 'worker')
      wx.navigateTo({
        url: '/subpackages/account/settings/index?section=notifications' + audience,
        fail: () => wx.showToast({ title: '无法打开消息通知设置', icon: 'none' })
      })
    }
  }
})
