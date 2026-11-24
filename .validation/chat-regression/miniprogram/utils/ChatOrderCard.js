// A compact text attachment, never an order authorization grant.
// Opening a card still uses the existing order detail API and its permissions.
const prefix='【平台订单】\n'
function parse(text){
 if(typeof text!=='string'||!text.startsWith(prefix))return null
 try{const v=JSON.parse(text.slice(prefix.length));return /^[A-Za-z0-9_-]{1,80}$/.test(v.publicNo)&&typeof v.title==='string'&&Array.from(v.title).length<=120?{publicNo:v.publicNo,title:v.title}:null}catch(_){return null}
}
function encode(v){
 const card={publicNo:String(v.publicNo||v.public_no||''),title:Array.from(String(v.title||'订单')).slice(0,120).join('')}
 const text=prefix+JSON.stringify(card);if(!parse(text))throw new Error('订单编号无效');return text
}
module.exports={parse,encode}
