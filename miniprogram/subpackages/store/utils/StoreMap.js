// Keep the original store/worker marker appearance and local positioning pin.
// Like the pre-subpackage home map, only devtools localizes remote images.
const CLOUDCONFIG = require('../../../config/config.js').CLOUDCONFIG
const FALLBACK = '/subpackages/store/images/2406shop.png'
function localMarker(location) {
  return { id:-1,isLocal:true,latitude:location.latitude,longitude:location.longitude,zIndex:999999,iconPath:'/images/local.png',width:40,height:40,customCallout:{anchorY:0,anchorX:0,display:'ALWAYS'} }
}
function cloudFileID(source) {
  const value=String(source||'')
  const match=value.match(/^https:\/\/([^.]+)\.tcb\.qcloud\.la\/(.+)$/i)
  if(!match||!CLOUDCONFIG.CLOUDENV)return value
  let objectPath=match[2];try{objectPath=decodeURIComponent(objectPath)}catch(error){}
  return 'cloud://'+CLOUDCONFIG.CLOUDENV+'.'+match[1]+'/'+objectPath
}
function isDevtools() {
  try{const info=wx.getSystemInfoSync();return info.brand==='devtools'||info.platform==='devtools'}catch(error){return false}
}
function markerSource(marker) { return cloudFileID(marker.imageFileID||marker.iconPath) }
function isRemote(marker) { return !marker.isLocal&&/^https?:\/\/|^cloud:\/\//i.test(marker.iconPath) }
function prepareMarkers(markers,cache) {
  const devtools=isDevtools()
  return markers.map(item=>{
    const marker={...item}
    if(!marker.iconPath)marker.iconPath=FALLBACK
    else if(devtools&&isRemote(marker))marker.iconPath=cache[markerSource(marker)]?.path||FALLBACK
    return marker
  })
}
function resolveImages(markers,cache,onResolved,isCurrent) {
  if(!isDevtools())return Promise.resolve(prepareMarkers(markers,cache))
  const result=prepareMarkers(markers,cache)
  let next=0
  async function worker() {
    while(next<markers.length&&(!isCurrent||isCurrent())) {
      const index=next++,marker=markers[index]
      if(!marker.iconPath||!isRemote(marker))continue
      const source=markerSource(marker)
      if(!cache[source]) {
        const entry={path:'',pending:null}
        entry.pending=new Promise(resolve=>{
          let done=false,timer
          const finish=path=>{
            if(done)return
            done=true;clearTimeout(timer)
            entry.path=path||FALLBACK;resolve(entry.path)
          }
          timer=setTimeout(()=>finish(FALLBACK),8000)
          const success=value=>finish(value&&(value.tempFilePath||value.path))
          const fail=()=>finish(FALLBACK)
          if(source.indexOf('cloud://')===0)wx.cloud.downloadFile({fileID:source,success,fail})
          else wx.downloadFile({url:source,success:value=>value.statusCode===200?success(value):fail(),fail})
        })
        cache[source]=entry
      }
      const iconPath=cache[source].path||await cache[source].pending
      result[index]={...marker,iconPath}
      if((!isCurrent||isCurrent())&&onResolved)onResolved(index,iconPath)
    }
  }
  return Promise.all(Array.from({length:Math.min(4,markers.length)},()=>worker())).then(()=>result)
}
module.exports={localMarker,prepareMarkers,resolveImages}
