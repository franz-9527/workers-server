const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const test=require('node:test');
const load=(p,ctx)=>vm.runInNewContext(fs.readFileSync(p,'utf8').replace(/^import .*$/gm,''),ctx);
test('submit invokes subscription during the tap, then validates the server policy and saves',async()=>{
 let page,release;const order=[];
 load('miniprogram/subpackages/account/workerProfile/index.js',{
   Page:d=>page=d,genDefDate:()=>'',getApp:()=>({globalData:{}}),MAPCONFIG:{MAPKEY:'fixture'},requirePlugin:()=>({}),
   require:name=>name.includes('SubscribeMessageUtils')?{ensureReviewResultSubscriptionForSubmit:()=>{order.push('tap');return new Promise(r=>{release=r;});}}:{},
 });
 page.data=Object.assign(page.data,{currentReviewStatus:'',isPublishChecking:false});
 page.setData=p=>Object.assign(page.data,p);
 page.checkLogin=()=>true;
 page.validatePublishData=()=>({imgList:[]});
 page.checkPublishLimit=async()=>{order.push('policy');return {};};
 page.handlePublishLimitResult=()=>true;
 page.confirmProfileEditIfNeeded=async()=>true;
 page.saveData=async d=>{order.push('save');assert.equal(d.reviewSubscribeStatus,'accept');};
 const done=page.tapPublish();
 assert.deepEqual(order,['tap']);
 release({status:'accept'});
 await done;
 assert.deepEqual(order,['tap','policy','save']);
 assert.equal(page.data.isPublishChecking,false);
});
test('audit status retains specific skip, failure, retry and accepted results',()=>{
 const ctx={Page(){},require:()=>({})};
 load('miniprogram/subpackages/account/review/index.js',ctx);
 const label=(code,errorStatus)=>ctx.getReviewNoticeMeaning({statusCode:code,errorStatus});
 assert.equal(label('SERVER_OUTBOX_SKIPPED','申请人已关闭入驻通知'),'申请人已关闭入驻通知');
 assert.equal(label('SERVER_OUTBOX_FAILED','模板字段不匹配'),'模板字段不匹配');
 assert.equal(label('SERVER_OUTBOX_RETRY','接口暂不可用'),'待重试 · 接口暂不可用');
 assert.equal(label('0','发送成功'),'微信已受理');
});
