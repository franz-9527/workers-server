// One application-scoped connection; all credentials, messages and cursors
// live in memory. No wx storage, page-owned sockets, or automatic read receipt.
const routes = {
 'conversation.bootstrap': 'conversations/bootstrap', 'history.request': 'messages/history',
 'sync.request': 'messages/sync', 'changes.sync': 'messages/changes', 'message.locate': 'messages/locate',
 'receipt.read': 'conversations/read', 'conversation.unread': 'conversations/unread',
 'account.digests': 'conversations/digests', 'message.send': 'messages/send', 'message.status': 'messages/result'
}
const failure = (code, retryable = false) => Object.assign(new Error(code), { code, retryable })
const compare = (a, b) => String(a).length - String(b).length || String(a).localeCompare(String(b))
class ChatManager {
 constructor(io) {
  this.io = io; this.epoch = 0; this.serial = 0; this.enabled = false; this.foreground = false; this.network = true
  this.listeners = new Set(); this.pending = new Map(); this.cache = new Map(); this.active = ''; this.token = null
  this.state = 'disabled'; this.attempt = 0; this.task = null; this.connecting = false; this.reconciling = null
 }
 emit(type, data) { this.listeners.forEach(fn => { try { fn(type, data) } catch (_) {} }) }
 subscribe(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn) }
 live(epoch) { return epoch === this.epoch && this.enabled && this.foreground && this.network }
 setEnabled(value) { this.enabled = value === true; this.reset() }
 setForeground(value) {
  this.foreground = value === true
  if (!this.foreground) { this.disconnect(); this.stopTimers(); return }
  this.start()
 }
 setNetwork(value) { this.network = value === true; this.disconnect(); this.stopTimers(); if (this.network) this.start() }
 reset() {
  this.epoch++; this.disconnect(); this.stopTimers(); this.token = null; this.cache.clear(); this.active = ''; this.reconciling = null
  this.credentialJob = null; this.presenceJob = null; this.lastTyping = 0; this.digestCursor = ''; this.again = false; this.restartDigestAfterCurrent = false; this.renewing = false; this.pollFailures = 0; this.focusEpoch = (this.focusEpoch || 0) + 1
  this.connecting = false; this.attempt = 0; this.state = this.enabled ? 'offline' : 'disabled'; this.emit('reset')
  this.start()
 }
 stopTimers() { ['reconnectTimer', 'pollTimer', 'authTimer', 'renewTimer', 'presenceTimer', 'presenceTimer'].forEach(key => { if (this[key]) this.io.clearTimeout(this[key]); this[key] = null }) }
 disconnect() {
  ;['authTimer', 'renewTimer', 'presenceTimer'].forEach(key => { if (this[key]) this.io.clearTimeout(this[key]); this[key] = null })
  this.socketReady = false; const task = this.task; this.task = null
  if (task) { try { task.close({ code: 1000 }) } catch (_) {} }
  this.pending.forEach(p => { this.io.clearTimeout(p.timer); p.reject(failure('CHAT_TRANSPORT_UNKNOWN', true)) }); this.pending.clear()
  this.state = this.enabled ? 'offline' : 'disabled'
 }
 start() {
  if (!this.live(this.epoch)) return
  this.connect(); this.schedulePoll(0)
 }
 async credential(epoch, force = false) {
  if (!this.token || force || this.token.expires <= this.io.now() + 30000) {
   if (!this.credentialJob) {
    const job = Promise.resolve().then(() => this.io.credential(force)).then(result => {
     if (!this.live(epoch)) throw failure('CHAT_CONTEXT_CHANGED')
     const expires = Date.parse(result.expires_at)
     if (!result.access_token || !Number.isFinite(expires) || expires <= this.io.now()) throw failure('CHAT_UNAUTHORIZED')
     this.token = { value: result.access_token, expires }
    }).finally(() => { if (this.credentialJob === job) this.credentialJob = null })
    this.credentialJob = job
   }
   await this.credentialJob
  }
  return this.token.value
 }
 async http(type, payload, epoch = this.epoch) {
  if (!this.live(epoch) || !routes[type]) throw failure('CHAT_UNAVAILABLE')
  const token = await this.credential(epoch)
  const result = await this.io.http(routes[type], payload, token)
  if (!this.live(epoch)) throw failure('CHAT_CONTEXT_CHANGED')
  return result
 }
 async connect() {
  if (!this.live(this.epoch) || this.task || this.connecting || this.reconnectTimer) return
  const epoch = this.epoch; this.connecting = true
  try {
   const token = await this.credential(epoch)
   const ticket = await this.io.http('auth/socket-ticket', {}, token)
   if (!this.live(epoch)) return
   const task = this.io.socket(); this.task = task
   const current = () => this.live(epoch) && this.task === task
   task.onOpen(() => { if (current()) this.write('auth', { ticket: ticket.ticket }, 'auth') })
   task.onMessage(event => {
    if (!current()) return
    let f; try { f = JSON.parse(event.data) } catch (_) { this.socketFailed(task); return }
    if (f.protocol_version !== 1 || !f.payload || typeof f.type !== 'string') return
    if (f.type === 'auth.ok') {
     this.socketReady = true; this.state = 'socket'; this.attempt = 0
     if (this.authTimer) this.io.clearTimeout(this.authTimer); this.authTimer = null
     if (this.renewTimer) this.io.clearTimeout(this.renewTimer)
     this.renewTimer = this.io.setTimeout(() => this.renew(task, epoch), Math.max(1000, Number(f.payload.expires_at) * 1000 - this.io.now() - 30000))
     this.schedulePoll(0); if (this.active) this.command('conversation.focus', { conversation_id: this.active }).then(() => this.presence()).catch(() => {})
     this.emit('connection', { state: 'socket' }); return
    }
    if (f.type === 'auth.required') { this.renew(task, epoch); return }
    if (f.type === 'ping') { this.write('pong', { nonce: f.payload.nonce }); return }
    if (this.pending.has(f.request_id)) {
     const p = this.pending.get(f.request_id); this.pending.delete(f.request_id); this.io.clearTimeout(p.timer)
     if (f.type === 'error') p.reject(failure(f.payload.code, f.payload.retryable)); else p.resolve(f.payload)
     return
    }
    if (f.type === 'message.new' || f.type === 'account.summary.changed' || f.type === 'message.changed' || f.type === 'policy.changed') this.schedulePoll(0)
    // Hints never advance sync/change cursors, unread or acknowledged read_seq.
    if (f.type === 'presence.changed') this.presence().catch(() => {})
    if (f.type === 'typing.start' || f.type === 'typing.stop' || f.type === 'presence.changed') this.emit(f.type, f.payload)
   })
   task.onClose(() => this.socketFailed(task)); task.onError(() => this.socketFailed(task))
   this.authTimer = this.io.setTimeout(() => { if (current() && !this.socketReady) this.socketFailed(task) }, 6000)
  } catch (_) { if (this.live(epoch)) this.retry() }
  finally { if (epoch === this.epoch) this.connecting = false }
 }
 socketFailed(task) {
  if (this.task !== task) return
  this.disconnect(); this.emit('connection', { state: 'http' }); this.retry(); this.schedulePoll(0)
 }
 retry() {
  if (!this.live(this.epoch) || this.reconnectTimer) return
  const delay = Math.min(30000, 1000 * Math.pow(2, Math.min(this.attempt++, 5))) * (0.8 + this.io.random() * 0.4)
  this.reconnectTimer = this.io.setTimeout(() => { this.reconnectTimer = null; this.connect() }, delay)
 }
 async renew(task, epoch) {
  if (this.renewing || !this.live(epoch) || task !== this.task) return
  this.renewing = true
  try { const token = await this.credential(epoch, true); if (this.live(epoch) && task === this.task) this.write('auth.refresh', { access_token: token }, 'renew') }
  catch (_) { this.socketFailed(task) }
  finally { this.renewing = false }
 }
 write(type, payload, id) {
  if (!this.task) throw failure('CHAT_TRANSPORT_UNKNOWN', true)
  const task = this.task
  task.send({ data: JSON.stringify({ protocol_version: 1, request_id: id || 'c' + (++this.serial), type, payload }), fail: () => this.socketFailed(task) })
 }
 command(type, payload) {
  const epoch = this.epoch
  if (!this.socketReady) return this.http(type, payload, epoch)
  return new Promise((resolve, reject) => {
   if (this.pending.size >= 16) return reject(failure('CHAT_RESOURCE_BUSY', true))
   const id = 'c' + (++this.serial)
   const timer = this.io.setTimeout(() => { this.pending.delete(id); reject(failure('CHAT_TRANSPORT_UNKNOWN', true)) }, 5000)
   this.pending.set(id, { resolve, reject, timer })
   try { this.write(type, payload, id) } catch (e) { this.io.clearTimeout(timer); this.pending.delete(id); reject(e) }
  }).catch(error => {
   if (error.code !== 'CHAT_TRANSPORT_UNKNOWN' || !this.live(epoch)) throw error
   // Reuse the original client_message_id, never mint an ID after uncertainty.
   if (type === 'message.send') return this.http('message.status', { conversation_id: payload.conversation_id, client_message_id: payload.client_message_id }, epoch)
   return this.http(type, payload, epoch)
  })
 }
 focus(id) {
  if (this.presenceTimer) this.io.clearTimeout(this.presenceTimer); this.presenceTimer = null
  this.lastTyping = 0; this.active = id || ''; this.focusEpoch = (this.focusEpoch || 0) + 1
  if (this.socketReady) this.command('conversation.focus', { conversation_id: this.active || '0' }).then(() => this.presence()).catch(() => {})
  this.schedulePoll(0)
 }
 // A newly mounted inbox needs the first page, not an old partial
 // continuation left by background reconciliation.
 refreshAccount() {
  if (!this.enabled) return
  if (this.reconciling) {
   this.restartDigestAfterCurrent = true
   this.again = true
   return
  }
  this.digestCursor = ''
  this.schedulePoll(0)
 }
 schedulePoll(delay) {
  if (!this.live(this.epoch)) return
  if (delay === 0 && this.reconciling) { this.again = true; return }
  if (this.pollTimer) this.io.clearTimeout(this.pollTimer)
  this.pollTimer = this.io.setTimeout(() => { this.pollTimer = null; this.reconcile().catch(() => {}) }, delay)
 }
 async reconcile() {
  if (!this.live(this.epoch)) return
  if (this.reconciling) { this.again = true; return this.reconciling }
  const epoch = this.epoch; const focusEpoch = this.focusEpoch; const id = this.active
  const job = (async () => {
   let cursor = ''; let hasMore = true; let pages = 0
   // Account-wide recovery checks every foreground account, even without an
   // open conversation. Each tick is bounded; pagination continues next tick.
   cursor = this.digestCursor || ''
   while (hasMore && pages++ < 3) {
    const page = await this.http('account.digests', { limit: 20, ...(cursor ? { cursor } : {}) }, epoch)
    if (!this.live(epoch)) return
    this.emit('digests', { items: page.items, has_more: page.has_more, replace: !cursor })
    if (!this.live(epoch)) return
    hasMore = page.has_more; cursor = page.next_cursor || ''; if (hasMore && !cursor) throw failure('CHAT_INVALID_RESPONSE')
   }
   this.digestCursor = hasMore ? cursor : ''; if (hasMore) this.again = true
   if (id && this.active === id && this.focusEpoch === focusEpoch) await this.recoverConversation(id, epoch, focusEpoch)
   if (this.live(epoch)) { this.state = this.socketReady ? 'socket' : 'http'; this.pollFailures = 0 }
  })().catch(error => {
   if (this.live(epoch)) { this.pollFailures = Math.min((this.pollFailures || 0) + 1, 6); this.state = 'unavailable'; this.emit('connection', { state: 'unavailable', code: error.code }); if (error.code === 'CHAT_RESYNC_REQUIRED') this.digestCursor = '' }
   throw error
  }).finally(() => {
   if (this.reconciling !== job) return
   this.reconciling = null
   if (this.restartDigestAfterCurrent) {
    this.restartDigestAfterCurrent = false
    this.digestCursor = ''
    this.again = true
   }
   if (this.live(epoch)) { const immediate = this.again; this.again = false; this.schedulePoll(immediate ? 0 : this.pollFailures ? Math.min(30000, 5000 * Math.pow(2, this.pollFailures - 1)) : this.socketReady ? 45000 : 7500) }
  })
  this.reconciling = job; return job
 }
 async presence() {
  if (!this.active || !this.socketReady || !this.live(this.epoch)) return
  if (this.presenceJob) return this.presenceJob
  if (this.presenceTimer) this.io.clearTimeout(this.presenceTimer); this.presenceTimer = null
  const id = this.active; const epoch = this.epoch
  const job = this.command('presence.query', { conversation_id: id }).then(snapshot => {
   if (this.live(epoch) && this.active === id) this.emit('presence.snapshot', snapshot)
  }).finally(() => {
   if (this.presenceJob !== job) return
   this.presenceJob = null
   if (this.live(epoch) && this.active === id && this.socketReady) this.presenceTimer = this.io.setTimeout(() => this.presence().catch(() => {}), 5000)
  })
  this.presenceJob = job; return job
 }
 async recoverConversation(id, epoch, focusEpoch) {
  const current = () => this.live(epoch) && this.active === id && this.focusEpoch === focusEpoch
  let model = this.cache.get(id)
  try {
   if (!model) {
    let page = await this.http('conversation.bootstrap', { conversation_id: id }, epoch)
    if (!current()) return
    model = { messages: new Map(), sync: page.sync_cursor, changes: page.change_cursor, policy: page.policy_version }
    this.cache.clear(); this.cache.set(id, model); page = this.merge(model, page); this.emit('conversation.reset', { conversation_id: id, page })
   }
   for (const [type, key, next] of [['sync.request', 'sync', 'sync_cursor'], ['changes.sync', 'changes', 'change_cursor']]) {
    for (let n = 0; n < 4; n++) {
     let page = await this.http(type, { conversation_id: id, cursor: model[key], limit: 50 }, epoch)
     if (!current()) return
     if (page.policy_version !== model.policy) throw failure('CHAT_RESYNC_REQUIRED')
     if (!page[next]) throw failure('CHAT_INVALID_RESPONSE')
     page = this.merge(model, page); model[key] = page[next]
     this.emit('conversation.page', { conversation_id: id, stream: key, page })
     if (!page.has_more) break
     if (n === 3) this.again = true
    }
   }
  } catch (error) {
   if (current() && ['CHAT_RESYNC_REQUIRED', 'CHAT_PERMISSION_DENIED', 'CHAT_UNAUTHORIZED'].includes(error.code)) { this.cache.delete(id); this.emit('conversation.clear', { conversation_id: id }); if (error.code === 'CHAT_RESYNC_REQUIRED') this.again = true }
   throw error
  }
 }
 merge(model, page) {
  const messages = [...(page.messages || []), ...(page.changes || []).map(c => c.message)]
  messages.forEach(m => { const prior = model.messages.get(m.message_id); if (!prior || compare(m.state_version, prior.state_version) >= 0) { const view = { ...m }; if (view.state !== 'published') delete view.text; model.messages.set(m.message_id, view) } })
  // S12/S13 render windows, rather than making this transport an archive.
  if (model.messages.size > 200) { const sorted = [...model.messages.values()].sort((a, b) => compare(a.seq, b.seq)); sorted.slice(0, sorted.length - 200).forEach(m => model.messages.delete(m.message_id)) }
  const safe = m => { const latest = model.messages.get(m.message_id); const view = { ...(latest && compare(latest.state_version, m.state_version) > 0 ? latest : m) }; if (view.state !== 'published') delete view.text; return view }
  return { ...page, messages: (page.messages || []).map(safe), changes: (page.changes || []).map(c => ({ ...c, message: safe(c.message) })) }
 }
 typing(active) {
  if (!this.active || !this.socketReady) return Promise.resolve(false)
  const now = this.io.now()
  if (active && this.lastTyping && now - this.lastTyping < 2500) return Promise.resolve(false)
  this.lastTyping = active ? now : 0
  return this.command(active ? 'typing.start' : 'typing.stop', { conversation_id: this.active })
 }
}
module.exports = { ChatManager, compare }
