/* AURENOEVA_COMPACT_CORRESPONDENCE_V1
 * Read-only supplier inbox for one RFQ. Never sends, marks messages read,
 * creates a new RFQ or changes stored email evidence.
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
    const stats=find('aur-case-mail-stats');
    const info=find('aur-case-mail-list-info');
    const pagination=find('aur-case-mail-pagination');
    let data;
    try{
      data=JSON.parse(find('aur-case-mail-data')?.textContent||'{}');
    }catch(error){
      info.textContent='Conversation display unavailable. Open the original Mail Centre.';
      return;
    }
    const threads=Array.isArray(data.threads)?data.threads:[];
    const totals=data.summary||{};
    const labels={
      all:'All suppliers',awaiting:'Awaiting reply',replied:'Reply received',
      review:'Needs review',unconfirmed:'Send unconfirmed'
    };
    const formatDate=value=>{
      if(!value)return 'Date not recorded';
      const parsed=new Date(value);
      return Number.isFinite(parsed.valueOf())?
        parsed.toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'}):
        String(value).slice(0,28);
    };
    const node=(tag,content,className)=>{
      const el=document.createElement(tag);
      if(content!==undefined)el.textContent=String(content);
      if(className)el.className=className;
      return el;
    };
    const filters=[...root.querySelectorAll('[data-aur-mail-filter]')];
    const pageSize=20;
    let filter=(totals.awaiting||0)>0?'awaiting':'all';
    let page=0,selectedId=null,query='';
    const matching=()=>{
      const found=threads.filter(thread=>{
        if(filter!=='all'&&thread.status!==filter)return false;
        if(!query)return true;
        const searchable=[thread.supplier,thread.email,
          ...thread.messages.map(m=>m.subject)].join(' ').toLocaleLowerCase();
        return searchable.includes(query);
      });
      if(ordering.value==='supplier')
        found.sort((a,b)=>a.supplier.localeCompare(b.supplier));
      else{
        const asc=ordering.value==='oldest'?1:-1;
        found.sort((a,b)=>asc*((Date.parse(a.last_at)||0)-(Date.parse(b.last_at)||0))
          ||a.supplier.localeCompare(b.supplier));
      }
      return found;
    };
    const openMailbox=()=>{
      const full=find('aur-mail-center');
      const container=full?.closest('details');
      if(container)container.open=true;
      if(full)full.scrollIntoView({behavior:'smooth',block:'start'});
    };
    root.querySelector('.aur-correspondence-open-mail')?.addEventListener('click',event=>{
      event.preventDefault();openMailbox();
    });
    function renderPreview(thread){
      preview.replaceChildren();
      if(!thread){
        preview.append(node('h3','No supplier selected'),
          node('p','Choose another filter or search term. No messages have been discarded.','muted'));
        return;
      }
      const head=node('div',undefined,'aur-correspondence-detail-head');
      const identity=node('div');
      identity.append(node('h3',thread.supplier),node('p',thread.email||'Sender address not confirmed','muted'));
      head.append(identity,node('span',labels[thread.status]||'Needs review','aur-correspondence-state state-'+thread.status));
      preview.append(head);
      preview.append(node('p',[
        thread.sent_count+' sent (SMTP accepted)',
        thread.reply_count+' matched reply/replies',
        thread.review_count+' for verification',
        thread.unconfirmed_count+' unconfirmed attempt(s)'
      ].join(' · '),'aur-conversation-facts'));
      const actions=node('div',undefined,'aur-correspondence-actions');
      const mailbox=node('button','Open full Mail Centre');
      mailbox.type='button';mailbox.addEventListener('click',openMailbox);
      actions.append(mailbox);
      if(thread.email&&thread.sent_count>0){
        const followup=node('button','Draft follow-up','aur-followup-action');
        followup.type='button';
        followup.addEventListener('click',()=>{
          const latest=[...thread.messages].reverse().find(x=>x.kind==='sent');
          const subject=latest?.subject||'';
          document.dispatchEvent(new CustomEvent('aurenoeva:compose-supplier-followup',
            {detail:{to:thread.email,subject}}));
        });
        actions.append(followup);
      }
      preview.append(actions);
      if(thread.status==='review')
        preview.append(node('p','Sender, batch or delivery evidence needs review. Do not assume that a valid quotation or reply has been verified.','aur-correspondence-caution'));
      if(thread.status==='unconfirmed')
        preview.append(node('p','Sending is not confirmed. Inspect Sent before preparing a duplicate message.','aur-correspondence-caution'));
      if(thread.status==='awaiting')
        preview.append(node('p','No case-matched reply recorded for this supplier yet. This does not establish whether the message reached the inbox.','aur-correspondence-hint'));
      const timeline=node('div',undefined,'aur-correspondence-thread');
      timeline.append(node('h4','Conversation · '+thread.messages.length+' recorded event(s)'));
      thread.messages.forEach((message,index)=>{
        const box=document.createElement('details');box.className='aur-correspondence-message';
        if(index===thread.messages.length-1)box.open=true;
        const summary=document.createElement('summary');
        const heading=node('strong',message.kind==='sent'?'Sent':
          message.kind==='received'?'Received':
          message.kind==='attempt'?'Send unconfirmed':
          message.kind==='delivery_issue'?'Delivery issue':'Reply needs review');
        const time=node('time',formatDate(message.time));
        const title=node('small',message.subject||'(No subject)');
        summary.append(heading,time,title);
        box.append(summary);
        const contents=node('div',undefined,'aur-correspondence-message-body');
        contents.append(node('p',message.label||'' ,'muted'));
        if(message.item_numbers)
          contents.append(node('p','RFQ item(s): '+message.item_numbers,'muted'));
        if(message.classification)
          contents.append(node('p','Classification: '+message.classification.replaceAll('_',' '),'muted'));
        contents.append(node('pre',message.body||'No saved message preview available.'));
        if(message.preview_only)
          contents.append(node('p','Stored inbox preview; open the full Mail Centre to inspect original content and attachments.','muted'));
        box.append(contents);timeline.append(box);
      });
      preview.append(timeline);
    }
    function render(){
      const found=matching();
      const pages=Math.max(1,Math.ceil(found.length/pageSize));
      page=Math.min(page,pages-1);
      const visible=found.slice(page*pageSize,(page+1)*pageSize);
      if(!found.some(thread=>thread.id===selectedId))selectedId=visible[0]?.id||null;
      if(selectedId&&!visible.some(thread=>thread.id===selectedId)){
        const matchIndex=found.findIndex(thread=>thread.id===selectedId);
        if(matchIndex>=0)page=Math.floor(matchIndex/pageSize);
      }
      const displayed=found.slice(page*pageSize,(page+1)*pageSize);
      list.replaceChildren();
      for(const thread of displayed){
        const button=document.createElement('button');
        button.type='button';
        button.className='aur-conversation-row';
        button.setAttribute('aria-pressed',String(thread.id===selectedId));
        const top=node('span',undefined,'aur-conversation-row-top');
        top.append(node('strong',thread.supplier),node('time',formatDate(thread.last_at)));
        const bottom=node('span',undefined,'aur-conversation-row-bottom');
        bottom.append(node('small',thread.email||'Unknown email'),
          node('span',labels[thread.status]||'Needs review','aur-correspondence-state state-'+thread.status));
        button.append(top,bottom);
        button.addEventListener('click',()=>{
          selectedId=thread.id;
          renderPreview(thread);
          for(const child of list.children)
            child.setAttribute('aria-pressed',String(child===button));
        });
        list.append(button);
      }
      if(!displayed.length){
        list.append(node('p','No suppliers match this filter. Select All or change the search term.','aur-correspondence-empty'));
      }
      info.textContent=found.length?('Showing '+(page*pageSize+1)+'–'+Math.min((page+1)*pageSize,found.length)+' of '+found.length+' suppliers'):
        'No matching suppliers';
      pagination.replaceChildren();
      if(pages>1){
        const back=node('button','Previous');back.type='button';back.disabled=page===0;
        back.addEventListener('click',()=>{page--;selectedId=null;render();});
        const next=node('button','Next');next.type='button';next.disabled=page===pages-1;
        next.addEventListener('click',()=>{page++;selectedId=null;render();});
        pagination.append(back,node('span','Page '+(page+1)+' of '+pages),next);
      }
      const selected=found.find(thread=>thread.id===selectedId)||null;
      renderPreview(selected);
    }
    const counts=[
      ['all','Suppliers'],['awaiting','Awaiting'],['replied','Replied'],
      ['review','Needs review'],['unconfirmed','Unconfirmed']
    ];
    stats.replaceChildren();
    for(const [key,label] of counts){
      const card=node('span',undefined,'aur-correspondence-metric');
      card.append(node('strong',totals[key]||0),node('small',label));
      stats.append(card);
    }
    function updateFilter(){
      filters.forEach(button=>button.setAttribute('aria-pressed',
        String(button.dataset.aurMailFilter===filter)));
      page=0;selectedId=null;render();
    }
    filters.forEach(button=>button.addEventListener('click',()=>{
      filter=button.dataset.aurMailFilter;updateFilter();
    }));
    search.addEventListener('input',()=>{query=search.value.trim().toLocaleLowerCase();page=0;selectedId=null;render();});
    ordering.addEventListener('change',()=>{page=0;selectedId=null;render();});
    updateFilter();
  });
})();
