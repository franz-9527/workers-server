const Notice=require('../../utils/InAppNotice.js')
Component({
 properties:{customNav:{type:Boolean,value:false}},
 data:{notice:null,top:8},
 lifetimes:{
  attached(){
   this.owner={};this.active=false
   this.off=Notice.subscribe(value=>{if(this.active)this.setData({notice:value})})
  },
  ready(){this.showNotice()},
  detached(){this.active=false;if(this.off)this.off();Notice.hide(this.owner)}
 },
 pageLifetimes:{show(){this.showNotice()},hide(){this.active=false;this.setData({notice:null});Notice.hide(this.owner)}},
 methods:{
  showNotice(){
   if(!this.owner)return
   const pages=getCurrentPages(),page=pages[pages.length-1];if(!page)return
   const route=page.route||page.__route__||''
   if(this.active&&this.visibleRoute===route)return
   this.active=true;this.visibleRoute=route
   let info={},menu=null
   try{info=wx.getWindowInfo?wx.getWindowInfo():wx.getSystemInfoSync();menu=wx.getMenuButtonBoundingClientRect()}catch(_){}
   const status=Number(info.statusBarHeight)||20,nav=menu?.top?Math.max(40,(menu.top-status)*2+menu.height):44
   const top=this.properties.customNav?status+nav+8:8
   if(this.data.top!==top)this.setData({top})
   Notice.show(this.owner,route,page.options||{})
  },
  open(){Notice.activate()},
  dismiss(){Notice.dismiss()}
 }
})
