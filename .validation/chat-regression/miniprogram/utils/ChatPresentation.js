// Presentation only: no reads, sends, membership or quota decisions.
// User-facing chat time is rendered in the site's Asia/Shanghai business timezone.
const pad=n=>String(n).padStart(2,'0')
const OFFSET=8*60*60*1000,DAY=24*60*60*1000
function parseTime(value){
 if(typeof value==='number')return Number.isFinite(value)?value:NaN
 const raw=String(value||'').trim()
 if(!raw)return NaN
 if(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(raw))
  return Date.parse(raw.replace(' ','T')+'+08:00')
 return Date.parse(raw)
}
function parts(value){
 const t=parseTime(value);if(!Number.isFinite(t)||t<=0)return null
 const d=new Date(t+OFFSET)
 return {t,year:d.getUTCFullYear(),month:d.getUTCMonth()+1,day:d.getUTCDate(),hour:d.getUTCHours(),minute:d.getUTCMinutes(),dayKey:Math.floor((t+OFFSET)/DAY)}
}
function timeLabel(value,now=Date.now()){
 const date=parts(value),today=parts(now)
 if(!date||!today)return ''
 const clock=pad(date.hour)+':'+pad(date.minute)
 if(date.dayKey===today.dayKey)return clock
 if(date.dayKey===today.dayKey-1)return '昨天 '+clock
 return (date.year===today.year?'':date.year+'年')+date.month+'月'+date.day+'日 '+clock
}
function fullTimeLabel(value){
 const date=parts(value);if(!date)return ''
 return date.year+'-'+pad(date.month)+'-'+pad(date.day)+' '+pad(date.hour)+':'+pad(date.minute)
}
function messageTimes(rows,now=Date.now()){
 let previous=0
 return rows.map(row=>{
  const t=parseTime(row.createdAt||'')
  const show=Number.isFinite(t)&&(!previous||Math.abs(t-previous)>=300000)
  if(show)previous=t
  return {...row,timeDivider:show?timeLabel(t,now):''}
 })
}
module.exports={parseTime,timeLabel,fullTimeLabel,messageTimes}
