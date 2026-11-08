'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const root=__dirname, files=path.join(root,'fixtures');
const manifest=require('./manifest.json');
for(const [name,expected] of Object.entries(manifest.files)){
 const source=fs.readFileSync(path.join(files,name));
 const sha=crypto.createHash('sha1').update(Buffer.concat([Buffer.from('blob '+source.length+'\0'),source])).digest('hex');
 assert.equal(sha,expected,'source Git blob mismatch '+name);
}
const presentation=require('./fixtures/order-support.js');
const base=()=>({
 viewer_mode:'owner',
 order:{id:'BXQZ123456',workflow_profile:'construction',lifecycle_status:'active',
  fulfillment_status:'in_progress',assignment_status:'accepted',is_platform_order:false,
  final_amount_cents:120000,booking_fee_cents:20000,payment_status:'paid',refund_status:'none'},
 assignment:{status:'accepted'},actions:{},media:[]
});
let data=base();
let bill=presentation.customerBill(data);
assert.equal(bill.summaryLabel,'线下待付参考');
assert.equal(bill.summaryAmount,'¥1000.00');
assert.equal(bill.balanceLabel,'剩余约定尾款');
assert.equal(bill.balanceAmount,'¥1000.00');
assert.ok(bill.rows.some(r=>r.label==='线上预约费'&&r.value==='¥200.00'&&r.style==='paid'));
assert.ok(bill.rows.some(r=>r.label==='约定线下尾款'&&r.value==='¥1000.00'));
data=base();data.order.payment_status='awaiting_payment';
bill=presentation.customerBill(data);
assert.equal(bill.summaryLabel,'待付预约费');
assert.equal(bill.summaryAmount,'¥200.00');
assert.notEqual(bill.balanceLabel,'剩余约定尾款');
data=base();data.media=[{purpose:'collection_receipt'}];
bill=presentation.customerBill(data);
assert.notEqual(bill.summaryLabel,'线下待付参考');
assert.equal(bill.balanceLabel,'线下尾款待核对');
assert.ok(bill.balanceNote.includes('凭证不等于'));
data=base();data.collection_adjustment={status:'approved',requested_tail_cents:70000};
bill=presentation.customerBill(data);
assert.equal(bill.balanceAmount,'¥700.00');
assert.ok(bill.tip.includes('批准金额'));
data=base();data.order.workflow_profile='measurement';data.order.final_amount_cents=80000;data.measurement={service_payment_status:'paid'};
bill=presentation.customerBill(data);
assert.equal(bill.balanceLabel,'测量服务款');
assert.equal(bill.hasTail,false);
assert.ok(bill.rows.some(r=>r.label==='测量服务补款（线上）'&&r.style==='paid'));
data=base();data.order.is_platform_order=true;data.order.booking_fee_cents=0;
data.platform_order={customer_flow_mode:'platform_managed',customer_contract_total_cents:200000,customer_tail_cents:0,booking_paid_cents:0};
bill=presentation.customerBill(data);
assert.equal(bill.summaryLabel,'平台托管');
assert.equal(bill.balanceLabel,'平台负责收款安排');
assert.ok(!bill.rows.some(r=>r.label==='约定线下尾款'));
data=base();data.order.refund_status='processing';
bill=presentation.customerBill(data);
assert.equal(bill.balanceLabel,'原订单金额');
assert.ok(bill.balanceNote.includes('不代表仍需付款'));
data=base();data.order.final_amount_cents=0;data.order.payment_status='awaiting_payment';
bill=presentation.customerBill(data);
assert.equal(bill.summaryLabel,'待付预约费');
console.log('PASS: 8 customer billing scenarios incl. paid, unpaid, receipt, adjusted, measurement, platform, refund, unpriced');

const wxml=fs.readFileSync(path.join(files,'detail.wxml'),'utf8');
const style=fs.readFileSync(path.join(files,'detail.wxss'),'utf8');
const script=fs.readFileSync(path.join(files,'detail.js'),'utf8');
const has=s=>assert.ok(wxml.includes(s),'missing WXML '+s);
has('class="bill-pull-tab"');
has('bindtap="toggleBillSheet"');
has('catchtouchstart="billTouchStart"');
has('catchtouchend="billTouchEnd"');
has('class="bill-drawer-grab-zone"');
has('class="bill-drawer-mask');
has('class="bill-drawer');
has('class="bill-line');
has('{{bill.balanceNote}}');
has('bindtap="openBillSheet"');
assert.ok(!wxml.includes('class="offline-collection-notice"'),'old top billing card still shown');
for(const x of ['@keyframes bill-pull-breathe','.bill-drawer.is-visible','.bill-pull-tab',
 '.order-footer-safe','env(safe-area-inset-bottom)','prefers-color-scheme:dark']) assert.ok(style.includes(x),'missing style '+x);
assert.ok(script.includes('OrderSupport.customerBill(data)'));
assert.ok(script.includes('bill: null, billSheetOpen: false'));
let page;
require('node:vm').runInNewContext(script,{Page:o=>page=o,require:()=>({}),console,setTimeout,clearTimeout,Promise,Date},{filename:'detail.js'});
assert.ok(page);
let ctx={data:{bill:{},billSheetOpen:false},setData(v){Object.assign(this.data,v)}};
page.billTouchStart.call(ctx,{touches:[{clientY:300}]});
page.billTouchEnd.call(ctx,{changedTouches:[{clientY:230}]});
assert.equal(ctx.data.billSheetOpen,true,'upward pull opens drawer');
ctx.billLastSwipeAt=0;
page.toggleBillSheet.call(ctx);
assert.equal(ctx.data.billSheetOpen,false,'tap closes drawer');
page.openBillSheet.call(ctx);
page.billTouchStart.call(ctx,{touches:[{clientY:200}]});
page.billTouchEnd.call(ctx,{changedTouches:[{clientY:260}]});
assert.equal(ctx.data.billSheetOpen,false,'downward pull closes drawer');
ctx.data.bill=null;
page.openBillSheet.call(ctx);
assert.equal(ctx.data.billSheetOpen,false,'drawer cannot open without data');
console.log('PASS: touch/tap toggles and no-bill guard');
console.log('PASS: safe area, motion, theme, modal, accounting markup contracts');
