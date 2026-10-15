'use strict'
const assert = require('assert')
const fs = require('fs')
const vm = require('vm')
const path = require('path')
const root = __dirname

function buildRepairEnv(options) {
  let definition = null
  const requests = [], triggered = []
  const page = { route:'pages/workerOrder/detail', data:{ orderId:'BX-W4', isMeasurementOrder:false, repairAppointment:options.appointment||null, afterSales:[{id:'AS-W4',type:'warranty_repair',negotiation_status:options.status||'pending',can_worker_process:true}] }, setData(p){Object.assign(this.data,p)}, showModal(e){this.lastModal=e}, loadDetail(){return Promise.resolve()} }
  const context = { require(){return {request(route,method,body){requests.push({route,method,body});return Promise.resolve({message:'已处理'})}}}, Component(v){definition=v}, getCurrentPages(){return [page]}, wx:{showModal(o){if(o.success)o.success({confirm:true,cancel:false,content:''})},showToast(){}}, console, setTimeout(fn){fn();return 1}, clearTimeout, Promise }
  const src=fs.readFileSync(path.join(root,'order-negotiation-panel.js'),'utf8'); vm.runInNewContext(src,context)
  const c={properties:{cases:options.cases||[],orderId:'BX-W4'},data:JSON.parse(JSON.stringify(definition.data)),setData(p){Object.assign(this.data,p)},triggerEvent(n){triggered.push(n)}}
  Object.keys(definition.methods).forEach(k=>c[k]=definition.methods[k].bind(c))
  return {c,page,requests,triggered}
}
async function flush(){await Promise.resolve();await Promise.resolve();await new Promise(r=>setTimeout(r,0))}

async function testRepair(){
  const wxml=fs.readFileSync(path.join(root,'order-negotiation-panel.wxml'),'utf8')
  let env=buildRepairEnv({status:'pending',cases:[{kind:'repair',reference:'AS-W4',status:'pending',can_respond:true}]}); env.c.syncRepairWorkflow(); assert.strictEqual(env.page.data.afterSales[0].can_worker_process,false); assert(wxml.includes('第 1 步 · 先回应申请'))
  env=buildRepairEnv({status:'accepted_processing',cases:[{kind:'repair',reference:'AS-W4',status:'accepted_processing'}]}); env.c.syncRepairWorkflow(); env.c.openRepairAppointment({currentTarget:{dataset:{index:0}}}); assert.strictEqual(env.page.lastModal.currentTarget.dataset.context,'repair'); assert.strictEqual(env.requests.length,0); assert(wxml.includes('第 2 步 · 已接受处理'))
  env=buildRepairEnv({status:'accepted_processing',appointment:{appointment_at:'2026-10-20 14:00'},cases:[{kind:'repair',reference:'AS-W4',status:'accepted_processing'}]}); env.c.syncRepairWorkflow(); env.c.completeRepair({currentTarget:{dataset:{index:0}}}); await flush(); assert.strictEqual(env.requests.length,1); assert.strictEqual(env.requests[0].route,'after_sales.repair.respond'); assert.strictEqual(env.requests[0].body.action,'complete'); assert(env.triggered.includes('changed')); assert(wxml.includes('修改预约不会自动完成维修'))
  env.page.route='pages/userOrder/detail'; env.c.syncRepairWorkflow(); const before=env.requests.length; env.c.completeRepair({currentTarget:{dataset:{index:0}}}); await flush(); assert.strictEqual(env.requests.length,before)
}

function buildIncomeEnv(requestImpl){
  let definition=null; const requests=[],toasts=[],modals=[]; let backs=0
  const context={require(){return{request(route,method,body,query){requests.push({route,method,body,query});return requestImpl(route,method,body,query)}}},Page(v){definition=v},wx:{showModal(o){modals.push(o);if(o.success)o.success({confirm:true,cancel:false})},showToast(o){toasts.push(o)},showLoading(){},hideLoading(){},stopPullDownRefresh(){},navigateBack(){backs++}},console,Promise,setTimeout,clearTimeout}
  vm.runInNewContext(fs.readFileSync(path.join(root,'platformIncome.js'),'utf8'),context)
  const p=Object.assign({},definition,{data:JSON.parse(JSON.stringify(definition.data))});p.setData=patch=>Object.assign(p.data,patch);return{p,requests,toasts,modals,backs:()=>backs}
}
async function testIncome(){
  const env=buildIncomeEnv((route,method,body,query)=>{if(route==='admin.workbench.order'){assert.deepStrictEqual(JSON.parse(JSON.stringify(query)),{id:'BX-PLAT'});return Promise.resolve({data:{tasks:[{task_type:'platform_income_verification',action_order_id:101,income_amount_cents:25000}]}})}if(route==='admin.platform-income.confirm')return Promise.resolve({message:'已结清'});return Promise.reject(new Error(route))})
  env.p.onLoad({id:'BX-PLAT',order_id:'999',amount:'0.01'});await flush();assert.strictEqual(env.p.data.actionOrderId,101);assert.strictEqual(env.p.data.confirmedAmount,'250.00');env.p.checkedChange({detail:{value:[]}});assert.strictEqual(env.p.data.receiptChecked,false);env.p.checkedChange({detail:{value:['confirmed']}});assert.strictEqual(env.p.data.receiptChecked,true);env.p.referenceInput({detail:{value:'BANK-001'}});env.p.submit();await flush();const submits=env.requests.filter(x=>x.route==='admin.platform-income.confirm');assert.strictEqual(submits.length,1);assert.deepStrictEqual(JSON.parse(JSON.stringify(submits[0].body)),{order_id:101,confirmed_amount:'250.00',payment_reference:'BANK-001',receipt_checked:true,note:''});assert.strictEqual(env.backs(),1)
  const stale=buildIncomeEnv(route=>route==='admin.workbench.order'?Promise.resolve({data:{tasks:[]}}):Promise.reject(new Error('no submit')));stale.p.onLoad({id:'STALE'});await flush();assert.strictEqual(stale.p.data.stale,true);stale.p.submit();assert.strictEqual(stale.requests.some(x=>x.route==='admin.platform-income.confirm'),false)
  const wxml=fs.readFileSync(path.join(root,'platformIncome.wxml'),'utf8');assert(wxml.includes('<checkbox-group bindchange="checkedChange">'));assert(wxml.includes('部分到账'));assert(!fs.readFileSync(path.join(root,'platformIncome.js'),'utf8').includes('options.amount'));assert(!fs.readFileSync(path.join(root,'platformIncome.js'),'utf8').includes('options.order_id'))
}

function testMerchantBoundary(){
  const source=fs.readFileSync(path.join(root,'merchant-role-boundary.wxml'),'utf8')
  const required=['order.is_merchant_order && actions.is_owner && !actions.is_admin','本单角色与确认','当前商家账号','现场对接人仅用于师傅上门联系','不因填写姓名或手机号获得支付、接受加价、验收等账号权限','师傅手机号继续按平台隐私规则开放','师傅电话默认隐藏，请等待师傅主动联系并预约']
  required.forEach(x=>assert(source.includes(x),x))
}

(async()=>{testMerchantBoundary();await testRepair();await testIncome();console.log('V2.5.0 next audit batch UI smoke OK')})().catch(e=>{console.error(e);process.exit(1)})
