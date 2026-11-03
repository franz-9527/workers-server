'use strict'
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),test=require('node:test'),crypto=require('node:crypto')
const root=path.resolve(__dirname,'..')
if(path.basename(root)!=='workers-server')throw Error('Independent test repository required')
const read=p=>fs.readFileSync(path.join(root,p),'utf8')
const flush=async()=>{for(let i=0;i<8;i++)await new Promise(setImmediate)}
function mapFixture({old=false,platform='devtools'}={}) {
 let def
 const app={globalData:{version:'fixture',location:{latitude:30,longitude:120}}},calls=[],downloads=[],snapshots=[],timers=new Map(),contexts=[]
 let timerID=0,center={latitude:31,longitude:121},translateCalls=0
 const wx={cloud:{callFunction:o=>calls.push(o),downloadFile:o=>downloads.push(o)},downloadFile:o=>downloads.push(o),getSystemInfoSync:()=>({platform}),getSystemInfo:o=>o.success({}),getLocation:o=>{},createMapContext:(id,scope)=>{contexts.push({id,scope});return {getCenterLocation:o=>o.success(center),translateMarker:o=>{translateCalls++;if(!p.data.markers.some(m=>m.id===o.markerId))throw Error('translateMapMarker:fail can not find marker with id '+o.markerId)}}}}
 function moduleAt(file,requires){const module={exports:{}};vm.runInNewContext(read(file),{module,exports:module.exports,require:requires,wx,console,Promise});return module.exports}
 const map=moduleAt('miniprogram/subpackages/store/utils/StoreMap.js',()=>({CLOUDCONFIG:{CLOUDENV:'worker-real'}}))
 const storefront=moduleAt('miniprogram/subpackages/store/utils/Storefront.js',()=>({resolve:x=>x}))
 const plugin={setLocation:x=>{},getLocation:()=>null}
 const source=read(old?'fixtures/r38-store-before-subpackage.js':'miniprogram/subpackages/store/index/index.js').replace(/^import .*$/gm,'')
 vm.runInNewContext(source,{Page:d=>def=d,getApp:()=>app,require:name=>name.includes('Storefront')?storefront:name.includes('StoreMap')?map:name.includes('SubpackageRedirect')?{resolve:x=>x}:{load:()=>Promise.resolve({}),onChange:()=>()=>{}},requirePlugin:()=>plugin,wx,MAPCONFIG:{MAPKEY:'fixture',DEFLOCATION:app.globalData.location},formatTime:x=>String(x),calDistance:()=>'',setTimeout:fn=>{timers.set(++timerID,fn);return timerID},clearTimeout:id=>timers.delete(id),console:{log(){},warn(){},error(){}}})
 const p={...def,data:structuredClone(def.data),setData(patch){Object.assign(this.data,patch);snapshots.push(structuredClone(patch))}}
 for(const name of ['getLocation','getStoreBanner','getStoreTips','getStoreIconList','getLastStore'])p[name]=()=>{}
 p.onLoad();if(p.onReady)p.onReady()
 return {p,map,calls,downloads,snapshots,contexts,center:x=>center=x,translations:()=>translateCalls,runTimers(){const fns=Array.from(timers.values());timers.clear();fns.forEach(fn=>fn())},timers}
}
test('pre-subpackage page reproduces the missing -1 marker before cloud data returns',()=>{
 const f=mapFixture({old:true})
 assert.throws(()=>f.p.mapRegionChange({type:'end',causedBy:'update'}),/can not find marker with id -1/)
})
test('initial map pin exists before nearby response and initialization never invokes translateMarker',async()=>{
 const f=mapFixture()
 assert.equal(f.p.data.markers[0].id,-1)
 assert.equal(f.contexts[0].scope,f.p)
 f.p.mapRegionChange({type:'end',causedBy:'update'})
 assert.equal(f.translations(),0);assert.equal(f.timers.size,0)
 f.calls[0].success({result:{code:1,data:[]}});await flush()
 assert.equal(f.p.data.markers.length,1);assert.equal(f.p.data.markers[0].id,-1)
})
test('devtools downloads original cloud avatars before assigning markers, keeps distinct record routes and uses local fallback',async()=>{
 const f=mapFixture()
 f.calls[0].success({result:{code:1,data:[
  {id:0,_id:'store-one',StoreName:'原门店',latitude:30,longitude:120,iconPath:'https://776f-worker-real-1301382302.tcb.qcloud.la/store/a%20b.png'},
  {id:1,_id:'worker-one',workerName:'原师傅',latitude:30,longitude:120,iconPath:'https://other.example/worker.png'},
  {id:2,_id:'store-no-image',StoreName:'无头像门店',latitude:30,longitude:120,iconPath:''}
 ]}});await flush()
 assert.equal(f.p.data.markers.length,1,'no remote avatar is passed to renderer while downloading')
 assert.equal(f.downloads[0].fileID,'cloud://worker-real.776f-worker-real-1301382302/store/a b.png')
 f.downloads[0].success({tempFilePath:'wxfile://cloud-avatar.png'})
 f.downloads[1].success({statusCode:403,tempFilePath:'wxfile://403.html'});await flush()
 assert.equal(f.p.data.markers[0].iconPath,'wxfile://cloud-avatar.png')
 assert.equal(f.p.data.markers[0].StoreName,'原门店');assert.equal(f.p.data.markers[1].workerName,'原师傅')
 assert.ok(f.p.data.markers.slice(1,3).every(m=>m.iconPath==='/subpackages/store/images/2406shop.png'))
 assert.ok(f.snapshots.every(patch=>!patch.markers||patch.markers.every(m=>!/^https?:/.test(m.iconPath))))
 assert.ok(fs.existsSync(path.join(root,'miniprogram',f.p.data.markers[1].iconPath)))
})
test('real devices retain original remote avatars and never run devtools download shim',async()=>{
 const f=mapFixture({platform:'ios'})
 f.calls[0].success({result:{code:1,data:[{id:0,_id:'original',iconPath:'https://image.example/original.jpg'}]}});await flush()
 assert.equal(f.downloads.length,0);assert.equal(f.p.data.markers[0].iconPath,'https://image.example/original.jpg')
})
test('drag keeps the original local pin and debounced nearby/list refresh, never recursively handles update events',async()=>{
 const f=mapFixture();let lists=0
 f.p.getLastStore=()=>lists++
 f.p.mapRegionChange({type:'regionchange',detail:{type:'end',causedBy:'drag'}})
 f.center({latitude:32,longitude:122});f.p.mapRegionChange({type:'end',causedBy:'drag'})
 assert.equal(f.p.data.markers.at(-1).latitude,32);assert.equal(f.timers.size,1)
 f.p.mapRegionChange({type:'end',causedBy:'update'});assert.equal(f.timers.size,1)
 f.runTimers();await flush();assert.equal(lists,1);assert.equal(f.calls.length,2)
 assert.equal(f.calls[1].data.geo.latitude,32);assert.equal(f.translations(),0)
 // Original in-flight cloud result may not overwrite the newer center.
 f.calls[0].success({result:{code:1,data:[{id:0,_id:'stale',iconPath:'/images/local.png'}]}})
 f.calls[1].success({result:{code:1,data:[{id:0,_id:'latest',iconPath:'/images/local.png'}]}});await flush()
 assert.equal(f.p.data.markers[0]._id,'latest');assert.equal(f.p.data.markers.at(-1).latitude,32)
})
test('old avatar downloads and responses cannot overwrite a newer search or an unloaded page',async()=>{
 const f=mapFixture()
 f.calls[0].success({result:{code:1,data:[{id:0,_id:'old',iconPath:'https://example.test/slow.png'}]}});await flush()
 const next=f.p.getNear({latitude:33,longitude:123})
 f.calls[1].success({result:{code:1,data:[{id:0,_id:'new',iconPath:'/images/local.png'}]}});await next
 f.downloads[0].success({statusCode:200,tempFilePath:'wxfile://old.png'});await flush()
 assert.equal(f.p.data.markers[0]._id,'new')
 const last=f.p.getNear({latitude:34,longitude:124});f.p.onUnload();const count=f.snapshots.length
 f.calls[2].success({result:{code:1,data:[]}});await last;assert.equal(f.snapshots.length,count)
})
test('a failed nearby query leaves the original positioning pin and no nonexistent marker animation',async()=>{
 const f=mapFixture();f.calls[0].fail({errMsg:'FUNCTION_NOT_FOUND'});await flush()
 assert.equal(f.p.data.markers.length,1);assert.equal(f.p.data.markers[0].isLocal,true)
 f.p.mapRegionChange({type:'end',causedBy:'drag'});assert.equal(f.translations(),0)
 f.p.onUnload();assert.equal(f.timers.size,0)
})
function phoneFixture(old=false) {
 let caller='admin';const reads=[],writes=[]
 const data={worker:[{_id:'w',_openid:'owner',workerName:'原师傅',published:true,mobile:'13900000000'}],user:[{_id:'u',openid:'owner',mobile:'13800000000'}],worker_runtime_config:[{_id:'current',adminOpenids:['admin'],phoneRevealEnabled:true,phoneRevealAdminEnabled:true,phoneRevealMemberEnabled:false}]}
 const missing=name=>Object.assign(new Error('database collection '+name+' not exists'),{code:'DATABASE_COLLECTION_NOT_EXIST'})
 const db={collection:name=>({
  doc:id=>({get:async()=>{reads.push(name);if(!data[name])throw missing(name);const row=data[name].find(x=>x._id===id);if(!row)throw Error('document not found');return{data:structuredClone(row)}}}),
  where:filter=>({limit:()=>({get:async()=>{reads.push(name);if(!data[name])throw missing(name);return{data:structuredClone(data[name].filter(row=>Object.entries(filter).every(([k,v])=>row[k]===v)))}})}),
  add:async()=>{writes.push(name);if(!data[name])throw missing(name)}
 })}
 const cloud={init(){},DYNAMIC_CURRENT_ENV:'worker-real',database:()=>db,getWXContext:()=>({OPENID:caller})}
 const module={exports:{}},source=read(old?'fixtures/r38-worker-before-subpackage.js':'cloudfunctions/worker/index.js')
 vm.runInNewContext(source+'\nmodule.exports.fixture={miniAdminPhoneReveal,handleMiniWorkerAction,readableWorkerAdminError,resolveWorkerPhone};',{module,exports:module.exports,require:name=>name==='wx-server-sdk'?cloud:name==='crypto'?crypto:require('../cloudfunctions/worker/profile-time.js'),Date,Buffer,Promise,console:{log(){},error(){}},process:{env:{}}})
 return {fn:module.exports.fixture,data,reads,writes,caller:x=>caller=x}
}
test('old deployed administrator lookup reproduces false missing worker message when only reveal logs are absent',async()=>{
 const f=phoneFixture(true),result=await f.fn.handleMiniWorkerAction({action:'miniAdmin.phoneReveal',id:'w'},{})
 assert.equal(result.code,0);assert.match(result.msg,/不存在 worker 集合/)
 assert.ok(f.reads.includes('user'));assert.deepEqual(f.writes,['worker_phone_reveal_logs'])
})
test('administrator lookup uses worker._openid -> user.openid -> mobile, without any logging/quota writes or collection creation',async()=>{
 const f=phoneFixture(),result=await f.fn.handleMiniWorkerAction({action:'miniAdmin.phoneReveal',id:'w'},{})
 assert.equal(result.code,1);assert.equal(result.data.mobile,'13800000000','never uses mobile stored on worker')
 assert.deepEqual(f.reads,['worker_runtime_config','worker','user']);assert.deepEqual(f.writes,[])
})
test('permission restrictions and missing user diagnostics remain intact; no misleading create-worker advice',async()=>{
 const f=phoneFixture();f.caller('member');await assert.rejects(f.fn.miniAdminPhoneReveal({id:'w'}),/仅后台配置/)
 f.caller('admin');delete f.data.user
 const result=await f.fn.handleMiniWorkerAction({action:'miniAdmin.phoneReveal',id:'w'},{})
 assert.equal(result.code,0);assert.ok(!result.msg.includes('请先在云开发控制台建立'))
 assert.match(result.msg,/不代表 worker 集合缺失/)
 assert.deepEqual(f.writes,[])
})
