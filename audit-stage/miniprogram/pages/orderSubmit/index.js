const ApiEnvironment = require('../../utils/ApiEnvironment.js')
const PRODUCT_TEMPLATE_KEY='merchant_product_templates_v2'
const PRODUCT_TEMPLATE_SELECTION_KEY='merchant_product_template_selection_v1'
Page({
  data:{steps:[],fields:[],categories:[],answers:{},selectedCategoryIds:[],selectedCategoryName:'暂不确定',merchantTemplateScope:'',agreementsAccepted:false,expectedFeeRows:[]},
  merchantTemplateStorageKey(prefix){
    const scope=String(this.data.merchantTemplateScope||'').replace(/[^A-Za-z0-9_-]/g,'')
    const keyPrefix=String(prefix||PRODUCT_TEMPLATE_KEY)
    return scope?ApiEnvironment.storageKey(keyPrefix+':'+scope):''
  },
  productTemplateReusableKeys(){
    const firstStep=(this.data.steps||[])[0]||{}
    const supported=new Set(['radio','single','boolean','checkbox','multiple','category_picker'])
    const excluded=new Set(['workflow_profile','is_renovation','material_images','quantity','wall_images','wall_height','expected_fee','expected_fee_type'])
    return (this.data.fields||[]).filter(field=>{
      if(String(field.step_id||'')!==String(firstStep.id||''))return false
      const key=String(field.field_key||'')
      if(!key||excluded.has(key))return false
      return supported.has(String(field.component_type||''))||key==='product_link'
    }).map(field=>String(field.field_key||'')).filter(Boolean)
  },
  applyPendingProductTemplate(){
    const selectionKey=this.merchantTemplateStorageKey(PRODUCT_TEMPLATE_SELECTION_KEY)
    if(!selectionKey||!(this.data.fields||[]).length||!(this.data.steps||[]).length)return
    let template=null;try{template=wx.getStorageSync(selectionKey)}catch(error){}
    if(!template||!template.id||!template.answers)return
    try{wx.removeStorageSync(selectionKey)}catch(error){}
    const reusableKeys=this.productTemplateReusableKeys()
    const allowedKeys=new Set(reusableKeys.concat(['category_ids','category_mode','category_other']))
    const answers=Object.assign({},this.data.answers||{})
    reusableKeys.forEach(key=>{delete answers[key]});delete answers.category_ids;delete answers.category_mode;delete answers.category_other
    Object.keys(template.answers||{}).forEach(key=>{if(allowedKeys.has(key))answers[key]=template.answers[key]})
    const selectedCategoryIds=(template.selectedCategoryIds||template.answers.category_ids||[]).map(Number).filter(id=>id>0).slice(0,6)
    const categories=(this.data.categories||[]).map(item=>Object.assign({},item,{selected:selectedCategoryIds.indexOf(Number(item.id))>=0}))
    let categoryMode=selectedCategoryIds.length?'listed':String(answers.category_mode||'unknown')
    if(categoryMode!=='other'&&categoryMode!=='unknown')categoryMode=selectedCategoryIds.length?'listed':'unknown'
    answers.category_ids=selectedCategoryIds;answers.category_mode=categoryMode;answers.category_other=categoryMode==='other'?String(answers.category_other||''):''
    const selectedCategoryName=selectedCategoryIds.length?categories.filter(item=>item.selected).map(item=>item.name).join('、'):(categoryMode==='other'?'其他：'+answers.category_other:'暂不确定')
    this.setData({answers,categories,selectedCategoryIds,selectedCategoryName,agreementsAccepted:false},()=>{
      if(answers.expected_fee_type==='unit')this.syncExpectedFeeRows(true)
      else{this.saveDraft();this.refreshFields()}
      wx.showToast({title:'商品模板已应用',icon:'success'})
    })
  }
})