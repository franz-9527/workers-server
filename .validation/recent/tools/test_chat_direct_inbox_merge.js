'use strict'

const assert=require('assert')
const fs=require('fs')
const path=require('path')
const root=path.resolve(__dirname,'..')
const Inbox=require(path.join(root,'miniprogram/utils/ChatInboxModel.js'))
const Presentation=require(path.join(root,'miniprogram/utils/ChatPresentation.js'))

const unread=(overrides={})=>({
  conversation_type:'direct',conversation_mode:'direct',policy_version:'7',conversation_state:'active',can_send:true,
  unread_count:2,unread_count_capped:false,last_message_at:'2026-11-22T08:00:00Z',
  last_message:{message_id:'901',state_version:'1',state:'published',message_type:'image'},
  preferences:{member_version:'a'.repeat(64),pinned:true,pinned_version:'2',later:true,later_version:'3',account_revision:'4',muted:true},
  ...overrides
})
const accepted=(overrides={})=>({
  relationship:'200',ready:true,version:'7',application:'accepted',requested_by_me:false,blocked:false,blocked_by_me:false,peer_id:'10203',
  peer:{display_name:'张师傅',roles:['worker'],version:'2'},...overrides
})

function modelTests(){
  const row=Inbox.directRow(accepted(),unread())
  assert(row,'accepted Direct should normalize')
  assert.strictEqual(row.conversationType,'direct')
  assert.strictEqual(row.displayTitle,'张师傅')
  assert.strictEqual(row.subtitle,'师傅 · 普通私聊')
  assert.strictEqual(row.avatarPath,Inbox.identities.avatars.worker)
  assert.strictEqual(row.peerActorId,'10203')
  assert.strictEqual(row.unreadCount,2)
  assert.strictEqual(row.unreadLabel,'2')
  assert.strictEqual(row.preferences.pinned,true)
  assert.strictEqual(row.preferences.later,true)
  assert.strictEqual(row.preferences.muted,true)
  assert.strictEqual(row.lastText,'[图片]')
  assert.strictEqual(Presentation.timeLabel(row.activityAt,'2026-11-22T09:00:00Z'),'16:00')
  assert(Inbox.directRow(accepted({application:'pending'}),unread()),'legacy pending snapshots should no longer hide an otherwise valid Direct conversation')
  assert.strictEqual(Inbox.directRow(accepted(),unread({last_message:null})),null,'empty prepared Direct relationships must not enter the main inbox')
  assert.strictEqual(Inbox.directRow(accepted({blocked:true}),unread()),null)
  assert.strictEqual(Inbox.directRow(accepted(),unread({conversation_type:'order'})),null)

  const support=Inbox.supportInboxRow({
    conversation_id:'300',owner_id:'10203',owner_suffix:'0203',owner_name:'张师傅',owner_role:'worker',
    last_message_id:'902',work_version:'4',last_user_seq:'8',handled:false,pinned:false,unread_count:1,unread_count_capped:false,
    last_user_message_at:'2026-11-22T08:30:00Z',
    last_user_message:{message_id:'902',state_version:'1',state:'published',message_type:'text',text:'这里需要处理'}
  })
  assert(support&&support.conversationType==='support'&&support.peerActorId==='10203','Support inbox row exposes the trusted owner grouping key')
  const groups=Inbox.adminUserGroups([row,support],{'user:10203':true})
  assert.strictEqual(groups.length,1,'same user Direct and Support rows fold into one admin user group')
  assert.strictEqual(groups[0].conversationCount,2)
  assert.strictEqual(groups[0].expanded,true)
  assert(groups[0].typeSummary.includes('反馈 1')&&groups[0].typeSummary.includes('私聊 1'))
}

