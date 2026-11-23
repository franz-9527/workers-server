const Api=require('../../../utils/OrderApi.js')
const ChatRuntime=require('../../../utils/ChatRuntime.js')

const id=value=>typeof value==='string'&&/^[1-9][0-9]{0,19}$/.test(value)
const avatars={user:'/images/chat-user.svg',worker:'/images/chat-worker.svg',platform:'/images/chat-platform.svg'}
const safeTarget=value=>typeof value==='string'&&/^\/pages\/record\/detail\?id=[A-Za-z0-9%_.~-]+$/.test(value)
const safeText=(value,max=80)=>typeof value==='string'&&value.trim()&&!/[\u0000-\u001f\u007f<>]/.test(value)&&Array.from(value.trim()).length<=max?value.trim():''
const durationLabels={'86400':'1天','259200':'3天','604800':'7天',custom:'自定义',permanent:'永久'}

Page({
  data:{
    loading:true,error:'',profile:null,avatarPath:avatars.user,roleLabels:[],identityText:'普通用户',
    isWorker:false,workerProfileTarget:'',locationRows:[],ipLocationNotice:false,
    canModerate:false,moderationLoading:false,moderationStatus:null,moderationStatusText:'',moderationOpen:false,
    moderationMode:'86400',customHours:'24',moderationReason:'',moderationBusy:false,durationLabels
  },
  onLoad(options){
    this.conversation=String(options.conversation_id||'')
    this.actor=String(options.actor_id||'')
    if(!id(this.conversation)||!id(this.actor)){
      this.setData({loading:false,error:'用户资料入口无效'})
      return
    }
    this.load()
  },
  load(){
    if(!id(this.conversation)||!id(this.actor))return
    this.setData({loading:true,error:''})
    Api.request('chat.participant.profile','POST',{conversation_id:this.conversation,actor_id:this.actor}).then(result=>{
      const raw=result&&result.data||{},roles=Array.isArray(raw.roles)?raw.roles.filter(role=>role==='worker'||role==='admin'):[]
      const name=safeText(raw.display_name)||'用户'
      const avatar=Object.prototype.hasOwnProperty.call(avatars,raw.avatar)?raw.avatar:'user'
      const isWorker=roles.includes('worker'),isAdmin=roles.includes('admin')
      const labels=[]
      if(isWorker)labels.push('师傅')
      if(isAdmin)labels.push('平台工作人员')
      if(raw.real_name_approved===true&&isWorker)labels.push('已实名')
      if(!labels.length)labels.push('普通用户')
      const target=safeTarget(raw.worker_profile_target)?raw.worker_profile_target:''
      const locationRows=Array.isArray(raw.location_rows)?raw.location_rows.map(row=>({
        label:safeText(row&&row.label,12),
        value:safeText(row&&row.value,80),
        kind:row&&row.kind==='residence'?'residence':'ip'
      })).filter(row=>row.label&&row.value).slice(0,2):[]
      const canModerate=raw.can_moderate===true
      this.setData({
        loading:false,
        profile:{displayName:name},
        avatarPath:avatars[avatar],
        roleLabels:labels,
        identityText:isWorker?'认证师傅':isAdmin?'平台工作人员':'普通用户',
        isWorker,
        workerProfileTarget:target,
        locationRows,
        ipLocationNotice:raw.ip_location_notice===true&&locationRows.some(row=>row.kind==='ip'),
        canModerate,
        moderationStatus:null,
        moderationStatusText:canModerate?'正在读取处置状态':''
      })
      wx.setNavigationBarTitle({title:isWorker?'师傅资料':'用户资料'})
      if(canModerate)this.loadModeration()
    }).catch(error=>{
      const code=String(error&&error.code||'')
      const message=code==='CHAT_FORBIDDEN'?'当前无权查看该用户资料':code==='CHAT_UNAUTHORIZED'?'登录状态已失效，请重新进入':'用户资料暂时无法加载'
      this.setData({loading:false,error:message,profile:null})
    })
  },
  retry(){this.load()},
  openWorkerProfile(){
    const target=this.data.workerProfileTarget
    if(!safeTarget(target))return
    wx.navigateTo({url:target})
  },
  noop(){},
  formatUntil(value){
    const date=new Date(value)
    if(!Number.isFinite(date.getTime()))return ''
    const pad=n=>String(n).padStart(2,'0')
    return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
  },
  moderationText(status){
    if(!status||status.blocked!==true)return '当前可正常发送消息'
    if(status.permanent===true)return '当前为永久禁言'
    const until=this.formatUntil(status.blocked_until)
    return until?`禁言至 ${until}`:'当前处于限时禁言'
  },
  moderationCall(path,payload){
    return Api.request('chat.moderation.credentials','POST',{}).then(result=>{
      const token=result&&result.data&&result.data.access_token
      if(typeof token!=='string'||token.length<32)throw Object.assign(new Error('管理员处置凭证不可用'),{code:'CHAT_UNAUTHORIZED'})
      return ChatRuntime.get().io.http(path,payload,token)
    })
  },
  loadModeration(){
    if(!this.data.canModerate||this.data.moderationLoading)return
    this.setData({moderationLoading:true})
    this.moderationCall('governance/status',{actor_id:this.actor}).then(status=>{
      this.setData({moderationStatus:status,moderationStatusText:this.moderationText(status)})
    }).catch(error=>{
      const code=String(error&&error.code||'')
      const text=code==='CHAT_PERMISSION_DENIED'||code==='CHAT_UNAUTHORIZED'?'当前账号不能处置该用户':'处置状态暂未更新'
      this.setData({moderationStatus:null,moderationStatusText:text})
    }).finally(()=>this.setData({moderationLoading:false}))
  },
  openModeration(){
    if(!this.data.canModerate||this.data.moderationBusy)return
    if(!this.data.moderationStatus){this.loadModeration();return}
    this.setData({moderationOpen:true,moderationMode:'86400',customHours:'24',moderationReason:''})
  },
  closeModeration(){if(!this.data.moderationBusy)this.setData({moderationOpen:false})},
  selectModerationMode(event){
    const mode=String(event.currentTarget.dataset.mode||'')
    if(['86400','259200','604800','custom','permanent'].includes(mode))this.setData({moderationMode:mode})
  },
  inputCustomHours(event){
    const raw=String(event.detail.value||'').replace(/[^0-9]/g,'').slice(0,3)
    this.setData({customHours:raw})
  },
  inputModerationReason(event){this.setData({moderationReason:String(event.detail.value||'').slice(0,200)})},
  confirmModeration(){
    const status=this.data.moderationStatus
    if(this.data.moderationBusy||!status||!id(String(status.version||'')))return
    const reason=String(this.data.moderationReason||'').trim()
    if(Array.from(reason).length<2){wx.showToast({title:'请填写至少2个字的处置原因',icon:'none'});return}
    const mode=this.data.moderationMode
    let action='timed',seconds=Number(mode),label=durationLabels[mode]||''
    if(mode==='permanent'){action='permanent';seconds=0}
    if(mode==='custom'){
      const hours=Number(this.data.customHours)
      if(!Number.isInteger(hours)||hours<1||hours>720){wx.showToast({title:'自定义时长为1–720小时',icon:'none'});return}
      seconds=hours*3600;label=hours+'小时'
    }
    wx.showModal({
      title:'确认禁言',
      content:`将该用户禁言${label}。禁言仅限制聊天发送，不影响订单、退款和售后。`,
      confirmText:'确认处置',
      confirmColor:'#d9514e',
      success:result=>{if(result.confirm)this.applyModeration(action,seconds,reason)}
    })
  },
  unban(){
    const status=this.data.moderationStatus
    if(this.data.moderationBusy||!status||status.blocked!==true||!id(String(status.version||'')))return
    const reason=String(this.data.moderationReason||'').trim()
    if(Array.from(reason).length<2){wx.showToast({title:'请填写至少2个字的解除原因',icon:'none'});return}
    wx.showModal({
      title:'解除禁言',
      content:'解除后该用户可立即恢复聊天发送。',
      confirmText:'确认解除',
      success:result=>{if(result.confirm)this.applyModeration('unban',0,reason)}
    })
  },
  applyModeration(action,seconds,reason){
    const status=this.data.moderationStatus
    if(!status)return
    this.setData({moderationBusy:true})
    const payload={actor_id:this.actor,expected_version:String(status.version),action,reason}
    if(action==='timed')payload.seconds=seconds
    this.moderationCall('governance/restrict',payload).then(next=>{
      this.setData({
        moderationStatus:next,
        moderationStatusText:this.moderationText(next),
        moderationOpen:false,
        moderationReason:''
      })
      wx.showToast({title:action==='unban'?'已解除禁言':'处置已生效',icon:'success'})
    }).catch(error=>{
      const code=String(error&&error.code||'')
      if(code==='CHAT_PREFERENCE_CONFLICT'){this.loadModeration();wx.showToast({title:'状态已变化，请重新操作',icon:'none'});return}
      if(code==='CHAT_PERMISSION_DENIED'||code==='CHAT_UNAUTHORIZED'){wx.showToast({title:'当前账号不能处置该用户',icon:'none'});return}
      wx.showToast({title:'处置未完成，请稍后重试',icon:'none'})
    }).finally(()=>this.setData({moderationBusy:false}))
  }
})
