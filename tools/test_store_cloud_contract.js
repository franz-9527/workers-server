'use strict'
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict')
const root=path.resolve(__dirname,'..')
if(process.env.STORE_ORDER_TEST!=='1'||path.basename(root)!=='workers-server')throw Error('Independent test repository required')
let checks=0,queries=[],sdkCalls=[]
const fixtures={
 store:[{_id:'fixture-store',StoreName:'演示门店',location:{latitude:30,longitude:120},createTime:100,imgList:['cloud://worker-real.776f-worker-real-1301382302/store.png']}],
 worker:[{_id:'fixture-worker',workerName:'演示师傅',location:{latitude:30.1,longitude:120.1},createTime:101,imgList:[]}]
}
function query(collection,filter,limit){
 return {field:()=>query(collection,filter,limit),limit:n=>query(collection,filter,n),get:async()=>{queries.push({collection,filter,limit});return {data:structuredClone(fixtures[collection]||[])}}}
}
const db={command:{geoNear:value=>({geoNear:value})},Geo:{Point:(longitude,latitude)=>({longitude,latitude})},collection:name=>({where:filter=>query(name,filter)})}
const sdk={DYNAMIC_CURRENT_ENV:'current-fixture',init:o=>sdkCalls.push(['init',o]),database:()=>db,getWXContext:()=>({OPENID:'fixture-only'}),openapi:{security:{msgSecCheck:()=>{throw Error('Read must never invoke safety/write APIs')},imgSecCheck:()=>{throw Error('Read must never invoke safety/write APIs')}}}}
const exported={}
vm.runInNewContext(fs.readFileSync(path.join(root,'cloudfunctions/store/index.js'),'utf8'),{require:name=>{assert.equal(name,'wx-server-sdk');return sdk},exports:exported,console:{log(){},warn(){},error(){}},Buffer})
async function main(){
 const caps=await exported.main({action:'getCapabilities'})
 assert.equal(caps.code,1);assert.equal(caps.data.function_name,'store');assert.equal(caps.data.read_contract,1);assert.equal(queries.length,0);checks+=4
 for(const action of ['getStoreIconList','getStoreBanner','getLastStore','getNearbyStore','getNear']){
  const before=queries.length,result=await exported.main({action,geo:{latitude:30,longitude:120}})
  assert.equal(result.code,1,action);assert.ok(Array.isArray(result.data));checks+=2
  if(action==='getStoreIconList'){assert.equal(result.data.length,4);checks++}
  if(action==='getNear'){assert.equal(result.data.length,2);assert.equal(result.data[0].StoreName,'演示门店');assert.equal(result.data[1].workerName,'演示师傅');checks+=3}
  for(const q of queries.slice(before)){assert.equal(q.filter.published,true);assert.equal(q.filter.deleteTime,'');assert.ok(q.filter.geo.geoNear.geometry);checks+=3}
 }
 const missing=await exported.main({action:'unsupported'});assert.equal(missing.error_code,'STORE_ACTION_UNSUPPORTED');checks++
 const invalid=await exported.main(null);assert.equal(invalid.error_code,'STORE_ACTION_UNSUPPORTED');checks++
 assert.equal((await exported.main({action:'getLastStore'})).code,0);checks++
 assert.equal(sdkCalls.length,1);checks++
 console.log('PASS '+checks+' actual merged cloud function read/deployment contract checks')
}
main().catch(e=>{console.error(e);process.exit(1)})
