'use strict'
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const { chromium } = require('playwright')
const icons = require('./icons.json')
const read = p => fs.readFileSync(p,'utf8')
const tabPath='miniprogram/custom-tab-bar/index'
const hallPath='miniprogram/pages/orderHall/orderHall'
const hallTemplate=read(hallPath+'.wxml').split('  <view class="identity-card">')[1].split('  <view wx:if="{{identity.can_view_hall')[0]
const tabTemplate=read(tabPath+'.wxml').split('<block wx:for="{{list}}" wx:key="pagePath">')[1].split('</block>')[0]
let definition
vm.runInNewContext(read(tabPath+'.js'),{Component(value){definition=value},require(){return {}}})
const tabState={data:structuredClone(definition.data),setData(patch){Object.assign(this.data,patch)}}
const badge=summary=>definition.methods.applyNotificationBadge.call(tabState,summary)
badge({messageBadgeCount:7,attentionKnown:true})
assert.equal(tabState.data.notificationBadgeText,'7')
badge({messageBadgeCount:0,unreadCount:1,attentionKnown:false})
assert.equal(tabState.data.notificationBadgeCount,1)
assert.equal(tabState.data.notificationBadgeText,'')
badge({messageBadgeCount:104,attentionKnown:true})
assert.equal(tabState.data.notificationBadgeText,'99+')
badge({messageBadgeCount:0,attentionKnown:true})
assert.equal(tabState.data.notificationBadgeCount,0)
assert.ok(!('notificationBadgeTone' in tabState.data))
const htmlize=s=>s.replace(/<view\b/g,'<div').replace(/<\/view>/g,'</div>').replace(/<text\b/g,'<span').replace(/<\/text>/g,'</span>').replace(/<navigator\b/g,'<a').replace(/<\/navigator>/g,'</a>').replace(/<image\b/g,'<img').replace(/<\/image>/g,'').replace(/\s(?:wx:(?:if|elif)|bindtap|catchtap|mode)="[^"]*"/g,'')
const esc=text=>text.replace(/&/g,'&amp;').replace(/</g,'&lt;')
function hall({approved,name,status}) {
  return htmlize('  <view class="identity-card">'+hallTemplate
    .replace(/\{\{identity\.is_worker \?[\s\S]*?\}\}/,esc(name))
    .replace(/\{\{identity\.worker_status[\s\S]*?\}\}/,esc(status))
    .replace(approved?/<button[\s\S]*?<\/button>/:/<navigator[\s\S]*?<\/navigator>/,'')
    .replace('url="/pages/constructionOrder/index"','href="#construction"'))
}
function tab(badgeText, dot, selected) {
  return '<div class="tabBar"><div class="cont">'+definition.data.list.map((item,i)=>{
    let s=tabTemplate
      .replace('{{item.pagePath}}',item.pagePath)
      .replace("{{item.plus ? '发布' : item.text}}",item.plus?'发布':item.text)
      .replace("{{item.plus?'plus':'item'}} {{(showPublishMenu ? item.plus : selected === index) ? 'on' : 'off'}}",(item.plus?'plus':'item')+(selected===i?' on':' off'))
      .replace('{{(showPublishMenu ? item.plus : selected === index) ? item.selectedIconPath : item.iconPath}}','data:image/png;base64,'+icons[(selected===i?item.selectedIconPath:item.iconPath).split('/').at(-1)])
      .replace("{{notificationBadgeText ? '' : 'is-dot'}}",dot?'is-dot':'')
      .replace("{{notificationBadgeText ? notificationBadgeText + ' 条消息提醒' : '有新消息提醒'}}",dot?'有新消息提醒':badgeText+' 条消息提醒')
      .replace('{{notificationBadgeText}}',badgeText)
      .replace('{{item.text}}',item.text)
    if(i!==3) s=s.replace(/<view wx:if="{{index === 3[^"]*" class="tab-notice-badge[\s\S]*?<\/view>/,'')
    if(item.plus)s=s.replace(/<view wx:if="{{item.text}}" class="txt"><\/view>/,'')
    return htmlize(s)
  }).join('')+'</div></div>'
}
function css(width){
  return (read(tabPath+'.wxss')+'\n'+read(hallPath+'.wxss'))
    .replace(/(-?\d*\.?\d+)rpx/g,(_,n)=>(Number(n)*width/750).toFixed(3)+'px')
    .replace(/env\(safe-area-inset-bottom\)/g,'var(--fixture-safe-area, 0px)')
}
const layouts=[
 {width:320,height:680,theme:'light',approved:false,name:'接单大厅',status:'入驻后可报价接单',badgeText:'7',dot:false,safe:0},
 {width:390,height:840,theme:'light',approved:true,name:'林师傅 · 墙面施工',status:'已审核 · 可报价接单',badgeText:'99+',dot:false,safe:34},
 {width:320,height:680,theme:'dark',approved:true,name:'华东地区高标准墙面与软装专业施工服务师傅',status:'已审核 · 可报价接单',badgeText:'',dot:true,safe:0},
 {width:390,height:840,theme:'dark',approved:false,name:'接单大厅',status:'入驻后可报价接单',badgeText:'12',dot:false,safe:34},
]
;(async()=>{
  const browser=await chromium.launch()
  fs.mkdirSync('layout-artifacts',{recursive:true})
  try {
    for(const f of layouts){
      const page=await browser.newPage({viewport:{width:f.width,height:f.height},colorScheme:f.theme})
      const demo=Array.from({length:7},(_,i)=>'<div class="fixture-order"><i></i><div><strong>'+['墙布铺贴','旧墙翻新','上门测量'][i%3]+'</strong><small>附近订单 · 可查看需求</small></div></div>').join('')
      await page.setContent('<!doctype html><html lang="zh"><meta charset="utf-8"><style>'+css(f.width)+
        ':root{--fixture-safe-area:'+f.safe+'px}*{box-sizing:border-box}body{margin:0;font:14px system-ui,sans-serif;background:#f4f7f8}button{font:inherit;border:0}a{text-decoration:none}img{object-fit:contain}.cuIcon-right:before{content:"›"}.fixture-header{height:44px;display:grid;place-items:center;font-size:16px;font-weight:650;color:#fff;background:#00b9cb}.fixture-filter{padding:12px 16px;background:#fff;color:#13606a;font-size:13px}.fixture-order{margin:10px 12px;padding:12px;border-radius:12px;background:#fff;display:flex;gap:12px;align-items:center}.fixture-order i{width:66px;height:55px;border-radius:8px;background:linear-gradient(130deg,#86c5c9,#e3f1e8 68%,#86b0a8)}.fixture-order small{display:block;margin-top:8px;color:#60737a}@media(prefers-color-scheme:dark){body{background:#101718;color:#edf4f6}.fixture-header{background:#17323a}.fixture-filter,.fixture-order{background:#202d32;color:#e5f5f5}.fixture-order small{color:#a7bec3}}'+
        '</style><header class="fixture-header">接单大厅</header><main class="hall-page">'+hall(f)+'<div class="hall-filter fixture-filter">全部　　墙纸　　墙布　　墙板　　其它</div>'+demo+'</main>'+tab(f.badgeText,f.dot,1)+'</html>')
      const layout=await page.evaluate(()=>{
        const box=sel=>{const el=document.querySelector(sel),r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,height:r.height,width:r.width}}
        const computed=sel=>getComputedStyle(document.querySelector(sel))
        const icon=document.querySelector('.tab-notice-badge').parentElement.getBoundingClientRect()
        return {card:box('.identity-card'),name:box('.identity-name'),copy:box('.identity-copy'),action:box('.identity-action'),status:box('.identity-status'),label:box('.identity-action-label'),badge:box('.tab-notice-badge'),dock:box('.tabBar'),iconLeft:icon.left,iconRight:icon.right,iconTop:icon.top,badgeColor:computed('.tab-notice-badge').backgroundColor,blur:computed('.tabBar').backdropFilter,cardColor:computed('.identity-name').color}
      })
      assert.ok(layout.card.height<64,'compact identity card')
      assert.ok(layout.copy.right<=layout.action.left,'action shares row without overlapping copy')
      assert.ok(layout.name.height<24 && layout.status.height<19,'title and regular status fit one line')
      assert.ok(layout.label.width<90 && layout.action.height>32,'narrow pill with usable touch target')
      assert.equal(layout.badgeColor,'rgb(217, 31, 57)','both badge forms use red')
      assert.ok(layout.badge.left>layout.iconLeft && layout.badge.top<=layout.iconTop+1,'badge stays at top-right of icon')
      assert.ok(layout.badge.right<layout.dock.right && layout.badge.left<layout.iconRight,'badge anchored to icon')
      assert.ok(f.dot ? layout.badge.width<10 : layout.badge.width>=10,'real dot and number pill have distinct sizing')
      assert.ok(layout.blur.includes('22px'),'background uses blur')
      assert.ok(Math.abs(f.height-layout.dock.bottom-f.safe-f.width*10/750)<1,'dock respects the bottom safe area')
      await page.screenshot({path:'layout-artifacts/'+f.theme+'-'+f.width+'.png'})
      await page.close()
      console.log('PASS',f.theme,f.width,Math.round(layout.card.height)+'px card',f.dot?'red dot':f.badgeText)
    }
  } finally {await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1})
