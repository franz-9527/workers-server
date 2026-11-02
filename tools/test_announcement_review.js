const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),test=require('node:test')
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {resolve,reject,promise}}
test('announcements reject stale and mismatched site responses without caching production content',async()=>{
 let site='production',revision=0;const pending=[]
 const sandbox={module:{exports:{}},require(p){if(p.includes('OrderApi'))return {publicRequest(route,q){const d=deferred();pending.push(d);return d.promise}};if(p.includes('ApiEnvironment'))return {capture:()=>({key:site,revision}),assertCurrent(c){if(c.key!==site||c.revision!==revision)throw Error('stale')},onChange:()=>()=>{}};throw Error(p)}}
 vm.runInNewContext(fs.readFileSync('miniprogram/utils/AnnouncementLoader.js','utf8'),sandbox)
 const api=sandbox.module.exports,p=api.load('home');site='testing';revision++;pending.shift().resolve({data:{enabled:true,text:'正式公告',environment:'production'}})
 await assert.rejects(p,/stale/);const q=api.load('home');pending.shift().resolve({data:{enabled:true,text:'测试公告',environment:'testing'}});assert.equal((await q).text,'测试公告')
 const r=api.load('home');pending.shift().resolve({data:{enabled:true,text:'错误站点',environment:'production'}});await assert.rejects(r,/站点不匹配/)
})
test('component clears old content on environment switch and ignores responses after detach',async()=>{
 let def,listener,requests=[]
 vm.runInNewContext(fs.readFileSync('miniprogram/components/order-announcement/index.js','utf8'),{Component(d){def=d},require(p){if(p.includes('AnnouncementLoader'))return {onChange(cb){listener=cb;return ()=>{}},load(){const d=deferred();requests.push(d);return d.promise}};return {resolve:x=>x}}})
 const c={properties:{scene:'order_hall'},data:{visible:false},setData(p){Object.assign(this.data,p)},...def.methods}
 def.lifetimes.attached.call(c);requests.shift().resolve({enabled:true,text:'正式',link:''});await Promise.resolve();assert.equal(c.data.text,'正式')
 listener();assert.equal(c.data.visible,false);requests.shift().resolve({enabled:true,text:'测试',link:''});await Promise.resolve();assert.equal(c.data.text,'测试')
 c.refreshAnnouncement();const last=requests.shift();def.lifetimes.detached.call(c);last.resolve({enabled:true,text:'旧响应'});await Promise.resolve();assert.equal(c.data.visible,false)
})
test('review labels are compact and server queued status is refreshed rather than treated as final',()=>{
 let s=fs.readFileSync('miniprogram/subpackages/account/review/index.js','utf8').replace(/^import .+$/mg,'')
 const ctx={require(p){if(p.includes('WorkerProfileTime'))return {profileTimestamp:()=>0};return {}},formatTime:()=>'',Page(){},wx:{}};vm.createContext(ctx);vm.runInContext(s,ctx)
 assert.equal(vm.runInContext("needsServerNoticeFallback({reviewStatus:'approved',reviewNotice:{status:'queued'}})",ctx),true)
 assert.equal(vm.runInContext("needsServerNoticeFallback({reviewStatus:'approved',reviewNotice:{status:'unrecorded'}})",ctx),true)
 const merged=vm.runInContext("mergeServerNoticeFallback({items:[{_id:'a',reviewStatus:'approved',reviewNotice:{status:'queued'}}]},{items:[{_id:'a',reviewNotice:{status:'sent',statusCode:'0'}}]})",ctx)
 assert.equal(merged.items[0].reviewNotice.status,'sent');assert.equal(vm.runInContext("decorateRecord({reviewStatus:'approved',reviewNotice:{status:'sent',statusCode:'0'}},'worker').reviewNoticeText",ctx),'微信已受理')
 assert.ok(!fs.readFileSync('miniprogram/subpackages/account/review/index.wxml','utf8').includes('审核结果通知：'))
})
test('home and store announcement sources are site APIs and review writes use the reliable PHP outbox',()=>{
 for(const file of ['miniprogram/pages/index/index.js','miniprogram/subpackages/store/index/index.js']){const s=fs.readFileSync(file,'utf8');assert.ok(s.includes('Announcements.load('));assert.ok(!/action:\s*'get(?:Store)?Tips'/.test(s))}
 const s=fs.readFileSync('miniprogram/pages/record/detail.js','utf8');assert.ok(s.includes("OrderApi.request('admin.worker.review'"));assert.ok(!s.includes("callWorker('miniAdmin.review',"))
})
