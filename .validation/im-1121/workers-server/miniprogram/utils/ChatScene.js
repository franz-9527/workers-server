function supportsOrder(meta){
 return !!meta&&(meta.conversation_type===undefined||meta.conversation_type==='order')&&
  (meta.conversation_mode===undefined||['direct','assignment_group','collaboration'].includes(meta.conversation_mode))
}
module.exports={supportsOrder}
