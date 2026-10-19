(()=>{'use strict';
const panel=document.querySelector('[data-bf-studio-form]');if(!panel)return;
const rows=panel.querySelector('[data-bf-field-rows]'),title=panel.querySelector('[data-bf-form-title]'),output=panel.querySelector('[data-bf-schema-output]'),status=panel.querySelector('[data-bf-studio-status]');
const selectOpen=document.querySelector('[data-bf-form-open]');
selectOpen?.addEventListener('change',()=>{const slug=selectOpen.value;if(/^[a-z][a-z0-9_-]{0,63}$/.test(slug))location.assign('/admin/forms?name='+encodeURIComponent(slug))});
const TYPES=['text','email','number','date','textarea','select','checkbox','file','autocomplete'];
let state;
try{state=JSON.parse(document.querySelector('[data-bf-form-initial]').value)}catch{state={title:'New form',fields:[]}}
const label=(text,control)=>{const el=document.createElement('label');el.className='bf-field';const caption=document.createElement('span');caption.textContent=text;el.append(caption,control);return el};
const input=(value,max=120)=>{const el=document.createElement('input');el.type='text';el.value=value||'';el.maxLength=max;return el};
const button=(text,handler)=>{const b=document.createElement('button');b.type='button';b.className='btn btn-secondary btn-small';b.textContent=text;b.addEventListener('click',handler);return b};
const sync=()=>{const fields=[];rows.querySelectorAll('[data-bf-editor-row]').forEach(row=>{const o={name:row.querySelector('[data-bf-name]').value.trim(),label:row.querySelector('[data-bf-label]').value.trim(),type:row.querySelector('[data-bf-type]').value,required:row.querySelector('[data-bf-required]').checked};
if(o.type==='select'){o.options={};row.querySelector('[data-bf-options]').value.split('\n').forEach(line=>{const at=line.indexOf('=');if(at>0)o.options[line.slice(0,at).trim()]=line.slice(at+1).trim()})}
if(o.type==='autocomplete')o.endpoint=row.querySelector('[data-bf-endpoint]').value.trim();
if(o.type==='file')o.multiple=row.querySelector('[data-bf-multiple]').checked;
fields.push(o)});
return {title:title.value.trim(),fields};};
function draw(){rows.replaceChildren();state.fields.forEach((field,index)=>{
const row=document.createElement('fieldset');row.className='bf-studio-row';row.dataset.bfEditorRow='1';const legend=document.createElement('legend');legend.textContent='Field '+(index+1);row.append(legend);
const name=input(field.name,64);name.dataset.bfName='1';name.required=true;name.pattern='[a-z][a-z0-9_]{0,63}';
const fieldLabel=input(field.label,120);fieldLabel.dataset.bfLabel='1';fieldLabel.required=true;
const kind=document.createElement('select');kind.dataset.bfType='1';TYPES.forEach(type=>{const o=document.createElement('option');o.value=type;o.textContent=type;kind.append(o)});kind.value=TYPES.includes(field.type)?field.type:'text';
const required=document.createElement('input');required.type='checkbox';required.dataset.bfRequired='1';required.checked=Boolean(field.required);
const options=document.createElement('textarea');options.dataset.bfOptions='1';options.rows=3;options.placeholder='key=Option label (one per line)';options.value=Object.entries(field.options||{}).map(([a,b])=>a+'='+b).join('\n');
const endpoint=input(field.endpoint||'/admin/autocomplete',200);endpoint.dataset.bfEndpoint='1';
const multiple=document.createElement('input');multiple.type='checkbox';multiple.dataset.bfMultiple='1';multiple.checked=Boolean(field.multiple);
const optionWrap=label('Select options',options),endpointWrap=label('AJAX endpoint (same-origin)',endpoint),multipleWrap=label('Allow multiple files',multiple);
const updateConditional=()=>{optionWrap.hidden=kind.value!=='select';endpointWrap.hidden=kind.value!=='autocomplete';multipleWrap.hidden=kind.value!=='file'};kind.addEventListener('change',updateConditional);
const primary=document.createElement('div');primary.className='bf-studio-grid';primary.append(label('Name',name),label('Label',fieldLabel),label('Control type',kind),label('Required',required));row.append(primary,optionWrap,endpointWrap,multipleWrap);
const actions=document.createElement('div');actions.className='bf-actions';actions.append(button('↑ Move up',()=>move(index,-1)),button('↓ Move down',()=>move(index,1)),button('Remove',()=>{state=sync();state.fields.splice(index,1);draw()}));row.append(actions);rows.append(row);updateConditional();
});status.textContent=state.fields.length+' fields in editor. Save to update the preview.'}
function move(index,offset){state=sync();const next=index+offset;if(next<0||next>=state.fields.length)return;[state.fields[index],state.fields[next]]=[state.fields[next],state.fields[index]];draw()}
const valid=definition=>{if(!definition||typeof definition.title!=='string'||!Array.isArray(definition.fields)||definition.fields.length<1||definition.fields.length>40)throw Error('A form needs 1–40 fields');const names=new Set();for(const field of definition.fields){if(!/^[a-z][a-z0-9_]{0,63}$/.test(field.name)||names.has(field.name))throw Error('Field names must be unique lowercase identifiers');names.add(field.name);if(!field.label||!TYPES.includes(field.type))throw Error('Label or field type is invalid');if(field.type==='select'&&!Object.keys(field.options).length)throw Error('Select control needs key=label options');if(field.type==='autocomplete'&&!/^\/(?!\/)[A-Za-z0-9/_-]{1,200}$/.test(field.endpoint))throw Error('Invalid autocomplete endpoint')}};
title.value=state.title;draw();
panel.querySelector('[data-bf-add-field]').addEventListener('click',()=>{state=sync();if(state.fields.length>=40){status.textContent='Maximum 40 fields';return}state.fields.push({name:'field_'+(state.fields.length+1),label:'New field',type:'text',required:false});draw()});
panel.addEventListener('submit',event=>{try{state=sync();valid(state);output.value=JSON.stringify(state)}catch(err){event.preventDefault();status.textContent=err.message}});
const raw=document.querySelector('[data-bf-form-json]');if(raw)raw.value=JSON.stringify(state,null,2);
document.querySelector('[data-bf-import-json]')?.addEventListener('click',()=>{try{const proposal=JSON.parse(raw.value);valid(proposal);state=proposal;title.value=state.title;draw();status.textContent='JSON imported into unsaved editor.'}catch(err){status.textContent='Import rejected: '+err.message}});
})();
