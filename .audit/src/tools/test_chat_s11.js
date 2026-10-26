const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const { ChatManager } = require('../miniprogram/utils/ChatManager.js')
const flush = async () => { for (let i = 0; i < 12; i++) await new Promise(resolve => setImmediate(resolve)) }
function fixture(handler) {
 let serial = 0; let time = 1000000
 const timers = new Map(); const sockets = []; const calls = []; const events = []
 const io = {
  now: () => time, random: () => 0.5,
  setTimeout(fn, delay) { const id = ++serial; timers.set(id, { fn, delay }); return id }, clearTimeout(id) { timers.delete(id) },
  async credential() { calls.push(['credential']); return { access_token: 'memory-only', expires_at: new Date(time + 300000).toISOString() } },
  async http(path, data, token) { calls.push([path, data, token]); if (handler) return handler(path, data, token); if (path === 'auth/socket-ticket') return { ticket: 'one-use' }; return { items: [], has_more: false } },
  socket() {
   const task = { sent: [], closed: false, onOpen(fn) { this.open = fn }, onMessage(fn) { this.message = fn }, onClose(fn) { this.closeEvent = fn }, onError(fn) { this.error = fn }, send(data) { this.sent.push(JSON.parse(data.data)) }, close() { this.closed = true }, receive(type, payload, id = 'server') { this.message({ data: JSON.stringify({ protocol_version: 1, request_id: id, type, payload }) }) }
   }
   sockets.push(task); return task
  }
 }
 const m = new ChatManager(io); m.subscribe((type, data) => events.push([type, data]))
 return { m, io, timers, sockets, calls, events, advance: n => { time += n } }
}
async function connected(f) { f.m.setEnabled(true); f.m.setForeground(true); await flush(); const s = f.sockets.at(-1); s.open(); s.receive('auth.ok', { actor_id: '1', expires_at: (f.io.now() + 300000) / 1000 }); return s }
const message = (seq, state = 'published', version = '1') => ({ message_id: seq, seq, sender_id: '2', policy_version: '1', state_version: version, state, text: 'text-' + seq })
function history(path, data) {
 if (path === 'auth/socket-ticket') return { ticket: 'ticket' }
 if (path === 'conversations/digests') return { items: [{ conversation_id: 'other-order', unread: { unread_count: 1 } }], has_more: false }
 if (path === 'conversations/bootstrap') return { messages: [message('100')], changes: [], policy_version: '1', sync_cursor: 'signed-100', change_cursor: 'change-100' }
 if (path === 'messages/sync') { const previous = Number(data.cursor.split('-')[1]); const seq = Math.min(103, previous + 1); return { messages: seq > previous ? [message(String(seq))] : [], policy_version: '1', sync_cursor: 'signed-' + seq, has_more: seq < 103 } }
 if (path === 'messages/changes') return { messages: [], changes: [{ message: message('100', 'recalled', '2') }], policy_version: '1', change_cursor: 'change-101', has_more: false }
 throw Error(path)
}
test('default gate has no network calls; multiple starts keep one socket; hide cancels timers', async () => {
 const f = fixture(); f.m.setForeground(true); await flush(); assert.equal(f.calls.length, 0)
 f.m.setEnabled(true); f.m.start(); f.m.start(); await flush(); assert.equal(f.sockets.length, 1); assert.equal(f.calls.filter(c => c[0] === 'credential').length, 1)
 f.m.setForeground(false); assert.equal(f.timers.size, 0); assert.equal(f.sockets[0].closed, true)
})
test('103 hint does not skip 101/102; both streams restore recall; account recovery runs on every page', async () => {
 const f = fixture(history); const socket = await connected(f); f.m.focus('order-A')
 socket.receive('message.new', { conversation_id: 'order-A', highest_seq: '103' })
 assert.equal(f.m.cache.size, 0)
 await f.m.reconcile()
 const model = f.m.cache.get('order-A'); assert.deepEqual([...model.messages.keys()], ['100', '101', '102', '103'])
 assert.equal(model.sync, 'signed-103'); assert.equal(model.changes, 'change-101'); assert.equal(model.messages.get('100').text, undefined)
 assert.equal(f.calls.some(c => c[0] === 'conversations/read'), false)
 assert.equal(f.events.some(e => e[0] === 'digests' && e[1].items[0].conversation_id === 'other-order'), true)
 f.m.focus(''); await f.m.reconcile(); assert.equal(f.calls.filter(c => c[0] === 'conversations/digests').length, 2)
 f.m.setForeground(false)
})
test('older message state cannot restore recalled text in cache or emitted pages', () => {
 const f = fixture(); const model = { messages: new Map() }
 f.m.merge(model, { messages: [message('4', 'recalled', '3')] })
 const page = f.m.merge(model, { messages: [message('4', 'published', '1')] })
 assert.equal(model.messages.get('4').state_version, '3'); assert.equal(page.messages[0].state, 'recalled'); assert.equal(page.messages[0].text, undefined)
})
test('socket timeout queries original send id using HTTP; transport failure never mints another id', async () => {
 const f = fixture((path, payload) => { if (path === 'auth/socket-ticket') return { ticket: 'ticket' }; if (path === 'messages/result') return { state: 'accepted', client_message_id: payload.client_message_id }; return { items: [], has_more: false } })
 await connected(f); const request = { conversation_id: '1', client_message_id: 'original-id', text: 'text' }
 const pending = f.m.command('message.send', request); const timer = [...f.timers.entries()].find(([, t]) => t.delay === 5000); assert.ok(timer); f.timers.delete(timer[0]); timer[1].fn()
 const result = await pending; assert.equal(result.client_message_id, 'original-id'); assert.equal(f.calls.find(c => c[0] === 'messages/result')[1].client_message_id, 'original-id'); assert.equal(f.calls.some(c => c[0] === 'messages/send'), false)
 f.m.setForeground(false)
})
test('WSS outage uses bounded HTTP cadence; entire Go outage reports unavailable and backs off', async () => {
 let unavailable = false
 const f = fixture((path) => { if (unavailable) throw Object.assign(Error('down'), { code: 'CHAT_NETWORK_ERROR' }); return path === 'auth/socket-ticket' ? { ticket: 'ticket' } : { items: [], has_more: false } })
 const s = await connected(f); s.error({}); await f.m.reconcile(); assert.equal(f.m.state, 'http'); assert.ok([...f.timers.values()].some(t => t.delay === 7500)); assert.ok([...f.timers.values()].some(t => t.delay === 1000))
 unavailable = true; await assert.rejects(f.m.reconcile()); assert.equal(f.m.state, 'unavailable'); assert.ok([...f.timers.values()].some(t => t.delay === 5000)); f.m.setForeground(false)
})
test('identity reset discards a late response and clears cursors/body without persisting anything', async () => {
 let release
 const f = fixture((path) => path === 'conversations/digests' ? new Promise(resolve => { release = resolve }) : { ticket: 'ticket' })
 await connected(f); const job = f.m.reconcile(); await flush(); assert.ok(release)
 f.m.digestCursor = 'old-account'; f.m.cache.set('private', { text: 'old' }); f.m.reset(); f.m.setForeground(false)
 release({ items: [{ conversation_id: 'secret' }], has_more: false }); await assert.rejects(job)
 assert.equal(f.m.cache.size, 0); assert.equal(f.m.digestCursor, ''); assert.equal(f.events.some(e => e[0] === 'digests'), false); assert.equal(f.timers.size, 0)
})
test('focus switch rejects a previous conversation response', async () => {
 let release
 const f = fixture((path, data) => path === 'conversations/bootstrap' ? new Promise(resolve => { release = resolve }) : history(path, data))
 await connected(f); f.m.focus('old'); const job = f.m.reconcile(); await flush(); f.m.focus('new'); release(history('conversations/bootstrap')); await job
 assert.equal(f.m.cache.has('old'), false); f.m.setForeground(false)
})
test('resync clears the old window and a new bootstrap recreates it', async () => {
 let stale = true
 const f = fixture((path, data) => { if (path === 'messages/sync' && stale) throw Object.assign(Error('expired'), { code: 'CHAT_RESYNC_REQUIRED' }); return history(path, data) })
 await connected(f); f.m.focus('A'); await assert.rejects(f.m.reconcile()); assert.equal(f.m.cache.size, 0); assert.equal(f.events.some(e => e[0] === 'conversation.clear'), true)
 stale = false; await f.m.reconcile(); assert.equal(f.m.cache.has('A'), true); f.m.setForeground(false)
})
test('account pagination continues bounded batches and background performs no reconciliation', async () => {
 let pages = 0
 const f = fixture((path) => { if (path === 'auth/socket-ticket') return { ticket: 'ticket' }; pages++; return { items: [], has_more: pages < 5, next_cursor: pages < 5 ? 'page-' + pages : '' } })
 await connected(f); await f.m.reconcile(); assert.equal(pages, 3); assert.equal(f.m.digestCursor, 'page-3'); await f.m.reconcile(); assert.equal(pages, 5); assert.equal(f.m.digestCursor, '')
 f.m.setForeground(false); await f.m.reconcile(); assert.equal(pages, 5)
})
test('network restoration creates a new socket, and typing uses only the current socket with local throttle', async () => {
 const f = fixture(); await connected(f); f.m.focus('A'); const first = f.m.typing(true); assert.equal(await f.m.typing(true), false)
 const task = f.sockets[0]; const sent = task.sent.at(-1); task.receive('typing.result', { active: true }, sent.request_id); await first
 f.m.setNetwork(false); assert.equal(f.timers.size, 0); f.m.setNetwork(true); await flush(); assert.equal(f.sockets.length, 2); f.m.setForeground(false)
})
test('OrderApi cannot save a late login token after identity invalidation', async () => {
 const storage = new Map(); const requests = []; const logins = []; let resets = 0
 const context = { key: 'production', revision: 0, baseURL: 'https://example.invalid/api/' }
 const apiEnv = { onChange() {}, capture: () => context, ready: async () => {}, assertCurrent() {}, storageKey: k => k }
 const sandbox = { module: { exports: {} }, Set, Promise, console, setTimeout, require(path) { if (path.includes('ApiEnvironment')) return apiEnv; if (path.includes('config')) return { APICONFIG: { VERSION: '2.7.0' } }; if (path.includes('NotificationState')) return { reset() {} }; if (path.includes('ChatRuntime')) return { reset() { resets++ } }; throw Error(path) }, wx: { getStorageSync: k => storage.get(k), setStorageSync: (k, v) => storage.set(k, v), removeStorageSync: k => storage.delete(k), login: opts => logins.push(opts), request: opts => { requests.push(opts); return {} } } }
 vm.runInNewContext(fs.readFileSync(require.resolve('../miniprogram/utils/OrderApi.js'), 'utf8'), sandbox)
 const api = sandbox.module.exports; const job = api.login(false); await flush(); api.invalidateSession(); logins[0].success({ code: 'test-code' }); await flush(); requests[0].success({ statusCode: 200, data: { ok: true, data: { token: 'old-token' } } }); await assert.rejects(job, e => e.code === 'CHAT_CONTEXT_CHANGED'); assert.equal(storage.size, 0); assert.equal(resets, 1)
})

