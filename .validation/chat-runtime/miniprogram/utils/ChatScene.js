function supportsOrder(meta){
 return meta?.conversation_type==='order'&&['direct','assignment_group','collaboration'].includes(meta.conversation_mode)
}
function supportsConversation(meta,scene=''){
 if(scene==='direct')return meta?.conversation_type==='direct'&&meta.conversation_mode==='private'
 if(scene==='support')return meta?.conversation_type==='support'&&meta.conversation_mode==='support'
 return supportsOrder(meta)
}
module.exports={supportsOrder,supportsConversation}
