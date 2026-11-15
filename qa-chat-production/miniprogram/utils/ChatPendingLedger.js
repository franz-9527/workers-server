// Persistent unknown-send ledger. It stores only opaque action identifiers and
// conversation IDs; message bodies, reply metadata, drafts and credentials are
// deliberately never written to storage.
const conversation = value => typeof value === 'string' && /^[1-9][0-9]{0,19}$/.test(value)
const client = value => typeof value === 'string' && /^[1-9][0-9]{9}\.[0-9a-f]{32}$/.test(value)
const environments = new Set(['production','test','testing','development'])
class ChatPendingLedger {
 constructor(io){this.io=io;this.key='';this.rows=[]}
 bind(environment,actor){
  const env=String(environment||''),id=String(actor||'')
  if(!environments.has(env)||!conversation(id)){this.unbind();return false}
  const key='chat-pending-v1:'+env+':'+id
  if(this.key===key)return true
  this.key=key;this.rows=this.read();this.prune();return true
 }
 unbind(){this.key='';this.rows=[]}
 read(){
  if(!this.key)return []
  let raw
  try{raw=this.io.get(this.key)}catch(_){return []}
  if(!Array.isArray(raw))return []
  const seen=new Set(),rows=[]
  for(const value of raw){
   if(!value||!conversation(String(value.conversation_id||''))||!client(String(value.client_message_id||'')))continue
   const key=String(value.client_message_id)
   if(seen.has(key))continue
   rows.push({conversation_id:String(value.conversation_id),client_message_id:key})
   seen.add(key)
  }
  return rows.slice(-20)
 }
 write(){
  if(!this.key)return
  try{
   if(this.rows.length)this.io.set(this.key,this.rows.map(row=>({...row})))
   else this.io.remove(this.key)
  }catch(_){}
 }
 prune(){this.rows=this.rows.slice(-20);this.write()}
 list(){this.prune();return this.rows.map(row=>({...row}))}
 put(row){
  if(!this.key||!row||!conversation(String(row.conversation_id||''))||!client(String(row.client_message_id||'')))return false
  const value={conversation_id:String(row.conversation_id),client_message_id:String(row.client_message_id)}
  this.rows=this.rows.filter(v=>v.client_message_id!==value.client_message_id);this.rows.push(value);this.rows=this.rows.slice(-20);this.write();return true
 }
 remove(clientID){
  if(!this.key)return
  const before=this.rows.length;this.rows=this.rows.filter(row=>row.client_message_id!==clientID);if(this.rows.length!==before)this.write()
 }
 forget(conversationID){
  if(!this.key)return
  const before=this.rows.length;this.rows=this.rows.filter(row=>row.conversation_id!==conversationID);if(this.rows.length!==before)this.write()
 }
}
function createWxPendingLedger(wx){
 if(typeof wx?.getStorageSync!=='function'||typeof wx?.setStorageSync!=='function'||typeof wx?.removeStorageSync!=='function')return null
 return new ChatPendingLedger({get:key=>wx.getStorageSync(key),set:(key,value)=>wx.setStorageSync(key,value),remove:key=>wx.removeStorageSync(key)})
}
module.exports={ChatPendingLedger,createWxPendingLedger}