test('S13 drafts expire in memory, keep five conversations and reject stale replacement', () => {
 const f=fixture(); f.m.saveDraft('1','old'); const revision=f.m.draft('1').revision
 f.m.saveDraft('1','new'); assert.equal(f.m.saveDraft('1','replacement',revision),false); assert.equal(f.m.draft('1').text,'new')
 for(let n=2;n<=6;n++)f.m.saveDraft(String(n),'draft-'+n)
 assert.equal(f.m.drafts.size,5); assert.equal(f.m.draft('1').text,'')
 f.m.saveDraft('6','🙂'.repeat(2001)); assert.equal(Array.from(f.m.draft('6').text).length,2000)
 f.advance(30*60*1000); assert.equal(f.m.draft('6').text,''); assert.equal(f.m.drafts.size,0)
 f.m.saveDraft('1','private'); f.m.reset(); assert.equal(f.m.drafts.size,0); assert.equal(f.m.outbox.size,0)
})
test('S13 text validation matches Go UTF-8 limits without altering draft text', () => {
 const {validText}=require('../miniprogram/utils/ChatManager.js')
 assert.equal(validText(' \n\t'),false);assert.equal(validText('a\0b'),false);assert.equal(validText('\ud800'),false)
 assert.equal(validText('🙂'.repeat(1000)),true);assert.equal(validText('🙂'.repeat(1001)),false)
 assert.equal(validText('汉'.repeat(1333)+'a'),true);assert.equal(validText('汉'.repeat(1334)),false)
 assert.equal(validText('a'.repeat(2001)),false);assert.equal(validText('尺寸2.5米🙂\n第二行'),true)
 const f=fixture();f.m.saveDraft('1','汉'.repeat(2000));assert.equal(f.m.draft('1').text.length,2000)
})
function focused(f,id='9'){
 f.m.enabled=true;f.m.foreground=true;f.m.active=id;f.m.focusEpoch=1
 f.m.cache.set(id,{messages:new Map(),policy:'1',canSend:true,sync:'sync-100',changes:'change-100',read:{cursor:'sync-100',seq:'100'}})
}
test('S13 unknown HTTP send first queries result; retry preserves original ID and exact bytes',async()=>{
 let phase='first', release
 const f=fixture((path,data)=>{
  if(path==='messages/send'){
   if(phase==='first')return new Promise((resolve,reject)=>{release=()=>reject(Object.assign(Error('timeout'),{code:'CHAT_NETWORK_ERROR'}))})
   return {client_message_id:data.client_message_id,state:'accepted',message_id:'42',message_state:'published'}
  }
  if(path==='messages/result'){
   if(phase==='query-down')throw Object.assign(Error('down'),{code:'CHAT_NETWORK_ERROR'})
   throw Object.assign(Error('absent'),{code:'CHAT_SEND_RESULT_UNKNOWN'})
  }
  throw Error(path)
 })
 focused(f);f.advance(1700000000000);f.m.saveDraft('9','文字🙂\n  保留空格 ');const old=f.m.draft('9')
 const job=f.m.sendText('9',old.text,old.revision);await flush();assert.equal(f.m.draft('9').text,'')
 f.m.saveDraft('9','发送期间新草稿');release();await job
 const row=f.m.attempts('9')[0];assert.equal(row.state,'unknown');assert.match(row.client_message_id,/^[1-9][0-9]{9}\.[a-f0-9]{32}$/)
 phase='query-down';await f.m.retryText('9',row.client_message_id);assert.equal(f.calls.filter(c=>c[0]==='messages/send').length,1)
 phase='retry';await f.m.retryText('9',row.client_message_id)
 const sends=f.calls.filter(c=>c[0]==='messages/send');assert.equal(sends.length,2);assert.deepEqual(sends[0][1],sends[1][1])
 assert.equal(f.m.attempts('9')[0].state,'accepted');assert.equal(f.m.attempts('9')[0].text,'');assert.equal(f.m.draft('9').text,'发送期间新草稿')
 assert.equal(f.calls.some(c=>c[0]==='conversations/read'),false);f.m.setForeground(false)
})
test('S13 checking/rejected results do not resend; retryable retries only after query',async()=>{
 let state='checking', sends=0
 const f=fixture((path,data)=>{if(path==='messages/send'){sends++;return {state,client_message_id:data.client_message_id}}
 if(path==='messages/result')return {state,client_message_id:data.client_message_id};throw Error(path)})
 focused(f);await f.m.sendText('9','hello',0);const id=f.m.attempts('9')[0].client_message_id
 await f.m.retryText('9',id);assert.equal(sends,1)
 f.advance(10000);await f.m.retryText('9',id);assert.equal(sends,2,'expired checking lease can be reclaimed with the original ID')
 state='retryable';await f.m.retryText('9',id);assert.equal(sends,3)
 state='rejected';await f.m.retryText('9',id);assert.equal(f.m.attempts('9')[0].state,'rejected')
 await f.m.retryText('9',id);assert.equal(sends,3);f.m.setForeground(false)
})
test('S13 a completed read target is frozen before later sync and history never advances it',async()=>{
 const f=fixture((path,data)=>{
  if(path==='conversations/read')return '100'
  if(path==='messages/history'||path==='messages/locate')return {messages:[message('20')],policy_version:'1',sync_cursor:'not-a-read-proof',history_cursor:'before-20'}
  throw Error(path)
 })
 focused(f);const target=f.m.readTarget('9')
 await f.m.window('9','history.request','old-cursor');await f.m.window('9','message.locate','20')
 assert.deepEqual(f.m.readTarget('9'),target);assert.equal(f.calls.some(c=>c[0]==='conversations/read'),false)
 f.m.cache.get('9').read={cursor:'sync-103',seq:'103'}
 await f.m.acknowledge('9',target);assert.equal(f.calls.find(c=>c[0]==='conversations/read')[1].cursor,'sync-100');f.m.setForeground(false)
})
test('S13 partial sync disables read target until every page completes',async()=>{
 let seq=100
 const f=fixture((path,data)=>{
  if(path==='messages/sync'){seq++;return {messages:[message(String(seq))],policy_version:'1',sync_cursor:'sync-'+seq,snapshot_max_seq:'105',has_more:seq<105}}
  if(path==='messages/changes')return {changes:[],policy_version:'1',change_cursor:'change-100',has_more:false}
  throw Error(path)
 })
 focused(f);await f.m.recoverConversation('9',f.m.epoch,f.m.focusEpoch);assert.equal(seq,104);assert.equal(f.m.readTarget('9'),null)
 await f.m.recoverConversation('9',f.m.epoch,f.m.focusEpoch);assert.equal(f.m.readTarget('9').seq,'105');f.m.setForeground(false)
})
test('S13 delayed windows are rejected after focus or message-state changes',async()=>{
 let release
 const f=fixture(()=>new Promise(resolve=>{release=resolve}));focused(f)
 const first=f.m.window('9','message.locate','20');await flush();f.m.focus('10');release({policy_version:'1',messages:[message('20')]})
 await assert.rejects(first,e=>e.code==='CHAT_CONTEXT_CHANGED');assert.equal(f.events.some(e=>e[0]==='conversation.page'),false)
 focused(f);const second=f.m.window('9','history.request','cursor');await flush();f.m.cache.get('9').changes='change-101';release({policy_version:'1',messages:[message('20')]})
 await assert.rejects(second,e=>e.code==='CHAT_WINDOW_CHANGED');f.m.setForeground(false)
})
test('S13 revocation erases draft/outbox and logout cannot be repopulated by late send',async()=>{
 let release
 const f=fixture((path)=>{if(path==='messages/send')return new Promise(resolve=>{release=resolve});throw Object.assign(Error('denied'),{code:'CHAT_PERMISSION_DENIED'})})
 focused(f);f.m.saveDraft('9','private');const job=f.m.sendText('9','private',f.m.draft('9').revision);await flush()
 f.m.reset();f.m.setForeground(false);release({client_message_id:'ignored',state:'accepted'});await job
 assert.equal(f.m.outbox.size,0);assert.equal(f.m.drafts.size,0);assert.equal(f.events.filter(e=>e[0]==='composer').length,1)
 focused(f);f.m.saveDraft('9','private');f.m.outbox.set('old',{conversation_id:'9'})
 await assert.rejects(f.m.unread('9'));assert.equal(f.m.drafts.size,0);assert.equal(f.m.outbox.size,0);assert.equal(f.m.cache.has('9'),false)
 f.m.setForeground(false)
})

