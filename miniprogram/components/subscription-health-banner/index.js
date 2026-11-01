const Subscribe=require('../../utils/SubscribeMessageUtils.js')
const OrderApi=require('../../utils/OrderApi.js')
const context=()=>OrderApi.contextRevision?OrderApi.contextRevision():0
Component({
 options:{styleIsolation:'apply-shared'},
 properties:{
  audience:{type:String,value:'user'},compact:{type:Boolean,value:true},
  active:{type:Boolean,value:true},reviewPending:{type:Boolean,value:false}
 },
 data:{health:null,hintText:'',actionText:'',pending:false},
 observers:{'active,audience':function(){if(this._attached)this.refresh()}},
 lifetimes:{
  attached(){this._attached=true;this._visible=false;this._generation=0;this._updatedAt=0;this._context=context()},
  ready(){this._visible=true;this.refresh()},
  detached(){this._attached=false;this._visible=false;this._generation++;this._job=null}
 },
 pageLifetimes:{
  show(){const force=this._resume===true;this._resume=false;this._visible=true;this.refresh(force)},
  hide(){this._resume=true;this._visible=false;this._generation++;this._job=null;this._pending=false;this.setData({pending:false})}
 },
 methods:{
  current(stamp,revision,audience){return this._attached&&this._visible&&this.properties.active&&stamp===this._generation&&revision===context()&&audience===this.properties.audience},
  refresh(force=false){
   if(!this._attached||!this._visible)return Promise.resolve()
   const audience=this.properties.audience,revision=context()
   if(this._context!==revision||this._audience!==audience){
    this._context=revision;this._audience=audience;this._generation++;this._job=null;this._updatedAt=0;this._pending=false
    this.setData({health:null,hintText:'',actionText:'',pending:false})
   }
   if(!this.properties.active){
    this._generation++;this._job=null;this._updatedAt=0;this._pending=false
    if(this.data.health||this.data.pending)this.setData({health:null,hintText:'',actionText:'',pending:false})
    return Promise.resolve()
   }
   if(this._pending)return Promise.resolve()
   if(this._job)return this._job
   if(!force&&this._updatedAt&&Date.now()-this._updatedAt<60000)return Promise.resolve()
   const stamp=++this._generation
   const job=Subscribe.getSubscriptionHealth(!!force,{audience:audience==='account'?'':audience}).then(health=>{
    if(!this.current(stamp,revision,audience))return
    const actionable=health&&['top_up','wechat_setting'].includes(health.action)?health:null
    const application=audience==='worker_application'
    const hintText=actionable?(actionable.code==='insufficient'?(application?'审核微信提醒次数不足':'微信提醒次数不足'):(actionable.action==='top_up'?'微信提醒未开启':'微信提醒未授权')):''
    this._updatedAt=Date.now()
    this.setData({health:actionable,hintText,actionText:actionable?(actionable.action==='top_up'?(actionable.subscription_type==='long_term'?'开启':'续收'):'去设置'):''})
   }).catch(()=>{
    if(this.current(stamp,revision,audience)){this._updatedAt=0;this.setData({health:null,hintText:'',actionText:''})}
   }).finally(()=>{if(this._job===job)this._job=null})
   this._job=job;return job
  },
  open(){
   const health=this.data.health
   if(!health||this._pending||!this.properties.active||!this._visible)return Promise.resolve()
   if(health.action!=='top_up'){
    wx.navigateTo({url:'/subpackages/account/settings/index?section=notifications'})
    return Promise.resolve()
   }
   this._pending=true;this.setData({pending:true})
   const stamp=this._generation,revision=context(),audience=this.properties.audience
   const options={audience:audience==='account'?'':audience,silent:true,subscriptionType:health.subscription_type||'one_time'}
   // Authorization stays in the original tap; refresh/prefetch never opens a dialog.
   let request
   try{
    request=audience==='worker_application'?Subscribe.requestSubscriptionScenes(['reviewResult'],{...options,includeApplication:true})
     :health.subscription_type==='long_term'?Subscribe.requestSubscriptionScenes(health.scene_keys||[],options):Subscribe.requestImportantTopUp(options)
   }catch(error){request=Promise.reject(error)}
   return Promise.resolve(request).then(result=>{
    if(!this.current(stamp,revision,audience))return
    if(['no_candidate','unavailable'].includes(result.status)){wx.navigateTo({url:'/subpackages/account/settings/index?section=notifications'});return}
    wx.showToast({title:result.acceptedCount>0?'微信提醒已开启':result.status==='not_prepared'?'提醒正在准备，请稍后重试':'本次未开启微信提醒',icon:'none'})
   }).catch(()=>{if(this.current(stamp,revision,audience))wx.showToast({title:'微信提醒未开启，请稍后重试',icon:'none'})})
    .finally(()=>{
     if(!this.current(stamp,revision,audience))return
     this._pending=false;this.setData({pending:false});this._updatedAt=0;this.refresh(true)
    })
  }
 }
})
