(()=>{'use strict';const form=document.querySelector('[data-module-evolve]');if(!form)return;
const original=JSON.parse(document.querySelector('[data-evolve-initial]').value),holder=form.querySelector('[data-evolve-fields]'),output=form.querySelector('[data-evolve-output]'),status=form.querySelector('[data-evolve-status]');
const fields=[];const types=['text','textarea','email','integer','decimal','date','boolean','relation'];
const mk=(tag,props={})=>Object.assign(document.createElement(tag),props);
function render(){holder.replaceChildren();fields.forEach((f,i)=>{const row=mk('div',{className:'bf-evolution-new'});
 const name=mk('input',{value:f.name,placeholder:'field_name',required:true,maxLength:40,pattern:'[a-z][a-z0-9_]{0,39}'});name.addEventListener('input',()=>f.name=name.value);
 const label=mk('input',{value:f.label,placeholder:'Label',required:true,maxLength:100});label.addEventListener('input',()=>f.label=label.value);
 const type=mk('select');for(const t of types){const opt=mk('option',{value:t,textContent:t});if(t===f.type)opt.selected=true;type.append(opt);}
 const ref=mk('input',{placeholder:'Related module',value:f.references||'',maxLength:36});
 const update=()=>{f.type=type.value;ref.hidden=f.type!=='relation';ref.required=f.type==='relation';if(f.type==='relation')f.references=ref.value;else delete f.references;};type.addEventListener('change',update);ref.addEventListener('input',()=>f.references=ref.value);update();
 const remove=mk('button',{type:'button',textContent:'Remove',className:'btn btn-secondary'});remove.addEventListener('click',()=>{fields.splice(i,1);render();});
 row.append(name,label,type,ref,remove);holder.append(row);});}
form.querySelector('[data-evolve-add]').addEventListener('click',()=>{if(original.fields.length+fields.length>=24){status.textContent='Maximum 24 fields per module.';return;}fields.push({name:'new_field_'+(fields.length+1),label:'New field',type:'text',required:false});render();});
form.addEventListener('submit',event=>{if(fields.length===0||original.fields.length+fields.length>24){event.preventDefault();status.textContent='Add 1 or more nullable fields';return;}output.value=JSON.stringify({...original,fields:[...original.fields,...fields.map(f=>({...f,required:false}))]});});
render();})();