test('S13 unresolved sends have a hard memory bound and expire without creating replacement IDs',async()=>{
 const f=fixture(()=>{throw Object.assign(Error('offline'),{code:'CHAT_NETWORK_ERROR'})});focused(f)
 for(let n=0;n<20;n++)await f.m.sendText('9','attempt-'+n,0)
 assert.equal(f.m.outbox.size,20);assert.equal(new Set(f.m.attempts('9').map(r=>r.client_message_id)).size,20)
 f.m.saveDraft('9','keep pending draft')
 await assert.rejects(f.m.sendText('9','keep pending draft',f.m.draft('9').revision),e=>e.code==='CHAT_RESOURCE_BUSY')
 assert.equal(f.m.draft('9').text,'keep pending draft');assert.equal(f.calls.filter(c=>c[0]==='messages/send').length,20)
 f.advance(30*60*1000);assert.equal(f.m.attempts('9').length,0);assert.equal(f.calls.filter(c=>c[0]==='messages/send').length,20)
 f.m.setForeground(false)
})

test('S13 an unavailable locate target is distinct from confirmed conversation revocation',async()=>{
 let version='1',revoked=false
 const f=fixture(path=>{if(path==='conversations/unread'&&!revoked)return {policy_version:version};throw Object.assign(Error('denied'),{code:'CHAT_PERMISSION_DENIED'})})
 focused(f);f.m.saveDraft('9','keep draft')
 await assert.rejects(f.m.window('9','message.locate','20'),e=>e.code==='CHAT_MESSAGE_UNAVAILABLE')
 assert.equal(f.m.cache.has('9'),true);assert.equal(f.m.draft('9').text,'keep draft')
 version='2';await assert.rejects(f.m.window('9','message.locate','20'),e=>e.code==='CHAT_RESYNC_REQUIRED')
 assert.equal(f.m.cache.has('9'),false);assert.equal(f.m.draft('9').text,'')
 focused(f);revoked=true
 await assert.rejects(f.m.window('9','message.locate','20'),e=>e.code==='CHAT_PERMISSION_DENIED');assert.equal(f.m.cache.has('9'),false)
 f.m.setForeground(false)
})
test('S14 preference writes need no focused conversation and keep an immutable field CAS', async () => {
 let release;const pending=new Promise(resolve=>{release=resolve})
 const f=fixture((path,data)=>path==='auth/socket-ticket'?{ticket:'ticket'}:path==='conversations/preference'?pending:{items:[],has_more:false,badge_count:0})
 await connected(f)
 const member='a'.repeat(64),snapshot={member_version:member,pinned:false,pinned_version:'7',later:true,later_version:'19'}
 const job=f.m.updatePreference('9',snapshot,'pinned',true);await flush()
 snapshot.pinned_version='88';snapshot.later=false
 const sent=f.calls.find(c=>c[0]==='conversations/preference')[1]
 assert.deepEqual(sent,{conversation_id:'9',member_version:member,field:'pinned',value:true,expected_version:'7'})
 await assert.rejects(f.m.updatePreference('9',snapshot,'later',false),e=>e.code==='CHAT_RESOURCE_BUSY')
 release({member_version:member,pinned:true,pinned_version:'8',later:true,later_version:'19'})
 await job;assert.equal(f.m.active,'');assert.equal(f.m.preferenceJobs.size,0)
 assert.equal(f.events.filter(e=>e[0]==='preference').length,1)
})
test('S14 ambiguous preference response only probes original result; conflict never retries a newer CAS', async () => {
 const member='b'.repeat(64),snapshot={member_version:member,pinned:false,pinned_version:'4',later:false,later_version:'0'}
 let mode='confirmed'
 const f=fixture((path)=>{
  if(path==='auth/socket-ticket')return {ticket:'ticket'}
  if(path==='conversations/preference')throw Object.assign(Error('lost'),{code:mode==='conflict'?'CHAT_PREFERENCE_CONFLICT':'CHAT_NETWORK_ERROR'})
  if(path==='conversations/preferences')return {member_version:member,pinned:true,pinned_version:mode==='confirmed'?'5':'7',later:false,later_version:'0'}
  return {items:[],has_more:false,badge_count:0}
 })
 await connected(f);await f.m.updatePreference('9',snapshot,'pinned',true)
 assert.equal(f.calls.filter(c=>c[0]==='conversations/preference').length,1)
 assert.equal(f.calls.filter(c=>c[0]==='conversations/preferences').length,1)
 mode='aba';await assert.rejects(f.m.updatePreference('9',snapshot,'pinned',true),e=>e.code==='CHAT_PREFERENCE_UNKNOWN')
 const probes=f.calls.filter(c=>c[0]==='conversations/preferences').length
 mode='conflict';await assert.rejects(f.m.updatePreference('9',snapshot,'pinned',true),e=>e.code==='CHAT_PREFERENCE_CONFLICT')
 assert.equal(f.calls.filter(c=>c[0]==='conversations/preferences').length,probes)
 assert(f.calls.filter(c=>c[0]==='conversations/preference').every(c=>c[1].expected_version==='4'))
})
test('S14 account refresh generation rejects late pre-mutation pages and only final totals are published', async () => {
 let release;const wait=new Promise(resolve=>{release=resolve});let hold=true
 const f=fixture(path=>path==='auth/socket-ticket'?{ticket:'ticket'}:hold?wait:{items:[],has_more:false,badge_count:126})
 await connected(f)
 const pending=f.m.reconcile();await flush();f.m.refreshAccount()
 release({items:[{conversation_id:'9'}],has_more:false,badge_count:99});await pending
 assert.equal(f.events.filter(e=>e[0]==='digests'||e[0]==='account.summary').length,0)
 hold=false;await f.m.reconcile()
 assert.equal(f.events.filter(e=>e[0]==='account.summary').at(-1)[1].badge_count,126)
 // Even a synchronous digest subscriber may invalidate this scan.
 const stop=f.m.subscribe(type=>{if(type==='digests')f.m.refreshAccount()})
 const count=f.events.filter(e=>e[0]==='account.summary').length
 await f.m.reconcile();assert.equal(f.events.filter(e=>e[0]==='account.summary').length,count);stop()
})
test('S14 identity reset rejects an in-flight preference write and cannot repopulate the next account', async () => {
 let release;const wait=new Promise(resolve=>{release=resolve})
 const f=fixture(path=>path==='auth/socket-ticket'?{ticket:'ticket'}:path==='conversations/preference'?wait:{items:[],has_more:false,badge_count:0})
 await connected(f);const job=f.m.updatePreference('9',{member_version:'c'.repeat(64),later_version:'0'},'later',true)
 await flush();f.m.reset('logout');release({member_version:'c'.repeat(64),later:true,later_version:'1'})
 await assert.rejects(job,e=>e.code==='CHAT_CONTEXT_CHANGED')
 assert.equal(f.m.preferenceJobs.size,0);assert.equal(f.events.filter(e=>e[0]==='preference').length,0)
})

