const fs = require('node:fs')
const assert = require('node:assert/strict')
const {chromium} = require('playwright')
const icons = require('./icons.json')
const previous = fs.readFileSync('tests/tab-hall/previous.wxss','utf8')
const current = fs.readFileSync('miniprogram/custom-tab-bar/index.wxss','utf8')
const scene = ['墙布铺贴','旧墙翻新','上门测量']
const icon = (name, plus=false) => '<img class="image-style" alt="" src="data:image/png;base64,'+icons[name]+'">'
const markup = '<div class="tabBar"><div class="cont">'+[
  ['item off','index.png','首页'],['item on','hallon.png','接单大厅'],['plus off','plus2.png',''],['item off','chat.png','消息'],['item off','mine.png','我的'],
].map(([cls,name,label])=>'<div class="'+cls+'">'+icon(name)+(name==='chat.png'?'<span class="tab-notice-badge unread">7</span>':'')+'<div class="txt'+(cls==='item on'?' selectedColor':'')+'">'+label+'</div></div>').join('')+'</div></div>'
const rest = '*{box-sizing:border-box}body{margin:0;background:#f2f7f7;color:#20383b;font:16px system-ui,sans-serif}header{padding:16px;text-align:center;font-weight:700;background:#02afbc;color:white}.tile{display:flex;gap:12px;margin:11px 12px;padding:12px;border-radius:12px;background:#fff}.tile i{width:74px;height:54px;border-radius:9px;background:linear-gradient(115deg,#84c4d0,#f0f1b8,#a6cfc1)}small{display:block;margin-top:8px;color:#51676c}@media(prefers-color-scheme:dark){body{background:#131e22;color:#e5f5f5}.tile{background:#233337}small{color:#a5bfc2}}'
function measure(page){
  return page.evaluate(()=>{
    const rect=e=>{const {x,y,width,height}=e.getBoundingClientRect();return [x,y,width,height]}
    const dock=document.querySelector('.tabBar'),css=getComputedStyle(dock),line=getComputedStyle(document.querySelector('.cont .item.on'),'::before')
    return {dock:rect(dock),items:[...dock.querySelectorAll('.item,.plus')].map(rect),icons:[...dock.querySelectorAll('img')].map(rect),corners:[css.borderTopLeftRadius,css.borderTopRightRadius,css.borderBottomLeftRadius,css.borderBottomRightRadius],stripe:[line.content,line.height,line.backgroundColor],badge:getComputedStyle(dock.querySelector('.tab-notice-badge')).backgroundColor,fill:css.backgroundColor,gradient:css.backgroundImage,blur:css.backdropFilter}
  })
}
async function render(page,css,width,inset){
  const converted=css.replace(/(-?\d*\.?\d+)rpx/g,(_,n)=>(Number(n)*width/750).toFixed(3)+'px').replace(/env\(safe-area-inset-bottom\)/g,inset+'px')
  await page.setContent('<!doctype html><html lang="zh"><meta charset="utf-8"><style>'+rest+converted+'</style><header>接单大厅</header>'+Array.from({length:10},(_,i)=>'<div class="tile"><i></i><div><strong>'+scene[i%scene.length]+'</strong><small>附近订单 · 可查看需求</small></div></div>').join('')+markup)
}
;(async()=>{
  const browser=await chromium.launch()
  fs.mkdirSync('layout-artifacts',{recursive:true})
  try {
    for(const theme of ['light','dark'])for(const width of [320,390]){
      const page=await browser.newPage({viewport:{width,height:width===320?680:844},colorScheme:theme})
      const inset=width===320?0:34
      await render(page,previous,width,inset)
      const before=await measure(page)
      await render(page,current,width,inset)
      const after=await measure(page)
      for(const key of ['dock','items','icons','corners','stripe','badge']) assert.deepEqual(after[key],before[key],key+' unchanged')
      assert.equal(after.dock[0],0)
      assert.equal(after.dock[2],width)
      assert.equal(after.dock[1]+after.dock[3],width===320?680:844)
      assert.equal(after.corners[2],'0px')
      assert.notEqual(after.stripe[0],'none')
      assert.match(after.blur,/blur\(20px\) saturate\(1.4\)/)
      assert.match(after.fill,theme==='dark'?/0\.81\)/:/0\.74\)/)
      assert.match(after.gradient,/linear-gradient/)
      await page.screenshot({path:'layout-artifacts/'+theme+'-'+width+'.png'})
      await page.close()
      console.log('PASS',theme,width,'bottom fills viewport, top corners and indicator unchanged, translucent backdrop')
    }
  } finally {await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1})