function pageFixture(){
  let definition=null
  const navigations=[]
  let inboxItems=[
    accepted(),
    accepted({relationship:'201',ready:false,application:'pending',requested_by_me:false,peer:{display_name:'新联系人',roles:[],version:'1'}})
  ]
  let summaries=[{conversation_id:'200',unread:unread()}]
  let summaryError=null
  const manager={
    enabled:true,ordersOnly:false,simulation:false,testSandbox:false,identityEpoch:1,epoch:1,state:'socket',
    credential:async()=> 'fixture-token',
    io:{
      http:async(pathName,body,token,scene)=>{
        assert.strictEqual(pathName,'direct/summaries')
        assert.strictEqual(token,'fixture-token')
        assert.strictEqual(scene,'direct')
        assert.deepStrictEqual(body.conversation_ids,['200'])
        if(summaryError)throw Object.assign(new Error('summary failed'),{code:summaryError})
        return {items:summaries}
      }
    },
    refreshes:0,refreshAccount(){this.refreshes++},subscribe(){return()=>{}}
  }
  const OrderApi={
    request:async(route,method,body)=>{
      assert.strictEqual(method,'POST')
      if(route==='chat.direct.availability')return{data:{enabled:true}}
      if(route==='chat.direct.inbox'){
        assert.deepStrictEqual(body,{after:'0',filter:'active'})
        return{data:{items:inboxItems,next:''}}
      }
      throw Error('unexpected route '+route)
    }
  }
  const modules={
    '../../utils/NotificationState.js':{subscribe(){return()=>{}},refresh(){return Promise.resolve({})}},
    '../../utils/ChatRuntime.js':{get(){return manager},refreshAvailability(){return Promise.resolve()},discoverCollaborations(){return Promise.resolve(false)},toggleChatScope(){}},
    '../../utils/ChatInboxModel.js':Inbox,
    '../../utils/ChatPresentation.js':Presentation,
    '../../utils/ApiEnvironment.js':{current(){return{baseURL:'https://test.invalid/api/'}}},
    '../../utils/OrderApi.js':OrderApi
  }
  const wx={
    navigateTo(v){navigations.push(v.url)},
    showActionSheet(){},
    stopPullDownRefresh(){},
    showToast(){}
  }
  const code=fs.readFileSync(path.join(root,'miniprogram/pages/chatMessages/index.js'),'utf8')
  new Function('require','Page','getApp','wx',code)(
    name=>{if(Object.prototype.hasOwnProperty.call(modules,name))return modules[name];throw Error('unexpected require '+name)},
    value=>{definition=value},
    ()=>({globalData:{userInfo:{}}}),
    wx
  )
  assert(definition,'chatMessages Page was not registered')
  const page={...definition,data:{...definition.data}}
  page.setData=function(patch,done){Object.assign(this.data,patch);if(done)done()}
  page._visible=true;page._showSerial=1;page.activeIdentity='fixture'
  page.chatManager=manager;page.digestMap={};page.pendingDigests={};page.directMap={};page.memberIdentities={}
  page.data.isLoggedIn=true;page.data.chatEnabled=true;page.data.chatRole='all';page.data.chatFilter='all';page.data.ordersOnly=false
  return{
    page,manager,navigations,
    setInbox(value){inboxItems=value},
    setSummaries(value){summaries=value},
    setSummaryError(value){summaryError=value}
  }
}

