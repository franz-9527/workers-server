'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const root=path.join(__dirname,'fixtures');
const manifest=require('./manifest.json');
for(const group of Object.values(manifest)){
  for(const [name,expected] of Object.entries(group)){
    const b=fs.readFileSync(path.join(root,name));
    const actual=crypto.createHash('sha1').update(Buffer.concat([Buffer.from('blob '+b.length+'\0'),b])).digest('hex');
    assert.equal(actual,expected,'blob mismatch '+name);
  }
}
const customer=require('./fixtures/CustomerOrderBillingPresentation.js');
const support=require('./fixtures/OrderSupportPresentation.js');
assert.equal(typeof customer.customerBill,'function');
assert.equal('offlineCollection' in customer,false);
assert.equal('workerCards' in customer,false);
assert.equal(typeof support.offlineCollection,'function');
assert.equal(typeof support.workerCards,'function');
assert.equal(typeof support.customerCards,'function');
assert.equal('customerBill' in support,false);

const userJs=fs.readFileSync(path.join(root,'userOrder-detail.js'),'utf8');
const userXml=fs.readFileSync(path.join(root,'userOrder-detail.wxml'),'utf8');
const userCss=fs.readFileSync(path.join(root,'userOrder-detail.wxss'),'utf8');
const workerJs=fs.readFileSync(path.join(root,'workerOrder-detail.js'),'utf8');
const workerXml=fs.readFileSync(path.join(root,'workerOrder-detail.wxml'),'utf8');
const workerCss=fs.readFileSync(path.join(root,'workerOrder-detail.wxss'),'utf8');

assert.ok(userJs.includes("CustomerOrderBillingPresentation.js"));
assert.ok(userJs.includes("CustomerBilling.customerBill(data)"));
assert.ok(!userJs.includes("OrderSupport.customerBill"));
assert.ok(!userJs.includes("offlineCollection: null"));
assert.ok(userXml.includes('class="bill-pull-tab"'));
assert.ok(userXml.includes('class="bill-drawer '));
assert.ok(!userXml.includes('class="offline-collection-notice"'));
assert.ok(userXml.includes('尾款延期记录')); // customer-visible record, not worker action
assert.ok(userXml.includes('商家订单线下尾款')); // customer-facing QR card
assert.ok(userCss.includes('.bill-drawer'));
assert.ok(userCss.includes('.bill-pull-tab'));

assert.ok(workerJs.includes("OrderSupport.offlineCollection(detail, true)"));
assert.ok(!workerJs.includes("CustomerOrderBillingPresentation"));
assert.ok(!workerJs.includes("billSheetOpen"));
assert.ok(workerXml.includes('class="offline-collection-notice"'));
assert.ok(workerXml.includes('实际收取尾款'));
assert.ok(workerXml.includes('尾款未收齐'));
assert.ok(workerXml.includes('登记尾款延期'));
assert.ok(workerXml.includes('平台服务费'));
assert.ok(!workerXml.includes('bill-pull-tab'));
assert.ok(!workerXml.includes('bill-drawer'));
assert.ok(!workerCss.includes('.bill-drawer'));

const base=()=>({
 viewer_mode:'owner',
 order:{id:'BXQZ123456',workflow_profile:'construction',lifecycle_status:'active',
 fulfillment_status:'in_progress',assignment_status:'accepted',is_platform_order:false,
 final_amount_cents:120000,booking_fee_cents:20000,payment_status:'paid',refund_status:'none'},
 assignment:{status:'accepted'},actions:{},media:[]
});
let d=base(), bill=customer.customerBill(d);
assert.equal(bill.summaryLabel,'线下待付参考');
assert.equal(bill.balanceLabel,'剩余约定尾款');
d=base(); d.order.payment_status='awaiting_payment'; bill=customer.customerBill(d);
assert.equal(bill.summaryLabel,'待付预约费');
d=base(); d.media=[{purpose:'collection_receipt'}]; bill=customer.customerBill(d);
assert.equal(bill.balanceLabel,'线下尾款待核对');
d=base(); d.order.workflow_profile='measurement'; d.order.final_amount_cents=80000; d.measurement={service_payment_status:'paid'};
bill=customer.customerBill(d); assert.equal(bill.balanceLabel,'测量服务款'); assert.equal(bill.hasTail,false);
console.log('PASS: user and worker order presentation modules are separated');
console.log('PASS: user drawer exists only in user order page');
console.log('PASS: worker receivable, adjustment, delay, service-fee UI remains only on worker order page');
console.log('PASS: customer billing scenarios remain correct after module split');
