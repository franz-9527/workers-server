const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm')
const flush=async()=>{for(let i=0;i<10;i++)await new Promise(setImmediate)}
function fixture(){
 let definition;const calls=[],pending=[]
 const api={request:()=>{throw Error('unrequested SMS/network query')}}
 const app={globalData:{userInfo:{mobile:'13800000000'}},ensureUserReady:()=>Promise.resolve({mobile:'13800000000'})}
 const wx={cloud:{database:()=>({}),callFunction:o=>{calls.push(o.data);if(o.data.action==='miniAdmin.identity')o.success({result:{code:1,data:{is_admin:true}}});else pending.push(o)}},showToast(){},showLoading(){},hideLoading(){},showModal(){}}
 vm.runInNewContext(fs.readFileSync('miniprogram/pages/record/detail.js','utf8'),{Page:x=>definition=x,getApp:()=>app,require:()=>api,wx,Promise,Date,Map,Set,console,setTimeout,clearTimeout})
 const p={...definition,data:structuredClone(definition.data),setData(x){Object.assign(this.data,x)}};p.data.id='fixture-worker'
 return{p,calls,pending,success:i=>pending[i].success({result:{code:1,data:{_openid:'fixture-owner'}}}),fail:i=>pending[i].fail(new Error('fixture offline'))}
}
test('administrator identity check does not load or reveal record identity',async()=>{
 const f=fixture();await f.p.loadAdminIdentity()
 assert.equal(f.p.data.isOrderAdmin,true);assert.equal(f.p.data.adminRecordIdentityVisible,false);assert.equal(f.p.data.adminWorkerOpenid,'')
 assert.deepEqual(f.calls.map(x=>x.action),['miniAdmin.identity'])
})
test('manual reveal loads once, blocks repeated taps while loading and keeps SMS independent',async()=>{
 const f=fixture();await f.p.loadAdminIdentity();const one=f.p.showAdminRecordIdentity();const duplicate=f.p.showAdminRecordIdentity()
 assert.equal(f.p.data.adminRecordIdentityVisible,true);assert.equal(f.p.data.adminWorkerIdentityLoading,true);assert.equal(f.pending.length,1)
 f.success(0);await Promise.all([one,duplicate]);assert.equal(f.p.data.adminWorkerOpenid,'fixture-owner');assert.equal(f.p.data.adminWorkerIdentityLoaded,true);assert.equal(f.p.data.adminWorkerIdentityLoading,false)
 await f.p.showAdminRecordIdentity();assert.equal(f.pending.length,1)
})
test('nonadministrator cannot reveal IDs or trigger private cloud reads',async()=>{
 const f=fixture();await f.p.showAdminRecordIdentity();await f.p.loadAdminPrivateDetail()
 assert.equal(f.calls.length,0);assert.equal(f.p.data.adminRecordIdentityVisible,false)
})
test('failed identity request supports tap retry without a permanently loading row',async()=>{
 const f=fixture();await f.p.loadAdminIdentity();const one=f.p.showAdminRecordIdentity();f.fail(0);await one
 assert.equal(f.p.data.adminWorkerIdentityLoading,false);assert.equal(f.p.data.adminWorkerIdentityLoaded,false);assert.match(f.p.data.adminWorkerIdentityError,/重试/)
 const retry=f.p.showAdminRecordIdentity();assert.equal(f.pending.length,2);f.success(1);await retry;assert.equal(f.p.data.adminWorkerIdentityError,'');assert.equal(f.p.data.adminWorkerIdentityLoaded,true)
})
test('leaving a page or changing worker discards late private detail responses',async()=>{
 for(const leave of [p=>p.onUnload(),p=>{p.data.id='other-worker'}]){
  const f=fixture();await f.p.loadAdminIdentity();const one=f.p.showAdminRecordIdentity();leave(f.p);f.success(0);await one
  assert.equal(f.p.data.adminWorkerOpenid,'');assert.equal(f.p.data.adminWorkerIdentityLoaded,false)
 }
})
test('identity values appear only inside administrator and manual-reveal gates in WXML',()=>{
 const wxml=fs.readFileSync('miniprogram/pages/record/detail.wxml','utf8'),start=wxml.indexOf('bindtap="showAdminRecordIdentity"'),row=wxml.slice(start-80,wxml.indexOf('bindtap="loadAdminSmsStats"',start))
 assert.match(row,/wx:if="{{isOrderAdmin}}"/);assert.match(row,/wx:if="{{adminRecordIdentityVisible}}"/);assert.match(row,/点击查看/);assert.match(row,/记录：{{id}}/);assert.match(row,/_openid：{{adminWorkerOpenid}}/)
})
