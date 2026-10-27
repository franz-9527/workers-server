const test=require('node:test'),assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm'),path=require('path')
function fixture(){
 const requests=[],routes=[],storage=new Map();let definition,quote
 const item={recipient_id:'12',display_name:'李师傅',message_id:'8',policy_version:'1',wave:'a'.repeat(64),fee_cents:10,quote_token:'signed'}
 quote={enabled:true,payer_id:'11',items:[item],available_cents:100}
 const manager={epoch:1,enabled:true,io:{now:()=>100000,randomBytes:async()=> 'b'.repeat(32),async sms(action,data){requests.push({action,data:structuredClone(data)});if(action==='quote')return structuredClone(quote);if(action==='create')return{state:'queued',fee_cents:10,client_request_id:data.client_request_id};return{state:'delivered',fee_cents:10}}}
 const wx={getStorageSync:k=>storage.get(k),setStorageSync:(k,v)=>storage.set(k,v),navigateTo:r=>routes.push(r.url),showToast(){}}
 const module={exports:{}}
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../miniprogram/subpackages/chat/conversation/sms.js'),'utf8'),{module,require:()=>({storageKey:x=>'test:'+x}),wx,setTimeout:()=>1,clearTimeout(){}})
 const p={...module.exports,id:'9',visible:true,life:1,manager,data:{canSend:true,messages:[{messageId:'8',isMine:true}],smsBusy:false},current(l,e){return this.visible&&l===this.life&&e===manager.epoch},setData(v){Object.assign(this.data,v)}}
 return{p,manager,requests,routes,quote(v){quote=v},item,base:()=>structuredClone(quote)}
}
test('eligible poll shows only a hint and never reserves or marks read',async()=>{const f=fixture();await f.p.refreshSMS(true);assert.equal(f.p.data.smsAnchor,'8');assert(f.p.data.smsFirstHint);assert.deepEqual(f.requests.map(r=>r.action),['quote']);await f.p.refreshSMS(true);assert.equal(f.p.data.smsFirstHint,false)})
test('zero and insufficient balances offer recharge without creating SMS',async()=>{const f=fixture(),q=f.base();q.available_cents=0;f.quote(q);await f.p.openSMS();assert(f.p.data.smsDialog.insufficient);await f.p.confirmSMS();assert.deepEqual(f.routes,['/pages/recharge/recharge']);assert(!f.requests.some(r=>r.action==='create'))})
test('recipient read before confirmation suppresses the hold',async()=>{const f=fixture();await f.p.openSMS();f.quote({...f.base(),items:[]});await f.p.confirmSMS();assert(!f.requests.some(r=>r.action==='create'))})
test('price increase requires a fresh visible confirmation',async()=>{const f=fixture();await f.p.openSMS();const q=f.base();q.items[0].fee_cents=20;f.quote(q);await f.p.confirmSMS();assert.equal(f.p.data.smsDialog.fee,'¥0.20');assert(!f.requests.some(r=>r.action==='create'))})
test('explicit confirmation creates once and replays original key after uncertain transport',async()=>{
 const f=fixture();await f.p.openSMS();let first=true
 const original=f.manager.io.sms;f.manager.io.sms=async(a,d)=>{if(a==='create'){f.requests.push({action:a,data:structuredClone(d)});if(first){first=false;throw Error('network')}return{state:'queued',client_request_id:d.client_request_id,fee_cents:10}}if(a==='status'){f.requests.push({action:a,data:d});return{state:'not_found'}}return original(a,d)}
 await f.p.confirmSMS();assert(f.p.smsPending);assert.equal(f.p.data.smsDialog.state,'pending');await f.p.checkSMS()
 const creates=f.requests.filter(r=>r.action==='create');assert.equal(creates.length,2);assert.deepEqual(creates[0].data,creates[1].data)
})
test('hidden or changed account cannot resume a stale quote and create a hold',async()=>{const f=fixture();let done;f.manager.io.sms=()=>new Promise(r=>done=r);const work=f.p.openSMS();f.p.visible=false;f.p.life++;f.manager.epoch++;done(f.base());await work;assert.equal(f.p.data.smsDialog,undefined);assert(!f.p.smsPending)})
test('closing the popup never sends; account reset erases paid operation state',async()=>{const f=fixture();await f.p.openSMS();f.p.closeSMS();assert(!f.requests.some(r=>r.action==='create'));f.p.smsPending={request:'old'};f.p.stopSMS(true);assert.equal(f.p.smsPending,null);assert.equal(f.p.data.smsDialog,null)})
