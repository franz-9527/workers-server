const assert=require('assert')
const {createVoiceRecorder,cleanTranscript}=require('./ChatVoiceRecorder')

assert.strictEqual(cleanTranscript('  尺寸十二米  '),'尺寸十二米')
assert.strictEqual(cleanTranscript('a\0b'),'')
assert.strictEqual(cleanTranscript('中'.repeat(1001)),'')

const manager={
 startOptions:null,stopped:false,
 start(options){this.startOptions=options},
 stop(){this.stopped=true}
}
const native={native:true}
const recorder=createVoiceRecorder(name=>{
 assert.strictEqual(name,'WechatSI')
 return {getRecordRecognitionManager:()=>manager}
},()=>native)

let started=0,stopped=null,failed=0
recorder.onStart(()=>started++)
recorder.onStop(res=>{stopped=res})
recorder.onError(()=>failed++)
recorder.start({duration:70000,sampleRate:16000,format:'mp3'})
assert.deepStrictEqual(manager.startOptions,{duration:60000,lang:'zh_CN'})
manager.onStart('ok')
assert.strictEqual(started,1)
manager.onStop({tempFilePath:'wxfile://voice.mp3',duration:1234,fileSize:99,result:'  你好师傅  '})
assert.strictEqual(stopped.clientTranscript,'你好师傅')
assert.strictEqual(stopped.duration,1234)
recorder.stop()
assert.strictEqual(manager.stopped,true)

manager.onError({retcode:-1})
assert.strictEqual(failed,1)
const nativeAfterError={native:'fallback'}
const second=createVoiceRecorder(()=>{throw new Error('must not retry disabled plugin')},()=>nativeAfterError)
assert.strictEqual(second,nativeAfterError)
console.log('CHAT_VOICE_RECORDER_CONTRACT_PASSED')
