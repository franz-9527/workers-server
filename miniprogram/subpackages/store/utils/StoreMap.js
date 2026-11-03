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
function resolveImages(markers,cache) {
  const devtools=isDevtools()
  return Promise.all(markers.map(item=>{
    const marker={...item}
    if(!marker.iconPath){marker.iconPath=FALLBACK;return marker}
    if(marker.isLocal||!devtools||!/^https?:\/\/|^cloud:\/\//i.test(marker.iconPath))return marker
    const source=cloudFileID(marker.imageFileID||marker.iconPath)
    if(!cache[source])cache[source]=new Promise(resolve=>{
      const fail=()=>resolve(FALLBACK)
      const success=result=>resolve(result&&(result.tempFilePath||result.path)||FALLBACK)
      if(source.indexOf('cloud://')===0)wx.cloud.downloadFile({fileID:source,success,fail})
      else wx.downloadFile({url:source,success:result=>result.statusCode===200?success(result):fail(),fail})
    })
    return cache[source].then(iconPath=>({...marker,iconPath}))
  }))
}
module.exports={localMarker,resolveImages}