async function pageTests(){
  const f=pageFixture(),p=f.page
  await p.loadDirectInbox(1)
  assert.strictEqual(p.data.directEnabled,true)
  assert.strictEqual(p.data.directSummaryStale,false)
  assert(p.directMap['200'],'accepted Direct should enter unified map')
  assert(!p.directMap['201'],'unprojected Direct must not become a message row')
  assert(p.data.visibleChatRows.some(row=>row.conversationId==='200'&&row.conversationType==='direct'),'Direct should be mixed into the main inbox')

  p.openConversation({currentTarget:{dataset:{id:'200'}}})
  assert.strictEqual(f.navigations.pop(),'/subpackages/chat/direct/index?id=200')

  p.data.chatRole='customer';p.showChatRows()
  assert(!p.data.visibleChatRows.some(row=>row.conversationId==='200'),'order role filters must hide Direct')
  p.data.chatRole='all';p.data.chatFilter='ended';p.showChatRows()
  assert(!p.data.visibleChatRows.some(row=>row.conversationId==='200'),'ended-order filter must hide Direct')
  p.data.chatFilter='later';p.showChatRows()
  assert(p.data.visibleChatRows.some(row=>row.conversationId==='200'),'later filter should include Direct with later=true')
  p.data.chatFilter='all';p.showChatRows()

  f.setSummaries([])
  await p.loadDirectInbox(1)
  assert(!p.directMap['200'],'successful omitted summary must clear stale Direct preview')

  f.setSummaries([{conversation_id:'200',unread:unread()}])
  await p.loadDirectInbox(1)
  assert(p.directMap['200'])
  f.setSummaryError('CHAT_NETWORK_ERROR')
  await p.loadDirectInbox(1)
  assert(p.directMap['200'],'transient summary failure may keep the last trusted summary for a still-eligible relationship')
  assert.strictEqual(p.data.directSummaryStale,true)

  f.setSummaryError('CHAT_UNAUTHORIZED')
  await p.loadDirectInbox(1)
  assert.strictEqual(Object.keys(p.directMap).length,0,'explicit authorization loss must clear cached Direct summaries')
  assert.strictEqual(p.data.directEnabled,false)

  f.setSummaryError(null)
  f.setSummaries([{conversation_id:'200',unread:unread()}])
  await p.loadDirectInbox(1)
  assert(p.directMap['200'])

  f.setInbox([accepted({blocked:true})])
  await p.loadDirectInbox(1)
  assert(!p.directMap['200'],'blocked relationship must clear the prior preview even when no new summary is fetched')

  let realtime=0
  p.data.directEnabled=true;p.data.ordersOnly=false
  p.loadDirectInbox=async serial=>{assert.strictEqual(serial,1);realtime++}
  p.onChatEvent('account.summary',{badge_count:1})
  assert.strictEqual(realtime,1,'complete account summary should refresh the mixed Direct snapshot')

  const emptyOnly=pageFixture(),q=emptyOnly.page
  emptyOnly.setInbox([accepted({relationship:'201',ready:false,started:false,peer:{display_name:'新联系人',roles:[],version:'1'}})])
  emptyOnly.manager.credential=async()=>{throw Error('unprojected empty Direct must not request Go credential')}
  await q.loadDirectInbox(1)
  assert.strictEqual(q.data.directSummaryStale,false)
  assert.strictEqual(Object.keys(q.directMap).length,0)
}

function markupTests(){
  const xml=fs.readFileSync(path.join(root,'miniprogram/pages/chatMessages/index.wxml'),'utf8')
  const css=fs.readFileSync(path.join(root,'miniprogram/pages/chatMessages/index.wxss'),'utf8')
  const direct=fs.readFileSync(path.join(root,'miniprogram/subpackages/chat/direct/index.js'),'utf8')
  assert(xml.includes('data-action="direct"')&&xml.includes('私聊管理'))
  assert(!xml.includes('directAttentionCount')&&!xml.includes('direct-request-row')&&!xml.includes('私聊申请'))
  assert(xml.includes('!visibleChatRows.length'),'support row must still suppress the contradictory empty state')
  assert(!xml.includes('查看私聊和会话申请'),'persistent second-level Direct row should be removed')
  assert(xml.includes('正在同步会话'))
  assert(!css.includes('.direct-request-avatar')&&!css.includes('.direct-request-mark'))
  assert(direct.includes('Inbox.directSummary(unread)'),'Direct detail list and main inbox should share the same summary parser')
  assert(!direct.includes("['request','accept','reject'"),'Direct must not expose request/accept/reject transitions')
}

modelTests()
markupTests()
pageTests().then(()=>console.log('Unified Direct inbox merge: OK')).catch(error=>{console.error(error);process.exitCode=1})