const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs')
const {ChatManager}=require('../miniprogram/utils/ChatManager.js')
const source=fs.readFileSync(require.resolve('../miniprogram/subpackages/chat/conversation/index.js'),'utf8')
const avSource=fs.readFileSync(require.resolve('../miniprogram/subpackages/chat/conversation/av.js'),'utf8')
const limits=require('../miniprogram/utils/ChatAV.js').limits
const flush=async()=>{for(let i=0;i<12;i++)await new Promise(r=>setImmediate(r))}
function fixture(){
 let picker,definition;const selected=[],videos=[],wx={
  chooseMedia(q){picker=q},showActionSheet(q){q.success({tapIndex:1})},
  compressVideo(q){q.success({tempFilePath:'compressed.mp4'})},getVideoInfo(q){q.success({width:640,height:480,duration:2})}
 }
 const model={canSend:true},manager={epoch:1,identityEpoch:1,enabled:true,active:'9',cache:new Map([['9',model]]),
  images:{stage(id,files){selected.push({id,files})},list(){return []},setVisible(){},urls:new Map()},
  av:{stage(id,file){videos.push({id,file})}},io:{av:{info:async()=>({size:102400})}},focus(id){this.active=id},typing:()=>Promise.resolve()}
 const module={exports:{}};vm.runInNewContext(avSource,{module,require:()=>({limits}),wx,Promise})
 vm.runInNewContext(source,{Page(p){definition=p},require(name){
  if(name.includes('ChatRuntime'))return {refreshAvailability(){}}
  if(name.includes('ChatInboxModel'))return {validID:()=>true}
  if(name.includes('ChatManager'))return {}
  if(name.endsWith('/calls.js'))return require('../miniprogram/subpackages/chat/conversation/calls.js')
  if(name.endsWith('/av.js'))return module.exports
  return {}
 },wx,setTimeout,clearTimeout,Promise,console})
 const p={data:JSON.parse(JSON.stringify(definition.data)),setData(values,done){Object.assign(this.data,values);done?.()}}
 for(const [k,v]of Object.entries(definition))if(typeof v==='function')p[k]=v.bind(p)
 Object.assign(p,{id:'9',manager,managerEpoch:1,managerIdentityEpoch:1,life:1,visible:true,views:{},identities:{},atBottom:true,mode:'latest'})
 p.data.canSend=true;p.data.videoEnabled=true;p.data.status='http'
 for(const name of ['stopSMS','closeMessageMenu','stopReceiptPoll','stopAV','resetReceipts','clearIdentities','refreshComposer','refreshRestriction','startAV','refreshCalls','startSMS','startReceiptPoll','loadParticipants','refreshAV'])p[name]=()=>{}
 p.showError=e=>{throw e}
 return {p,manager,selected,videos,picker:()=>picker,
  hide(){p.onHide();manager.epoch++},
  show(){p.onShow()},
  ready(){p.data.canSend=true;p.data.videoEnabled=true;p.consumeImageSelection();return p.consumeVideo()}}
}
test('transport-only foreground restore keeps the rendered conversation snapshot',()=>{
 const f=fixture();f.p.initialized=true;f.p.views={'1':{messageId:'1'}};f.p.data.messages=[{messageId:'1'}]
 f.hide();f.show()
 assert.equal(f.p.initialized,true);assert.equal(f.p.data.messages.length,1);assert(f.p.views['1'])
})
test('transport background advances epoch without changing account identity',()=>{
 const m=new ChatManager({now:()=>0,setTimeout(){return 1},clearTimeout(){}}),identity=m.identityEpoch,epoch=m.epoch
 m.setForeground(false);assert(m.epoch>epoch);assert.equal(m.identityEpoch,identity)
 m.reset();assert(m.identityEpoch>identity)
})
for(const callbackFirst of [true,false])test('image survives native picker hide/show; callback first='+callbackFirst,()=>{
 const f=fixture();f.p.chooseImages();const picker=f.picker();f.hide()
 if(callbackFirst)picker.success({tempFiles:[{tempFilePath:'photo.jpg',size:10000}]})
 f.show()
 if(!callbackFirst)picker.success({tempFiles:[{tempFilePath:'photo.jpg',size:10000}]})
 f.ready();assert.equal(f.selected.length,1);assert.equal(f.selected[0].files[0].path,'photo.jpg')
 f.ready();assert.equal(f.selected.length,1,'must not stage a second copy')
})
test('picker selection after account/site reset is discarded',()=>{
 const f=fixture();f.p.chooseImages();f.hide();f.manager.identityEpoch++;f.p.handleEvent('reset');f.show()
 f.picker().success({tempFiles:[{tempFilePath:'private.jpg',size:10000}]});f.ready();assert.equal(f.selected.length,0)
})
test('unloaded page cannot accept a late native selection',()=>{
 const f=fixture();f.p.chooseImages();f.p.onUnload();f.manager.epoch++;f.show()
 f.picker().success({tempFiles:[{tempFilePath:'private.jpg',size:10000}]});f.ready();assert.equal(f.selected.length,0)
})
test('permission revocation clears a selection already returned while hidden',()=>{
 const f=fixture();f.p.chooseImages();f.hide()
 f.picker().success({tempFiles:[{tempFilePath:'photo.jpg',size:10000}]})
 f.p.handleEvent('conversation.clear',{conversation_id:'9'});f.show();f.ready();assert.equal(f.selected.length,0)
})
test('video survives native picker foreground transition',async()=>{
 const f=fixture();f.p.chooseVideo();const picker=f.picker();f.hide();f.show()
 picker.success({tempFiles:[{tempFilePath:'video.mp4',size:102400}]});await f.ready();await flush()
 assert.equal(f.videos.length,1);assert.equal(f.videos[0].file.path,'compressed.mp4')
})

test('replacement image survives native picker transition',async()=>{
 const f=fixture(),replaced=[]
 f.manager.images.rows=new Map([['prior',{submitted:false}]])
 f.manager.images.id=()=> 'upload-id'
 f.manager.images.revise=async(id,client,change)=>{const items=[{}];change(items);replaced.push(items[0]);return 'next'}
 f.manager.images.upload=()=>{}
 f.p.changeImage({currentTarget:{dataset:{client:'prior',index:'0',action:'replace'}}})
 const picker=f.picker();f.hide();f.show()
 picker.success({tempFiles:[{tempFilePath:'replacement.jpg',size:10000}]});await f.ready();await flush()
 assert.equal(replaced.length,1);assert.equal(replaced[0].path,'replacement.jpg')
})


test('late image preview cannot reopen after message version changes',async()=>{
 const f=fixture();let resolve,opened=0
 f.p.views['3']={isImage:true,stateVersion:'1',raw:{images:[{item_id:'4'}]}}
 f.manager.images.nativePreviewURL=()=>new Promise(r=>{resolve=r})
 f.p.openImageViewer=()=>{opened++}
 const pending=f.p.previewImage({currentTarget:{dataset:{message:'3',item:'4'}}})
 f.p.views['3']={isImage:true,stateVersion:'2',raw:{images:[{item_id:'4'}]}}
 resolve('https://fixture.invalid/image');await pending
 assert.equal(opened,0)
})
