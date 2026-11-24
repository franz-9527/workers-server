// One application-scoped connection; all credentials, messages and cursors
// and cursors stay in memory. Published content may use an account-scoped file
// cache after server authorization; no drafts, tokens or implicit read receipts.
const { ChatImages } = require('./ChatImages.js')
const {ChatAV}=require('./ChatAV.js')
const {ChatCalls,terminal:terminalCallStates}=require('./ChatCalls.js')
const routes = {
 'calls.availability':'calls/availability','calls.create':'calls/create','calls.inbox':'calls/inbox','calls.status':'calls/status','calls.history':'calls/history','calls.prepare':'calls/prepare','calls.accept':'calls/accept','calls.reject':'calls/reject','calls.end':'calls/end','calls.pulse':'calls/pulse','calls.ready':'calls/ready','calls.prepared':'calls/prepared','calls.failure':'calls/failure',
'av.upload':'av/upload', 'av.part':'av/part', 'av.complete':'av/complete', 'av.status':'av/status', 'av.cancel':'av/cancel', 'av.pending':'av/pending', 'av.url':'av/url', 'av.listened':'av/listened',

 'governance.status':'governance/status','governance.report':'governance/report','governance.appeal':'governance/appeal','governance.cases':'governance/cases',
 'media.upload':'media/upload','media.uploaded':'media/uploaded','media.status':'media/status','media.cancel':'media/cancel','media.pending':'media/pending','media.url':'media/url','media.report':'media/report',
 'conversation.readers':'conversations/readers','support.handled':'support/handled',
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
  this.av=new ChatAV(this);this.images=new ChatImages(this);this.io = io;this.disk=io.disk||null;this.ledger=io.ledger||null; this.epoch = 0; this.identityEpoch=0; this.serial = 0; this.enabled = false; this.foreground = false; this.network = true
  this.calls=new ChatCalls(this);this.callRefreshJobs=new Map();this.callRefreshAgain=new Set();this.listeners = new Set(); this.preferenceJobs = new Map(); this.accountGeneration = 0; this.pending = new Map(); this.cache = new Map(); this.drafts = new Map(); this.recalled = new Map(); this.outbox = new Map(); this.active = ''; this.activeScene=''; this.token = null
  this.state = 'disabled'; this.attempt = 0; this.task = null; this.connecting = false; this.reconciling = null;this.pendingLedgerJob=null;this.pendingLedgerTimer=null;this.textProbeTimer=null;this.textProbeJob=null
 }
 emit(type, data) { this.listeners.forEach(fn => { try { fn(type, data) } catch (_) {} }) }
 subscribe(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn) }
 live(epoch) { return epoch === this.epoch && this.enabled && this.foreground && this.network }
 setEnabled(value) { this.enabled = value === true; this.reset();this.calls.foreground(this.enabled&&this.foreground) }
 setForeground(value) {this.calls.foreground(value);
  this.foreground = value === true
  if (!this.foreground) { this.epoch++;this.focusEpoch=(this.focusEpoch||0)+1;this.accountGeneration++;this.reconciling=null;this.textProbeJob=null;this.pendingLedgerJob=null;this.callRefreshJobs.clear();this.callRefreshAgain.clear();this.credentialJob=null;this.credentialFailure=null;this.presenceJob=null;this.connecting=false;this.renewing=false;this.token=null;this.again=false;this.outbox.forEach(row=>{if(row.busy){row.busy=false;if(row.state==='sending'){row.state='unknown';row.code='CHAT_TRANSPORT_UNKNOWN'}}});this.av.pause();this.images.pause(); if(this.disk)this.disk.pause();this.disconnect(); this.stopTimers(); return }
  if(this.disk)this.disk.sweep().catch(()=>{})
  this.start()
 }
 setNetwork(value) { this.network = value === true;this.calls.foreground(this.foreground&&this.network); this.disconnect(); this.stopTimers(); if (this.network) this.start() }
 reset() {
  // Native file pickers may background the app; only identity resets cancel their results.
  this.identityEpoch++;
  this.simulation=false;this.testSandbox=false
  this.calls.reset();this.callRefreshJobs.clear();this.callRefreshAgain.clear();
  if(this.io.av&&this.disk?.scope)this.io.av.reset(this.disk.scope).catch(()=>{});this.av.reset();if(this.disk)this.disk.reset().catch(()=>{});this.images.reset();this.epoch++; this.accountGeneration++; this.preferenceJobs.clear(); this.disconnect(); this.stopTimers(); this.token = null; this.cache.clear(); this.drafts.clear(); this.recalled.clear(); this.outbox.clear(); this.active = ''; this.activeScene=''; this.reconciling = null
  this.credentialJob = null; this.credentialFailure = null; this.presenceJob = null;this.pendingLedgerJob=null;this.textProbeJob=null;if(this.pendingLedgerTimer)this.io.clearTimeout(this.pendingLedgerTimer);this.pendingLedgerTimer=null;if(this.ledger)this.ledger.unbind(); this.lastTyping = 0; this.digestCursor = ''; this.again = false; this.restartDigestAfterCurrent = false; this.renewing = false; this.pollFailures = 0; this.focusEpoch = (this.focusEpoch || 0) + 1
  this.connecting = false; this.attempt = 0; this.state = this.enabled ? 'offline' : 'disabled'; this.emit('reset')
  this.start()
 }
 stopTimers() { ['reconnectTimer', 'pollTimer', 'authTimer', 'renewTimer', 'presenceTimer', 'pendingLedgerTimer', 'textProbeTimer'].forEach(key => { if (this[key]) this.io.clearTimeout(this[key]); this[key] = null }) }
 disconnect() {
  ;['authTimer', 'renewTimer', 'presenceTimer'].forEach(key => { if (this[key]) this.io.clearTimeout(this[key]); this[key] = null })
  this.socketReady = false; const task = this.task; this.task = null
  if (task) { try { task.close({ code: 1000 }) } catch (_) {} }
  this.pending.forEach(p => { this.io.clearTimeout(p.timer); p.reject(failure('CHAT_TRANSPORT_UNKNOWN', true)) }); this.pending.clear()
  this.state = this.enabled ? 'offline' : 'disabled'
 }
 start() {
  if (!this.live(this.epoch)) return
  this.connect(); this.schedulePoll(0);this.scheduleTextProbe()
 }
 retryConnection() {
  this.credentialFailure=null
  if(this.reconnectTimer)this.io.clearTimeout(this.reconnectTimer)
  this.reconnectTimer=null;this.attempt=0;this.start()
 }
 async credential(epoch, force = false) {
  if(this.credentialFailure&&this.credentialFailure.until>this.io.now())throw this.credentialFailure.error
  if (!this.token || force || this.token.expires <= this.io.now() + 30000) {
   if (!this.credentialJob) {
    const job = Promise.resolve().then(() => this.io.credential(force)).then(result => {
     if (!this.live(epoch)) throw failure('CHAT_CONTEXT_CHANGED')
     const expires = Date.parse(result.expires_at)
     if (!result.access_token || !Number.isFinite(expires) || expires <= this.io.now()) throw failure('CHAT_UNAUTHORIZED')
     if(result.environment&&this.io.environment&&result.environment!==this.io.environment())throw failure('CHAT_UNAUTHORIZED')
     if(this.disk&&result.actor_id&&result.environment)this.disk.bind(result.environment,String(result.actor_id))
     if(this.ledger&&result.actor_id&&result.environment)this.ledger.bind(result.environment,String(result.actor_id))
     if(this.io.legacyDirectLedger&&this.ledger&&result.actor_id&&result.environment&&result.transport!=='simulation'&&result.test_sandbox!==true){const legacy=this.io.legacyDirectLedger;legacy.bind(result.environment,String(result.actor_id));for(const row of legacy.list()){const existing=this.ledger.list();if(!existing.some(v=>v.client_message_id===row.client_message_id)&&existing.length>=20)break;if(this.ledger.put({...row,scene:'direct'})&&this.ledger.read().some(v=>v.client_message_id===row.client_message_id&&v.scene==='direct'))legacy.remove(row.client_message_id)}legacy.unbind()}
     this.calls.actorID=String(result.actor_id||'');this.calls.setEnabled(result.calls_enabled===true);this.token = { value: result.access_token, expires, transport:result.transport||'go' }
     this.credentialFailure=null;this.simulation=result.transport==='simulation';this.testSandbox=result.test_sandbox===true;this.emit('transport',{simulation:this.simulation});this.emit('capabilities',{calls:this.calls.enabled});this.listenForCalls(epoch);this.schedulePendingRestore(epoch)
    }).catch(error=>{
     if(this.live(epoch)){
      const dependency=error.statusCode===503||error.statusCode===429||['CHAT_DEPENDENCY_UNAVAILABLE','CHAT_SIGNING_NOT_CONFIGURED','CHAT_RECOVERY_NOT_READY','CHAT_DISABLED'].includes(error.code)
      this.credentialFailure={error,until:this.io.now()+(dependency?60000:10000)}
      this.state='unavailable';this.emit('connection',{state:'unavailable',code:error.code})
     }
     throw error
    }).finally(() => { if (this.credentialJob === job) this.credentialJob = null })
    this.credentialJob = job
   }
   await this.credentialJob
  }
  return this.token.value
 }
 sceneFor(id){
  id=String(id||'')
  if(!id)return ''
  const cached=this.cache.get(id)?.scene
  if(cached)return cached
  return this.active===id?this.activeScene:''
 }
 sceneForPayload(payload){
  const id=String(payload?.conversation_id||'')
  return id?this.sceneFor(id):''
 }
 async http(type, payload, epoch = this.epoch, scene) {
  if (!this.live(epoch) || !routes[type]) throw failure('CHAT_UNAVAILABLE')
  const requestScene=scene===undefined?this.sceneForPayload(payload):scene
  const token = await this.credential(epoch)
  let result
  try{
   try{result=await this.io.http(routes[type],payload,token,requestScene)}catch(error){
    // A 401 is a pre-dispatch rejection; retry once with the same payload.
    // Timeouts and other uncertain write results are never replayed here.
    if(error.statusCode!==401||!this.live(epoch))throw error
    const renewed=await this.credential(epoch,true)
    if(!this.live(epoch))throw failure('CHAT_CONTEXT_CHANGED')
    result=await this.io.http(routes[type],payload,renewed,requestScene)
   }
  }catch(error){
   // Apply the same revocation handling to the original and renewed request.
   if(epoch===this.epoch&&(type.startsWith('media.')||type.startsWith('av.'))&&payload.conversation_id&&['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED'].includes(error.code)){this.invalidateConversation(payload.conversation_id,error.code)}
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
     this.schedulePoll(0); if (this.active&&!this.activeScene) this.command('conversation.focus', { conversation_id: this.active }).then(() => this.presence()).catch(() => {})
     this.listenForCalls(epoch)
     this.emit('connection', { state: 'socket' }); return
    }
    if(f.type==='calls.probe'){
     const challenge=String(f.payload.challenge_id||'')
     const call=this.calls.current
     if(/^[a-f0-9]{48}$/.test(challenge)&&this.calls.enabled&&this.calls.instance&&typeof this.io.incoming==='function'&&
       !this.calls.pendingCreate&&(!call||terminalCallStates.has(call.state))) {
       this.command('calls.probe_ack',{challenge_id:challenge}).catch(()=>{})
     }
     return
    }
    if(f.type==='calls.changed'){
     this.calls.hint(f.payload)
     if(terminalCallStates.has(String(f.payload.state||'')))this.refreshConversationChanges(String(f.payload.conversation_id||'')).catch(()=>{})
     return
    }
    if(f.type==='message.new'||f.type==='message.changed'){
     const cid=String(f.payload.conversation_id||'')
     if(cid&&this.cache.has(cid)&&this.active!==cid)this.refreshConversationChanges(cid,true).catch(()=>{})
    }
    if(f.type==='receipt.changed'){
     const cid=String(f.payload.conversation_id||''),seq=String(f.payload.read_seq||''),model=this.cache.get(cid)
     if(model&&/^(0|[1-9][0-9]{0,19})$/.test(seq)&&(!f.payload.policy_version||String(f.payload.policy_version)===String(model.policy))&&(!model.peerReadSeq||compare(seq,model.peerReadSeq)>=0))model.peerReadSeq=seq
     this.emit('receipt.changed',{...f.payload,...(model&&model.peerReadSeq?{read_seq:model.peerReadSeq}:{})});return
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
  const jitter=typeof this.io.jitter==='function'?this.io.jitter():0.5
  const backoff = Math.min(30000, 1000 * Math.pow(2, Math.min(this.attempt++, 5))) * (0.8 + jitter * 0.4)
  const delay = Math.max(backoff, (this.credentialFailure?.until||0)-this.io.now())
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
 command(type, payload, scene='') {
  const epoch = this.epoch
  if(scene)return this.http(type,payload,epoch,scene)
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
 focus(id,scene='') {
  if (this.presenceTimer) this.io.clearTimeout(this.presenceTimer); this.presenceTimer = null
  // Cache belongs to this authenticated epoch; identity resets still erase it.
  this.lastTyping = 0; this.active = id || ''; this.activeScene=this.active&&['support','direct'].includes(scene)?scene:''; this.focusEpoch = (this.focusEpoch || 0) + 1
  if (this.socketReady&&!this.activeScene) this.command('conversation.focus', { conversation_id: this.active || '0' }).then(() => this.presence()).catch(() => {})
  this.schedulePoll(0);this.scheduleTextProbe()
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
   // Prioritize the visible conversation over multi-page account reconciliation.
   if(id && this.active===id && this.focusEpoch===focusEpoch)await this.recoverConversation(id,epoch,focusEpoch)
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
   if (this.live(epoch)) { const recovered=this.state==='unavailable';this.state = this.socketReady ? 'socket' : 'http'; this.pollFailures = 0;if(recovered)this.emit('connection',{state:this.state}) }
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
   if (this.live(epoch)) { const immediate = this.again; this.again = false; this.schedulePoll(immediate ? 0 : this.pollFailures ? Math.min(30000, 5000 * Math.pow(2, this.pollFailures - 1)) : this.activeScene ? 8000 : this.socketReady ? 45000 : 7500) }
  })
  this.reconciling = job; return job
 }
 async presence() {
  if (!this.active || this.activeScene || !this.socketReady || !this.live(this.epoch)) return
  if (this.presenceJob) return this.presenceJob
  if (this.presenceTimer) this.io.clearTimeout(this.presenceTimer); this.presenceTimer = null
  const id = this.active; const epoch = this.epoch; const focusEpoch = this.focusEpoch
  const job = this.command('presence.query', { conversation_id: id }).then(snapshot => {
   if (this.live(epoch) && this.active === id && this.focusEpoch === focusEpoch) this.emit('presence.snapshot', snapshot)
  }).finally(() => {
   if (this.presenceJob !== job) return
   this.presenceJob = null
   if (this.live(epoch) && this.active && this.socketReady) this.presenceTimer = this.io.setTimeout(() => this.presence().catch(() => {}), this.focusEpoch === focusEpoch ? 5000 : 1000)
  })
  this.presenceJob = job; return job
 }
 async listenForCalls(epoch=this.epoch) {
  if(!this.socketReady||!this.live(epoch))return
  try {
   const ready=this.calls.enabled&&typeof this.io.incoming==='function'
   const instance=ready?await this.calls.device():''
   if(this.socketReady&&this.live(epoch))await this.command('calls.listen',{instance_id:instance})
  }catch(_){}
 }
 async recoverConversation(id, epoch, focusEpoch) {
  const current = () => this.live(epoch) && this.active === id && this.focusEpoch === focusEpoch
  let model = this.cache.get(id)
  try {
   if (!model) {
    let page = await this.http('conversation.bootstrap', { conversation_id: id }, epoch)
    if (!current()) return
    model = { conversationId:id,scene:this.sceneFor(id),metadata:{},viewerID:page.viewer_actor_id||'', messages: new Map(), sync: page.sync_cursor, changes: page.change_cursor, policy: page.policy_version, canSend: page.can_send === true, read: page.sync_cursor ? { cursor: page.sync_cursor, seq: page.snapshot_max_seq } : null }
    this.cache.clear(); this.cache.set(id, model); page = this.merge(model, page); this.emit('conversation.reset', { conversation_id: id, page });this.scheduleTextProbe()
   }
   for (const [type, key, next] of [['sync.request', 'sync', 'sync_cursor'], ['changes.sync', 'changes', 'change_cursor']]) {
    for (let n = 0; n < 4; n++) {
     let page = await this.http(type, { conversation_id: id, cursor: model[key], limit: 50 }, epoch)
     if (!current()||this.cache.get(id)!==model) return
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
   if (current() && ['CHAT_RESYNC_REQUIRED', 'CHAT_PERMISSION_DENIED', 'CHAT_UNAUTHORIZED'].includes(error.code)) this.invalidateConversation(id,error.code)
   throw error
  }
 }
 async refreshConversationChanges(id,force=false) {
  id=String(id||'')
  if(!/^[1-9][0-9]{0,19}$/.test(id)||!this.live(this.epoch))return
  const model=this.cache.get(id)
  if(!model)return
  if(!this.callRefreshJobs)this.callRefreshJobs=new Map()
  if(!this.callRefreshAgain)this.callRefreshAgain=new Set()
  if(this.callRefreshJobs.has(id)){
   if(force)this.callRefreshAgain.add(id)
   return this.callRefreshJobs.get(id)
  }
  const epoch=this.epoch
  const job=(async()=>{
   for(let n=0;n<4;n++){
    if(!this.live(epoch)||this.cache.get(id)!==model)return
    const cursor=model.changes
    let page
    try {
     page=await this.http('changes.sync',{conversation_id:id,cursor,limit:50},epoch)
    } catch(error) {
     if(this.live(epoch)&&this.cache.get(id)===model&&['CHAT_RESYNC_REQUIRED','CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED'].includes(error.code)){
      this.invalidateConversation(id,error.code);if(error.code==='CHAT_RESYNC_REQUIRED')this.schedulePoll(0)
     }
     throw error
    }
    if(!this.live(epoch)||this.cache.get(id)!==model||model.changes!==cursor)return
    if(page.policy_version!==model.policy){
     this.invalidateConversation(id,'CHAT_RESYNC_REQUIRED');this.schedulePoll(0);return
    }
    if(!page.change_cursor)throw failure('CHAT_INVALID_RESPONSE')
    const merged=this.merge(model,page)
    model.changes=page.change_cursor
    if(typeof page.can_send==='boolean')model.canSend=page.can_send
    this.emit('conversation.page',{conversation_id:id,stream:'changes',page:{...merged,window:[...model.messages.values()]}})
    if(!page.has_more)return
   }
   this.schedulePoll(0)
  })().finally(()=>{
   if(this.callRefreshJobs.get(id)===job)this.callRefreshJobs.delete(id)
   if(this.callRefreshAgain.delete(id)&&this.live(epoch))this.refreshConversationChanges(id).catch(()=>{})
  })
  this.callRefreshJobs.set(id,job)
  return job
 }
 merge(model, page) {
  if(!require('./ChatScene.js').supportsConversation(page,model.scene||this.sceneFor(model.conversationId))){
   if(model.conversationId&&this.cache.get(model.conversationId)===model)this.invalidateConversation(model.conversationId,'CHAT_PERMISSION_DENIED')
   throw failure('CHAT_SCENE_UNSUPPORTED')
  }
  if(page.viewer_actor_id)model.viewerID=String(page.viewer_actor_id)
  if(typeof page.peer_read_seq==='string'&&/^(0|[1-9][0-9]{0,19})$/.test(page.peer_read_seq)){
   if(!model.peerReadSeq||compare(page.peer_read_seq,model.peerReadSeq)>=0)model.peerReadSeq=page.peer_read_seq
  }
  if(page.capabilities)model.capabilities={...page.capabilities}
  if(page.policy_version)model.metadata={conversation_type:page.conversation_type,conversation_mode:page.conversation_mode,order_context:page.order_context,member_role:page.member_role,viewer_actor_id:page.viewer_actor_id,policy_version:page.policy_version,can_send:page.can_send,direct_state:page.direct_state,preferences:page.preferences,service_name:page.service_name,work:page.work}
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
    if(target&&compare(target.state_version,ref.state_version||'0')>0)safe[field]=projectReference(ref,target,field)
   }
   model.messages.set(key,safe)
  }
  if(this.disk&&model.capabilities?.cache)this.disk.remember(model.conversationId,[...model.messages.values()]).catch(()=>{})
  // The in-memory render window stays bounded independently of device cache.
  if (model.messages.size > 200) { const sorted = [...model.messages.values()].sort((a, b) => compare(a.seq, b.seq)); sorted.slice(0, sorted.length - 200).forEach(m => model.messages.delete(m.message_id)) }
  const safe = m => model.messages.get(m.message_id) || mergeMessage(null,m)
  return { ...page, ...(model.peerReadSeq!==undefined?{peer_read_seq:model.peerReadSeq}:{}), messages: (page.messages || []).map(safe), changes: (page.changes || []).map(c => ({ ...c, message: safe(c.message) })) }
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
   if(this.live(epoch)&&['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED'].includes(error.code)){this.invalidateConversation(id,error.code)}
   throw error
  } finally {if(epoch===this.epoch){this.preferenceJobs.delete(id);this.refreshAccount()}}
 }
 // All user actions are bound to the visible focus and identity generation.
 checkFocus(id, epoch, focus) {
  if (!this.live(epoch) || this.active !== id || this.focusEpoch !== focus) throw failure('CHAT_CONTEXT_CHANGED')
 }
 forgetConversation(id,purge=true) {
  if(this.disk&&purge)this.disk.forget(id).catch(()=>{})
  if(this.ledger&&purge)this.ledger.forget(id)
  this.av.reset(id);if(purge&&this.io.av&&this.disk?.scope)this.io.av.forget(this.disk.scope,id).catch(()=>{})
  this.images.reset(id)
  this.cache.delete(id); this.drafts.delete(id)
  this.recalled.forEach((v,k)=>{if(v.conversation_id===id)this.recalled.delete(k)})
  this.outbox.forEach((v, k) => { if (v.conversation_id === id) this.outbox.delete(k) })
 }
 // Both cases erase unauthorized/stale render data. Resync keeps only unresolved IDs,
 // never stale text, references or a replayable send payload.
 invalidateConversation(id,code) {
  const resync=code==='CHAT_RESYNC_REQUIRED'
  const pending=resync?[...this.outbox.values()].filter(r=>r.conversation_id===id&&!['accepted','rejected'].includes(r.state)).map(r=>({conversation_id:id,client_message_id:r.client_message_id,scene:r.scene||'',text:'',source:{},state:'unknown',code:'',recovered:true,busy:false,createdAt:r.createdAt,lastSubmitted:0,expires:r.expires})):[]
  if(this.active===id)this.focusEpoch=(this.focusEpoch||0)+1
  this.forgetConversation(id,!resync)
  for(const row of pending)this.outbox.set(row.client_message_id,row)
  this.emit('conversation.clear',{conversation_id:id,reason:resync?'resync':'denied'})
 }
 async action(id, type, payload) {
  const epoch = this.epoch; const focus = this.focusEpoch
  this.checkFocus(id, epoch, focus)
  try {
   const result = await this.command(type, { conversation_id: id, ...payload },this.sceneFor(id))
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
   if (epoch === this.epoch && this.active===id&&this.focusEpoch===focus&&['CHAT_PERMISSION_DENIED', 'CHAT_UNAUTHORIZED', 'CHAT_RESYNC_REQUIRED'].includes(error.code)) {
    this.invalidateConversation(id,error.code)
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
  if (this.cache.get(id) !== model) throw failure('CHAT_CONTEXT_CHANGED')
  if(page.policy_version!==model.policy){this.invalidateConversation(id,'CHAT_RESYNC_REQUIRED');this.schedulePoll(0);throw failure('CHAT_RESYNC_REQUIRED')}
  // A concurrent change response must not be overwritten by an older window.
  if (changes !== model.changes) throw failure('CHAT_WINDOW_CHANGED', true)
  return this.merge({ conversationId:id,scene:model.scene,metadata:{...(model.metadata||{})},messages: new Map(model.messages) }, page)
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
 dismissRejectedText(id,client) {
  this.checkFocus(id,this.epoch,this.focusEpoch);this.pruneMemory()
  const row=this.outbox.get(client)
  if(!this.cache.has(id)||!row||row.conversation_id!==id||row.busy||row.state!=='rejected')return false
  this.outbox.delete(client);if(this.ledger)this.ledger.remove(client);this.emitAttempt(row);return true
 }
 emitAttempt(row) { this.emit('composer', { conversation_id: row.conversation_id });this.scheduleTextProbe() }
 // Status probes are read-only, separate from account/receipt polling. No automatic
 // message.send, even when the original action is absent or the provider is down.
 scheduleTextProbe() {
  if(this.textProbeTimer){this.io.clearTimeout(this.textProbeTimer);this.textProbeTimer=null}
  const id=this.active
  if(this.textProbeJob||!id||!this.live(this.epoch)||!this.cache.has(id))return
  this.pruneMemory()
  const rows=[...this.outbox.values()].filter(r=>r.conversation_id===id&&!r.busy&&['unknown','checking','review_pending'].includes(r.state))
  if(!rows.length)return
  const now=this.io.now();for(const row of rows)if(!row.nextProbeAt)row.nextProbeAt=now+5000
  const delay=Math.max(1000,Math.min(...rows.map(r=>r.nextProbeAt))-now)
  this.textProbeTimer=this.io.setTimeout(()=>{this.textProbeTimer=null;this.probeTextAttempts().catch(()=>{})},delay)
 }
 async probeTextAttempts() {
  if(this.textProbeJob)return this.textProbeJob
  const id=this.active,epoch=this.epoch,focus=this.focusEpoch,model=this.cache.get(id)
  const current=()=>this.live(epoch)&&this.active===id&&this.focusEpoch===focus&&this.cache.get(id)===model
  if(!id||!model||!current())return
  this.pruneMemory()
  const now=this.io.now(),rows=[...this.outbox.values()].filter(r=>r.conversation_id===id&&!r.busy&&['unknown','checking','review_pending'].includes(r.state)&&(!r.nextProbeAt||r.nextProbeAt<=now)).sort((a,b)=>(a.nextProbeAt||0)-(b.nextProbeAt||0)).slice(0,3)
  const job=(async()=>{
   for(const row of rows){
    if(!current()||this.outbox.get(row.client_message_id)!==row||row.busy)continue
    row.busy=true
    try{
     const result=await this.http('message.status',{conversation_id:id,client_message_id:row.client_message_id},epoch,row.scene||undefined)
     if(!current()||this.outbox.get(row.client_message_id)!==row)continue
     this.settleAttempt(row,result);row.probeFailures=0
    }catch(error){
     if(!current()||this.outbox.get(row.client_message_id)!==row)continue
     if(['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED','CHAT_RESYNC_REQUIRED'].includes(error.code)){
      this.invalidateConversation(id,error.code);if(error.code==='CHAT_RESYNC_REQUIRED')this.schedulePoll(0);break
     }
     if(error.code==='CHAT_ACTION_EXPIRED')this.failAttempt(row,error)
     else row.probeFailures=Math.min(4,(row.probeFailures||0)+1)
    }finally{
     if(epoch===this.epoch&&this.outbox.get(row.client_message_id)===row){
      row.busy=false;row.nextProbeAt=this.io.now()+Math.min(60000,(row.state==='review_pending'?30000:5000)*Math.pow(2,row.probeFailures||0));this.emitAttempt(row)
     }
    }
   }
  })().finally(()=>{if(this.textProbeJob===job){this.textProbeJob=null;this.scheduleTextProbe()}})
  this.textProbeJob=job;return job
 }
 async clientID() {
  if(typeof this.io.randomBytes!=='function')throw failure('CHAT_RANDOM_UNAVAILABLE')
  const raw=await this.io.randomBytes(26)
  if(typeof raw!=='string'||!/^[0-9a-f]{52}$/.test(raw))throw failure('CHAT_RANDOM_UNAVAILABLE')
  let prefix=String(parseInt(raw.slice(0,2),16)%9+1)
  for(let i=1;i<10;i++)prefix+=String(parseInt(raw.slice(i*2,i*2+2),16)%10)
  return prefix+'.'+raw.slice(20)
 }
 schedulePendingRestore(epoch=this.epoch) {
  if(!this.ledger||this.pendingLedgerJob||this.pendingLedgerTimer||!this.live(epoch)||this.simulation===true||this.testSandbox===true)return
  this.pendingLedgerTimer=this.io.setTimeout(()=>{this.pendingLedgerTimer=null;this.restorePending(epoch).catch(()=>{})},0)
 }
 async restorePending(epoch=this.epoch) {
  if(!this.ledger||this.pendingLedgerJob||!this.live(epoch))return this.pendingLedgerJob
  const job=(async()=>{
   const rows=this.ledger.list().slice(-20)
   for(const saved of rows){
    if(!this.live(epoch))throw failure('CHAT_CONTEXT_CHANGED')
    let row=this.outbox.get(saved.client_message_id)
    if(!row){
     row={conversation_id:saved.conversation_id,client_message_id:saved.client_message_id,scene:['support','direct'].includes(saved.scene)?saved.scene:'',text:'',source:{},state:'unknown',busy:false,recovered:true,lastSubmitted:0,expires:this.io.now()+30*60*1000}
     this.outbox.set(row.client_message_id,row);this.emitAttempt(row)
    }
    if(row.busy)continue
    row.busy=true;this.emitAttempt(row)
    try{
     const result=await this.http('message.status',{conversation_id:row.conversation_id,client_message_id:row.client_message_id},epoch,row.scene||undefined)
     if(!this.live(epoch)||this.outbox.get(row.client_message_id)!==row)throw failure('CHAT_CONTEXT_CHANGED')
     this.settleAttempt(row,result)
    }catch(error){
     if(!this.live(epoch)||this.outbox.get(row.client_message_id)!==row)throw error
     if(['CHAT_PERMISSION_DENIED','CHAT_UNAUTHORIZED','CHAT_RESYNC_REQUIRED'].includes(error.code)){this.invalidateConversation(row.conversation_id,error.code);if(error.code==='CHAT_RESYNC_REQUIRED'&&this.active===row.conversation_id)this.schedulePoll(0);continue}
     if(error.code==='CHAT_SEND_RESULT_UNKNOWN'){row.state='unknown';row.code=error.code}
     else if(error.code==='CHAT_ACTION_EXPIRED'){row.state='rejected';row.code=error.code;this.ledger.remove(row.client_message_id)}
     else row.code=error.code||'CHAT_NETWORK_ERROR'
    }finally{
     if(epoch===this.epoch&&this.outbox.get(row.client_message_id)===row){row.busy=false;this.emitAttempt(row)}
    }
   }
  })().finally(()=>{if(this.pendingLedgerJob===job)this.pendingLedgerJob=null})
  this.pendingLedgerJob=job;return job
 }
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
  const epoch=this.epoch,focus=this.focusEpoch
  const client = await this.clientID()
  this.checkFocus(id,epoch,focus)
  if(this.cache.get(id)!==model||!model.canSend)throw failure('CHAT_CONTEXT_CHANGED')
  const row = { conversation_id: id, client_message_id: client, scene:this.sceneFor(id), text, source:{...(draft.source||{})}, state: 'sending', busy: true, createdAt:this.io.now(),lastSubmitted: this.io.now(), expires: this.io.now() + 30 * 60 * 1000 }
  this.outbox.set(client, row);if(this.ledger)this.ledger.put(row)
  if (this.draft(id).text === text) this.saveDraft(id, '', draftRevision,{})
  this.emitAttempt(row)
  return this.submit(row)
 }
 async submit(row) {
  row.lastSubmitted = this.io.now()
  const epoch = this.epoch
  try {
   const result = await this.action(row.conversation_id, 'message.send', { client_message_id: row.client_message_id, message_type: 'text', text: row.text, ...(row.source||{}) })
   if(epoch===this.epoch&&this.outbox.get(row.client_message_id)===row)this.settleAttempt(row, result)
  } catch (error) { if (epoch === this.epoch && this.outbox.get(row.client_message_id) === row) this.failAttempt(row, error) }
  finally { if (epoch === this.epoch && this.outbox.get(row.client_message_id) === row) { row.busy = false; this.emitAttempt(row) } }
 }
 settleAttempt(row, result) {
  if (!result || result.client_message_id !== row.client_message_id || !['accepted', 'rejected', 'retryable', 'checking', 'review_pending'].includes(result.state)) throw failure('CHAT_INVALID_RESPONSE')
  row.risk_event_id=result.risk_event_id||'';row.state = result.state; row.code = result.code || ''; row.message_id = result.message_id || '';row.nextProbeAt=this.io.now()+(row.state==='review_pending'?30000:5000)
  if (result.state === 'accepted') {
   // The result API never returns text. Render only the confirmed sync view.
   row.text = '';if(this.ledger)this.ledger.remove(row.client_message_id); this.schedulePoll(0); this.refreshAccount()
  } else if(result.state==='rejected'&&this.ledger)this.ledger.remove(row.client_message_id)
 }
 failAttempt(row, error) {
  if(['CHAT_REFERENCE_UNAVAILABLE','CHAT_REEDIT_LIMIT'].includes(error.code))this.schedulePoll(0)
  row.code = error.code || 'CHAT_NETWORK_ERROR'
  row.state = ['CHAT_CHECK_UNAVAILABLE','CHAT_DEPENDENCY_UNAVAILABLE','CHAT_RESOURCE_BUSY'].includes(row.code) ? 'retryable' : ['CHAT_ACTION_EXPIRED', 'CHAT_CLIENT_MESSAGE_CONFLICT', 'CHAT_CONTENT_BLOCKED', 'CHAT_REVIEW_REQUIRED', 'CHAT_SEND_RESTRICTED', 'CHAT_DIRECT_STRANGER_LIMIT', 'CHAT_DIRECT_DAILY_LIMIT', 'CHAT_INVALID_REQUEST','CHAT_REFERENCE_UNAVAILABLE','CHAT_REEDIT_LIMIT'].includes(row.code) ? 'rejected' : 'unknown'
  row.nextProbeAt=this.io.now()+5000
  if(row.state==='rejected'&&this.ledger)this.ledger.remove(row.client_message_id)
 }
 async retryText(id, client) {
  this.checkFocus(id, this.epoch, this.focusEpoch)
  this.pruneMemory()
  const row = this.outbox.get(client)
  if (!row || row.conversation_id !== id || row.busy || ['accepted', 'rejected','review_pending'].includes(row.state)) return
  const epoch = this.epoch; row.busy = true; this.emitAttempt(row)
  try {
   let result
   try { result = await this.action(id, 'message.status', { client_message_id: client }) }
   catch (error) { if (error.code !== 'CHAT_SEND_RESULT_UNKNOWN') throw error }
   if(epoch!==this.epoch||this.outbox.get(client)!==row)return
   if (result) this.settleAttempt(row, result)
   // Absence is not failure: an explicit retry keeps the immutable original ID.
   // Recovered ledger rows deliberately contain no body and therefore only
   // reconcile status; they can never recreate or silently resend content.
   if ((!result || result.state === 'retryable' || result.state === 'checking' && this.io.now() - row.lastSubmitted >= 10000) && !row.recovered && row.state!=='review_pending') {
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
  const page=this.merge(model,{...model.metadata,messages:[result]})
  this.emit('conversation.page',{conversation_id:id,stream:'changes',page:{...model.metadata,...page,window:[...model.messages.values()]}})
  this.refreshAccount()
  return result
 }
 typing(active) {
  if (!this.active || this.activeScene || !this.socketReady) return Promise.resolve(false)
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
// Keep only authoritative, display-safe quote fields after the target changes.
// A recalled or blocked target never carries its original text or media metadata.
function projectReference(ref,target,field){
 const out={message_id:ref.message_id,state:target.state,state_version:target.state_version}
 if(target.state!=='published')return out
 const kind=target.message_type||ref.message_type
 if(target.sender_id||ref.sender_id)out.sender_id=target.sender_id||ref.sender_id
 if(kind)out.message_type=kind
 if(field==='reply'&&(kind==='image'||kind==='image_album')&&ref.item_id){
  out.item_id=ref.item_id
  if(Number.isInteger(ref.ordinal)&&ref.ordinal>=0)out.ordinal=ref.ordinal
 }
 if(field==='reply'&&kind==='text'&&typeof target.text==='string')out.text=Array.from(target.text).slice(0,160).join('')
 return out
}
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
module.exports = { ChatManager, compare, validText, mergeMessage, projectReference }
