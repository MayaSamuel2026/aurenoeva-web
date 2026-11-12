/* AURENOEVA_SIMPLE_MAIL_THREAD_20261112
 * One supplier and one chronological Sent -> Received -> Sent conversation.
 * Actual Hostinger messages are read on selection. The existing Mail Centre
 * owns composing and sending; this display never changes mailbox evidence.
 */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root=document.getElementById('aur-mail-trail');
    if(!root)return;
    const find=id=>document.getElementById(id);
    const list=find('aur-case-mail-list');
    const preview=find('aur-case-mail-preview');
    const search=find('aur-case-mail-search');
    const ordering=find('aur-case-mail-sort');
    const info=find('aur-case-mail-list-info');
    const pagination=find('aur-case-mail-pagination');
    const refreshStatus=find('aur-case-mail-refresh-status');
    let data;
    try{data=JSON.parse(find('aur-case-mail-data')?.textContent||'{}');}
    catch(error){preview.textContent='Mailbox list unavailable. Open the full Mail Centre.';return;}
    const threads=Array.isArray(data.threads)?data.threads:[];
    const pageSize=20;
    let page=0,selectedId=null,query='',requestNumber=0;
    const node=(tag,value,className)=>{
      const el=document.createElement(tag);
      if(value!==undefined)el.textContent=String(value);
      if(className)el.className=className;
      return el;
    };
    const date=value=>{
      const stamp=Date.parse(value||'');
      return Number.isFinite(stamp)
        ?new Date(stamp).toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'})
        :'Date not available';
    };
    const matched=()=>{
      const shown=threads.filter(thread=>[thread.supplier,thread.email,
        ...(thread.aliases||[]),...(thread.messages||[]).map(m=>m.subject)]
        .join(' ').toLocaleLowerCase().includes(query));
      const sort=ordering.value;
      shown.sort((a,b)=>sort==='supplier'
        ?a.supplier.localeCompare(b.supplier)
        :((Date.parse(a.last_at)||0)-(Date.parse(b.last_at)||0))
          *(sort==='oldest'?1:-1)||a.supplier.localeCompare(b.supplier));
      return shown;
    };
    const openMailbox=()=>{
      const full=find('aur-mail-center');
      const drawer=full?.closest('details');
      if(drawer)drawer.open=true;
      full?.scrollIntoView({behavior:'smooth',block:'start'});
    };
    root.querySelector('a.aur-correspondence-open-mail[href="#aur-mail-center"]')
      ?.addEventListener('click',event=>{event.preventDefault();openMailbox();});
    const sendDraft=(thread,subject)=>{
      document.dispatchEvent(new CustomEvent('aurenoeva:compose-supplier-followup',{
        detail:{to:thread.email,subject}
      }));
    };
    const renderMessages=(thread,items,warnings)=>{
      preview.replaceChildren();
      const head=node('div',undefined,'aur-correspondence-detail-head');
      const identity=node('div');
      identity.append(node('h3',thread.supplier),node('p',thread.email,'muted'));
      head.append(identity);
      const actions=node('div',undefined,'aur-correspondence-actions');
      const reply=node('button','Write a response','aur-followup-action');
      reply.type='button';
      const last=items.length?items[items.length-1]:null;
      reply.addEventListener('click',()=>sendDraft(thread,last?.subject||''));
      actions.append(reply);
      head.append(actions);
      preview.append(head);
      if(warnings.length)preview.append(node('p',
        'Mailbox access is incomplete: '+warnings.join(' ')+' Open full mailbox to inspect missing messages.',
        'aur-correspondence-caution'));
      preview.append(node('p',items.length+' message(s) · oldest first · original Sent and Inbox',
        'aur-conversation-facts'));
      if(!items.length){
        preview.append(node('p','No source messages found. Open the full mailbox to inspect this correspondent.','muted'));
        return;
      }
      const timeline=node('div',undefined,'aur-correspondence-thread');
      items.forEach((mail,i)=>{
        const item=document.createElement('details');
        item.className='aur-correspondence-message';
        if(i===items.length-1)item.open=true;
        const heading=document.createElement('summary');
        const direction=mail.kind==='sent'?'Sent by AURENOEVA':'Received from supplier';
        heading.append(node('strong',(i+1)+'. '+direction),
          node('time',date(mail.date)),node('small',mail.subject||'(No subject)'));
        item.append(heading);
        const body=node('div',undefined,'aur-correspondence-message-body');
        if(mail.from)body.append(node('p','From: '+mail.from,'muted'));
        if(mail.to)body.append(node('p','To: '+mail.to,'muted'));
        body.append(node('pre',mail.body||'Full text unavailable in this source.'));
        if(mail.has_attachments)body.append(node('p','This email includes attachments.','muted'));
        if(mail.download){
          const link=node('a','Open original email (.eml)');
          link.href='/procurement/?'+new URLSearchParams({
            mail_action:'download',folder:mail.folder,uid:mail.uid
          });
          link.className='aur-mail-download';
          body.append(link);
        }
        if(Array.isArray(mail.attachments)&&mail.attachments.length){
          const attachments=node('div',undefined,'aur-mail-sent-attachments');
          attachments.append(node('strong','Attachments'));
          mail.attachments.forEach((attachment,index)=>{
            const a=node('a',attachment.name||'Attachment '+(index+1));
            a.href='/procurement/?'+new URLSearchParams({
              mail_action:'download_attachment',folder:mail.folder,
              uid:mail.uid,attachment_index:index
            });
            attachments.append(a);
          });
          body.append(attachments);
        }
        item.append(body);timeline.append(item);
      });
      preview.append(timeline);
    };
    const open=async(thread)=>{
      const number=++requestNumber;
      preview.replaceChildren(node('h3',thread.supplier),
        node('p','Loading original Sent and Inbox messages…','muted'));
      if(!thread.email){
        preview.replaceChildren(node('p','Supplier address not confirmed. Open full mailbox.','muted'));
        return;
      }
      try{
        const url='/procurement/?'+new URLSearchParams({
          mail_action:'conversation',email:thread.email
        });
        const response=await fetch(url,{credentials:'same-origin',cache:'no-store',
          headers:{Accept:'application/json'}});
        const payload=await response.json();
        if(!response.ok||!payload.ok)throw Error(payload.error||'mailbox_unavailable');
        if(number!==requestNumber)return;
        renderMessages(thread,Array.isArray(payload.messages)?payload.messages:[],
          Array.isArray(payload.warnings)?payload.warnings:[]);
      }catch(error){
        if(number!==requestNumber)return;
        preview.replaceChildren(node('h3',thread.supplier),
          node('p','Original mailbox temporarily unavailable. No source messages were modified.','aur-correspondence-caution'));
        const button=node('button','Open full Mail Centre');
        button.type='button';button.addEventListener('click',openMailbox);
        preview.append(button);
      }
    };
    function render(){
      const found=matched();
      const pages=Math.max(1,Math.ceil(found.length/pageSize));
      page=Math.min(page,pages-1);
      const subset=found.slice(page*pageSize,(page+1)*pageSize);
      list.replaceChildren();
      for(const thread of subset){
        const button=node('button',undefined,'aur-conversation-row');
        button.type='button';
        button.setAttribute('aria-pressed',String(thread.id===selectedId));
        const upper=node('span',undefined,'aur-conversation-row-top');
        upper.append(node('strong',thread.supplier),node('time',date(thread.last_at)));
        const lower=node('span',undefined,'aur-conversation-row-bottom');
        lower.append(node('small',thread.email||'Email not verified'),
          node('span',thread.reply_count>0?'Reply received':'See conversation','aur-correspondence-state'));
        button.append(upper,lower);
        button.addEventListener('click',()=>{
          if(selectedId!==thread.id){
            selectedId=thread.id;open(thread);
          }
          [...list.children].forEach(x=>x.setAttribute('aria-pressed',String(x===button)));
        });
        list.append(button);
      }
      if(!subset.length)list.append(node('p','No suppliers match this search.','muted'));
      info.textContent=found.length?'Showing '+(page*pageSize+1)+'–'+Math.min((page+1)*pageSize,found.length)+
        ' of '+found.length+' suppliers':'No suppliers found';
      pagination.replaceChildren();
      if(pages>1){
        const back=node('button','Previous');back.type='button';back.disabled=page===0;
        back.addEventListener('click',()=>{page--;selectedId=null;render();});
        const next=node('button','Next');next.type='button';next.disabled=page>=pages-1;
        next.addEventListener('click',()=>{page++;selectedId=null;render();});
        pagination.append(back,node('span','Page '+(page+1)+' of '+pages),next);
      }
      if(!subset.some(x=>x.id===selectedId)){
        const first=subset[0]||null;
        selectedId=first?.id||null;
        if(first)open(first);
        else {++requestNumber;preview.replaceChildren(node('p','Choose a supplier.','muted'));}
      }
    }
    search.addEventListener('input',()=>{query=search.value.trim().toLocaleLowerCase();
      page=0;selectedId=null;render();});
    ordering.addEventListener('change',()=>{page=0;selectedId=null;render();});
    find('aur-case-mail-refresh')?.addEventListener('click',()=>{
      const thread=threads.find(x=>x.id===selectedId);
      refreshStatus.textContent='Reading original mailbox again…';
      if(thread)open(thread).finally(()=>{refreshStatus.textContent='Mailbox conversation refreshed.';});
      else refreshStatus.textContent='Choose a supplier to refresh.';
    });
    render();
  });
})();
