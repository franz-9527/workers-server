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
function unsupported(error) {
 return /FUNCTION_NOT_FOUND|FUNCTION_NOT_EXIST|function.*(?:not found|not exist)|未找到.*云函数|云函数不存在/i.test(String(error && (error.errMsg||error.message)||''))
}
function read(action,data) {
 if(!READ_ACTIONS.includes(action))return Promise.reject(new Error('仅允许门店公开读取操作'))
 const invoke=name=>new Promise((resolve,reject)=>wx.cloud.callFunction({name,data:{...(data||{}),action},success:resolve,fail:reject}))
 return invoke('store').then(result=>{
  // Older deployed store functions have no public read action and return undefined.
  if(!result || !result.result || typeof result.result.code==='undefined')return invoke('base_store')
  return result
 },error=>{if(unsupported(error))return invoke('base_store');throw error})
}
function call(options) {
 return read(options.data.action,options.data).then(result=>{if(options.success)options.success(result);return result})
  .catch(error=>{if(options.fail)options.fail(error);else console.warn('[门店] 公开内容读取失败',error)})
}
module.exports={icons,read,call,DEFAULT_ICONS,ICONS}
