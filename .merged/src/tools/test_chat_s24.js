const test=require('node:test')
const assert=require('node:assert/strict')
const vm=require('node:vm')
const fs=require('node:fs')
const Identity=require('../miniprogram/utils/ChatIdentity.js')
const Model=require('../miniprogram/utils/ChatInboxModel.js')
const {ChatCache}=require('../miniprogram/utils/ChatCache.js')
const source=fs.readFileSync(require.resolve('../miniprogram/subpackages/chat/group/index.js'),'utf8')
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve}}
const flush=async()=>{for(let i=0;i<15;i++)await new Promise(r=>setImmediate(r))}
function fixture(){
 let definition,listener
 const calls=[],jobs=[],routes=[]
 const manager={enabled:true,epoch:1,io:{collaboration(action,input){calls.push({action,input});const job=deferred();jobs.push(job);return job.promise}},subscribe(fn){listener=fn;return()=>{}},refreshAccount(){},forgetConversation(){}}
 vm.runInNewContext(source,{Page(p){definition=p},require(name){return name.includes('ChatRuntime')?{get:()=>manager}:name.includes('ChatIdentity')?Identity:{login:async()=>{}}},Promise,console,setTimeout,clearTimeout,wx:{navigateTo:v=>routes.push(v.url),switchTab:v=>routes.push(v.url),showModal(){},getFileSystemManager(){return {unlink(){}}}}})
 const page={data:JSON.parse(JSON.stringify(definition.data)),setData(v){Object.assign(this.data,v)}}
 for(const [k,v]of Object.entries(definition))if(typeof v==='function')page[k]=v.bind(page)
 return {page,manager,calls,jobs,routes,reset(){manager.epoch++;listener('reset')}}
}
function group(){return {title:'订单协作群 · TEST-1',conversation_id:'9',policy_version:'2',viewer_actor_id:'1',state:'open',member_limit:10,can_invite:true,can_leave:false,can_edit_role:false,invite_generation:'0',members:[{actor_id:'1',name:'用户ABC123',role_source:'merchant_owner',display_role:'merchant',member_version:'1',display_version:'1',can_remove:false}],events:[]}}
test('S24 historical self-selected labels stay distinct from certified system identity',()=>{
 const profile=Identity.normalize({actor_id:'4',role:'user',avatar:'user',display_name:'商家 · 用户ABC123',user_suffix:'ABC123',role_source:'invite',display_role:'merchant'})
 assert(profile);assert.equal(profile.role,'user')
 assert.equal(Identity.attribution({role_source:'invite',display_role:'designer',display_role_version:'1'},profile).displayName,'设计师 · 用户ABC123')
 assert.equal(Identity.attribution({role_source:'invite',display_role:'team_worker',display_role_version:'2'},profile).avatarPath,Identity.avatars.worker)
 assert.equal(Identity.normalize({actor_id:'4',role:'user',avatar:'user',display_name:'业主13800138000',user_suffix:'ABC123',role_source:'invite',display_role:'owner'}),null)
 assert.equal(Identity.normalize({actor_id:'4',role:'user',avatar:'https://invalid/x',display_name:'其他 · 用户ABC123',user_suffix:'ABC123',role_source:'invite',display_role:'other'}),null)
})
test('S24 two-person collaboration keeps a group title while ordinary notifications remain unchanged',()=>{
 const entry={version:'1',viewerId:'1',list:[{actorId:'1',displayName:'商家',avatarPath:Identity.avatars.merchant},{actorId:'2',displayName:'师傅',avatarPath:Identity.avatars.worker}]}
 assert.equal(Identity.row({kind:'chat',isGroup:true,policyVersion:'1',title:'项目'},entry).displayTitle,'订单协作群')
 const notification={kind:'notification',title:'普通通知'};assert.equal(Identity.row(notification,entry),notification)
 assert.equal(Model.orderURL(Model.orderContext({public_no:'TEST-1',title:'施工',workflow:'construction'},'collaborator')),'')
})
test('S24 invite entry accepts only opaque server token and hides late preview on background',async()=>{
 const f=fixture();f.page.onLoad({invite:'a'.repeat(32),order_id:'forged',actor_id:'forged'});f.page.onShow()
 assert.equal(f.calls[0].action,'preview');assert.deepEqual({...f.calls[0].input},{token:'a'.repeat(32)})
 f.page.onHide();f.jobs[0].resolve({title:'old account',valid:true});await flush()
 assert.equal(f.page.data.preview,null);assert.equal(f.page.data.group,null)
})
test('S24 role correction uses server display CAS and cannot invent business fields',async()=>{
 const f=fixture();f.page.onLoad({conversation_id:'9'});f.page.onShow()
 f.jobs[0].resolve({...group(),viewer_actor_id:'4',can_edit_role:true,members:[{actor_id:'4',name:'用户ABC123',role_source:'invite',display_role:'designer',member_version:'6',display_version:'3',can_remove:true}]});await flush()
 f.page.chooseRole({detail:{value:0}});const job=f.page.editRole();await flush()
 assert.equal(f.calls[1].action,'role');assert.deepEqual({...f.calls[1].input},{conversation_id:'9',display_role:'merchant',expected_display_version:'3'})
 f.page.onHide();f.jobs[1].resolve(group());await job
 assert.equal(f.page.data.group,null)
})
test('S24 account reset clears roster, poster and prepared sharing and fences stale commands',async()=>{
 const f=fixture();f.page.onLoad({conversation_id:'9'});f.page.onShow();f.jobs[0].resolve(group());await flush()
 const generating=f.page.generate();await flush();assert.equal(f.calls[1].action,'invite')
 f.reset();assert.equal(f.page.data.share,null);assert.equal(f.page.data.poster,'');assert.equal(f.page.data.group,null)
 f.jobs[1].resolve({token:'a'.repeat(32),generation:'1',path:'/old-account'});await generating;await flush()
 assert.equal(f.page.data.share,null)
 f.page.onHide();f.jobs[2]?.resolve(group())
})
test('S24 cache stores only immutable role metadata, never nickname/avatar or invite credentials',()=>{
 const cache=new ChatCache({}),meta=cache.messageMeta('9',{message_id:'1',seq:'1',sender_id:'4',state_version:'1',state:'published',message_type:'text',text:'施工沟通',retention_mode:'permanent',sender:{role_source:'invite',display_role:'designer',display_role_version:'2',nickname:'私人昵称',token:'secret',avatar:'external'}})
 assert.deepEqual(meta.sender,{role_source:'invite',display_role:'designer',display_role_version:'2'});assert.equal(meta.nickname,undefined)
})