test('S15 reply/reedit metadata is immutable across unknown sends and every new edit gets a new action ID',async()=>{
 let phase='lost'
 const f=fixture((path,data)=>{
  if(path==='messages/send'){if(phase==='lost')throw Object.assign(Error('lost'),{code:'CHAT_NETWORK_ERROR'});return {client_message_id:data.client_message_id,state:'accepted',message_id:'50'}}
  if(path==='messages/result')throw Object.assign(Error('unknown'),{code:'CHAT_SEND_RESULT_UNKNOWN'})
  throw Error(path)
 })
 focused(f);f.advance(1700000000000)
 f.m.saveDraft('9','修改文字',0,{reply_to_message_id:'7',edited_from_recalled_message_id:'8'})
 const draft=f.m.draft('9');await f.m.sendText('9',draft.text,draft.revision)
 const row=f.m.attempts('9')[0],first=f.calls.find(c=>c[0]==='messages/send')[1]
 row.source.reply_to_message_id='999'
 assert.equal(f.m.draft('9').source,undefined,'sent draft clears both text and source')
 phase='accepted';await f.m.retryText('9',row.client_message_id)
 assert.deepEqual(f.calls.filter(c=>c[0]==='messages/send')[1][1],first,'retry cannot retarget the original action')
 f.m.saveDraft('9','再次修改',0,{edited_from_recalled_message_id:'8'})
 const second=f.m.draft('9');await f.m.sendText('9',second.text,second.revision)
 assert.notEqual(f.calls.filter(c=>c[0]==='messages/send').at(-1)[1].client_message_id,row.client_message_id)
 assert.equal(f.calls.some(c=>c[0]==='conversations/read'),false)
})
test('S15 recalled targets clear cached quote bodies; old parent versions cannot restore them',()=>{
 const {mergeMessage}=require('../miniprogram/utils/ChatManager.js')
 const f=fixture();focused(f);const model=f.m.cache.get('9');model.conversationId='9';model.viewerID='1'
 const original={message_id:'7',seq:'1',sender_id:'1',message_type:'text',text:'本机原文',state:'published',state_version:'1'}
 const reply={message_id:'8',seq:'2',sender_id:'2',message_type:'text',text:'回复',state:'published',state_version:'1',reply:{message_id:'7',state:'published',state_version:'1',text:'本机原文'}}
 f.m.merge(model,{messages:[original,reply]})
 f.m.merge(model,{messages:[{...original,state:'recalled',state_version:'2',text:undefined}]})
 assert.equal(model.messages.get('8').reply.state,'recalled');assert.equal(model.messages.get('8').reply.text,undefined)
 f.m.merge(model,{messages:[reply]})
 assert.equal(model.messages.get('8').reply.state_version,'2');assert.equal(model.messages.get('8').reply.text,undefined)
 assert.equal(f.m.editableRecall('9','7').text,'本机原文')
 const source=mergeMessage(null,{...reply,edited_from:{message_id:'7',state:'recalled',text:'must never project'}})
 assert.equal(source.edited_from.text,undefined)
 assert.equal(mergeMessage(reply,{...reply,state:'blocked',state_version:'3'}).reply,undefined)
 f.m.forgetConversation('9');assert.equal(f.m.editableRecall('9','7'),null)
})
test('S15 uncertain recall probes a safe current window, preserves seq/read, and never fetches original text',async()=>{
 const original={message_id:'7',seq:'1',sender_id:'1',message_type:'text',text:'仅本机缓存',state:'published',state_version:'1'}
 const f=fixture(path=>{
  if(path==='messages/recall')throw Object.assign(Error('lost'),{code:'CHAT_NETWORK_ERROR'})
  if(path==='messages/locate')return {messages:[{...original,state:'recalled',state_version:'2',text:undefined}],policy_version:'1'}
  throw Error(path)
 })
 focused(f);const model=f.m.cache.get('9');Object.assign(model,{conversationId:'9',viewerID:'1',metadata:{viewer_actor_id:'1',policy_version:'1',can_send:true}});model.messages.set('7',original)
 await f.m.recall('9','7')
 assert.equal(f.m.editableRecall('9','7').text,'仅本机缓存');assert.equal(model.messages.get('7').text,undefined)
 assert.equal(model.messages.get('7').seq,'1');assert.equal(model.read.cursor,'sync-100')
 assert.deepEqual(f.calls.filter(c=>c[0]!=='credential').map(c=>c[0]),['messages/recall','messages/locate'])
 for(let n=8;n<30;n++)f.m.rememberRecall('9',String(n),'本机'+n)
 assert.equal(f.m.recalled.size,20);f.advance(1800001);assert.equal(f.m.editableRecall('9','29'),null)
 f.m.rememberRecall('9','40','隐私');f.m.reset();assert.equal(f.m.recalled.size,0)
})
test('S15 focus or resync while recall is in flight cannot restore old cache or source text',async()=>{
 let release
 const f=fixture(path=>path==='messages/recall'?new Promise(resolve=>{release=resolve}):{})
 focused(f);const model=f.m.cache.get('9');Object.assign(model,{conversationId:'9',viewerID:'1'});model.messages.set('7',{message_id:'7',seq:'1',sender_id:'1',text:'old',state:'published',state_version:'1'})
 const job=f.m.recall('9','7');await flush();f.m.cache.delete('9')
 release({message_id:'7',seq:'1',sender_id:'1',state:'recalled',state_version:'2'})
 await assert.rejects(job,e=>e.code==='CHAT_CONTEXT_CHANGED')
 assert.equal(f.m.recalled.size,0)
})

