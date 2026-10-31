// One application-scoped connection; all credentials, messages and cursors
// and cursors stay in memory. Published content may use an account-scoped file
// cache after server authorization; no drafts, tokens or implicit read receipts.
const { ChatImages } = require('./ChatImages.js')
const {ChatAV}=require('./ChatAV.js')
const {ChatCalls}=require('./ChatCalls.js')
const routes = {
 'calls.availability':'calls/availability','calls.create':'calls/create','calls.inbox':'calls/inbox','calls.status':'calls/status','calls.history':'calls/history','calls.prepare':'calls/prepare','calls.accept':'calls/accept','calls.reject':'calls/reject','calls.end':'calls/end','calls.pulse':'calls/pulse','calls.ready':'calls/ready','calls.prepared':'calls/prepared',
'av.upload':'av/upload', 'av.part':'av/part', 'av.complete':'av/complete', 'av.status':'av/status', 'av.cancel':'av/cancel', 'av.pending':'av/pending', 'av.url':'av/url', 'av.listened':'av/listened',

 'governance.status':'governance/status','governance.report':'governance/report','governance.appeal':'governance/appeal','governance.cases':'governance/cases',
 'media.upload':'media/upload','media.uploaded':'media/uploaded','media.status':'media/status','media.cancel':'media/cancel','media.pending':'media/pending','media.url':'media/url','media.report':'media/report',
 'conversation.readers':'conversations/readers',
 'conversation.bootstrap': 'conversations/bootstrap', 'history.request': 'messages/history',
 'sync.request': 'messages/sync', 'changes.sync': 'messages/changes', 'message.locate': 'messages/locate',
 'receipt.read': 'conversations/read', 'conversation.unread': 'conversations/unread',
 'conversation.preferences':'conversations/preferences', 'conversation.preference':'conversations/preference',
 'account.digests': 'conversations/digests', 'message.recall':'messages/recall', 'message.send': 'messages/send', 'message.status': 'messages/result'
}
const failure = (code, retryable = false) => Object.assign(new Error(code), { code, retryable })
const compare = (a, b) => String(a).length - String(b).length || String(a).localeCompare(String(b))
class ChatManager {
 constructor(io) {
  this.av=new ChatAV(this);this.images=new ChatImages(this);this.io = io;this.disk=io.disk||null; this.epoch = 0; this.serial = 0; this.enabled = false; this.foreground = false; this.network = true
  this.calls=new ChatCalls(this);this.listeners = new Set(); this.preferenceJobs = new Map(); this.accountGeneration = 0; this.pending = new Map(); this.cache = new Map(); this.drafts = new Map(); this.recalled = new Map(); this.outbox = new Map(); this.active = ''; this.token = null
  this.state = 'disabled'; this.attempt = 0; this.task = null; this.connecting = false; this.reconciling = null
 }
 emit(type, data) { this.listeners.forEach(fn => { try { fn(type, data) } catch (_) {} }) }
 subscribe(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn) }
 live(epoch) { return epoch === this.epoch && this.enabled && this.foreground && this.network }
 setEnabled(value) { this.enabled = value === true; this.reset();this.calls.foreground(this.enabled&&this.foreground) }
 setForeground(value) {this.calls.foreground(value);
  this.foreground = value === true
  if (!this.foreground) { this.av.pause(); if(this.disk)this.disk.pause();this.disconnect(); this.stopTimers(); return }
  if(this.disk)this.disk.sweep().catch(()=>{})
  this.start()
 }
 setNetwork(value) { this.network = value === true;this.calls.foreground(this.foreground&&this.network); this.disconnect(); this.stopTimers(); if (this.network) this.start() }
 reset() {
  this.simulation=false
  this.calls.reset();
  if(this.io.av&&this.disk?.scope)this.io.av.reset(this.disk.scope).catch(()=>{});this.av.reset();if(this.disk)this.disk.reset().catch(()=>{});this.images.reset();this.epoch++; this.accountGeneration++; this.preferenceJobs.clear(); this.disconnect(); this.stopTimers(); this.token = null; this.cache.clear(); this.drafts.clear(); this.recalled.clear(); this.outbox.clear(); this.active = ''; this.reconciling = null
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
     if(result.environment&&this.io.environment&&result.environment!==this.io.environment())throw failure('CHAT_UNAUTHORIZED')
     if(this.disk&&result.actor_id&&result.environment)this.disk.bind(result.environment,String(result.actor_id))
     this.calls.actorID=String(result.actor_id||'');this.calls.setEnabled(result.calls_enabled===true);this.token = { value: result.access_token, expires, transport:result.transport||'go' }
     this.simulation=result.transport==='simulation';this.emit('transport',{simulation:this.simulation})
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
  let result
  try{result=await this.io.http(routes[type],payload,token)}catch(error){
   if(epoch===this.epoch&&(type.startsWith('media.')||type.startsWith('av.'))&&payload.conversation_id&&['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED'].includes(error.code)){this.forgetConversation(payload.conversation_id);this.emit('conversation.clear',{conversation_id:payload.conversation_id})}
   throw error
  }
  if (!this.live(epoch)) throw failure('CHAT_CONTEXT_CHANGED')
  return result
 }
 async connect() {
  if (!this.live(this.epoch) || this.task || this.connecting || this.reconnectTimer) return
  const epoch = this.epoch; this.connecting = true
  try {
   const token = await this.credential(epoch)
   if(this.token?.transport==='simulation'){this.state='http';this.emit('connection',{state:'http'});return}
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
    if(f.type==='calls.changed'){this.calls.schedule(0);return}
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
  if (id && this.active !== id) this.cache.delete(id)
  this.lastTyping = 0; this.active = id || ''; this.focusEpoch = (this.focusEpoch || 0) + 1
  if (this.socketReady) this.command('conversation.focus', { conversation_id: this.active || '0' }).then(() => this.presence()).catch(() => {})
  this.schedulePoll(0)
 }
 // A newly mounted inbox needs the first page, not an old partial
 // continuation left by background reconciliation.
 refreshAccount() {
  this.accountGeneration++
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
  const epoch = this.epoch; const accountGeneration=this.accountGeneration; const focusEpoch = this.focusEpoch; const id = this.active
  const job = (async () => {
   let cursor = ''; let hasMore = true; let pages = 0
   // Account-wide recovery checks every foreground account, even without an
   // open conversation. Each tick is bounded; pagination continues next tick.
   cursor = this.digestCursor || ''
   while (hasMore && pages++ < 3) {
    const page = await this.http('account.digests', { limit: 20, ...(cursor ? { cursor } : {}) }, epoch)
    if (!this.live(epoch) || accountGeneration!==this.accountGeneration)return
    this.emit('digests', { items: page.items, has_more: page.has_more, replace: !cursor,preference_revision:page.preference_revision })
    if (!this.live(epoch) || accountGeneration!==this.accountGeneration) return
    if(!page.has_more && Number.isSafeInteger(page.badge_count) && page.badge_count>=0)this.emit('account.summary',{badge_count:page.badge_count,preference_revision:page.preference_revision})
    if (!this.live(epoch) || accountGeneration!==this.accountGeneration) return
    hasMore = page.has_more; cursor = page.next_cursor || ''; if (hasMore && !cursor) throw failure('CHAT_INVALID_RESPONSE')
   }
   this.digestCursor = hasMore ? cursor : ''; if (hasMore) this.again = true
   if (id && this.active === id && this.focusEpoch === focusEpoch) await this.recoverConversation(id, epoch, focusEpoch)
   if (this.live(epoch)) { this.state = this.socketReady ? 'socket' : 'http'; this.pollFailures = 0 }
  })().catch(error => {
   if (this.live(epoch) && accountGeneration===this.accountGeneration) { this.pollFailures = Math.min((this.pollFailures || 0) + 1, 6); this.state = 'unavailable'; this.emit('connection', { state: 'unavailable', code: error.code }); if (error.code === 'CHAT_RESYNC_REQUIRED') this.digestCursor = '' }
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
    model = { conversationId:id,metadata:{},viewerID:page.viewer_actor_id||'', messages: new Map(), sync: page.sync_cursor, changes: page.change_cursor, policy: page.policy_version, canSend: page.can_send === true, read: page.sync_cursor ? { cursor: page.sync_cursor, seq: page.snapshot_max_seq } : null }
    this.cache.clear(); this.cache.set(id, model); page = this.merge(model, page); this.emit('conversation.reset', { conversation_id: id, page })
   }
   for (const [type, key, next] of [['sync.request', 'sync', 'sync_cursor'], ['changes.sync', 'changes', 'change_cursor']]) {
    for (let n = 0; n < 4; n++) {
     let page = await this.http(type, { conversation_id: id, cursor: model[key], limit: 50 }, epoch)
     if (!current()) return
     if (page.policy_version !== model.policy) throw failure('CHAT_RESYNC_REQUIRED')
     if (!page[next]) throw failure('CHAT_INVALID_RESPONSE')
     page = this.merge(model, page); model[key] = page[next]; model.canSend = page.can_send === true
     if (key === 'sync') model.read = !page.has_more ? { cursor: page.sync_cursor, seq: page.snapshot_max_seq } : null
     this.emit('conversation.page', { conversation_id: id, stream: key, page: { ...page, window: [...model.messages.values()] } })
     if (!page.has_more) break
     if (n === 3) this.again = true
    }
   }
  } catch (error) {
   if (current() && ['CHAT_RESYNC_REQUIRED', 'CHAT_PERMISSION_DENIED', 'CHAT_UNAUTHORIZED'].includes(error.code)) { this.forgetConversation(id,error.code!=='CHAT_RESYNC_REQUIRED'); this.emit('conversation.clear', { conversation_id: id }); if (error.code === 'CHAT_RESYNC_REQUIRED') this.again = true }
   throw error
  }
 }
 merge(model, page) {
  if(page.viewer_actor_id)model.viewerID=String(page.viewer_actor_id)
  if(page.capabilities)model.capabilities={...page.capabilities}
  if(page.policy_version)model.metadata={conversation_mode:page.conversation_mode,order_context:page.order_context,member_role:page.member_role,viewer_actor_id:page.viewer_actor_id,policy_version:page.policy_version,can_send:page.can_send}
  const messages = [...(page.messages || []), ...(page.changes || []).map(c => c.message)]
  messages.forEach(m => {
   const prior=model.messages.get(m.message_id)
   if(prior&&prior.state==='published'&&m.state==='recalled'&&compare(m.state_version,prior.state_version)>=0&&String(prior.sender_id)===model.viewerID&&typeof prior.text==='string'&&model.conversationId)this.rememberRecall(model.conversationId,m.message_id,prior.text)
   model.messages.set(m.message_id,mergeMessage(prior,m))
   if(this.disk&&m.state!=='published')this.disk.revoke(model.conversationId,m.message_id).catch(()=>{})
   if(m.state!=='published')for(const key of this.images.urls.keys())if(key.startsWith(model.conversationId+':'+m.message_id+':'))this.images.urls.delete(key)
  })
  // A target's state version is independent of the replying message's version.
  for(const [key,message] of model.messages){
   const safe={...message}
   for(const field of ['reply','edited_from']){
    const ref=safe[field],target=ref&&model.messages.get(ref.message_id)
    if(target&&compare(target.state_version,ref.state_version||'0')>0)safe[field]={message_id:ref.message_id,sender_id:target.sender_id,state:target.state,state_version:target.state_version,...(target.state==='published'&&field==='reply'&&typeof target.text==='string'?{text:Array.from(target.text).slice(0,160).join('')}:{})}
   }
   model.messages.set(key,safe)
  }
  if(this.disk&&model.capabilities?.cache)this.disk.remember(model.conversationId,[...model.messages.values()]).catch(()=>{})
  // The in-memory render window stays bounded independently of device cache.
  if (model.messages.size > 200) { const sorted = [...model.messages.values()].sort((a, b) => compare(a.seq, b.seq)); sorted.slice(0, sorted.length - 200).forEach(m => model.messages.delete(m.message_id)) }
  const safe = m => model.messages.get(m.message_id) || mergeMessage(null,m)
  return { ...page, messages: (page.messages || []).map(safe), changes: (page.changes || []).map(c => ({ ...c, message: safe(c.message) })) }
 }
 async updatePreference(id, snapshot, field, value) {
  if(!snapshot || !['pinned','later'].includes(field) || typeof value!=='boolean')throw failure('CHAT_INVALID_REQUEST')
  if(this.preferenceJobs.has(id)||this.preferenceJobs.size>=16)throw failure('CHAT_RESOURCE_BUSY')
  const epoch=this.epoch
  const payload={conversation_id:id,member_version:snapshot.member_version,field,value,expected_version:snapshot[field+'_version']}
  this.preferenceJobs.set(id,true);this.refreshAccount()
  try {
   let result
   try {result=await this.http('conversation.preference',payload,epoch)}
   catch(error){
    if(!['CHAT_NETWORK_ERROR','CHAT_TRANSPORT_UNKNOWN','CHAT_DEPENDENCY_UNAVAILABLE','CHAT_UNAVAILABLE'].includes(error.code))throw error
    // An uncertain write is only confirmed by its immutable original CAS transition.
    result=await this.http('conversation.preferences',{conversation_id:id},epoch)
    const previous=String(payload.expected_version),current=String(result[field+'_version'])
    const next=increment(previous)
    if(result.member_version!==payload.member_version || result[field]!==value || current!==next)throw failure('CHAT_PREFERENCE_UNKNOWN')
   }
   if(!this.live(epoch))throw failure('CHAT_CONTEXT_CHANGED')
   this.emit('preference',{conversation_id:id,preferences:result})
   return result
  } catch(error){
   if(this.live(epoch)&&['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED'].includes(error.code)){this.forgetConversation(id);this.emit('conversation.clear',{conversation_id:id})}
   throw error
  } finally {if(epoch===this.epoch){this.preferenceJobs.delete(id);this.refreshAccount()}}
 }
 // All user actions are bound to the visible focus and identity generation.
 checkFocus(id, epoch, focus) {
  if (!this.live(epoch) || this.active !== id || this.focusEpoch !== focus) throw failure('CHAT_CONTEXT_CHANGED')
 }
 forgetConversation(id,purge=true) {
  if(this.disk&&purge)this.disk.forget(id).catch(()=>{})
  this.av.reset(id);if(purge&&this.io.av&&this.disk?.scope)this.io.av.forget(this.disk.scope,id).catch(()=>{})
  this.images.reset(id)
  this.cache.delete(id); this.drafts.delete(id)
  this.recalled.forEach((v,k)=>{if(v.conversation_id===id)this.recalled.delete(k)})
  this.outbox.forEach((v, k) => { if (v.conversation_id === id) this.outbox.delete(k) })
 }
 async action(id, type, payload) {
  const epoch = this.epoch; const focus = this.focusEpoch
  this.checkFocus(id, epoch, focus)
  try {
   const result = await this.command(type, { conversation_id: id, ...payload })
   this.checkFocus(id, epoch, focus)
   return result
  } catch (error) {
   if (error.code === 'CHAT_PERMISSION_DENIED' && type === 'message.locate') {
    // Locate intentionally uses a generic denial for a missing/invisible target.
    // Recheck conversation authority before treating that as a member revocation.
    try {
     const snapshot = await this.http('conversation.unread', { conversation_id: id }, epoch)
     this.checkFocus(id, epoch, focus)
     const model = this.cache.get(id)
     error = failure(model && snapshot.policy_version !== model.policy ? 'CHAT_RESYNC_REQUIRED' : 'CHAT_MESSAGE_UNAVAILABLE')
    } catch (probe) { error = probe }
   }
   if (epoch === this.epoch && ['CHAT_PERMISSION_DENIED', 'CHAT_UNAUTHORIZED', 'CHAT_RESYNC_REQUIRED'].includes(error.code)) {
    this.forgetConversation(id); this.emit('conversation.clear', { conversation_id: id })
    if (error.code === 'CHAT_RESYNC_REQUIRED') this.schedulePoll(0)
   }
   throw error
  }
 }
 async window(id, type, value) {
  if (!['history.request', 'message.locate', 'conversation.bootstrap'].includes(type)) throw failure('CHAT_INVALID_REQUEST')
  const model = this.cache.get(id); if (!model) throw failure('CHAT_UNAVAILABLE')
  const changes = model.changes
  const page = await this.action(id, type, type === 'history.request' ? { cursor: value, limit: 30 } : type === 'message.locate' ? { message_id: value, limit: 30 } : { limit: 30 })
  if (this.cache.get(id) !== model || page.policy_version !== model.policy) throw failure('CHAT_RESYNC_REQUIRED')
  // A concurrent change response must not be overwritten by an older window.
  if (changes !== model.changes) throw failure('CHAT_WINDOW_CHANGED', true)
  return this.merge({ messages: new Map(model.messages) }, page)
 }
 unread(id) { return this.action(id, 'conversation.unread', {}) }
 readTarget(id) {
  const model = this.cache.get(id)
  return model && model.read ? { ...model.read, epoch: this.epoch, policy: model.policy } : null
 }
 async acknowledge(id, target) {
  const model = this.cache.get(id)
  if (!target || target.epoch !== this.epoch || !model || target.policy !== model.policy) throw failure('CHAT_CONTEXT_CHANGED')
  const result = await this.action(id, 'receipt.read', { cursor: target.cursor })
  this.refreshAccount(); return result
 }
 pruneMemory() {
  const now = this.io.now()
  this.drafts.forEach((v, k) => { if (v.expires <= now) this.drafts.delete(k) })
  this.outbox.forEach((v, k) => { if (v.expires <= now) this.outbox.delete(k) })
  this.recalled.forEach((v,k)=>{if(v.expires<=now)this.recalled.delete(k)})
 }
 draft(id) { this.pruneMemory(); return this.drafts.get(id) || { text: '', revision: 0 } }
 saveDraft(id, text, expected, source) {
  this.pruneMemory()
  const current = this.draft(id)
  if (expected !== undefined && current.revision !== expected) return false
  const value = Array.from(String(text || '')).slice(0, 2000).join('')
  const metadata=source===undefined?(current.source||{}):source
  const clean={}
  for(const key of ['reply_to_message_id','reply_to_item_id','edited_from_recalled_message_id'])if(metadata[key]&&/^[1-9][0-9]{0,19}$/.test(String(metadata[key])))clean[key]=String(metadata[key])
  if (!value && !Object.keys(clean).length) this.drafts.delete(id)
  else {
   this.drafts.delete(id)
   this.drafts.set(id, { text: value, source:clean, revision: ++this.serial, expires: this.io.now() + 30 * 60 * 1000 })
   while (this.drafts.size > 5) this.drafts.delete(this.drafts.keys().next().value)
  }
  return true
 }
 attempts(id) {
  this.pruneMemory()
  return [...this.outbox.values()].filter(v => v.conversation_id === id).map(v => ({ ...v,source:{...(v.source||{})} }))
 }
 emitAttempt(row) { this.emit('composer', { conversation_id: row.conversation_id }) }
 async sendText(id, text, draftRevision) {
  const model = this.cache.get(id)
  this.checkFocus(id, this.epoch, this.focusEpoch)
  if (!model || !model.canSend) throw failure('CHAT_SEND_RESTRICTED')
  if (!validText(text)) throw failure('CHAT_TEXT_INVALID')
  const draft=this.draft(id)
  if(draftRevision!==undefined && draft.revision!==draftRevision)throw failure('CHAT_CONTEXT_CHANGED')
  this.pruneMemory()
  if (this.outbox.size >= 20) {
   for (const [key, row] of this.outbox) if (['accepted', 'rejected'].includes(row.state)) { this.outbox.delete(key); break }
   if (this.outbox.size >= 20) throw failure('CHAT_RESOURCE_BUSY')
  }
  const nonce = Array.from({ length: 24 }, () => Math.floor(this.io.random() * 16).toString(16)).join('') + (++this.serial).toString(16).padStart(8, '0').slice(-8)
  const client = Math.floor(this.io.now() / 1000) + '.' + nonce
  const row = { conversation_id: id, client_message_id: client, text, source:{...(draft.source||{})}, state: 'sending', busy: true, lastSubmitted: this.io.now(), expires: this.io.now() + 30 * 60 * 1000 }
  this.outbox.set(client, row)
  if (this.draft(id).text === text) this.saveDraft(id, '', draftRevision,{})
  this.emitAttempt(row)
  return this.submit(row)
 }
 async submit(row) {
  row.lastSubmitted = this.io.now()
  const epoch = this.epoch
  try {
   const result = await this.action(row.conversation_id, 'message.send', { client_message_id: row.client_message_id, message_type: 'text', text: row.text, ...(row.source||{}) })
   this.settleAttempt(row, result)
  } catch (error) { if (epoch === this.epoch && this.outbox.get(row.client_message_id) === row) this.failAttempt(row, error) }
  finally { if (epoch === this.epoch && this.outbox.get(row.client_message_id) === row) { row.busy = false; this.emitAttempt(row) } }
 }
 settleAttempt(row, result) {
  if (!result || result.client_message_id !== row.client_message_id || !['accepted', 'rejected', 'retryable', 'checking', 'review_pending'].includes(result.state)) throw failure('CHAT_INVALID_RESPONSE')
  row.risk_event_id=result.risk_event_id||'';row.state = result.state; row.code = result.code || ''; row.message_id = result.message_id || ''
  if (result.state === 'accepted') {
   // The result API never returns text. Render only the confirmed sync view.
   row.text = ''; this.schedulePoll(0); this.refreshAccount()
  }
 }
 failAttempt(row, error) {
  if(['CHAT_REFERENCE_UNAVAILABLE','CHAT_REEDIT_LIMIT'].includes(error.code))this.schedulePoll(0)
  row.code = error.code || 'CHAT_NETWORK_ERROR'
  row.state = row.code === 'CHAT_CHECK_UNAVAILABLE' ? 'retryable' : ['CHAT_ACTION_EXPIRED', 'CHAT_CLIENT_MESSAGE_CONFLICT', 'CHAT_CONTENT_BLOCKED', 'CHAT_SEND_RESTRICTED', 'CHAT_INVALID_REQUEST','CHAT_REFERENCE_UNAVAILABLE','CHAT_REEDIT_LIMIT'].includes(row.code) ? 'rejected' : 'unknown'
 }
 async retryText(id, client) {
  this.checkFocus(id, this.epoch, this.focusEpoch)
  this.pruneMemory()
  const row = this.outbox.get(client)
  if (!row || row.conversation_id !== id || row.busy || ['accepted', 'rejected'].includes(row.state)) return
  const epoch = this.epoch; row.busy = true; this.emitAttempt(row)
  try {
   let result
   try { result = await this.action(id, 'message.status', { client_message_id: client }) }
   catch (error) { if (error.code !== 'CHAT_SEND_RESULT_UNKNOWN') throw error }
   if (result) this.settleAttempt(row, result)
   // Absence is not failure: an explicit retry keeps the immutable original ID.
   if (!result || result.state === 'retryable' || result.state === 'checking' && this.io.now() - row.lastSubmitted >= 10000) {
    if (!this.cache.get(id) || !this.cache.get(id).canSend) throw failure('CHAT_SEND_RESTRICTED')
    await this.submit(row)
   }
  } catch (error) { if (epoch === this.epoch && this.outbox.get(client) === row) this.failAttempt(row, error) }
  finally { if (epoch === this.epoch && this.outbox.get(client) === row) { row.busy = false; this.emitAttempt(row) } }
 }
 rememberRecall(id,message,text) {
  if(typeof text!=='string'||!text)return
  this.pruneMemory()
  const key=id+':'+message
  if(this.recalled.has(key))return
  this.recalled.set(key,{conversation_id:id,message_id:message,text,expires:this.io.now()+30*60*1000})
  while(this.recalled.size>20)this.recalled.delete(this.recalled.keys().next().value)
 }
 editableRecall(id,message){this.pruneMemory();return this.recalled.get(id+':'+message)||null}
 async recall(id,message){
  const epoch=this.epoch,focus=this.focusEpoch,model=this.cache.get(id),original=model&&model.messages.get(message)
  this.checkFocus(id,epoch,focus)
  if(!model)throw failure('CHAT_UNAVAILABLE')
  let result
  try{result=await this.action(id,'message.recall',{message_id:message})}
  catch(error){
   if(!['CHAT_NETWORK_ERROR','CHAT_TRANSPORT_UNKNOWN','CHAT_DEPENDENCY_UNAVAILABLE','CHAT_UNAVAILABLE'].includes(error.code))throw error
   const page=await this.window(id,'message.locate',message)
   result=(page.messages||[]).find(m=>m.message_id===message)
   if(!result||result.state!=='recalled')throw failure('CHAT_RECALL_UNKNOWN')
  }
  this.checkFocus(id,epoch,focus)
  if(this.cache.get(id)!==model)throw failure('CHAT_CONTEXT_CHANGED')
  if(!result||result.message_id!==message||result.state!=='recalled')throw failure('CHAT_INVALID_RESPONSE')
  if(original&&original.state==='published'&&String(original.sender_id)===model.viewerID&&typeof original.text==='string')this.rememberRecall(id,message,original.text)
  const page=this.merge(model,{messages:[result]})
  this.emit('conversation.page',{conversation_id:id,stream:'changes',page:{...model.metadata,...page,window:[...model.messages.values()]}})
  this.refreshAccount()
  return result
 }
 typing(active) {
  if (!this.active || !this.socketReady) return Promise.resolve(false)
  const now = this.io.now()
  if (active && this.lastTyping && now - this.lastTyping < 2500) return Promise.resolve(false)
  this.lastTyping = active ? now : 0
  return this.command(active ? 'typing.start' : 'typing.stop', { conversation_id: this.active })
 }
}
// UTF-8 budget matches the Go fingerprint, including astral emoji and no NUL.
function validText(text) {
 if (typeof text !== 'string' || !text.trim() || text.includes('\0') || Array.from(text).length > 2000) return false
 let bytes = 0
 for (const char of text) { const n = char.codePointAt(0); if (n >= 0xd800 && n <= 0xdfff) return false; bytes += n <= 0x7f ? 1 : n <= 0x7ff ? 2 : n <= 0xffff ? 3 : 4 }
 return bytes <= 4000
}
function increment(value){const digits=String(value).split('');for(let i=digits.length-1;i>=0;i--){if(digits[i]!=='9'){digits[i]=String(Number(digits[i])+1);return digits.join('')}digits[i]='0'}return '1'+digits.join('')}
// Merge nested source versions as well as the containing message version.
function mergeMessage(prior,next){
 let view={...(prior&&compare(prior.state_version,next.state_version)>0?prior:next)}
 for(const field of ['reply','edited_from']){
  const previous=prior&&prior[field],incoming=view[field]
  if(previous&&incoming&&previous.message_id===incoming.message_id&&compare(previous.state_version||'0',incoming.state_version||'0')>0)view[field]={...previous}
  else if(incoming)view[field]={...incoming}
  if(view[field]&&(view[field].state!=='published'||field==='edited_from'))delete view[field].text
 }
 if(view.state!=='published'){delete view.text;delete view.reply;delete view.edited_from;delete view.images;delete view.av}
 return view
}
module.exports = { ChatManager, compare, validText, mergeMessage }