test('S24 waits for current membership projection and discards stale cross-device sharing',async()=>{
 const f=fixture();f.page.onLoad({conversation_id:'9'});f.page.onShow()
 f.jobs[0].resolve({...group(),projection_ready:false});await flush()
 const opening=f.page.openChat();await flush();assert.equal(f.routes.length,0)
 f.jobs[1].resolve({...group(),projection_ready:false});await opening
 assert.equal(f.routes.length,0);assert(f.page.data.error.includes('同步'))
 f.page.setData({share:{generation:'1',path:'/stale'},poster:'stale.png'})
 const refreshing=f.page.refresh();await flush();f.jobs[2].resolve({...group(),projection_ready:true,invite_generation:'2'});await refreshing
 assert.equal(f.page.data.share,null);assert.equal(f.page.data.poster,'')
 await f.page.openChat();assert(f.routes[0].includes('conversation_id=9'))
 f.page.onHide()
})

test('S24 identity reset during post-generation refresh cannot restore the old share',async()=>{
 const f=fixture();f.page.onLoad({conversation_id:'9'});f.page.onShow();f.jobs[0].resolve(group());await flush()
 const pending=f.page.generate();await flush();f.jobs[1].resolve({generation:'1',path:'/old-account'});await flush()
 assert.equal(f.calls[2].action,'info');f.reset();f.jobs[2].resolve({...group(),invite_generation:'1'});await pending;await flush()
 assert.equal(f.page.data.share,null);f.page.onHide();f.jobs[3]?.resolve(group())
})
