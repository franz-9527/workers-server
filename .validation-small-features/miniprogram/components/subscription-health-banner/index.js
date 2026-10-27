const SubscribeMessage = require('../../utils/SubscribeMessageUtils.js')

const WORKER_SCENES = {
  reviewResult: true,
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
  if (audience === 'worker_application') return rows.filter(item => String(item.key || '') === 'reviewResult')
  if (audience === 'worker') return rows.filter(item => isWorkerScene(item.key) && String(item.key || '') !== 'reviewResult')
  return rows.filter(item => !isWorkerScene(item.key))
}

function buildHealth(result, audience) {
  const related = relevantItems(result && result.items, audience)
  if (!related.length) return null
  if (!result || result.supported === false) {
    return { level: 'warning', title: '当前微信不支持订阅消息', description: '重要消息可能无法及时送达，请更新微信后再设置。' }
  }
  if (result.globalEnabled === false) {
    return { level: 'warning', title: '微信订阅提醒暂不可用', description: '重要消息仍会保留在消息中心，可进入设置查看状态。' }
  }
  if (!result.mainSwitch) {
    return { level: 'warning', title: '微信通知未开启', description: '重要消息可能只能在消息中心看到，建议开启订阅通知。' }
  }

  const focus = related.filter(item => item.available && (item.important || audience === 'worker_application'))
  if (!focus.length) return null
  const rejected = focus.filter(item => item.rejected)
  if (rejected.length) {
    return { level: 'warning', title: '部分重要通知未开启', description: rejected.length + ' 类通知已被微信拒绝或禁止，可能无法及时提醒。' }
  }
  const zero = focus.filter(item => item.subscription_type === 'one_time' && (Number(item.remaining_count) || 0) < 1 && !item.rejected)
  const longTermMissing = focus.filter(item => item.subscription_type === 'long_term' && item.needs_authorization && !item.rejected)
  if (zero.length || longTermMissing.length) {
    return { level: 'attention', title: '通知提醒次数不足', description: (zero.length + longTermMissing.length) + ' 类重要通知当前无法通过微信及时送达。' }
  }
  const low = focus.filter(item => item.subscription_type === 'one_time' && (Number(item.remaining_count) || 0) === 1 && !item.rejected)
  if (low.length) {
    return { level: 'attention', title: '通知提醒次数偏低', description: low.length + ' 类重要通知只剩 1 次提醒，建议提前补充。' }
  }
  return null
}

Component({
  // 让 ColorUI 的 cuIcon-timefill 字形进入组件，恢复旧版审核卡片时钟图标。
  options: { styleIsolation: 'apply-shared' },
  properties: {
    audience: { type: String, value: 'user' },
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
    attached() { this.refreshHealth() }
  },
  pageLifetimes: {
    show() { this.refreshHealth() }
  },
  observers: {
    audience() { this.refreshHealth() },
    reviewPending() { this.syncNotices() }
  },
  methods: {
    refreshHealth() {
      const audience = String(this.properties.audience || 'user')
      const token = (this._refreshToken || 0) + 1
      this._refreshToken = token
      return SubscribeMessage.getSubscriptionSettings(true, { includeApplication: audience === 'worker_application' })
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
          console.warn('[订阅提醒横幅] 状态读取失败', error)
          if (token === this._refreshToken) this.setData({ healthNotice: null, title: '', description: '' }, () => this.syncNotices())
        })
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
      const audience = String(this.properties.audience || 'user') === 'user' ? 'user' : 'worker'
      wx.navigateTo({
        url: '/pages/mine/settings?section=notifications&audience=' + audience,
        fail: () => wx.showToast({ title: '无法打开消息通知设置', icon: 'none' })
      })
    }
  }
})
