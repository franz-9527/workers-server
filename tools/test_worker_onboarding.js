'use strict'
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),test=require('node:test')
if(path.basename(path.resolve(__dirname,'..'))!=='workers-server')throw Error('Independent test repository required')
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8')
const flush=async()=>{for(let i=0;i<8;i++)await new Promise(setImmediate)}
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
function fixture(file,options={}){
 let def;const calls=[],modals=[],nav=[],toasts=[],loads=[],scroll=[],writes=[]
 const wx={cloud:{database:()=>({}),callFunction:o=>{calls.push(o);return options.cloud?options.cloud(o):Promise.resolve({result:[]})}},showModal:o=>modals.push(o),showToast:o=>toasts.push(o),showLoading:o=>loads.push(o),hideLoading:()=>loads.push('hide'),redirectTo:o=>nav.push({method:'redirectTo',...o}),navigateTo:o=>nav.push({method:'navigateTo',...o}),switchTab:o=>nav.push({method:'switchTab',...o}),pageScrollTo:o=>scroll.push(o),getWindowInfo:()=>({statusBarHeight:20}),...options.wx}
 const app={globalData:{userInfo:{openid:'admin',mobile:'13800000000'}},ensureUserReady:()=>Promise.resolve({openid:'admin',mobile:'13800000000'}),...options.app}
 vm.runInNewContext(read(file).replace(/^import .*$/gm,''),{Page:d=>def=d,Component:d=>def=d,require:name=>options.require?options.require(name):{},requirePlugin:()=>({getLocation:()=>null}),getApp:()=>app,getCurrentPages:()=>[{route:'pages/mine/mine'}],wx,MAPCONFIG:{MAPKEY:''},genDefDate:()=>'',formatTime:String,console:{log(){},warn(){},error(){}},setTimeout,clearTimeout,Promise})
 const p={...def,...def.methods,data:structuredClone(def.data),setData(d,cb){writes.push(d);Object.assign(p.data,d);if(cb)cb()}}
 return{p,def,wx,app,calls,modals,nav,toasts,loads,scroll,writes}
}
function phone(){
 const f=fixture('miniprogram/pages/record/detail.js');f.p.data.id='worker-1';f.p.data.isOrderAdmin=true;f.p.loadAdminIdentity=()=>Promise.resolve();f.p.loadAdminPrivateDetail=()=>{};f.p.ensurePageLogin=()=>Promise.resolve(true);return f
}
const respond=(o,data)=>o.success({result:{code:1,data}})
test('admin first tap awaits pending policy and reveals on the same operation; repeated tap shares both reads',async()=>{
 const f=phone(),policy=f.p.loadPhoneRevealPolicy(),a=f.p.makeCall2(),b=f.p.makeCall2();assert.equal(a,b);await flush();assert.equal(f.calls.length,1)
 respond(f.calls[0],{role:'admin',can_reveal:true});await policy;await flush();assert.equal(f.calls.length,2);assert.equal(f.calls[1].data.action,'miniAdmin.phoneReveal')
 respond(f.calls[1],{mobile:'13800000000'});await a;assert.equal(f.p.data.phoneRevealDialog.phone,'13800000000');assert.equal(f.p.data.phoneRevealDialog.visible,true);assert.equal(f.p.data.phoneRevealLoading,false);assert.deepEqual(f.loads,[])
})
test('cold account initialization is awaited before sensitive lookup',async()=>{
 const f=phone(),ready=deferred();delete f.p.ensurePageLogin;f.p.ensurePageLogin=f.def.ensurePageLogin;f.app.ensureUserReady=()=>ready.promise
 const a=f.p.makeCall2();await flush();assert.equal(f.calls.length,0)
 ready.resolve({openid:'admin',mobile:'13800000000'});await flush();assert.equal(f.calls.length,1);respond(f.calls[0],{role:'admin',can_reveal:true});await flush();respond(f.calls[1],{mobile:'13800000000'});await a;assert.equal(f.p.data.phoneRevealDialog.visible,true)
})
test('failed policy does not consume a reveal, unlocks and retries on the next explicit tap',async()=>{
 const f=phone(),a=f.p.makeCall2();await flush();f.calls[0].fail(new Error('offline'));await a;assert.equal(f.calls.length,1);assert.equal(f.p.data.phoneRevealLoading,false);assert.match(f.toasts[0].title,/加载失败/)
 const b=f.p.makeCall2();await flush();respond(f.calls[1],{role:'admin',can_reveal:true});await flush();respond(f.calls[2],{mobile:'13800000000'});await b;assert.equal(f.p.data.phoneRevealDialog.visible,true)
})
test('forbidden policy never attempts a privileged read',async()=>{
 const f=phone();f.p.data.phoneRevealPolicy={loaded:true,role:'member',can_reveal:false,reason:'额度不足'};await f.p.makeCall2();assert.equal(f.calls.length,0);assert.equal(f.toasts[0].title,'额度不足')
})
test('member rapid taps issue one member reveal and preserve returned remaining quota',async()=>{
 const f=phone();f.p.data.phoneRevealPolicy={loaded:true,role:'member',can_reveal:true};const a=f.p.makeCall2(),b=f.p.makeCall2();assert.equal(a,b);await flush();assert.equal(f.calls.length,1);assert.equal(f.calls[0].data.action,'member.phoneReveal');respond(f.calls[0],{mobile:'13800000000',remaining_24h:7});await a;assert.equal(f.p.data.phoneRevealPolicy.remaining_24h,7);respond(f.calls[1],{role:'member',can_reveal:true,remaining_24h:7});await flush();assert.equal(f.p.data.phoneRevealPolicy.remaining_24h,7)
})
test('leaving while policy loads suppresses late writes and all reveals',async()=>{
 const f=phone(),a=f.p.makeCall2();await flush();f.p.onUnload();const n=f.writes.length;respond(f.calls[0],{role:'admin',can_reveal:true});await a;assert.equal(f.calls.length,1);assert.equal(f.writes.length,n)
})
test('leaving while reveal loads suppresses late phone dialog',async()=>{
 const f=phone();f.p.data.phoneRevealPolicy={loaded:true,role:'admin',can_reveal:true};const a=f.p.makeCall2();await flush();f.p.onUnload();const n=f.writes.length;respond(f.calls[0],{mobile:'13800000000'});await a;assert.equal(f.p.data.phoneRevealDialog.visible,false);assert.equal(f.writes.length,n)
})
test('reveal error and invalid mobile both unlock without a fake success dialog',async()=>{
 for(const invalid of [false,true]){const f=phone();f.p.data.phoneRevealPolicy={loaded:true,role:'admin',can_reveal:true};const a=f.p.makeCall2();await flush();invalid?respond(f.calls[0],{mobile:''}):f.calls[0].fail(new Error('read failed'));await a;assert.equal(f.p.data.phoneRevealLoading,false);assert.equal(f.p.data.phoneRevealDialog.visible,false);assert.equal(f.modals.length,1)}
})
test('public detail and prompt requests never leave a loading mask across back navigation',()=>{
 const f=phone();f.p.getDetail();f.p.getDetailPrompt();assert.deepEqual(f.loads,[]);assert.equal(f.calls.length,2)
})
function publisher(){return fixture('miniprogram/subpackages/account/workerProfile/index.js',{require:name=>name.includes('OrderApi')?{request:async()=>({data:{}})}:{}})}
test('policy hint uses actual configured period/count and a single review explanation',()=>{
 const f=publisher();f.p.data.primaryRecordId='w';f.p.data.profileEditPolicy={ever_approved:true,limited:true,period_days:45,limit_count:2,can_edit:true};f.p.updateProfileEditHint();assert.equal(f.p.data.profileEditHint,'审核通过后每45天可修改2次，提交后重新审核。')
 f.p.data.profileEditPolicy={ever_approved:false};f.p.data.currentReviewStatus='pending';f.p.updateProfileEditHint();assert.equal(f.p.data.profileEditHint,'资料审核中，补充后保存即可。')
 f.p.data.profileEditPolicy={ever_approved:true,limited:false};f.p.updateProfileEditHint();assert.equal(f.p.data.profileEditHint,'修改后需重新审核。')
})
test('first applicant has no stale edit banner; quota rejection retains the availability date',()=>{
 const f=publisher();f.p.updateProfileEditHint();assert.equal(f.p.data.profileEditHint,'')
 f.p.data.profileEditPolicy={can_edit:false,message:'本周期资料修改次数已用完，请于 2026-12-03 再修改'};f.p.updateProfileEditHint();assert.match(f.p.data.profileEditHint,/2026-12-03/)
})
test('approved resubmit confirmation is concise and cancellation prevents submission',async()=>{
 const f=publisher();f.p.data.profileEditPolicy={ever_approved:true,can_edit:true,message:'重复旧提示'};const a=f.p.confirmProfileEditIfNeeded();assert.equal(f.modals[0].content,'提交后需重新审核，确认保存？');f.modals[0].success({confirm:false});assert.equal(await a,false)
})
test('locked policy cannot be confirmed even if invoked directly',async()=>{
 const f=publisher();f.p.data.profileEditPolicy={ever_approved:true,can_edit:false,message:'暂不能修改'};const a=f.p.confirmProfileEditIfNeeded();assert.equal(f.modals[0].showCancel,false);f.modals[0].success({confirm:true});assert.equal(await a,false)
})
test('legacy WeChat step prompt removed while unrelated publishing notice retained',()=>{
 const f=publisher();f.p.getPlusPrompt();f.calls[0].success({result:[{text:'步骤：师傅发布-加微信'},{text:'师傅发布 → 加微信'},{text:'请提交真实照片'}]});assert.equal(f.p.data.PlusPrompt.length,1);assert.equal(f.p.data.PlusPrompt[0].text,'请提交真实照片');assert.deepEqual(f.loads,[])
})
test('first successful submission explicitly offers WeChat and replaces the completed form',async()=>{
 const f=publisher();await f.p.saveData({imgList:['https://example.test/a.jpg']});assert.equal(f.modals.length,1);assert.equal(f.modals[0].confirmText,'去添加');assert.equal(f.nav.length,0);f.modals[0].success({confirm:true});assert.equal(f.nav[0].url,'/pages/record/record?reviewContact=1');assert.equal(f.nav[0].method,'redirectTo');assert.equal(f.p._submissionCompleted,true)
 f.p.tapPublish();assert.equal(f.nav.length,2);assert.equal(f.calls.length,0)
})
test('deferring WeChat keeps submission successful and leads to persistent pending guide',async()=>{
 const f=publisher();await f.p.saveData({imgList:[]});f.modals[0].success({confirm:false});assert.equal(f.nav[0].url,'/pages/record/record');assert.equal(f.p.data.currentReviewStatus,'pending')
})
test('editing existing approved or pending profiles never repeats first-time WeChat modal',async()=>{
 for(const status of ['approved','pending']){const f=publisher();f.p.data.primaryRecordId='w';f.p.data.currentReviewStatus=status
 f.wx.cloud.callFunction=async()=>({result:{code:1,data:{}}});await f.p.saveData({imgList:[]});assert.equal(f.modals.length,0);assert.equal(f.nav[0].url,'/pages/record/record')}
})
test('save failure never suggests WeChat completion or navigation and re-enables retry',async()=>{
 const f=fixture('miniprogram/subpackages/account/workerProfile/index.js',{require:name=>name.includes('OrderApi')?{request:async()=>{throw Error('save failed')}}:{}})
 await f.p.saveData({imgList:[]});assert.equal(f.modals.length,0);assert.equal(f.nav.length,0);assert.equal(f.p.data.isSavingPublish,false);assert.equal(f.p._submissionCompleted,undefined)
})
test('first submission with invite still offers review WeChat; defer returns to claimed order',async()=>{
 const f=fixture('miniprogram/subpackages/account/workerProfile/index.js',{require:name=>name.includes('OrderApi')?{request:async()=>({data:{pending_invite_order_id:'invited-1'}})}:{}})
 await f.p.saveData({imgList:[]});assert.equal(f.modals.length,1);f.modals[0].success({confirm:false});assert.equal(f.nav[0].url,'/pages/workerOrder/detail?id=invited-1')
})
function onboarding(){return fixture('miniprogram/pages/record/record.js',{require:name=>name.includes('WorkerProfileTime')?{profileTimestamp:()=>0}:{}})}
test('QR deep-link waits for view and records, then scrolls exactly once',async()=>{
 const load=deferred(),f=onboarding();f.p.loadRecords=()=>load.promise;f.p.prepareReviewSubscribePrompt=()=>{};f.p.onLoad({reviewContact:'1'});f.p.onReady();assert.equal(f.scroll.length,0);f.p.data.loading=false;f.p.focusReviewContact();assert.equal(f.scroll.length,1);assert.equal(f.scroll[0].selector,'#review-wechat');f.p.focusReviewContact();assert.equal(f.scroll.length,1);load.resolve();await flush()
})
test('returning from edit refreshes records without duplicate initial load',()=>{
 const f=onboarding();let count=0;f.p.loadRecords=()=>{count++;return Promise.resolve()};f.p.onLoad({});f.p.onShow();assert.equal(count,1);f.p.onShow();assert.equal(count,2)
})
test('pending profile retains add-WeChat prompt without claiming friendship exists',async()=>{
 const f=fixture('miniprogram/pages/record/record.js',{require:name=>name.includes('WorkerProfileTime')?{profileTimestamp:()=>0}:{},cloud:()=>Promise.resolve({result:{code:1,data:[{_id:'w',status:'pending'}]}})})
 await f.p.loadRecords();assert.equal(f.p.data.reviewPending,true);f.p.goReviewContact();assert.equal(f.scroll[0].selector,'#review-wechat');assert.equal(Object.keys(f.p.data).some(k=>/friend|wechatAdded/i.test(k)),false)
})
function tab(){
 let listener;const waiting=deferred();const f=fixture('miniprogram/custom-tab-bar/index.js',{require:()=>({subscribe:fn=>{listener=fn;return()=>{}},refresh:()=>waiting.promise})})
 f.def.lifetimes.attached.call(f.p);return {...f,notify:s=>listener(s)}
}
const plus=p=>p.switchTab({currentTarget:{dataset:{path:'/subpackages/account/workerProfile/index'}}})
test('plus responds immediately while notification refresh is unresolved; return closes stale layer',()=>{
 const f=tab();plus(f.p);assert.equal(f.p.data.showPublishMenu,true);f.def.pageLifetimes.hide.call(f.p);assert.equal(f.p.data.showPublishMenu,false);f.def.pageLifetimes.show.call(f.p);assert.equal(f.p.data.selected,4);plus(f.p);assert.equal(f.p.data.showPublishMenu,true)
})
test('menu navigation closes layer first, prevents duplicate navigation and recovers on failure',()=>{
 const f=tab();plus(f.p);f.p.publishWorker();f.p.publishWorker();assert.equal(f.nav.length,1);assert.equal(f.p.data.showPublishMenu,false);f.nav[0].fail();f.nav[0].complete();f.p.publishWorker();assert.equal(f.nav.length,2);assert.equal(f.toasts.length,1)
})
test('return resets pending native navigation even if its completion has not arrived',()=>{
 const f=tab();f.p.createOrder();f.def.pageLifetimes.hide.call(f.p);f.def.pageLifetimes.show.call(f.p);plus(f.p);assert.equal(f.p.data.showPublishMenu,true);f.p.createOrder();assert.equal(f.nav.length,2)
})
test('hidden tabs avoid message redraw and visible identical badge is not repainted',()=>{
 const f=tab(),summary={messageBadgeCount:2,attentionKnown:true};f.notify(summary);let n=f.writes.length;f.notify(summary);assert.equal(f.writes.length,n);f.def.pageLifetimes.hide.call(f.p);n=f.writes.length;f.notify({...summary,messageBadgeCount:4});assert.equal(f.writes.length,n);f.def.pageLifetimes.show.call(f.p);assert.equal(f.p.data.notificationBadgeCount,4)
})
test('changed templates retain explicit QR instruction and one status banner',()=>{
 const form=read('miniprogram/subpackages/account/workerProfile/index.wxml'),record=read('miniprogram/pages/record/record.wxml')
 assert.equal((form.match(/profile-edit-hint/g)||[]).length,1);assert.doesNotMatch(form,/非必要请勿频繁|正在修改原入驻资料/);assert.match(record,/id="review-wechat"/);assert.match(record,/长按下方二维码添加微信/);assert.match(record,/reviewPending/);assert.match(form,/showSuccessModal \|\| publishLimitBlocked/)
})
