const fs=require('fs');
const vm=require('vm');
const assert=require('assert');

class Element {
  constructor(tag){
    this.tagName=String(tag||'').toUpperCase();
    this.children=[];this.parentNode=null;this.dataset={};this.className='';this.name='';
    this.type='';this.value='';this.checked=false;this.disabled=false;this.required=false;this.hidden=false;
    this.textContent='';this.placeholder='';this.rows=0;this.events={};this.id='';
  }
  appendChild(child){if(child&&typeof child==='object')child.parentNode=this;this.children.push(child);return child;}
  setAttribute(name,value){this[name]=String(value);}
  addEventListener(name,fn){(this.events[name]||(this.events[name]=[])).push(fn);}
  trigger(name){(this.events[name]||[]).forEach(fn=>fn({target:this}));}
  descendants(){
    const out=[];
    const walk=node=>{for(const child of node.children||[]){if(child instanceof Element){out.push(child);walk(child);}}};
    walk(this);return out;
  }
  matches(selector){
    let s=selector.trim(),checked=false;
    if(s.endsWith(':checked')){checked=true;s=s.slice(0,-8);}
    const tag=(s.match(/^[a-zA-Z]+/)||[])[0];
    const name=(s.match(/\[name="([^"]*)"\]/)||[])[1];
    if(tag&&this.tagName!==tag.toUpperCase())return false;
    if(name!==undefined&&this.name!==name)return false;
    if(checked&&!this.checked)return false;
    return !!(tag||name!==undefined);
  }
  querySelectorAll(selector){
    const selectors=selector.split(',').map(v=>v.trim()).filter(Boolean);
    return this.descendants().filter(el=>selectors.some(sel=>el.matches(sel)));
  }
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  closest(selector){
    if(selector!=='.admin-field')return null;
    let node=this;
    while(node){if(String(node.className||'').split(/\s+/).includes('admin-field'))return node;node=node.parentNode;}
    return null;
  }
  insertAdjacentElement(position,element){
    assert.equal(position,'afterend');
    const parent=this.parentNode;
    if(!parent)throw new Error('afterend without parent');
    const index=parent.children.indexOf(this);
    parent.children.splice(index+1,0,element);element.parentNode=parent;
    return element;
  }
}
class TextNode {constructor(text){this.textContent=String(text);this.parentNode=null;}}

const ids={};
global.document={
  createElement:tag=>new Element(tag),
  createTextNode:text=>new TextNode(text),
  getElementById:id=>ids[id]||null
};

function addControl(parent,tag,name,value=''){
  const el=new Element(tag);el.name=name;el.value=value;parent.appendChild(el);return el;
}
function field(parent,name,tag='input',value=''){
  const wrap=new Element('div');wrap.className='admin-field';parent.appendChild(wrap);
  return addControl(wrap,tag,name,value);
}

const form=new Element('form');form.id='platform-order-form';ids['platform-order-form']=form;
field(form,'fulfillment_mode','select','direct_install');
const description=field(form,'description','textarea','');
field(form,'contact_name','input','测试联系人');
field(form,'contact_mobile','input','13900000001');
const categories=new Element('div');categories.id='platform-category-list';ids['platform-category-list']=categories;form.appendChild(categories);
const category=addControl(categories,'input','category_ids[]','1');category.type='checkbox';category.checked=true;

const code=fs.readFileSync(process.argv[2],'utf8');
vm.runInThisContext(code,{filename:'generated.js'});

function findRow(key){return form.descendants().find(el=>el.dataset&&el.dataset.platformFieldKey===key);}
const finish=findRow('finish_type');
const other=findRow('finish_type__other_text');
const range=findRow('visit_window');
const visit=findRow('expected_visit_date');
const sw=findRow('need_protect');
assert.ok(finish&&other&&range&&visit&&sw,'all dynamic rows rendered');

const otherInput=other.querySelector('input');
assert.equal(other.hidden,true,'other explanation hidden before Other selection');
assert.equal(otherInput.disabled,true,'hidden other explanation disabled from submit');
assert.equal(otherInput.required,false,'hidden other explanation cannot block browser submit');

const finishSelect=finish.querySelector('select');
finishSelect.value='__other__';
form.trigger('change');
assert.equal(other.hidden,false,'other explanation shown after Other selection');
assert.equal(otherInput.disabled,false,'visible other explanation enabled');
assert.equal(otherInput.required,true,'visible generated other explanation becomes required');

finishSelect.value='normal';
form.trigger('change');
assert.equal(other.hidden,true,'other explanation hides again after selection changes');
assert.equal(otherInput.disabled,true,'hidden stale value remains excluded');

const rangeControls=range.querySelectorAll('input');
assert.equal(rangeControls.length,2,'date_range renders two inputs');
assert.ok(rangeControls.every(el=>el.type==='date'),'date_range controls are date inputs');
assert.ok(rangeControls.every(el=>el.name==='answers[visit_window][]'),'date_range posts a two-value array');

const visitControls=visit.querySelectorAll('input,select');
assert.equal(visitControls.length,2,'expected visit renders date and period');
const visitDate=visitControls.find(el=>el.type==='date');
const visitPeriod=visitControls.find(el=>el.name==='answers[expected_visit_period]');
assert.ok(visitDate&&visitPeriod,'expected visit period control exists');
assert.equal(visitPeriod.required,false,'period optional until date chosen');
visitDate.value='2026-11-20';
form.trigger('input');
assert.equal(visitPeriod.required,true,'period required once visit date is chosen');

const switchSelect=sw.querySelector('select');
assert.ok(switchSelect,'switch renders as controlled select');

console.log('[PASS] dynamic rule runtime hides generated other fields and renders valid date controls');
