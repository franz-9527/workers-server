const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm'),crypto=require('crypto')
const Profile=require('../cloudfunctions/worker/profile-time.js'),Client=require('../miniprogram/utils/WorkerProfileTime.js')
const created=Date.UTC(2020,4,17,10,36,11),now=Date.UTC(2026,9,31,12),day='2026-10-31'
const legacy={_id:'legacy-worker',_openid:'owner',workerName:'Fixture worker',workerType:'墙布',createTime:created,startDate:'2020-05-17',updatedTime:now,published:true}
function fixture(worker=[legacy]){
 let caller='admin',clock=now;const writes=[],collections=[],reads=[],data={worker:structuredClone(worker),user:[{_id:'owner-user',openid:'owner',mobile:'13800000000'},{_id:'member-user',openid:'member',vip:true,vipEndTime:'2030-12-31'}],worker_runtime_config:[{_id:'current',adminOpenids:['admin'],phoneRevealEnabled:true,phoneRevealAdminEnabled:true,phoneRevealMemberEnabled:true,phoneRevealMember24hLimit:2}]}
 const command={gt:value=>({$gt:value}),gte:value=>({$gte:value}),or:value=>({$or:value}),and:value=>({$and:value}),in:value=>({$in:value}),exists:value=>({$exists:value})}
 function match(row,query){
  if(query.$and)return query.$and.every(q=>match(row,q));if(query.$or)return query.$or.some(q=>match(row,q))
  return Object.entries(query).every(([k,v])=>{const value=k.split('.').reduce((x,p)=>x&&x[p],row);if(v instanceof RegExp)return v.test(String(value||''));if(v&&typeof v==='object'){if('$gt'in v)return value>v.$gt;if('$gte'in v)return value>=v.$gte;if('$exists'in v)return (value!==undefined)===v.$exists;if('$in'in v)return v.$in.includes(value)}return value===v})
 }
 class Query{
  constructor(name,where={}){this.name=name;this.condition=where;this.offset=0;this.count=100}
  where(q){return new Query(this.name,q)}
  orderBy(key,direction){this.sort=this.sort||[];this.sort.push([key,direction]);return this}
  skip(v){this.offset=v;return this}limit(v){this.count=v;return this}
  async get(){reads.push(this.name);let rows=(data[this.name]||[]).filter(x=>match(x,this.condition));if(this.sort){rows.sort((a,b)=>{for(const[k,d]of this.sort){const diff=((a[k]||0)>(b[k]||0)?1:(a[k]||0)<(b[k]||0)?-1:0)*(d==='desc'?-1:1);if(diff)return diff}return 0})}return{data:structuredClone(rows.slice(this.offset,this.offset+this.count))}}
  doc(id){const self=this;return{
   async get(){reads.push(self.name);const row=(data[self.name]||[]).find(x=>x._id===id);if(!row)throw Error('document does not exist');return{data:structuredClone(row)}},
   async update({data:patch}){const row=(data[self.name]||[]).find(x=>x._id===id);Object.assign(row,patch);writes.push({name:self.name,patch});return{errMsg:'document.update:ok'}},
   async set({data:row}){if(!data[self.name])data[self.name]=[];const index=data[self.name].findIndex(x=>x._id===id);const next={...row,_id:id};if(index<0)data[self.name].push(next);else data[self.name][index]=next;writes.push({name:self.name,patch:row});return{}}
  }}
  async add({data:row}){if(!data[this.name])data[this.name]=[];const id='fixture-'+data[this.name].length;data[this.name].push({...row,_id:id});writes.push({name:this.name,patch:row});return{_id:id}}
 }
 const db={command,Geo:{Point:(longitude,latitude)=>({longitude,latitude})},RegExp:({regexp,options})=>new RegExp(regexp,options),collection:name=>new Query(name),createCollection:async name=>{collections.push(name);data[name]=data[name]||[]}}
 const cloud={DYNAMIC_CURRENT_ENV:'fixture',init(){},database:()=>db,getWXContext:()=>({OPENID:caller}),openapi:{security:{msgSecCheck:async()=>({errCode:0,result:{suggest:'pass'}})}}}
 const module={exports:{}},FakeDate=class extends Date{static now(){return clock}}
 const context={module,exports:module.exports,require:p=>p==='wx-server-sdk'?cloud:p==='crypto'?crypto:p==='./profile-time'?Profile:(()=>{throw Error('unexpected require '+p)})(),console,Date:FakeDate,Map,Set,Promise,Buffer,process:{env:{}}}
 vm.runInNewContext(fs.readFileSync('cloudfunctions/worker/index.js','utf8')+'\nmodule.exports.fixture={workerTimestamp,miniAdminReviewList,phoneSearchReviewItem,miniAdminPhoneReveal,currentPhonePolicy,memberPhoneReveal,serverRuntimeConfigSync,updateActiveLocation,saveUniqueRecord,getRecord,serverPhoneReveal};',context)
 return{fn:module.exports.fixture,writes,collections,reads,data,caller:v=>caller=v,advance:ms=>clock+=ms}
}
test('legacy date is stable while genuine resubmissions retain precise time in both clients and cloud',()=>{
 assert.equal(fs.readFileSync('cloudfunctions/worker/profile-time.js','utf8'),fs.readFileSync('miniprogram/utils/WorkerProfileTime.js','utf8'))
 for(const lib of [Profile,Client]){
  assert.equal(lib.profileTimestamp(legacy),created)
  assert.equal(lib.profileTimestamp({...legacy,startDate:day}),now)
  assert.equal(lib.profileTimestamp({...legacy,updatedTime:undefined}),created)
  assert.equal(lib.profileTimestamp({...legacy,startDate:'',updatedTime:now}),created)
  assert.equal(lib.profileTimestamp({...legacy,publishTime:now}),created)
  assert.equal(lib.timestampValue('2020-05-17 18:36:11'),created)
  assert.equal(lib.timestampValue('202005171836'),created-11000)
 }
})
test('changing residence writes only geo/location and leaves submission time and state intact',async()=>{
 const f=fixture();const before=structuredClone(f.data.worker[0]);const location={latitude:23.1,longitude:113.3,name:'Fixture residence'}
 const result=await f.fn.updateActiveLocation({userInfo:{openId:'owner'},location});assert.equal(result.code,1)
 assert.deepEqual(Object.keys(f.writes[0].patch).sort(),['geo','location'])
 for(const key of ['createTime','startDate','updatedTime','publishTime','published'])assert.equal(f.data.worker[0][key],before[key])
 assert.equal(f.fn.workerTimestamp(f.data.worker[0]),created)
})
test('review pagination ignores more than 100 contaminated updates and merges actual submissions without duplicates',async()=>{
 const rows=Array.from({length:230},(_,i)=>({...legacy,_id:'old-'+i,updatedTime:now+i,createTime:created+i}))
 for(let i=0;i<45;i++)rows.push({...legacy,_id:'new-'+i,createTime:now-10000-i,startDate:day,updatedTime:undefined})
 rows.push({...legacy,_id:'resubmitted',startDate:day,updatedTime:now-1})
 const f=fixture(rows),first=await f.fn.miniAdminReviewList({page:1,page_size:20}),second=await f.fn.miniAdminReviewList({page:2,page_size:20})
 assert.equal(first.items[0]._id,'resubmitted');assert.equal(first.items.length,20);assert.equal(first.has_more,true)
 assert.ok(first.items.every(x=>!x._id.startsWith('old-')));assert.ok(second.items.every(x=>!x._id.startsWith('old-')))
 assert.equal(new Set([...first.items,...second.items].map(x=>x._id)).size,40)
 const search=f.fn.phoneSearchReviewItem(legacy);assert.equal(search.profileTime,created);assert.equal(search.startDate,'2020-05-17');assert.equal(search.updatedTime,0,'old clients also ignore unrelated timestamp')
 assert.equal(f.writes.length,0)
})
test('owner records return correct time even when old updatedTime already exists',async()=>{
 const f=fixture(),result=await f.fn.getRecord({userInfo:{openId:'owner'}})
 assert.equal(result.data[0].profileTime,created);assert.match(result.data[0].timeText,/^2020-05-17/)
 assert.equal(f.writes.length,0)
})
test('administrator phone reads do not write logs/quotas or create collections',async()=>{
 const f=fixture();const policy=await f.fn.currentPhonePolicy('legacy-worker');assert.equal(policy.unlimited,true)
 const result=await f.fn.miniAdminPhoneReveal({id:'legacy-worker'});assert.equal(result.mobile,'13800000000')
 await f.fn.serverPhoneReveal({viewer_role:'admin',cloud_worker_id:'legacy-worker'})
 assert.equal(f.writes.length,0);assert.equal(f.collections.length,0)
 assert.ok(!f.reads.some(x=>/reveal_(logs|quota)/.test(x)))
})
test('runtime configuration sync creates only its configuration document',async()=>{
 const f=fixture();await f.fn.serverRuntimeConfigSync({adminOpenids:['admin'],phoneRevealEnabled:true,phoneRevealAdminEnabled:true,phoneRevealMemberEnabled:true,phoneRevealMember24hLimit:2})
 assert.deepEqual(f.collections,['worker_runtime_config']);assert.equal(f.writes.length,1)
})
test('member phone quota still applies to distinct workers, is created on demand and does not affect admin',async()=>{
 const f=fixture([legacy,{...legacy,_id:'second'},{...legacy,_id:'third'}]);f.caller('member')
 const one=await f.fn.memberPhoneReveal({id:'legacy-worker'});assert.equal(one.remaining_24h,1)
 await f.fn.memberPhoneReveal({id:'legacy-worker'});const two=await f.fn.memberPhoneReveal({id:'second'});assert.equal(two.remaining_24h,0)
 await assert.rejects(f.fn.memberPhoneReveal({id:'third'}),/上限/)
 assert.equal(f.data.worker_phone_reveal_quota.length,1);assert.equal(f.data.worker_phone_reveal_quota[0].items.length,2)
 assert.equal(f.data.worker_phone_reveal_logs.length,3);assert.ok(f.data.worker_phone_reveal_logs.every(x=>x.role==='member'))
 const count=f.writes.length;f.caller('admin');await f.fn.miniAdminPhoneReveal({id:'third'});assert.equal(f.writes.length,count)
})
test('front-end review and owner pages do not show the unrelated update as a new publication',()=>{
 for(const [file,name,tail]of [['miniprogram/subpackages/account/review/index.js','decorateRecord',", 'worker'"],['miniprogram/pages/record/record.js','normalizeRecord','']]){
  const context={module:{exports:{}},Page(){},wx:{},Date,console,Promise,require:p=>p.includes('WorkerProfileTime')?Client:{}}
  const code=fs.readFileSync(file,'utf8').replace(/^import \{ formatTime \} from [^\n]+/,"const formatTime = value => String(value)")
  vm.runInNewContext(code+'\nmodule.exports.run=value=>'+name+'(value'+tail+');',context)
  assert.equal(context.module.exports.run({...legacy,timeText:'7小时前'}).time,String(created))
 }
})

