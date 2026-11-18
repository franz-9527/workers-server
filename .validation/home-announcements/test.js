const assert = require('node:assert/strict')
const fs = require('fs')
const vm = require('vm')
const source = fs.readFileSync(__dirname + '/source/miniprogram/utils/HomeAnnouncementLoader.js','utf8')

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
    assert.equal(route,'announcement.home')
    assert.equal(query,undefined)
    if(offline)return Promise.reject(Object.assign(new Error('offline'),{code:'NETWORK_ERROR'}))
    return new Promise(resolve=>requests.push({route,resolve}))
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
  const first=loader.load()
  assert.equal(first,loader.load(),'same-site in-flight home requests must coalesce')
  assert.equal(h.requests.length,1)
  h.requests[0].resolve({data:{environment:'testing',items:[
    {id:'home-1',text:'公告一',link:'/pages/index/index'},
    {id:'home-2',text:'公告二',link:''}
  ]}})
  const result=await first
  assert.deepEqual(result.items.map(x=>x.text),['公告一','公告二'])

  h.setOffline(true)
  const cold=h.create()
  const cached=await cold.load()
  assert.equal(cached.stale,true)
  assert.deepEqual(cached.items.map(x=>x.text),['公告一','公告二'])

  h.setOffline(false)
  const empty=h.create().load()
  h.requests.at(-1).resolve({data:{environment:'testing',items:[]}})
  assert.deepEqual((await empty).items,[])
  h.setOffline(true)
  assert.deepEqual((await h.create().load()).items,[],'successful empty list must be cached as the authoritative disabled state')

  h.switchSite('production')
  await assert.rejects(h.create().load(),/offline/,'testing cache must not leak into production')
  console.log('JS dedicated home announcement loader: OK')
})().catch(error=>{console.error(error);process.exit(1)})
