// Isolated static UI contract; accepts supplied source strings and never connects to PHP/Go.
const required=['group','governance','audit','storage','call']
function balanced(w){
 const pattern=/<\\/?([A-Za-z][A-Za-z0-9-]*)\\b(?:[^>"']|"[^"]*"|'[^']*')*>/g
 let match,stack=[]
 while((match=pattern.exec(w))){
  const tag=match[1],raw=match[0]
  if(raw.startsWith('</')){if(stack.pop()!==tag)throw Error('Unbalanced '+tag)}
  else if(!raw.endsWith('/>'))stack.push(tag)
 }
 if(stack.length)throw Error('Unclosed '+stack.join(','))
}
const results=[]
for(const name of required){
 const part=sources[name]
 if(!part)throw Error('Missing fixture '+name)
 balanced(part.wxml)
 const handlers=[...new Set([...part.wxml.matchAll(/(?:bindtap|bindchange|bindinput|catchtap)="([a-zA-Z]\\w*)"/g)].map(x=>x[1]))]
 for(const fn of handlers)if(!new RegExp('\\b'+fn+'\\s*\\(').test(part.js))throw Error(name+': unresolved '+fn)
 const json=JSON.parse(part.json)
 if(json.navigationBarBackgroundColor!=='#f7f7f7'||json.homeButton!==false)throw Error(name+': native navigation drift')
 if(!part.wxss.includes('prefers-color-scheme'))throw Error(name+': dark styles missing')
 results.push(name+': markup, handlers, navigation, theme OK')
}
const g=sources.group.wxml
for(const s of ['item.can_remove','group.can_invite','open-type="share"','bindtap="makePoster"','bindtap="savePoster"','group.member_limit','canvas-id="groupPoster"','group.can_edit_role','group.can_leave'])if(!g.includes(s))throw Error('group contract missing '+s)
const gr=sources.group.js
for(const s of ["this.run('invite'","this.run('remove'","this.run('revoke'","this.run('poster'","display_role:roles[this.data.roleIndex].value"])if(!gr.includes(s))throw Error('group authorization flow missing '+s)
if(!sources.storage.js.includes("selected.size"))throw Error('storage empty-selection guard missing')
if(!sources.call.js.includes("historyLoading"))throw Error('history loading guard missing')
return results.concat(['invite/share/permissions OK','cache and call state guards OK'])
