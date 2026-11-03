'use strict'
const assert=require('node:assert/strict')
const fs=require('node:fs')
const vm=require('node:vm')
const source=fs.readFileSync('.validation/source/miniprogram/utils/SubscribeMessageUtils.js','utf8')

const backend={schema:2,global_enabled:true,items:[
  {key:'order_progress',scene_keys:['order_progress'],audience:'common',title:'订单进度提醒',description:'进度',template_id:'progress-template',template_title:'订单进度提醒',subscription_type:'one_time',available:true,important:true,remaining_count:0,total_authorized_count:0,authorization_status:''},
  {key:'order_fee_change',scene_keys:['order_fee_change'],audience:'common',title:'订单费用变更提醒',description:'费用',template_id:'fee-template',template_title:'订单费用变更提醒',subscription_type:'one_time',available:true,important:true,remaining_count:0,total_authorized_count:0,authorization_status:''},
  {key:'account_funds',scene_keys:['account_funds'],audience:'common',title:'账户资金变动提醒',description:'账户',template_id:'account-template',template_title:'账户资金变动提醒',subscription_type:'one_time',available:true,important:true,remaining_count:0,total_authorized_count:0,authorization_status:''},
  {key:'chat_unread',scene_keys:['chat_unread'],audience:'common',title:'聊天消息提醒',description:'聊天',template_id:'chat-template',template_title:'聊天消息提醒',subscription_type:'one_time',available:true,important:true,remaining_count:0,total_authorized_count:0,authorization_status:''}
]}
const requested=[]
const saved=[]
const context={
  module:{exports:{}},console,Promise,Date,Set,Object,
  wx:{
    getStorageSync(){return null},
    setStorageSync(){},
    getSetting({success}){success({subscriptionsSetting:{mainSwitch:true,itemSettings:{}}})},
    requestSubscribeMessage({tmplIds,success}){
      requested.push([...tmplIds])
      assert.ok(tmplIds.length>=1&&tmplIds.length<=3,'one WeChat request must contain at most three templates')
      success(Object.fromEntries(tmplIds.map(id=>[id,'accept'])))
    }
  },
  require(name){
    if(name.includes('ApiEnvironment'))return {onChange(){},storageKey:key=>key}
    if(name.includes('config/subscribe'))return {REVIEW_RESULT_TEMPLATE_ID:'review-template'}
    if(name.includes('OrderApi'))return {
      contextRevision:()=>1,
      request:(route,method,data)=>{
        if(route==='subscriptions.status')return Promise.resolve({data:structuredClone(backend)})
        if(route==='subscriptions.preference'){
          saved.push(data.key)
          const item=backend.items.find(row=>row.key===data.key)
          return Promise.resolve({data:{template_id:item.template_id,enabled:true,authorization_status:'accept',remaining_count:1,total_authorized_count:1}})
        }
        if(route==='subscriptions.diagnose')return Promise.resolve({data:{}})
        throw new Error('unexpected route '+route)
      }
    }
    throw new Error('unexpected require '+name)
  }
}
vm.runInNewContext(source,context,{filename:'SubscribeMessageUtils.js'})
const utils=context.module.exports

;(async()=>{
  const settings=await utils.getSubscriptionSettings(true)
  assert.deepEqual(Array.from(settings.items,x=>x.key),['order_progress','order_fee_change','account_funds','chat_unread'])
  assert.deepEqual(Array.from(settings.items,x=>x.title),['订单进度提醒','订单费用变更提醒','账户资金变动提醒','聊天消息提醒'])
  assert.equal(settings.items.some(x=>x.key==='financial_progress'),false)

  const first=await utils.requestSubscriptionScenes(['order_progress','order_fee_change','account_funds','chat_unread'])
  assert.equal(first.acceptedCount,3)
  assert.deepEqual(requested[0],['progress-template','fee-template','account-template'])
  assert.deepEqual(saved,['order_progress','order_fee_change','account_funds'])

  const second=await utils.requestImportantTopUp()
  assert.equal(second.acceptedCount,1)
  assert.deepEqual(requested[1],['chat-template'])
  assert.deepEqual(saved,['order_progress','order_fee_change','account_funds','chat_unread'])

  const refreshed=await utils.getSubscriptionSettings(false)
  assert.equal(refreshed.items.every(x=>x.remaining_count===1),true)
  console.log('subscription client four-template and three-template-limit contract: OK')
})().catch(error=>{console.error(error);process.exit(1)})
