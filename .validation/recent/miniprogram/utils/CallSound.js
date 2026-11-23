// UI call cues only. RTC media remains owned by the selected provider.
const ASSETS={incoming:'/audio/chat/incoming.wav',waiting:'/audio/chat/waiting.wav',connected:'/audio/chat/connected.wav',ended:'/audio/chat/ended.wav'}
const terminal=new Set(['rejected','cancelled','missed','ended','failed'])
let loop=null,shot=null,loopKind='',callID='',lastState=''
function context(){
 if(typeof wx==='undefined'||typeof wx.createInnerAudioContext!=='function')return null
 const audio=wx.createInnerAudioContext();audio.obeyMuteSwitch=true;return audio
}
function destroy(audio){if(!audio)return;try{audio.stop()}catch(_){}try{audio.destroy()}catch(_){}}
function stopLoop(){destroy(loop);loop=null;loopKind=''}
function playLoop(kind){
 if(loopKind===kind&&loop)return
 stopLoop();const audio=context();if(!audio)return
 loop=audio;loopKind=kind;audio.loop=true;audio.src=ASSETS[kind]
 if(audio.onError)audio.onError(()=>{if(loop===audio)stopLoop()})
 try{audio.play()}catch(_){stopLoop()}
}
function play(kind){
 destroy(shot);shot=context();if(!shot)return
 const audio=shot;audio.loop=false;audio.src=ASSETS[kind]
 const done=()=>{if(shot===audio){destroy(audio);shot=null}}
 if(audio.onEnded)audio.onEnded(done);if(audio.onError)audio.onError(done)
 try{audio.play()}catch(_){done()}
}
function sync(call,actorID){
 if(!call||!call.call_id)return
 const id=String(call.call_id),state=String(call.state||''),actor=String(actorID||'')
 if(callID!==id){stopLoop();destroy(shot);shot=null;callID=id;lastState=''}
 if(state==='ringing'&&String(call.callee_id)===actor)playLoop('incoming')
 else if(['ringing','preparing','connecting'].includes(state)&&String(call.caller_id)===actor)playLoop('waiting')
 else stopLoop()
 if(state==='active'&&lastState!=='active')play('connected')
 else if(terminal.has(state)&&lastState!==state)play('ended')
 lastState=state
}
function clear(id=''){
 if(id&&callID&&String(id)!==callID)return
 stopLoop();destroy(shot);shot=null;callID='';lastState=''
}
module.exports={sync,clear}
