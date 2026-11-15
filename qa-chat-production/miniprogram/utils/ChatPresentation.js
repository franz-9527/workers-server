// Presentation only: no reads, sends, membership or quota decisions.
const pad=n=>String(n).padStart(2,'0')
function timeLabel(value,now=Date.now()){
 const t=typeof value==='number'?value:Date.parse(String(value||''))
 if(!Number.isFinite(t)||t<=0)return ''
 const date=new Date(t),today=new Date(now);today.setHours(0,0,0,0)
 const day=new Date(t);day.setHours(0,0,0,0)
 const clock=pad(date.getHours())+':'+pad(date.getMinutes())
 if(day.getTime()===today.getTime())return clock
 const yesterday=new Date(today);yesterday.setDate(yesterday.getDate()-1)
 if(day.getTime()===yesterday.getTime())return '昨天 '+clock
 return (date.getFullYear()===today.getFullYear()?'':date.getFullYear()+'年')+(date.getMonth()+1)+'月'+date.getDate()+'日 '+clock
}
function messageTimes(rows,now=Date.now()){
 let previous=0
 return rows.map(row=>{
  const t=Date.parse(row.createdAt||'')
  const show=Number.isFinite(t)&&(!previous||Math.abs(t-previous)>=300000)
  if(show)previous=t
  return {...row,timeDivider:show?timeLabel(t,now):''}
 })
}
module.exports={timeLabel,messageTimes}
