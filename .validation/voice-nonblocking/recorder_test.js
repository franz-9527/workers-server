const assert=require('node:assert/strict')
const {createVoiceRecorder}=require('./candidate/ChatVoiceRecorder.js')

function manager(){
 const m={started:null,stopped:0}
 m.start=o=>{m.started=o}
 m.stop=()=>{m.stopped++}
 return m
}
{
 const m=manager(),events=[]
 const r=createVoiceRecorder(()=>({getRecordRecognitionManager:()=>m}),()=>{throw new Error('native not expected')})
 r.onStop(v=>events.push(v))
 r.start({duration:60000})
 m.onRecognize({result:'中间'})
 m.onRecognize({result:'识别'})
 m.onStop({tempFilePath:'voice.mp3',duration:1500,fileSize:100,result:''})
 assert.equal(events.length,1)
 assert.equal(events[0].clientTranscript,'中间识别')
 assert.equal(events[0].tempFilePath,'voice.mp3')
}
{
 const m=manager(),events=[],errors=[]
 const r=createVoiceRecorder(()=>({getRecordRecognitionManager:()=>m}),()=>{throw new Error('native not expected')})
 r.onStop(v=>events.push(v));r.onError(e=>errors.push(e))
 r.start({duration:60000})
 m.onRecognize({result:'可用片段'})
 m.onError({retcode:-30005,msg:'识别内部错误'})
 m.onError({retcode:-30012,msg:'停止后的重复错误'})
 m.onStop({tempFilePath:'voice2.mp3',duration:1800,fileSize:120,result:''})
 assert.equal(events.length,1)
 assert.equal(errors.length,0)
 assert.equal(events[0].clientTranscript,'可用片段')
 assert.equal(events[0].recognitionFailed,true)
 assert.equal(m.stopped,1)
}
console.log('VOICE_RECORDER_NONBLOCKING_OK')
