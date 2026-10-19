(()=>{'use strict';const root=document.documentElement;try{root.dataset.theme=localStorage.getItem('bf-theme')==='dark'?'dark':'light'}catch{};document.querySelectorAll('[data-theme-switch]').forEach(button=>button.addEventListener('click',()=>{const theme=root.dataset.theme==='dark'?'light':'dark';root.dataset.theme=theme;try{localStorage.setItem('bf-theme',theme)}catch{}}));document.querySelectorAll('.sidenav a').forEach(a=>{if(new URL(a.href).pathname===location.pathname)a.classList.add('is-active')});document.querySelectorAll('[data-auto-submit]').forEach(s=>s.addEventListener('change',()=>s.form?.requestSubmit()));const toast=msg=>{const node=document.querySelector('.bf-toast');if(!node)return;node.textContent=msg;node.classList.add('visible');setTimeout(()=>node.classList.remove('visible'),3200)};
// Accessible AJAX autocomplete. All remote strings are textContent (never HTML).
document.querySelectorAll('[data-autocomplete]').forEach(root=>{
 const input=root.querySelector('input:not([type=hidden])'),box=root.querySelector('[role=listbox]'),value=root.querySelector('[data-autocomplete-value]');
 if(!input||!box)return;
 let controller=null,timer=null,index=-1;
 const clear=()=>{box.replaceChildren();input.setAttribute('aria-expanded','false');index=-1};
 const choose=(item)=>{input.value=String(item.label||'');if(value)value.value=String(item.id??'');clear()};
 input.addEventListener('input',()=>{if(value)value.value='';clearTimeout(timer);controller?.abort();clear();
 if(input.value.trim().length<2)return;
 timer=setTimeout(async()=>{controller=new AbortController();try{
 const url=new URL(root.dataset.autocomplete,location.origin);if(url.origin!==location.origin)throw Error('Invalid endpoint');url.searchParams.set('q',input.value.trim());
 const response=await fetch(url,{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)throw Error('Unavailable');
 const results=await response.json();if(!Array.isArray(results))throw Error('Invalid results');clear();
 results.slice(0,8).forEach(item=>{if(!item||typeof item!=='object')return;const b=document.createElement('button');b.type='button';b.setAttribute('role','option');b.textContent=String(item.label||'');
 b.addEventListener('click',()=>choose(item));box.append(b)});input.setAttribute('aria-expanded',box.children.length?'true':'false');
 }catch(e){if(e.name!=='AbortError')clear()}},250)});
 input.addEventListener('keydown',e=>{const choices=[...box.querySelectorAll('[role=option]')];if(e.key==='Escape')clear();
 if(!choices.length)return;if(e.key==='ArrowDown'){e.preventDefault();index=(index+1)%choices.length;choices[index].focus()}
 if(e.key==='Enter'&&index>=0){e.preventDefault();choices[index].click()}});
 box.addEventListener('keydown',e=>{const choices=[...box.querySelectorAll('[role=option]')];if(e.key==='Escape'){clear();input.focus()}
 if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();index=(index+(e.key==='ArrowDown'?1:-1)+choices.length)%choices.length;choices[index]?.focus()}
 if(e.key==='Enter'&&index>=0){e.preventDefault();choices[index].click();input.focus()}});
});
// Multiple file control: reorder, remove, square-crop and resize entirely in the browser.
document.querySelectorAll('[data-file-control]').forEach(root=>{
const input=root.querySelector('input[type=file]'),area=root.querySelector('.bf-drop-area'),list=root.querySelector('.bf-file-list');if(!input||!area||!list)return;
let files=[],activeDrag=-1;
const maxCount=input.multiple?12:1,maxBytes=10*1024*1024;
const allowed=new Set((input.accept||'').split(',').map(s=>s.trim()).filter(Boolean));
const live=msg=>{const node=root.querySelector('small');if(node)node.textContent=msg};
const sync=()=>{if(typeof DataTransfer==='undefined')return false;const dt=new DataTransfer();files.forEach(f=>dt.items.add(f));input.files=dt.files;return true};
const draw=()=>{list.replaceChildren();files.forEach((file,index)=>{
const row=document.createElement('div');row.className='file-row';row.draggable=input.multiple;row.dataset.position=String(index);
const preview=document.createElement('img');preview.alt='';
if(file.type.startsWith('image/')){const url=URL.createObjectURL(file);preview.src=url;preview.onload=()=>URL.revokeObjectURL(url);preview.onerror=()=>URL.revokeObjectURL(url)}else{preview.style.display='none'}
const name=document.createElement('b');name.textContent=file.name+' ('+Math.round(file.size/1024)+' KB)';name.title=file.name;
const actions=document.createElement('span');actions.className='file-actions';
const make=(symbol,title,fn)=>{const b=document.createElement('button');b.type='button';b.textContent=symbol;b.title=title;b.setAttribute('aria-label',title+' '+file.name);b.addEventListener('click',fn);actions.append(b)};
const swap=(other)=>{if(other<0||other>=files.length)return;[files[index],files[other]]=[files[other],files[index]];sync();draw()};
if(input.multiple){make('↑','Move up',()=>swap(index-1));make('↓','Move down',()=>swap(index+1))}
if(file.type.startsWith('image/'))make('◫','Crop to centered square and resize',async()=>{try{
const bitmap=await createImageBitmap(file);try{const side=Math.min(bitmap.width,bitmap.height);if(side>12000||bitmap.width*bitmap.height>20000000)throw Error('Image too large');
const canvas=document.createElement('canvas');const target=Math.min(1200,side);canvas.width=target;canvas.height=target;
canvas.getContext('2d').drawImage(bitmap,Math.floor((bitmap.width-side)/2),Math.floor((bitmap.height-side)/2),side,side,0,0,target,target);
const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.88));if(!blob)throw Error('Unsupported canvas');
files[index]=new File([blob],file.name.replace(/\.[^.]+$/,'')+'-crop.jpg',{type:'image/jpeg'});sync();draw();live('Image crop prepared. The server validates the uploaded result.')
}finally{bitmap.close()}}catch{live('This image cannot be transformed in the browser.')}});
make('×','Remove file',()=>{files.splice(index,1);sync();draw()});
row.append(preview,name,actions);
row.addEventListener('dragstart',()=>activeDrag=index);
row.addEventListener('dragover',e=>{if(activeDrag>=0)e.preventDefault()});
row.addEventListener('drop',e=>{if(activeDrag<0)return;e.preventDefault();const dragged=files.splice(activeDrag,1)[0];files.splice(index,0,dragged);activeDrag=-1;sync();draw()});
row.addEventListener('dragend',()=>activeDrag=-1);
list.append(row)})};
const add=arr=>{let ignored=0;const selected=[];for(const f of arr){if((allowed.size&&!allowed.has(f.type))||f.size>maxBytes||f.size===0){ignored++;continue}selected.push(f)}
files=input.multiple?[...files,...selected].slice(0,maxCount):selected.slice(0,1);sync();draw();if(ignored)live('Some files were rejected (format or 10 MB maximum).')};
input.addEventListener('change',()=>{const chosen=[...input.files];files=[];add(chosen)});
area.addEventListener('click',()=>input.click());area.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();input.click()}});
area.addEventListener('dragover',e=>{e.preventDefault();area.classList.add('drag-over')});area.addEventListener('dragleave',()=>area.classList.remove('drag-over'));
area.addEventListener('drop',e=>{e.preventDefault();area.classList.remove('drag-over');add([...e.dataTransfer.files])});
const picker=root.querySelector('[data-bf-open-library]'),dialog=root.querySelector('dialog.bf-library-dialog'),existing=root.querySelector('[data-bf-existing-id]');
root.querySelector('[data-bf-close-library]')?.addEventListener('click',()=>dialog?.close());
picker?.addEventListener('click',async()=>{if(!dialog)return;const panel=dialog.querySelector('[data-bf-library-files]');panel.textContent='Loading private library…';dialog.showModal();try{
const endpoint=new URL(picker.dataset.endpoint,location.origin);if(endpoint.origin!==location.origin)throw Error('Unsafe endpoint');
const res=await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}});if(!res.ok)throw Error('Unavailable');
const items=await res.json();if(!Array.isArray(items))throw Error('Invalid response');panel.replaceChildren();
items.slice(0,100).forEach(item=>{const button=document.createElement('button');button.type='button';button.className='btn btn-secondary';button.textContent=String(item.original_name||'File');button.addEventListener('click',()=>{existing.value=String(item.id);live('Selected existing file: '+item.original_name);dialog.close()});panel.append(button)});
if(!items.length)panel.textContent='No files available';
}catch{panel.textContent='Unable to access media library'}})
});
// Optional media picker for controls; still requires server-side authorization.
document.querySelectorAll('[data-media-picker]').forEach(button=>button.addEventListener('click',async()=>{const modal=document.querySelector('.media-modal'),container=modal?.querySelector('.modal-files');if(!modal||!container)return;container.textContent='Loading…';modal.showModal();try{const response=await fetch('/admin/media/json',{credentials:'same-origin'});if(!response.ok)throw Error('Library unavailable');const files=await response.json();container.replaceChildren();if(!files.length){container.textContent='Your media library is empty.';return}files.forEach(file=>{const btn=document.createElement('button');btn.type='button';const img=document.createElement('img');img.src='/admin/media/file/'+encodeURIComponent(file.id);img.alt='';const label=document.createElement('small');label.textContent=String(file.original_name||'Image');btn.append(img,label);btn.addEventListener('click',()=>{document.querySelector('[data-media-id]').value=String(file.id);modal.close();toast('Existing media selected: '+file.original_name)});container.append(btn)})}catch(e){container.textContent='Could not load media library'}}));document.querySelectorAll('[data-close-modal]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog')?.close()));})();
;(()=>{document.querySelectorAll('[data-confirm-delete]').forEach(button=>button.addEventListener('click',event=>{if(!confirm('Delete the selected files and their generated variants permanently?'))event.preventDefault()}))})();
