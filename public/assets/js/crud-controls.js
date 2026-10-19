(()=>{'use strict';
for(const root of document.querySelectorAll('[data-bf-crud]')){
 const selectAll=root.querySelector('[data-bf-select-all]');
 const boxes=[...root.querySelectorAll('[data-bf-row-select]')];
 const form=root.querySelector('[data-bf-bulk]'),count=root.querySelector('[data-bf-selected-count]');
 const refresh=()=>{const selected=boxes.filter(box=>box.checked).length;count.textContent=String(selected);if(selectAll){selectAll.checked=selected===boxes.length&&boxes.length>0;selectAll.indeterminate=selected>0&&selected<boxes.length;}};
 selectAll?.addEventListener('change',()=>{for(const box of boxes)box.checked=selectAll.checked;refresh();});
 boxes.forEach(box=>box.addEventListener('change',refresh));
 form?.addEventListener('submit',event=>{const selected=boxes.filter(box=>box.checked).length;if(selected===0||selected>50||form.elements.confirm.value!=='DELETE'){event.preventDefault();return;}if(!window.confirm('Permanently delete '+selected+' selected records?'))event.preventDefault();});
 for(const combo of root.querySelectorAll('[data-bf-multi-relation]')){
  const search=combo.querySelector('[data-bf-relation-search]'),results=combo.querySelector('[data-bf-relation-results]'),selected=combo.querySelector('[data-bf-multi-selected]');
  let timer,controller,revision=0;
  const active=()=>new Set([...selected.querySelectorAll('[data-bf-multi-tag]')].map(tag=>Number(tag.dataset.id)));
  const add=(item)=>{
   if(active().has(item.id)||active().size>=50)return;
   const tag=document.createElement('span');tag.className='bf-multi-tag';tag.dataset.bfMultiTag='';tag.dataset.id=String(item.id);
   const id=document.createElement('input');id.type='hidden';id.name=combo.dataset.field+'[]';id.value=String(item.id);
   const text=document.createElement('span');text.textContent=item.label;
   const remove=document.createElement('button');remove.type='button';remove.dataset.bfRemoveTag='';remove.textContent='×';remove.setAttribute('aria-label','Remove '+item.label);
   tag.append(id,text,remove);selected.append(tag);search.value='';results.hidden=true;search.setAttribute('aria-expanded','false');
  };
  selected.addEventListener('click',event=>{const btn=event.target.closest('[data-bf-remove-tag]');if(btn)btn.closest('[data-bf-multi-tag]')?.remove();});
  const load=()=>{
   controller?.abort();controller=new AbortController();const current=++revision;
   const url=new URL(combo.dataset.url,window.location.href);url.searchParams.set('field',combo.dataset.field);url.searchParams.set('q',search.value.trim());
   fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal})
   .then(response=>{if(!response.ok)throw Error('Unavailable');return response.json();})
   .then(data=>{
    if(current!==revision||!Array.isArray(data.items))return;
    results.replaceChildren();const ids=active();let displayed=0;
    for(const item of data.items){if(!Number.isSafeInteger(item.id)||item.id<1||typeof item.label!=='string'||ids.has(item.id))continue;
     const option=document.createElement('button');option.type='button';option.setAttribute('role','option');option.className='bf-relation-option';option.textContent=item.label+' (#'+item.id+')';option.addEventListener('click',()=>add(item));results.append(option);displayed++;
    }
    if(!displayed){const empty=document.createElement('span');empty.textContent=ids.size>=50?'Maximum 50 selections':'No matching records';results.append(empty);}
    results.hidden=false;search.setAttribute('aria-expanded','true');
   }).catch(error=>{if(error.name!=='AbortError'&&current===revision)results.hidden=true;});
  };
  search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(load,200);});
  search.addEventListener('focus',()=>{clearTimeout(timer);timer=setTimeout(load,70);});
  search.addEventListener('keydown',event=>{if(event.key==='Escape'){results.hidden=true;search.setAttribute('aria-expanded','false');}});
  document.addEventListener('click',event=>{if(!combo.contains(event.target)){results.hidden=true;search.setAttribute('aria-expanded','false');}});
 }
 for(const combo of root.querySelectorAll('[data-bf-relation]')){
  const input=combo.querySelector('[data-bf-relation-search]'),hidden=combo.querySelector('[data-bf-relation-value]'),results=combo.querySelector('[data-bf-relation-results]');
  let timer,request,version=0,items=[],focusIndex=-1;
  const close=()=>{results.hidden=true;input.setAttribute('aria-expanded','false');focusIndex=-1;};
  const choose=(item)=>{hidden.value=String(item.id);input.value=item.label;close();};
  const display=(entries)=>{items=entries;focusIndex=-1;results.replaceChildren();for(const [index,item] of entries.entries()){
   const opt=document.createElement('button');opt.type='button';opt.setAttribute('role','option');opt.className='bf-relation-option';opt.textContent=item.label+' (#'+item.id+')';
   opt.addEventListener('mousedown',event=>event.preventDefault());opt.addEventListener('click',()=>choose(item));results.append(opt);
  }if(!entries.length){const empty=document.createElement('div');empty.textContent='No matching records';results.append(empty);}results.hidden=false;input.setAttribute('aria-expanded','true');};
  const load=()=>{request?.abort();request=new AbortController();const current=++version;
   const url=new URL(combo.dataset.url,window.location.href);url.searchParams.set('field',combo.dataset.field);url.searchParams.set('q',input.value.trim());
   fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'},signal:request.signal})
   .then(response=>{if(!response.ok)throw Error('Lookup unavailable');return response.json();})
   .then(data=>{if(current===version&&Array.isArray(data.items))display(data.items.filter(x=>Number.isInteger(x.id)&&typeof x.label==='string'));})
   .catch(error=>{if(error.name!=='AbortError'&&current===version)close();});};
  input.addEventListener('input',()=>{hidden.value='';clearTimeout(timer);timer=setTimeout(load,220);});
  input.addEventListener('focus',()=>{clearTimeout(timer);timer=setTimeout(load,80);});
  input.addEventListener('keydown',event=>{if(results.hidden)return;const options=[...results.querySelectorAll('[role="option"]')];
   if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();focusIndex=(focusIndex+(event.key==='ArrowDown'?1:-1)+options.length)%Math.max(1,options.length);options.forEach((opt,i)=>opt.classList.toggle('active',i===focusIndex));}
   if(event.key==='Enter'&&focusIndex>=0&&items[focusIndex]){event.preventDefault();choose(items[focusIndex]);}
   if(event.key==='Escape'){event.preventDefault();close();}});
  input.addEventListener('blur',()=>setTimeout(close,120));
 }
}
})();
