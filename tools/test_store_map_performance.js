'use strict'
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path'),test=require('node:test')
if(path.basename(path.resolve(__dirname,'..'))!=='workers-server')throw Error('Independent test repository required')
const turn=()=>new Promise(setImmediate)
test('both geo queries start before either resolves, request only map fields and retain existing filters/counts/IDs',async()=>{
 const pending=[],queries=[]
 function query(name,filter,fields,limit) {
  return {field:value=>query(name,filter,value,limit),limit:value=>query(name,filter,fields,value),get:()=>{
   queries.push({name,filter,fields,limit})
   return new Promise(resolve=>pending.push({name,resolve}))
  }}
 }
 const sdk={init(){},DYNAMIC_CURRENT_ENV:'fixture',getWXContext:()=>({OPENID:'fixture'}),database:()=>({command:{geoNear:value=>value},Geo:{Point:(longitude,latitude)=>({longitude,latitude})},collection:name=>({where:filter=>query(name,filter)})})}
 const exports={}
 vm.runInNewContext(fs.readFileSync('cloudfunctions/store/index.js','utf8'),{require:()=>sdk,exports,console:{log(){},error(){}}})
 const result=exports.main({action:'getNear',geo:{latitude:30,longitude:120}})
 await turn()
 assert.deepEqual(queries.map(x=>x.name),['store','worker'],'worker read is dispatched without waiting for store')
 assert.equal(queries[0].limit,30);assert.equal(queries[1].limit,undefined,'keep original SDK worker result limit')
 for(const q of queries) {
  assert.equal(q.filter.published,true);assert.equal(q.filter.deleteTime,'')
  assert.deepEqual(Object.keys(q.fields).sort(),['_id','createTime','imgList','location',q.name==='store'?'StoreName':'workerName'].sort())
 }
 assert.equal(queries[1].filter.sell,true)
 pending.find(x=>x.name==='worker').resolve({data:[{_id:'w',workerName:'原师傅',createTime:0,imgList:[],location:{latitude:31,longitude:121}}]})
 await turn()
 let settled=false;result.then(()=>settled=true);await turn();assert.equal(settled,false,'partial DB success does not fabricate an incomplete result')
 pending.find(x=>x.name==='store').resolve({data:[{_id:'s',StoreName:'原门店',createTime:0,imgList:[],location:{latitude:30,longitude:120}}]})
 const body=await result
 assert.equal(body.code,1);assert.equal(body.data.length,2);assert.equal(body.data[0]._id,'s');assert.equal(body.data[1]._id,'w')
 assert.equal(body.data[0].id,0);assert.equal(body.data[1].id,1)
 assert.ok(!body.data.some(x=>x.id===-1),'positioning marker remains frontend-owned')
})
test('parallel DB failure remains a visible read error instead of success with missing records',async()=>{
 function query(name) {
  return {where(){return this},field(){return this},limit(){return this},get(){return name==='store'?Promise.resolve({data:[]}):Promise.reject(Error('fixture database unavailable'))}}
 }
 const sdk={init(){},DYNAMIC_CURRENT_ENV:'fixture',getWXContext:()=>({OPENID:'fixture'}),database:()=>({command:{geoNear:x=>x},Geo:{Point:()=>({})},collection:query})}
 const exports={}
 vm.runInNewContext(fs.readFileSync('cloudfunctions/store/index.js','utf8'),{require:()=>sdk,exports,console:{log(){},error(){}}})
 await assert.rejects(exports.main({action:'getNear',geo:{latitude:30,longitude:120}}),/fixture database unavailable/)
})
