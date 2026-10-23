const ChatRuntime = require('../../../utils/ChatRuntime.js')
const InboxModel = require('../../../utils/ChatInboxModel.js')

// Native read-only message window for S12. S13 adds composer/confirmed read
// after rendering/visibility proofs; entering this page is never an auto-read.
Page({
  data: {
    conversationId: '',
    enabled: false,
    status: 'disabled',
    loading: false,
    messages: [],
    invalid: false
  },
  onLoad(options) {
    this.id = String(options && options.conversation_id || '')
    this.views = {}
    this.setData({ conversationId: this.id, invalid: !InboxModel.validID(this.id) })
    this.manager = ChatRuntime.get()
    this.managerEpoch = this.manager.epoch
    this.stopChat = this.manager.subscribe((type, payload) => this.handleEvent(type, payload))
  },
  onShow() {
    this.visible = true
    if (this.manager.epoch !== this.managerEpoch) {
      this.managerEpoch = this.manager.epoch
      this.clear()
    }
    if (!InboxModel.validID(this.id)) return
    const enabled = this.manager.enabled === true
    this.setData({ enabled, status: enabled ? this.manager.state : 'disabled', loading: enabled })
    if (enabled) this.manager.focus(this.id)
  },
  onHide() {
    this.visible = false
    if (this.manager && this.manager.active === this.id) this.manager.focus('')
  },
  onUnload() {
    this.visible = false
    if (this.stopChat) this.stopChat()
    if (this.manager && this.manager.active === this.id) this.manager.focus('')
    this.views = {}
  },
  handleEvent(type, payload) {
    if (!this.visible || !this.manager.enabled) return
    if (type === 'reset') {
      this.clear()
      this.setData({ loading: true })
      return
    }
    if (type === 'conversation.clear' && payload && payload.conversation_id === this.id) {
      this.clear()
      this.setData({ status: 'denied' })
      return
    }
    if (type === 'connection') {
      this.setData({ status: payload && payload.state || 'unavailable', loading: false })
      return
    }
    if ((type !== 'conversation.reset' && type !== 'conversation.page') || !payload || payload.conversation_id !== this.id) return
    if (type === 'conversation.reset') this.views = {}
    const page = payload.page || {}
    const candidates = (page.messages || []).concat((page.changes || []).map(change => change.message))
    candidates.forEach(message => {
      if (!message || !InboxModel.validID(message.message_id) || !InboxModel.validID(message.seq)) return
      const id = String(message.message_id)
      const previous = this.views[id]
      if (previous && (previous.stateVersion.length > String(message.state_version || '0').length || (previous.stateVersion.length === String(message.state_version || '0').length && previous.stateVersion > String(message.state_version || '0')))) return
      const visible = message.state === 'published' && message.message_type === 'text'
      this.views[id] = {
        messageId: id,
        seq: String(message.seq),
        stateVersion: String(message.state_version || '0'),
        body: visible ? String(message.text || '') : message.state === 'recalled' ? '消息已撤回' : '消息暂不可查看',
        isPlaceholder: !visible,
        createdAt: String(message.created_at || '')
      }
    })
    // UI only retains last 100 confirmed message snapshots. No local archives.
    const bySeq = (a,b) => a.seq.length - b.seq.length || a.seq.localeCompare(b.seq)
    const all = Object.keys(this.views).map(key => this.views[key]).sort(bySeq)
    const last = all.slice(-100)
    this.views = {}
    last.forEach(m => { this.views[m.messageId] = m })
    this.setData({ messages: last, loading: false, status: this.manager.state })
  },
  clear() {
    this.views = {}
    this.setData({ messages: [], loading: false })
  },
  onPullDownRefresh() {
    if (this.data.enabled) this.manager.schedulePoll(0)
    wx.stopPullDownRefresh()
  }
})
