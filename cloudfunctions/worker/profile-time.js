// Profile activity is a formal submission, not an address edit or an audit action.
const BEIJING_OFFSET = 8 * 60 * 60 * 1000
function timestampValue(raw) {
  if (raw instanceof Date) return raw.getTime()
  if (typeof raw === 'number') return Number.isFinite(raw) && raw > 0 ? (raw < 100000000000 ? raw * 1000 : raw) : 0
  const value = String(raw || '').trim()
  if (!value) return 0
  let parts
  if (/^(?:19|20)\d{10}$/.test(value)) {
    parts = value.match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/)
    return Date.UTC(+parts[1], +parts[2] - 1, +parts[3], +parts[4], +parts[5]) - BEIJING_OFFSET
  }
  if (/^\d{10,13}$/.test(value)) return Number(value) * (value.length === 10 ? 1000 : 1)
  parts = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/)
  if (parts) return Date.UTC(+parts[1], +parts[2] - 1, +parts[3], +(parts[4] || 0), +(parts[5] || 0), +(parts[6] || 0)) - BEIJING_OFFSET
  const parsed = Date.parse(value)
  return Number.isFinite(parsed) ? parsed : 0
}
function dateKey(value) {
  const time = timestampValue(value)
  return time > 0 ? new Date(time + BEIJING_OFFSET).toISOString().slice(0, 10) : ''
}
function submissionUpdate(record) {
  record = record || {}
  const day = String(record.startDate || '').trim()
  const updated = timestampValue(record.updatedTime)
  // Old updatedTime was also written when only the residence changed.
  // Formal profile saves always refresh startDate; an unmatched timestamp is not a new submission.
  return /^\d{4}-\d{2}-\d{2}$/.test(day) && updated > 0 && dateKey(updated) === day ? updated : 0
}
function profileTimestamp(record) {
  record = record || {}
  const created = timestampValue(record.createTime)
  return Math.max(created, submissionUpdate(record)) || timestampValue(record.startDate)
}
module.exports = { timestampValue, submissionUpdate, profileTimestamp }