test('S15 an invalid source is a rejected action, refreshes state and never retries blindly',async()=>{
 const f=fixture(path=>{if(path==='messages/send')throw Object.assign(Error('source'),{code:'CHAT_REFERENCE_UNAVAILABLE'});throw Error(path)})
 focused(f);f.m.saveDraft('9','新的回复',0,{reply_to_message_id:'7'})
 const draft=f.m.draft('9');await f.m.sendText('9',draft.text,draft.revision)
 const row=f.m.attempts('9')[0];assert.equal(row.state,'rejected');assert.equal(row.code,'CHAT_REFERENCE_UNAVAILABLE')
 assert([...f.timers.values()].some(t=>t.delay===0),'source invalidation prompts bounded reconciliation')
 await f.m.retryText('9',row.client_message_id);assert.equal(f.calls.filter(c=>c[0]==='messages/send').length,1)
})

function imageFixture(handler){
 const f=fixture(handler);f.advance(1700000000000)
 f.m.enabled=true;f.m.foreground=true;f.m.active='9';f.m.focusEpoch=1
 f.m.cache.set('9',{conversationId:'9',canSend:true,messages:new Map()})
 return f
}
test('S16 selected uploads never send; failed item alone retries; revision freezes ordered manifest',async()=>{
 let uploaded=0,secondFails=true,allocation=0;const sends=[]
 const f=imageFixture((path,data)=>{
  if(path==='media/upload')return {attachment_id:String(++allocation),object_version:'a'.repeat(64),key:'key'+allocation,token:'token',host:'https://up.fixture.invalid',state:'uploading'}
  if(path==='media/uploaded')return {state:'queued'}
  if(path==='messages/send'){sends.push(data);return {client_message_id:data.client_message_id,state:'checking'}}
  return {items:[],has_more:false}
 })
 f.io.upload=async(grant,path)=>{uploaded++;if(path==='two'&&secondFails)throw Object.assign(new Error(),{code:'CHAT_UPLOAD_FAILED'})}
 const id=f.m.images.stage('9',[{path:'one',size:100},{path:'two',size:200}]);await flush()
 let row=f.m.images.rows.get(id)
 assert.equal(uploaded,2);assert.equal(row.items[1].state,'upload_failed');assert.equal(sends.length,0)
 await assert.rejects(f.m.images.send('9',id),e=>e.code==='CHAT_IMAGE_NOT_READY')
 secondFails=false;await f.m.images.retry('9',id,1);assert.equal(uploaded,3,'successful first item is never uploaded twice')
 const next=await f.m.images.revise('9',id,items=>items.reverse());assert.notEqual(next,id)
 await f.m.images.send('9',next,{reply_to_message_id:'5',reply_to_item_id:'2'})
 assert.equal(sends.length,1);assert.equal(sends[0].message_type,'image_album');assert.equal(sends[0].revision,2)
 assert.equal(sends[0].items[0].ordinal,0);assert.equal(sends[0].items[0].attachment_id,'3');assert.equal(sends[0].reply_to_item_id,'2')
 assert.equal(sends[0].text,undefined);assert.equal(row.items[0].path,'one')
 f.m.reset()
})
test('S17 unknown cancellation must resolve original commit before a new selection revision',async()=>{
 let cancellation='accepted',calls=0
 const f=imageFixture((path,data)=>{
  if(path==='media/upload')return {attachment_id:String(++calls),object_version:'a'.repeat(64),state:'processed'}
  if(path==='media/uploaded')return {state:'processed'}
  if(path==='messages/send')throw Object.assign(new Error(),{code:'CHAT_NETWORK_ERROR'})
  if(path==='media/cancel')return {client_message_id:data.client_message_id,state:cancellation,message_id:cancellation==='accepted'?'44':undefined}
  return {items:[],has_more:false}
 })
 const client=f.m.images.stage('9',[{path:'one',size:20},{path:'two',size:20}]);await flush();await f.m.images.send('9',client)
 assert.equal(f.m.images.rows.get(client).state,'unknown')
 const next=await f.m.images.revise('9',client,items=>items.pop());assert.equal(next,null);assert.equal(f.m.images.rows.size,1)
 assert.equal(f.m.images.rows.get(client).state,'accepted','late committed result forbids an automatic remaining-image version')
 const second=f.m.images.stage('9',[{path:'three',size:20},{path:'four',size:20}]);await flush();await f.m.images.send('9',second)
 cancellation='canceled';const revised=await f.m.images.revise('9',second,items=>items.pop());assert(revised);assert.equal(f.m.images.rows.get(revised).items.length,1);assert.equal(f.m.images.rows.get(revised).submitted,false)
 f.m.reset()
})
test('S16 signed image URLs reauthorize after TTL and cannot survive recall or identity reset',async()=>{
 let resolveURL,urlCalls=0
 const f=imageFixture((path,data)=>{
  if(path==='media/url'){urlCalls++;return new Promise(resolve=>{resolveURL=resolve})}
  return {items:[],has_more:false}
 })
 const view={message_id:'7',seq:'7',message_type:'image',state:'published',state_version:'1',images:[{item_id:'3',ordinal:0}]}
 const model=f.m.cache.get('9');model.messages.set('7',view)
 const pending=f.m.images.url('9','7','3',false);await flush()
 model.messages.set('7',{...view,state:'recalled',state_version:'2',images:undefined})
 resolveURL({url:'https://private.fixture.invalid/one',expires_at:f.io.now()/1000+60})
 await assert.rejects(pending,e=>e.code==='CHAT_MEDIA_UNAVAILABLE')
 model.messages.set('7',view)
 const valid=f.m.images.url('9','7','3',true);await flush();resolveURL({url:'https://private.fixture.invalid/thumb',expires_at:f.io.now()/1000+60});await valid
 await f.m.images.url('9','7','3',true);assert.equal(urlCalls,2)
 f.advance(51000);const expired=f.m.images.url('9','7','3',true);await flush();assert.equal(urlCalls,3)
 f.m.reset();resolveURL({url:'https://private.fixture.invalid/thumb',expires_at:f.io.now()/1000+60});await assert.rejects(expired)
 assert.equal(f.m.images.urls.size,0);assert.equal(f.m.images.rows.size,0)
})
test('S17 a late published album snapshot cannot restore recalled attachments',()=>{
 const {mergeMessage}=require('../miniprogram/utils/ChatManager.js')
 const old={message_id:'7',message_type:'image_album',state:'published',state_version:'1',images:[{item_id:'3'}]}
 const hidden=mergeMessage(old,{...old,state:'recalled',state_version:'2'})
 assert.equal(hidden.images,undefined);assert.equal(mergeMessage(hidden,old).images,undefined)
})

