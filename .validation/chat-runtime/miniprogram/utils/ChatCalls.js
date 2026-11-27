// Shared call labels and a fail-closed controller for the shipping mini-program.
const terminal=new Set(['rejected','cancelled','missed','ended','failed'])
const callAliases={CALL_disabled:'CALL_DISABLED',CALL_busy:'CALL_BUSY',CALL_unavailable:'CALL_UNAVAILABLE',CALL_exhausted:'CALL_EXHAUSTED',CALL_safety_insufficient:'CALL_SAFETY_INSUFFICIENT',disabled:'CALL_DISABLED',busy:'CALL_BUSY',unavailable:'CALL_UNAVAILABLE',exhausted:'CALL_EXHAUSTED',safety_insufficient:'CALL_SAFETY_INSUFFICIENT',no_peer:'CALL_NO_PEER','仅单聊支持通话':'CALL_DIRECT_ONLY'}
const callMessages={CALL_BUSY:'对方或当前账号正在通话',CALL_UNAVAILABLE:'通话服务暂不可用，请检查通话资源后重试',CALL_EXHAUSTED:'本月平台通话额度已用完',CALL_SAFETY_INSUFFICIENT:'当前安全可用额度不足，可稍后重试',CALL_DISABLED:'通话尚未开放',CALL_DIRECT_ONLY:'仅单聊支持通话',CALL_NO_PEER:'当前没有可通话的另一位成员',CALL_STATE_CONFLICT:'通话状态已变化，请稍候重试',CALL_MEDIA_CLEANUP_PENDING:'正在关闭上一次通话，请稍候',CALL_OPERATION_PENDING:'正在确认上一操作结果，请点击同一入口重试',CALL_INVITATION_EXPIRED:'本次呼叫已过期，请重新发起',CALL_REQUEST_INVALID:'通话请求无效，请刷新后重试',CALL_DEVICE_UNSUPPORTED:'当前设备暂不支持通话',CHAT_PERMISSION_DENIED:'当前已无权继续通话',CHAT_UNAUTHORIZED:'登录状态已失效，请重新进入',CHAT_NETWORK_ERROR:'无法连接通话服务，请检查网络后重试',CHAT_TRANSPORT_UNKNOWN:'通话请求结果待确认，请点击同一入口重试',CHAT_DEPENDENCY_UNAVAILABLE:'通话服务依赖暂不可用',CHAT_CONTEXT_CHANGED:'账号或会话已变化，请重新进入'}
callMessages.CALL_PEER_OFFLINE='对方暂不在线，可先留言'
callMessages.CALL_PEER_NOT_READY='对方暂不可接听，可先留言'
function normalizeCallCode(value){const raw=typeof value==='string'?value:String(value?.code||value?.reason||'');return callAliases[raw]||raw}
function callMessage(value){const raw=typeof value==='string'?value:String(value?.code||value?.reason||'');const code=normalizeCallCode(raw);if(callMessages[code])return callMessages[code];if(raw&& !/^CALL_|^CHAT_/.test(raw))return raw;return '通话暂不可用，请稍后重试'}
function callProviderName(provider){return ({wechat:'微信官方',shengwang:'声网'})[provider]||''}

// The RTC implementation is retained in the excluded call package. Actor identity
// must still be recorded for ordinary message notifications. No timers, sound,
// native permission requests or calls.* network operations originate here.
const disabled=()=>Object.assign(new Error('通话尚未开放'),{code:'CALL_DISABLED',retryable:false})
class DisabledChatCalls{
 constructor(manager){this.manager=manager;this.resetState()}
 resetState(){this.enabled=false;this.actorID='';this.current=null;this.instance='';this.pendingCreate=null;this.pending=null}
 setEnabled(){this.enabled=false}
 foreground(){this.manager.emit('calls.suspend',{})}
 reset(){this.resetState();this.manager.emit('calls.suspend',{})}
 hint(){}
 schedule(){}
 apply(){}
 async poll(){}
 async status(){return null}
 async device(){throw disabled()}
 async create(){throw disabled()}
 async action(){throw disabled()}
 async prepare(){throw disabled()}
 async failure(){throw disabled()}
 nativeReadySender(){return async()=>{throw disabled()}}
}
module.exports={DisabledChatCalls,terminal,normalizeCallCode,callMessage,callProviderName}
