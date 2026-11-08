const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const dir=path.join(__dirname,'fixtures');
let navigations=[],colors=[];
const api={request:async()=>({data:{chat_conversation_id:'17',assigned_worker:{id:'W-5'}}})};
const wx={
 navigateTo:x=>navigations.push(x.url),
 setNavigationBarColor:x=>colors.push(x),
 onThemeChange:()=>{},
 offThemeChange:()=>{},
 getAppBaseInfo:()=>({theme:'dark'}),
 cloud:{database:()=>({})}
};
function page(name){
 let result;
 const context={Page:p=>result=p,wx,getApp:()=>({globalData:{userInfo:{}}}),require:n=>n.includes('OrderApi')?api:(n.includes('ChatManager')?{compare:()=>0,validText:()=>true,mergeMessage:(a,b)=>b}:{}),console,setTimeout,clearTimeout,Promise};
 vm.runInNewContext(fs.readFileSync(path.join(dir,name),'utf8'),context,{filename:name});
 assert.ok(result);return result;
}
const chat=page('conversation.js');
chat.syncNavigationTheme('dark');
chat.syncNavigationTheme('light');
assert.equal(colors[0].backgroundColor,'#1e1e1e');
assert.equal(colors[0].frontColor,'#ffffff');
assert.equal(colors[1].backgroundColor,'#f7f7f7');
const user=page('userOrder.js'), worker=page('workerOrder.js');
user.openOrderChat.call({data:{chatConversationId:'17'}});
worker.openOrderChat.call({data:{chatConversationId:'18'}});
assert.ok(navigations[0].includes('conversation_id=17'));
assert.ok(navigations[1].includes('conversation_id=18'));
const count=navigations.length;
user.openOrderChat.call({data:{chatConversationId:'not-an-id'}});
assert.equal(navigations.length,count);
const profile=page('workerProfile.js');
async function verify(expectedWorker,expectedCid){
 const target={data:{id:'cloud-1',contextChatId:''},pendingOrderChat:{orderNo:'BXQZ123456',workerId:expectedWorker,cid:expectedCid,profileId:'cloud-1'},_disposed:false,setData(next){Object.assign(this.data,next)}};
 await profile.verifyOrderChat.call(target);
 return target.data.contextChatId;
}
(async()=>{
 assert.equal(await verify('W-5','17'),'17');
 assert.equal(await verify('OTHER','17'),'');
 assert.equal(await verify('W-5','19'),'');
 console.log('PASS native navigation dark/light colors');
 console.log('PASS order chat ID routing guard');
 console.log('PASS profile contextual re-authorization');
})().catch(e=>{console.error(e);process.exit(1)});