test('S16 bounded historical windows can request one image; explicit media denial clears authority',async()=>{
 let denial=false,calls=0
 const f=imageFixture((path,data)=>{
  if(path==='media/url'){calls++;if(denial)throw Object.assign(new Error(),{code:'CHAT_PERMISSION_DENIED'});return {url:'https://private.fixture.invalid/history',expires_at:f.io.now()/1000+60}}
  return {items:[],has_more:false}
 })
 f.m.images.setVisible('9',[{message_id:'2',seq:'2',message_type:'image',state:'published',state_version:'1',images:[{item_id:'3'}]}])
 assert.equal(f.m.cache.get('9').messages.has('2'),false)
 await f.m.images.url('9','2','3',false);assert.equal(calls,1)
 denial=true;f.advance(51000)
 await assert.rejects(f.m.images.url('9','2','3',false),e=>e.code==='CHAT_PERMISSION_DENIED')
 assert.equal(f.m.cache.has('9'),false);assert.equal(f.m.images.urls.size,0);assert.equal(f.m.images.visibleViews.size,0)
 assert(f.events.some(v=>v[0]==='conversation.clear'))
 f.m.reset()
})

test('S16 local selected paths expire even while server-confirmed review continues',async()=>{
 const f=imageFixture((path,data)=>{
  if(path==='media/upload')return {attachment_id:'3',object_version:'a'.repeat(64),state:'processed'}
  if(path==='media/uploaded')return {state:'processed'}
  if(path==='messages/send')return {client_message_id:data.client_message_id,state:'review_pending'}
  return {items:[],has_more:false}
 })
 const client=f.m.images.stage('9',[{path:'temporary-original',size:20}]);await flush();await f.m.images.send('9',client)
 f.advance(31*60*1000);const stillPending=f.m.images.list('9')[0];assert.equal(stillPending.items[0].path,undefined);assert.equal(stillPending.state,'review_pending','local path expiry never silently cancels confirmed server intent')
 f.m.images.settle(f.m.images.rows.get(client),{client_message_id:client,state:'rejected'})
 f.advance(31*60*1000);assert.equal(f.m.images.list('9').length,0,'terminal action memory is bounded')
 f.m.reset()
})

