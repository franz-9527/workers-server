// Native chat inbox is the only Messages tab. The legacy PHP notification
// centre remains a normal page so back navigation and old deep links work.
let intent = null
function account() {
  try { const user = getApp().globalData.userInfo || {}; return String(user.openid || '') } catch (_) { return '' }
}
function open(section) {
  const mode = section === 'notice' ? 'all' : section === 'todo' ? 'todo' : 'chat'
  if (mode !== 'chat') {
    wx.navigateTo({ url: '/pages/notificationCenter/index?view=' + mode })
    return
  }
  intent = { account: account(), expires: Date.now() + 15000 }
  wx.switchTab({ url: '/pages/chatMessages/index', fail() { intent = null } })
}
function consume() {
  const value = intent; intent = null
  return !!(value && value.expires > Date.now() && value.account === account())
}
function reset() { intent = null }
module.exports = { open, consume, reset }
