const ApiEnvironment = require('../../../utils/ApiEnvironment.js')
const OrderApi = require('../../../utils/OrderApi.js')
const PRODUCT_TEMPLATE_KEY='merchant_product_templates_v2'
const PRODUCT_TEMPLATE_SOURCE_KEY='merchant_product_template_source_v1'
const PRODUCT_TEMPLATE_SELECTION_KEY='merchant_product_template_selection_v1'
function cleanScope(value){return String(value||'').replace(/[^A-Za-z0-9_-]/g,'')}
function storageKey(prefix,scope){return scope?ApiEnvironment.storageKey(prefix+':'+scope):''}
Page({
  data:{loading:true,loadError:'',templates:[],sourceAvailable:false,editing:false},
  onLoad(options){
    this.scope=cleanScope(options&&options.scope);this.templateKey=storageKey(PRODUCT_TEMPLATE_KEY,this.scope);this.sourceKey=storageKey(PRODUCT_TEMPLATE_SOURCE_KEY,this.scope);this.selectionKey=storageKey(PRODUCT_TEMPLATE_SELECTION_KEY,this.scope);this.quickTemplateId=cleanScope(options&&options.quick)
    if(!this.scope){wx.showToast({title:'账号状态无效',icon:'none'});return setTimeout(()=>wx.navigateBack(),300)}
    if(this.quickTemplateId&&this.quickUseTemplate(this.quickTemplateId))return
    this.loadPage()
  },
  quickUseTemplate(id){
    const quickId=cleanScope(id);if(!quickId||!this.templateKey||!this.selectionKey)return false
    let rows=[];try{rows=wx.getStorageSync(this.templateKey)||[]}catch(error){}
    rows=Array.isArray(rows)?rows.filter(Boolean):[]
    const item=rows.find(row=>row&&row.id===quickId&&row.answers);if(!item)return false
    const now=Date.now();const payload=Object.assign({},item,{lastUsedAt:now});delete payload.savedText;delete payload.timeLabel
    const persisted=rows.map(row=>row&&row.id===quickId?Object.assign({},row,{lastUsedAt:now}):row).sort((a,b)=>Number((b&&(b.lastUsedAt||b.savedAt))||0)-Number((a&&(a.lastUsedAt||a.savedAt))||0)).slice(0,20)
    try{wx.setStorageSync(this.selectionKey,payload);wx.setStorageSync(this.templateKey,persisted)}catch(error){return false}
    wx.navigateBack();return true
  },
  loadPage(){
    this.setData({loading:true,loadError:''})
    return OrderApi.request('forms.order','GET').then(res=>{this.setData({loading:false,templates:[]})}).catch(error=>this.setData({loading:false,loadError:(error&&error.message)||'商品模板加载失败'}))
  }
})