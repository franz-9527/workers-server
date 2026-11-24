const test=require('node:test'),assert=require('node:assert/strict')
const {ChatImages}=require('../miniprogram/utils/ChatImages.js')
function fixture(bytes){
 const images=new ChatImages({}),calls=[]
 images.view=()=>({state_version:'1',images:[{item_id:'3',main_size_bytes:bytes}]})
 images.url=async(a,b,c,thumb)=>{calls.push(thumb);return thumb?'thumb.jpg':'main.jpg'}
 return {images,calls}
}
test('processed mains up to and including one MiB load directly',async()=>{
 for(const size of [1,800000,1048576]){const {images,calls}=fixture(size);const result=await images.preview('1','2','3');assert.equal(result.url,'main.jpg');assert.equal(result.showOriginal,false);assert.deepEqual(calls,[false])}
})
test('larger mains require explicit original request and then reuse the full-image choice',async()=>{
 const {images,calls}=fixture(1048577)
 assert.equal((await images.preview('1','2','3')).showOriginal,true);assert.deepEqual(calls,[true])
 assert.equal((await images.preview('1','2','3',true)).thumbnail,false);assert.deepEqual(calls,[true,false])
 assert.equal((await images.preview('1','2','3')).thumbnail,false)
 images.reset();assert.equal((await images.preview('1','2','3')).thumbnail,true)
})
test('unknown sizes never download full image automatically',async()=>{const {images,calls}=fixture(undefined);assert.equal((await images.preview('1','2','3')).thumbnail,true);assert.deepEqual(calls,[true])})
test('expired uncached full image falls back to retained thumbnail without an original button',async()=>{
 const {images,calls}=fixture(512)
 images.url=async(a,b,c,thumb)=>{calls.push(thumb);if(!thumb)throw Object.assign(Error(),{code:'CHAT_MEDIA_CLOUD_EXPIRED'});return 'thumb.jpg'}
 const result=await images.preview('1','2','3');assert.equal(result.thumbnail,true);assert.equal(result.showOriginal,false);assert.deepEqual(calls,[false,true])
})
test('revocation never falls back to thumbnail',async()=>{
 const {images}=fixture(512);let calls=0
 images.url=async()=>{calls++;throw Object.assign(Error(),{code:'CHAT_CONTEXT_CHANGED'})}
 await assert.rejects(images.preview('1','2','3'));assert.equal(calls,1)
})
