'use strict'
const assert=require('node:assert/strict')
const fs=require('node:fs')
const path=require('node:path')
const root=__dirname
const read=name=>fs.readFileSync(path.join(root,name),'utf8')

// Conversation menu: reproduce the reported failure condition where active focus is temporarily empty.
const interactionModule={exports:{}}
const routes=[],modals=[],actions=[]
let keyboard=0
new Function('module','wx',read('interaction.js'))(interactionModule,{
 hideKeyboard(){keyboard++},
 navigateTo(o){routes.push(o.url)},
 showModal(o){modals.push(o)}
})
const p={...interactionModule.exports,visible:true,life:8,id:'123',
 manager:{enabled:true,epoch:5,active:'',testSandbox:false},
 data:{status:'http',showChatMenu:false,showTools:true,directCalls:true,isGroup:true},
 setData(patch,done){Object.assign(this.data,patch);if(done)done()},
 openGroup(){actions.push('group')},
 openOrder(){actions.push('order')},
 openCallHistory(){actions.push('calls')},
 openGovernance(){actions.push('governance')}
}
p.chatInfo()
assert.equal(p.data.showChatMenu,true)
assert.equal(p.data.showTools,false)
assert.equal(keyboard,1)
p.runChatMenuAction({currentTarget:{dataset:{action:'storage'}}})
assert.equal(routes[0],'/subpackages/chat/storage/index')
assert.equal(p.data.showChatMenu,false)
p.chatInfo();p.runChatMenuAction({currentTarget:{dataset:{action:'group'}}})
assert(actions.includes('group'))
p.chatInfo();p.runChatMenuAction({currentTarget:{dataset:{action:'governance'}}})
assert(actions.includes('governance'))
p.chatInfo();p.runChatMenuAction({currentTarget:{dataset:{action:'simulation'}}})
assert.equal(modals.length,1)

// WXML must not use catchtap for menu action rows; the panel itself still catches bubbling.
const wxml=read('conversation.wxml')
assert.equal((wxml.match(/bindtap="runChatMenuAction"/g)||[]).length,6)
assert.equal((wxml.match(/catchtap="runChatMenuAction"/g)||[]).length,0)
assert(wxml.includes('catchtap="stopMenuTouch"'))

// Returning from group settings should pop back to the existing conversation instead of stacking a duplicate page.
const group=read('group-index.js')
assert(group.includes("this.source==='conversation'"))
assert(group.includes('wx.navigateBack({delta:1})'))

// Inbox navigation actions should close synchronously and not rely on a setData callback.
const inbox=read('inbox.js')
const menu=inbox.slice(inbox.indexOf('runInboxMenuAction(event)'),inbox.indexOf('openNotifications()'))
assert(menu.includes('this.setData({showInboxMenu:false})'))
assert(!menu.includes('this.setData({showInboxMenu:false},()=>'))

console.log('chat menu click regression: OK')
