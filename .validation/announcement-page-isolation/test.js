const assert = require('node:assert/strict')
const fs = require('fs')
const vm = require('vm')
const source = fs.readFileSync(__dirname + '/source/miniprogram/utils/AnnouncementLoader.js','utf8')

function harness() {
  let selected='testing', revision=1, offline=false
  const requests=[], storage=new Map()
  const env={
    capture:()=>({key:selected,revision}),
    assertCurrent(c){if(c.key!==selected||c.revision!==revision)throw new Error('site changed')},
    storageKey(key,c){return c.key==='testing'?'api_testing:'+key:key},
    onChange(){return()=>{}}
  }
  const api={publicRequest(route,query){
    if(offline)return Promise.reject(Object.assign(new Error('offline'),{code:'NETWORK_ERROR'}))
    return new Promise(resolve=>requests.push({route,scene:query.scene,resolve}))
  }}
  const wx={
    getStorageSync:key=>storage.get(key),
    setStorageSync:(key,value)=>storage.set(key,value),
    removeStorageSync:key=>storage.delete(key)
  }
  const create=()=>{const module={exports:{}};vm.runInNewContext(source,{module,require:p=>p.includes('OrderApi')?api:env,wx,Date,console});return module.exports}
  return {requests,storage,create,setOffline:v=>offline=v,switchSite:key=>{selected=key;revision++}}
}

;(async()=>{
  const h=harness(), loader=h.create()
  const home=loader.load('home')
  assert.equal(home,loader.load('home'))
  const agreement=loader.load('platform_agreement')
  assert.deepEqual(h.requests.map(x=>x.scene),['home','platform_agreement'])
  h.requests[0].resolve({data:{environment:'testing',enabled:true,text:'首页公告',link:'/pages/index/index'}})
  h.requests[1].resolve({data:{environment:'testing',enabled:false,text:'',link:''}})
  assert.equal((await home).text,'首页公告')
  assert.equal((await agreement).enabled,false)
  assert.equal(loader.peek('platform_agreement').enabled,false)

  h.setOffline(true)
  const cold=h.create()
  assert.equal((await cold.load('home')).text,'首页公告')
  assert.equal((await cold.load('platform_agreement')).enabled,false)
  h.switchSite('production')
  await assert.rejects(cold.load('home'),/offline/)
  console.log('JS announcement scene isolation and disabled-cache contract: OK')
})().catch(error=>{console.error(error);process.exit(1)})