test('actual owner resubmission still refreshes its date and becomes pending review',async()=>{
 const f=fixture();f.caller('owner');const saved=await f.fn.saveUniqueRecord({userInfo:{openId:'owner'},data:{workerName:'Fixture worker',workerType:'墙布',location:{latitude:23.1,longitude:113.3}}})
 assert.equal(saved.code,1);const row=f.data.worker[0];assert.equal(row.startDate,day);assert.equal(row.updatedTime,now);assert.equal(row.reviewStatus,'pending');assert.equal(row.published,false);assert.equal(f.fn.workerTimestamp(row),now);assert.equal(row.createTime,created)
})
test('removing administrator logs does not grant administrator phone access to a member',async()=>{
 const f=fixture();f.caller('member');await assert.rejects(f.fn.miniAdminPhoneReveal({id:'legacy-worker'}),/仅后台配置/);assert.equal(f.writes.length,0)
})

test('legacy resubmission with only a newer startDate is shown consistently and included on the first review page',async()=>{
 const submitted={...legacy,_id:'date-only-worker',startDate:day,updatedTime:Date.UTC(2025,0,1)}
 const expected=Profile.timestampValue(day)
 assert.equal(Profile.profileTimestamp(submitted),expected);assert.equal(Client.profileTimestamp(submitted),expected)
 const rows=Array.from({length:160},(_,i)=>({...legacy,_id:'baseline-'+i,createTime:Date.UTC(2026,9,30)-i,updatedTime:undefined,startDate:'2026-10-30'}))
 const f=fixture([...rows,submitted]),page=await f.fn.miniAdminReviewList({page:1,page_size:20})
 assert.equal(page.items[0]._id,'date-only-worker');assert.equal(page.items[0].profileTime,expected);assert.equal(page.items[0].updatedTime,expected)
 for(const [file,name,tail]of [['miniprogram/subpackages/account/review/index.js','decorateRecord',", 'worker'"],['miniprogram/pages/record/record.js','normalizeRecord','']]){
  const context={module:{exports:{}},Page(){},wx:{},Date,console,Promise,require:p=>p.includes('WorkerProfileTime')?Client:{}}
  const code=fs.readFileSync(file,'utf8').replace(/^import \{ formatTime \} from [^\n]+/,"const formatTime = value => String(value)")
  vm.runInNewContext(code+'\nmodule.exports.run=value=>'+name+'(value'+tail+');',context)
  assert.equal(context.module.exports.run({...submitted,profileTime:created,timeText:'old cached date'}).time,String(expected),'stale derived response cannot override the latest submission date')
 }
})
test('same-day date-only batches preserve pagination and do not hide precise submissions',async()=>{
 const rows=Array.from({length:135},(_,i)=>({...legacy,_id:'date-'+String(i).padStart(3,'0'),startDate:day,updatedTime:undefined}))
 rows.push({...legacy,_id:'precise',startDate:day,updatedTime:now})
 const f=fixture(rows),one=await f.fn.miniAdminReviewList({page:1,page_size:20}),two=await f.fn.miniAdminReviewList({page:2,page_size:20})
 assert.equal(one.items[0]._id,'precise');assert.equal(one.items.length,20);assert.equal(two.items.length,20)
 assert.equal(new Set([...one.items,...two.items].map(x=>x._id)).size,40)
 assert.deepEqual(Array.from(one.items.slice(1).map(x=>x._id)),Array.from(rows.filter(x=>x._id!=='precise').map(x=>x._id).sort().reverse().slice(0,19)))
})
