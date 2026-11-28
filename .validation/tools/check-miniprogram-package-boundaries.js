// Full private source checkout only. Run in workers-server, never on the source repo.
// Static dependency / source-budget gate; WeChat compiler output remains authoritative.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict')
const {jsReferences}=require('./check-miniprogram-main-js-ownership.js')
const repo=path.resolve(__dirname,'..')
const project=JSON.parse(fs.readFileSync(path.join(repo,'project.config.json'),'utf8'))
const root=path.resolve(repo,project.miniprogramRoot||'miniprogram/')
const read=p=>fs.readFileSync(path.join(root,p),'utf8')
const app=JSON.parse(read('app.json'))
const packages=app.subpackages||app.subPackages||[]
const names=packages.map(p=>p.root)
const paused=['subpackages/chat/call','audio/chat']
const retired=/\bpages\/(?:orderCenter|constructionOrder|orderSubmit|userOrder|workerOrder|adminWorkbench|platformOrderShare)\/[\w-]+/g
const issues=[],files=new Map(),sizes={}
const dynamicReferences=[],externalReferences=[],resolvedReferences=[]
const media=/\.(?:png|jpe?g|svg|gif|webp|bmp|ico|avif|wav|mp3|aac|m4a|ogg|opus|flac|amr|mp4|webm|ttf|woff2?)(?:[?#].*)?$/i
// Runtime package integrity takes precedence over compiler-side file pruning.
for(const key of ['uglifyFileName','ignoreUploadUnusedFiles','ignoreDevUnusedFiles']){
 if(project.setting?.[key]!==false)issues.push('project.config.json: '+key+' must remain false until real package and device acceptance')
}
const inside=(p,d)=>p===d||p.startsWith(d+'/')
const owner=p=>names.find(d=>inside(p,d))||'main'
const normalize=p=>path.posix.normalize(p.replace(/^\/+/, '').replace(/\\/g,'/')).replace(/\/$/,'')
const rules=project.packOptions?.ignore||[]
for(const rule of rules)if(!['folder','file','suffix','prefix'].includes(rule.type)||typeof rule.value!=='string'||!rule.value)issues.push('unsupported packOptions.ignore: '+JSON.stringify(rule))
const ignored=p=>rules.some(rule=>{
 if(typeof rule.value!=='string'||!rule.value)return false
 const v=normalize(rule.value)
 return rule.type==='folder'?inside(p,v):rule.type==='file'?p===v:rule.type==='suffix'?p.endsWith(v):rule.type==='prefix'?p.startsWith(v):false
})
const excluded=p=>paused.some(d=>inside(p,d))||ignored(p)
function walk(dir){
 for(const item of fs.readdirSync(dir,{withFileTypes:true})){
  const full=path.join(dir,item.name),p=path.relative(root,full).split(path.sep).join('/')
  if(item.isDirectory()){if(!['node_modules','miniprogram_npm','.git'].includes(item.name))walk(full);continue}
  if(!item.isFile())continue
  files.set(p,fs.statSync(full).size)
  if(!excluded(p))sizes[owner(p)]=(sizes[owner(p)]||0)+files.get(p)
 }
}
walk(root)
const folded=new Map()
for(const p of files.keys()){
 const k=p.toLowerCase()
 if(!folded.has(k))folded.set(k,[])
 folded.get(k).push(p)
}
for(const paths of folded.values())if(paths.length>1&&paths.some(p=>!excluded(p)))issues.push('case-colliding package files: '+paths.join(', '))
function issue(file,message){issues.push(file+': '+message)}
function local(spec,from,ext,allowBare=false){
 if(typeof spec!=='string'||!spec){issue(from,'empty/non-string local reference');return}
 if(/^[a-z][a-z0-9+.-]*:/i.test(spec))return
 if(!spec.startsWith('.')&&!spec.startsWith('/')&&!allowBare)return
 if(spec.startsWith('//'))return
 const name=path.posix.normalize(spec.startsWith('/')?spec.slice(1):path.posix.join(path.posix.dirname(from),spec))
 if(name==='..'||name.startsWith('../')){issue(from,'reference escapes mini program: '+spec);return}
 const options=ext==='.js'?[name,name+'.js',name+'.json',name+'/index.js',name+'/index.json']:ext?[name,name+ext,name+'/index'+ext]:[name]
 const target=options.find(p=>files.has(p))
 if(!target){
  const matches=options.flatMap(p=>folded.get(p.toLowerCase())||[])
  issue(from,matches.length?'case mismatch '+spec+' (actual '+matches.join(', ')+')':'missing '+spec);return
 }
 if(excluded(target)){issue(from,'shipping dependency enters excluded file: '+target);return}
 const a=owner(from),b=owner(target)
 if(a!==b&&(b!=='main'||packages.some(p=>p.root===a&&p.independent))){issue(from,'synchronous cross-package dependency: '+target);return}
 resolvedReferences.push({from,target})
 return target
}
function plugin(from,name,kind){
 const pkg=packages.find(x=>x.root===owner(from))
 if(!pkg?.plugins?.[name]&&(!app.plugins?.[name]||pkg?.independent))issue(from,'plugin not declared in accessible scope: '+name)
 if(name==='WechatSI'&&owner(from)!=='subpackages/chat')issue(from,'speech plugin must remain chat-owned')
 externalReferences.push({from,kind,name})
}
function mediaRef(from,spec,kind){
 if(spec.includes('{{')||spec.includes('${')){dynamicReferences.push({from,kind,expression:spec});return}
 if(/^[a-z][a-z0-9+.-]*:/i.test(spec)||spec.startsWith('//'))return
 if(media.test(spec))local(spec.split(/[?#]/)[0],from,'',true)
}
function cssUrls(from,source){
 for(const m of source.matchAll(/url\(\s*(?:"([^"]*)"|'([^']*)'|([^)]*))\s*\)/gi)){
  const spec=(m[1]??m[2]??m[3]).trim()
  if(spec)mediaRef(from,spec,'css-url')
 }
}
if(Object.keys(app.usingComponents||{}).length)issue('app.json','global custom components defeat requiredComponents lazy injection; register on consuming pages')
const registered=new Set()
for(const page of app.pages||[])registered.add(page)
for(const pkg of packages)for(const page of pkg.pages||[])registered.add(pkg.root+'/'+page)
for(const page of registered){
 for(const ext of ['.js','.json','.wxml','.wxss']){
  if(excluded(page+ext))issue('app.json','excluded registered page file: '+page+ext)
  else if(!files.has(page+ext))issue('app.json','registered page missing '+page+ext)
 }
}
for(const platform of ['mini-ios','mini-android'])for(const page of app[platform]?.pages||[])if(!registered.has(page))issue('app.json','unregistered platform route: '+page)
for(const p of files.keys()){
 if(excluded(p)||!/\.(js|json|wxss|wxml|wxs)$/.test(p))continue
 const source=read(p)
 // Only literal references are checked; computed refs require targeted runtime tests.
 if(p.endsWith('.js')||p.endsWith('.wxs')){
  if(p.endsWith('.js')){
   try{
    for(const ref of jsReferences(source))if(ref.spec)local(ref.spec,p,'.js')
   }catch(error){issue(p,'invalid JS: '+error.message)}
  }else for(const m of source.matchAll(/\brequire\s*\(\s*['"]([^'"]+)['"]/g))local(m[1],p,'.wxs',true)
  for(const m of source.matchAll(/\brequirePlugin\s*\(\s*['"]([^'"]+)['"]/g)){
   plugin(p,m[1],'requirePlugin')
  }
  if(p.endsWith('.js')){
   for(const m of source.matchAll(/['"`]plugin:\/\/([\w-]+)\//g))plugin(p,m[1],'plugin-route')
   for(const m of source.matchAll(/(['"`])(\/(?:images|subpackages)\/[^'"`\r\n]+)\1/g)){
    if(media.test(m[2])||m[2].includes('${'))mediaRef(p,m[2],'js-local-media')
   }
   for(const m of source.matchAll(/\brequirePlugin\s*\(\s*(?!['"\s])([^)]*)\)/g))dynamicReferences.push({from:p,kind:'computed-plugin',expression:m[1]})
  }
 }else if(p.endsWith('.json')){
  let data
  try{data=JSON.parse(source)}catch(error){issue(p,'invalid JSON: '+error.message);continue}
  const specs=Object.values(data.usingComponents||{})
  for(const generic of Object.values(data.componentGenerics||{}))if(generic&&typeof generic==='object'&&generic.default)specs.push(generic.default)
  for(const spec of specs){
   if(typeof spec!=='string'||!spec){issue(p,'invalid component declaration');continue}
   if(spec.startsWith('plugin://')){
    const name=spec.match(/^plugin:\/\/([\w-]+)\//)?.[1]
    if(name)plugin(p,name,'plugin-component');else issue(p,'invalid plugin component: '+spec)
    continue
   }
   if(spec.startsWith('dynamicLib://')){externalReferences.push({from:p,kind:'dynamicLib-component',name:spec});continue}
   const target=local(spec,p,'.json',true)
   if(target&&target.endsWith('.json')){
    for(const ext of ['.js','.wxml','.wxss']){
     const sibling=target.slice(0,-5)+ext
     if(!files.has(sibling)||excluded(sibling))issue(p,'incomplete/excluded component: '+sibling)
    }
   }
  }
 }else if(p.endsWith('.wxss')){
  const clean=source.replace(/\/\*[\s\S]*?\*\//g,'')
  for(const m of clean.matchAll(/@import\s+['"]([^'"]+)['"]/g))local(m[1],p,'.wxss',true)
  cssUrls(p,clean)
 }else{
  const clean=source.replace(/<!--[\s\S]*?-->/g,'')
  for(const m of clean.matchAll(/<(import|include|wxs)\b[^>]*\bsrc\s*=\s*['"]([^'"]+)['"]/g))local(m[2],p,m[1]==='wxs'?'.wxs':'.wxml',true)
  for(const tag of clean.matchAll(/<(?:image|audio|video|cover-image)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>/g)){
   for(const m of tag[0].matchAll(/\s(?:src|poster)\s*=\s*(?:"([^"]*)"|'([^']*)')/g))mediaRef(p,m[1]??m[2],'wxml-media')
  }
  for(const m of clean.matchAll(/<wxs\b(?![^>]*\/>)[^>]*>([\s\S]*?)<\/wxs>/g)){
   for(const ref of m[1].matchAll(/\brequire\s*\(\s*['"]([^'"]+)['"]/g))local(ref[1],p,'.wxs',true)
  }
  for(const m of clean.matchAll(/\bstyle\s*=\s*"([^"]*)"|\bstyle\s*=\s*'([^']*)'/g))cssUrls(p,m[1]??m[2])
 }
 if(!p.endsWith('.wxss')){
  const clean=source.replace(/<!--[\s\S]*?-->/g,'').replace(/\/\*[\s\S]*?\*\//g,'')
  for(const m of clean.matchAll(retired))issue(p,'retired order route: '+m[0])
 }
}
const budget=1500000
if((sizes.main||0)>budget)issue('main','source estimate '+sizes.main+' exceeds '+budget+' bytes')
for(const [name,size] of Object.entries(sizes))if(name!=='main'&&size>2000000)issue(name,'source estimate '+size+' exceeds internal 2 MB budget')
console.log(JSON.stringify({sourceBytes:sizes,mainBudget:budget,registeredPages:registered.size,
 resolvedReferenceCount:resolvedReferences.length,externalReferences,dynamicReferences},null,2))
assert.equal(issues.length,0,issues.join('\n'))
console.log('PASS static package boundaries and source budgets; compiled size still requires WeChat tooling')