test('S16 admin prereview page requires every full image and both safety confirmations',async()=>{
 const nodes=new Map(),calls=[],timers=new Map();let serial=0
 const make=()=>({hidden:false,checked:false,value:'',textContent:'',style:{},children:[],dataset:{},append(...v){this.children.push(...v)},replaceChildren(...v){this.children=v},removeAttribute(name){delete this[name]}})
 const node=id=>{if(!nodes.has(id))nodes.set(id,make());return nodes.get(id)}
 node('chat-image-review').dataset={credential:'/admin/?route=chat.credentials',csrf:'fixture-csrf'}
 const response=data=>({ok:true,status:200,async json(){return {ok:true,data}}})
 const fetch=async(url,options)=>{
  const body=JSON.parse(options.body);calls.push({url,body,headers:options.headers})
  if(url.includes('chat.credentials'))return response({access_token:'in-memory-only',expires_at:new Date(Date.now()+300000).toISOString()})
  if(url.endsWith('review/list'))return response([{intent_id:'8',expected_version:'1',submitted_at:new Date().toISOString(),items:[{attachment_id:'1'},{attachment_id:'2'}]}])
  if(url.endsWith('review/decide'))return response({intent_id:'8',expected_version:'2',review_state:body.action==='claim'?'claimed':'approved'})
  if(url.endsWith('review/url'))return response({url:'https://private.fixture.invalid/'+body.item_id,expires_at:Date.now()/1000+60})
  throw Error(url)
 }
 vm.runInNewContext(fs.readFileSync(require('path').join(__dirname,'../server/assets/chat-image-review.js'),'utf8'),{
  document:{getElementById:node,createElement:make},fetch,URL,Date,Set,Error,addEventListener(){},
  setTimeout(fn,delay){const id=++serial;timers.set(id,{fn,delay});return id},clearTimeout(id){timers.delete(id)}
 })
 await flush()
 const row=node('image-review-list').children[0];await row.children[1].onclick()
 node('image-review-reason').value='完整检查通过';node('image-review-general').checked=true;node('image-review-group').checked=true
 await node('image-review-approve').onclick()
 assert.equal(calls.filter(v=>v.body.action==='approve').length,0)
 await node('image-review-items').children[0].onclick();node('image-review-photo').onload()
 await node('image-review-approve').onclick();assert.equal(calls.filter(v=>v.body.action==='approve').length,0)
 await node('image-review-items').children[1].onclick();node('image-review-photo').onload()
 await node('image-review-approve').onclick()
 const approved=calls.find(v=>v.body.action==='approve');assert(approved);assert.equal(approved.body.expected_version,'2');assert.equal(approved.body.group_confirmed,true)
 assert.equal(node('image-review-photo').src,undefined,'concluded task clears its URL');assert.equal(node('image-review-detail').hidden,true)
 assert.equal(calls[0].headers['X-CSRF-Token'],'fixture-csrf')
})

