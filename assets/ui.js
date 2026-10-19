'use strict';
for (const form of document.querySelectorAll('.confirm-delete')) form.addEventListener('submit',e=>{
  const remote=form.querySelector('input[name="action"]')?.value==='hide_remote';
  if(!confirm(remote?'Hide this remote post from your timelines? The original stays on its server.':'Delete your post and send its deletion to the Fediverse?')) e.preventDefault();
});
const form=document.getElementById('postComposer');
if(form){
  const field=document.getElementById('composeText'),counter=document.getElementById('composeCounter'),send=document.getElementById('publishButton');
  const file=document.getElementById('uploadPost'),panel=document.getElementById('attachmentPanel'),preview=document.getElementById('attachmentPreview');
  let objectUrl;
  const update=()=>{
    const n=500-Array.from(field.value).length;
    counter.textContent=n>=0?`${n} left`:`${-n} over limit`;
    counter.classList.toggle('over-limit',n<0);send.disabled=n<0||!field.value.trim();
  };
  const image=()=>{
    if(objectUrl) URL.revokeObjectURL(objectUrl);
    const selected=file.files[0]; panel.hidden=!selected;
    if(!selected){preview.removeAttribute('src');return;}
    if(selected.size>8*1024*1024||!['image/jpeg','image/png','image/gif','image/webp'].includes(selected.type)){alert('Choose a JPEG, PNG, GIF or WebP image of 8 MB or less.');file.value='';image();return;}
    objectUrl=URL.createObjectURL(selected);preview.src=objectUrl;document.getElementById('attachmentName').textContent=selected.name;
  };
  field.addEventListener('input',update);file.addEventListener('change',image);
  field.addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();update();if(!send.disabled)form.requestSubmit();}});
  document.getElementById('removeAttachment').addEventListener('click',()=>{file.value='';image();});
  form.addEventListener('submit',e=>{update();if(send.disabled)e.preventDefault();else{send.disabled=true;send.textContent='Publishing…';}});
  update();
}
for(const img of document.querySelectorAll('.media-tile img,.link-card>img,.quote-image')) img.addEventListener('error',()=>{
  // Keep the source link and text card available when a remote CDN refuses hotlinks.
  if(img.closest('.link-card')||img.classList.contains('quote-image'))img.hidden=true;
});
