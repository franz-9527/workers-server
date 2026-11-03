const resolveRoute = require('../../../utils/SubpackageRedirect.js').resolve
const ICONS = {
 '2406shop.png':'/subpackages/store/images/2406shop.png',
 '2406mini.png':'/subpackages/store/images/2406mini.png',
 '2406search.png':'/subpackages/store/images/2406search.png',
 '2406plus.png':'/subpackages/store/images/2406plus.png'
}
const DEFAULT_ICONS = [
 {id:0,name:'购买服务',img:ICONS['2406shop.png'],pagesUrl:'',appId:'wx2e4158c10c8005fc',path:'/pages/goods/detail?id=78',isMiniProgram:true},
 {id:1,name:'测量管理',img:ICONS['2406mini.png'],pagesUrl:'',appId:'wx9ed353c2ad8fda21',path:'/pages/neutralIndex/index',isMiniProgram:true},
 {id:2,name:'平台公告',img:ICONS['2406search.png'],pagesUrl:'/subpackages/web/h5/index?title=bbs-store'},
 {id:3,name:'门店入驻',img:ICONS['2406plus.png'],pagesUrl:'/subpackages/store/plus/plus'}
]
const READ_ACTIONS = ['getStoreIconList','getStoreBanner','getLastStore','getNearbyStore','getNear']
function icons(source) {
 const list = Array.isArray(source) && source.length ? source : DEFAULT_ICONS
 return list.filter(item=>item && typeof item==='object').map(item=>{
  const basename=String(item.img||'').split(/[?#]/)[0].split('/').pop()
  return {...item,img:ICONS[basename]||item.img,pagesUrl:item.isMiniProgram?item.pagesUrl:resolveRoute(item.pagesUrl)}
 })
}
function deploymentError(action,code,original) {
 const error=new Error(code==='STORE_ACTION_UNSUPPORTED'?'门店服务尚未完成更新，请稍后重试':'门店服务暂不可用，请稍后重试')
 error.code=code;error.functionName='store';error.action=action
 error.diagnostic=code==='STORE_ACTION_UNSUPPORTED'?'请将完整cloudfunctions/store目录部署为store云函数':'请检查当前云环境中的store云函数'
 error.originalMessage=String(original&&(original.errMsg||original.message)||'')
 return error
}
function read(action,data) {
 if(!READ_ACTIONS.includes(action))return Promise.reject(new Error('仅允许门店公开读取操作'))
 // Public reads and writes have been merged into store. Never call the removed function.
 return new Promise((resolve,reject)=>wx.cloud.callFunction({
  name:'store',data:{...(data||{}),action},
  success:result=>{
   const body=result&&result.result
   if(!body||typeof body.code==='undefined'||body.error_code==='STORE_ACTION_UNSUPPORTED')return reject(deploymentError(action,'STORE_ACTION_UNSUPPORTED'))
   if(Number(body.code)!==1||!Array.isArray(body.data))return reject(deploymentError(action,'STORE_READ_FAILED'))
   resolve(result)
  },
  fail:error=>{
   const missing=/FUNCTION_NOT_FOUND|FUNCTION_NOT_EXIST|FunctionName.*could not be found|function.*(?:not found|not exist)|云函数不存在/i.test(String(error&&(error.errMsg||error.message)||''))
   reject(deploymentError(action,missing?'STORE_FUNCTION_NOT_FOUND':'STORE_READ_FAILED',error))
  }
 }))
}
function call(options) {
 return read(options.data.action,options.data).then(result=>{if(options.success)options.success(result);return result})
  .catch(error=>{
   console.warn('[门店服务]',{functionName:error.functionName,action:error.action,code:error.code,diagnostic:error.diagnostic,originalMessage:error.originalMessage})
   if(options.fail)options.fail(error)
  })
}
module.exports={icons,read,call,DEFAULT_ICONS,ICONS}