const {ChatCache}=require('../miniprogram/utils/ChatCache.js')
function cacheFixture(){
 const data=new Map(),directories=new Set(['/cache','/tmp']);let now=1000000,downloads=0,warnings=0,blocked=false
 const io={root:'/cache',now:()=>now,full(){warnings++},
  async mkdir(path){let prefix='';for(const part of path.split('/').filter(Boolean)){prefix+='/'+part;directories.add(prefix)}},
  async names(path){const prefix=path+'/';return [...new Set([...directories,...data.keys()].filter(x=>x.startsWith(prefix)&&!x.slice(prefix.length).includes('/')).map(x=>x.slice(prefix.length)))]},
  async list(path){return (await io.names(path)).map(name=>({name,directory:directories.has(path+'/'+name),size:data.has(path+'/'+name)?(typeof data.get(path+'/'+name)==='string'?Buffer.byteLength(data.get(path+'/'+name)):data.get(path+'/'+name).bytes):0}))},
  async writeJSON(path,value){if(blocked)throw Error('ENOSPC');data.set(path,JSON.stringify(value))},
  async readJSON(path){return JSON.parse(data.get(path))},
  async exists(path){return data.has(path)},async size(path){return data.get(path).bytes},
  async remove(path){data.delete(path)},async removeDirectory(path){directories.delete(path)},
  async persist(from,to){if(blocked){data.set(to,{bytes:2});throw Error('ENOSPC')};data.set(to,data.get(from));data.delete(from)},
  async download(){const path='/tmp/'+(++downloads);data.set(path,{bytes:4096});return path}
 }
 const c=new ChatCache(io);c.bind('test','1')
 const q={conversation_id:'5',message_id:'9',item_id:'8',state_version:'1',kind:'image',variant:'main'}
 const access=(override={})=>({url:'https://private.invalid/media?token=secret',object_hash:'a'.repeat(64),state_version:'1',retention_mode:'permanent',server_time:new Date(now).toISOString(),expires_at:(now+60000)/1000,mime_type:'image/jpeg',...override})
 const message=(message_id,kind='text',extra={})=>({conversation_id:'5',message_id:String(message_id),seq:String(message_id),state_version:'1',message_type:kind,state:'published',retention_mode:'permanent',text:'施工原文',...extra})
 return {c,io,data,directories,q,access,message,downloads:()=>downloads,warnings:()=>warnings,advance(n){now+=n},block(){blocked=true}}
}
test('device cache reauthorizes every hit but downloads one immutable file',async()=>{
 const f=cacheFixture();let auth=0
 const first=await f.c.file(f.q,async()=>{auth++;return f.access()})
 const second=await f.c.file(f.q,async()=>{auth++;return f.access()})
 assert.equal(first,second);assert.equal(auth,2);assert.equal(f.downloads(),1)
 assert.ok(!JSON.stringify([...f.data.values()]).includes('private.invalid'))
 await assert.rejects(()=>f.c.file({...f.q,message_id:'../9'},async()=>f.access()),{code:'CHAT_CACHE_IDENTITY'})
})
test('published permanent cache has no 2000-message or 50-conversation cutoff',async()=>{
 const f=cacheFixture()
 await f.c.remember('5',Array.from({length:2101},(_,i)=>f.message(i+1)))
 for(let i=6;i<60;i++)await f.c.remember(String(i),[f.message(1)])
 const stats=await f.c.inventory()
 assert.equal(stats.items.reduce((n,x)=>n+x.files,0),2155)
 f.advance(400*86400000);await f.c.sweep()
 assert.equal((await f.c.inventory()).items.reduce((n,x)=>n+x.files,0),2155)
})
test('cache only persists safe message fields and manual media cleanup preserves text',async()=>{
 const f=cacheFixture()
 await f.c.remember('5',[f.message(1,'text',{url:'https://secret',access_token:'token',reply:{text:'quoted-secret'}}),f.message(9,'image')])
 await f.c.file(f.q,async()=>f.access())
 const wire=JSON.stringify([...f.data.values()]);assert.ok(!wire.includes('quoted-secret')&&!wire.includes('access_token')&&!wire.includes('https://secret'))
 await f.c.clear({conversation_id:'5',kind:'image'})
 const stats=await f.c.inventory();assert.deepEqual(stats.items.map(x=>x.kind),['text']);assert.equal(stats.items[0].files,1)
 assert.ok([...f.data.keys()].some(x=>x.endsWith('m-1.json')))
})
test('cloud expiry preserves device video cache and stable metadata indefinitely',async()=>{
 const f=cacheFixture(),expires=new Date(f.io.now()+1000).toISOString()
 await f.c.remember('5',[f.message(1),f.message(9,'video',{retention_mode:'days',cloud_expires_at:expires})])
 const cached=await f.c.file({...f.q,kind:'video'},async()=>f.access({retention_mode:'days',cloud_expires_at:expires,cloud_available:true,mime_type:'video/mp4'}))
 f.advance(90*86400000);await f.c.sweep()
 assert.ok([...f.data.keys()].some(x=>x.endsWith('m-1.json')))
 assert.ok([...f.data.keys()].some(x=>x.includes('v-9-')))
 const again=await f.c.file({...f.q,kind:'video'},async()=>f.access({url:'',retention_mode:'days',cloud_expires_at:expires,cloud_available:false,mime_type:'video/mp4'}))
 assert.equal(again,cached);assert.equal(f.downloads(),1)
 await f.c.clear({conversation_id:'5',kind:'video'})
 await assert.rejects(()=>f.c.file({...f.q,kind:'video'},async()=>f.access({url:'',retention_mode:'days',cloud_available:false})),{code:'CHAT_MEDIA_CLOUD_EXPIRED'})
})
test('cache full keeps existing content and never treats a partial copy as a hit',async()=>{
 const f=cacheFixture();await f.c.remember('5',[f.message(1)]);f.block()
 const temporary=await f.c.file(f.q,async()=>f.access())
 assert.ok(temporary.startsWith('/tmp/'));assert.equal(f.warnings()>0,true)
 assert.ok([...f.data.keys()].some(x=>x.endsWith('m-1.json')))
 assert.ok(![...f.data.keys()].some(x=>x.includes('v-9-')))
 f.c.pause();await flush();assert.equal(f.data.has(temporary),false)
})
test('recalled cache and identity reset cannot restore old files',async()=>{
 const f=cacheFixture();await f.c.file(f.q,async()=>f.access());await f.c.revoke('5','9')
 await assert.rejects(()=>f.c.file(f.q,async()=>f.access()),{code:'CHAT_CONTEXT_CHANGED'})
 await f.c.remember('5',[f.message(1)]);await f.c.reset();f.c.bind('test','2')
 assert.equal((await f.c.inventory()).bytes,0);assert.ok(![...f.data.keys()].some(x=>x.includes('/test-1/')))
})
test('hiding during cache download discards the late result before persistence',async()=>{
 const f=cacheFixture();let release
 f.io.download=()=>new Promise(resolve=>{release=()=>{f.data.set('/tmp/late',{bytes:4096});resolve('/tmp/late')}})
 const pending=f.c.file(f.q,async()=>f.access());await flush();assert.equal(typeof release,'function')
 f.c.pause();release();await assert.rejects(()=>pending,{code:'CHAT_CONTEXT_CHANGED'})
 assert.ok(![...f.data.keys()].some(x=>x.includes('v-9-')||x==='/tmp/late'))
})

test('retained video poster and sampled pictures share video cleanup category and never act as the original',async()=>{
 const f=cacheFixture()
 const cover=await f.c.file({...f.q,kind:'video',variant:'poster'},async()=>f.access({mime_type:'image/jpeg'}))
 const frame=await f.c.file({...f.q,kind:'video',variant:'frame-0'},async()=>f.access({mime_type:'image/jpeg'}))
 assert(cover.endsWith('.jpg')&&frame.endsWith('.jpg'))
 const inventory=await f.c.inventory();assert(inventory.items.some(x=>x.kind==='video'));assert(!inventory.items.some(x=>x.kind==='image'))
 await assert.rejects(()=>f.c.file({...f.q,kind:'video',variant:'main'},async()=>f.access({url:'',cloud_available:false})),{code:'CHAT_MEDIA_CLOUD_EXPIRED'})
 await f.c.clear({conversation_id:'5',kind:'video'});assert.equal((await f.c.inventory()).bytes,0)
})
test('cloud text cleanup preserves authorized text already cached on the device',async()=>{
 const f=cacheFixture()
 await f.c.remember('5',[f.message(1)])
 await f.c.remember('5',[f.message(1,'text',{text:null,retention_mode:'days',cloud_expires_at:new Date(1).toISOString()})])
 assert.equal(await f.c.text('5','1'),'施工原文')
 f.advance(900*86400000);await f.c.sweep();assert.equal(await f.c.text('5','1'),'施工原文')
 await f.c.revoke('5','1');assert.equal(await f.c.text('5','1'),null)
})
