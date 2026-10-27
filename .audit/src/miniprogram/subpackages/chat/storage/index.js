const Runtime=require('../../../utils/ChatRuntime.js')
const ApiEnvironment=require('../../../utils/ApiEnvironment.js')
const names={text:'文字',image:'图片',audio:'语音',video:'视频'}
const size=n=>n<1024?Math.round(n)+' B':n<1048576?(n/1024).toFixed(1)+' KB':(n/1048576).toFixed(1)+' MB'
Page({
 data:{loading:true,busy:false,error:'',total:'0 B',items:[],hasMore:false,kinds:[{key:'image',name:'图片',selected:true},{key:'audio',name:'语音',selected:true},{key:'video',name:'视频',selected:true},{key:'text',name:'文字',selected:false}]},
 onShow(){this.setData({busy:false});this._generation=(this._generation||0)+1;this._visible=true;this.refresh()},
 onHide(){this._visible=false;this._generation++},
 onUnload(){this._visible=false;this._generation++},
 async refresh(){
  const generation=this._generation,context=ApiEnvironment.capture(),disk=Runtime.get().disk
  this.setData({loading:true,error:''})
  try{
   if(!disk)throw new Error('当前微信暂不支持聊天文件缓存')
   if(!disk.scope){
    const result=(await require('../../../utils/OrderApi.js').request('chat.credentials','POST',{})).data
    ApiEnvironment.assertCurrent(context)
    if(!this._visible||generation!==this._generation)return
    if(!result||result.environment!==(context.key==='testing'?'test':'production')||!result.actor_id)throw new Error('暂时无法确认当前账号')
    disk.bind(result.environment,String(result.actor_id))
   }
   this._scope=disk.scope.name;await disk.sweep();const result=await disk.inventory()
   ApiEnvironment.assertCurrent(context);if(!this._visible||generation!==this._generation||disk.scope?.name!==this._scope)return
   this._context=context;this._rows=result.items.map((x,i)=>({...x,key:x.conversation_id+':'+x.kind,title:'订单沟通 '+(i+1)+' · '+names[x.kind],size:size(x.bytes)}));this._shown=0
   this.setData({total:size(result.bytes),loading:false,items:[]});this.more()
  }catch(error){if(this._visible&&generation===this._generation)this.setData({loading:false,error:error.message||'暂时无法读取聊天缓存，请稍后重试'})}
 },
 more(){const rows=this._rows||[];this._shown=Math.min(rows.length,(this._shown||0)+50);this.setData({items:rows.slice(0,this._shown),hasMore:this._shown<rows.length})},
 toggle(event){const key=event.currentTarget.dataset.kind;this.setData({kinds:this.data.kinds.map(k=>k.key===key?{...k,selected:!k.selected}:k)})},
 async clearRows(rows){
  if(this.data.busy)return
  const disk=Runtime.get().disk,generation=this._generation
  try{
   ApiEnvironment.assertCurrent(this._context);if(!disk||disk.scope?.name!==this._scope)throw new Error('账号已变化，请重新进入')
   this.setData({busy:true,error:''})
   for(const row of rows){ApiEnvironment.assertCurrent(this._context);if(disk.scope?.name!==this._scope)throw new Error('账号已变化');await disk.clear({conversation_id:row.conversation_id,kind:row.kind})}
   if(this._visible&&generation===this._generation){wx.showToast({title:'所选缓存已清理',icon:'success'});await this.refresh()}
  }catch(error){if(this._visible&&generation===this._generation)this.setData({error:error.message||'缓存清理未完成，可重试'})}
  finally{if(this._visible&&generation===this._generation)this.setData({busy:false})}
 },
 clearSelected(){const selected=new Set(this.data.kinds.filter(k=>k.selected).map(k=>k.key));this.clearRows((this._rows||[]).filter(x=>selected.has(x.kind)))},
 clearOne(event){const row=(this._rows||[]).find(x=>x.key===event.currentTarget.dataset.key);if(row)this.clearRows([row])}
})
