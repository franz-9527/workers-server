const Notice=require('../../utils/InAppNotice.js')
Component({
 properties:{customNav:{type:Boolean,value:false}},
 data:{notice:null,call:null,top:8,callBusy:false},
 lifetimes:{
  attached(){
   this.owner={};this.active=false
   this.off=Notice.subscribe(value=>{if(this.active)this.setData({notice:value})})
   this.offCall=Notice.subscribeCall(value=>{if(this.active)this.setData({call:value,callBusy:!!value?.busy})})
  },
  ready(){this.showNotice()},
  detached(){this.active=false;if(this.off)this.off();if(this.offCall)this.offCall();Notice.hide(this.owner)}
 },
 pageLifetimes:{show(){this.showNotice()},hide(){this.active=false;this.setData({notice:null,call:null,callBusy:false});Notice.hide(this.owner)}},
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
  dismiss(){Notice.dismiss()},
  openCall(){Notice.openCall()},
  callAction(event){
   const action=String(event.currentTarget.dataset.action||'')
   if(this.data.callBusy||!['accept','reject'].includes(action))return
   this.setData({callBusy:true})
   Promise.resolve(Notice.answerCall(action)).finally(()=>{if(this.active&&this.data.call)this.setData({callBusy:false})})
  }
 }
})