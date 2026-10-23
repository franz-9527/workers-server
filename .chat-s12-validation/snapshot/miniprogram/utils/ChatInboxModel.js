// Pure view model for a bounded in-memory, per-account conversation digest.
// The server is the authority for unread_count; never infer unread from seq gaps.
const MAX_ROWS = 100
const validID = value => /^[1-9][0-9]{0,19}$/.test(String(value || ''))
function count(value) {
  const n = Number(value)
  return Number.isSafeInteger(n) && n > 0 ? Math.min(n, 999999) : 0
}
function idCompare(a, b) {
  const left = String(a), right = String(b)
  return left.length - right.length || (left < right ? -1 : left > right ? 1 : 0)
}
function normalize(item) {
  if (!item || !validID(item.conversation_id) || !item.unread || typeof item.unread !== 'object') return null
  const unreadCount = count(item.unread.unread_count)
  const id = String(item.conversation_id)
  const snapshotSeq = String(item.unread.snapshot_max_seq || '0')
  return {
    conversationId: id,
    title: '订单沟通',
    subtitle: '订单会话 ' + id,
    lastText: unreadCount > 0 ? '有新的订单沟通消息' : '查看此订单的沟通记录',
    unreadCount,
    unreadLabel: unreadCount > 99 ? '99+' : String(unreadCount),
    snapshotSeq: /^[0-9]+$/.test(snapshotSeq) ? snapshotSeq : '0'
  }
}
function applyPage(before, payload) {
  const map = payload && payload.replace !== false ? {} : Object.assign({}, before || {})
  for (const item of (payload && Array.isArray(payload.items) ? payload.items : [])) {
    const row = normalize(item)
    if (row) map[row.conversationId] = row
  }
  const keys = Object.keys(map).sort((a, b) => idCompare(b, a)).slice(0, MAX_ROWS)
  const next = {}
  keys.forEach(key => { next[key] = map[key] })
  return next
}
function toRows(map, filter) {
  const rows = Object.keys(map || {}).map(k => map[k])
  // A lightweight inbox orders conversations requiring attention first;
  // full last-activity ordering needs the later server-side thread summary.
  rows.sort((a,b) => (b.unreadCount > 0 ? 1 : 0) - (a.unreadCount > 0 ? 1 : 0) || idCompare(b.conversationId, a.conversationId))
  return filter === 'unread' ? rows.filter(row => row.unreadCount > 0) : rows
}
module.exports = { MAX_ROWS, validID, normalize, applyPage, toRows }
