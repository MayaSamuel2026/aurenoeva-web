/* AURENOEVA_SINGLE_RFQ_WORKBENCH_20261111
 * One original case. All forms and mail/supplier writers are original source.
 * This script only relocates live DOM sections; it neither creates RFQs nor
 * sends, approves, or edits messages.
 */
(() => {
'use strict';
document.addEventListener('DOMContentLoaded', () => {
 const tabs=document.getElementById('aur-tabs');
 const rfq=document.getElementById('aur-one-rfq');
 if(!tabs||!rfq)return;
 const keys=['rfq','sources','mail','drafts','more'];
 const panels={};
 const get=id=>document.getElementById(id);
 const move=(id,parent)=>{
   const node=typeof id==='string'?get(id):id;
   if(node&&parent)parent.append(node);
   return node;
 };
 const drawer=(panel,label,items)=>{
   const d=document.createElement('details');
   d.className='aur-tool-drawer';
   const summary=document.createElement('summary');summary.textContent=label;
   d.append(summary);
   for(const id of items)move(id,d);
   if(d.children.length>1)panel.append(d);
   return d;
 };
 const intro=(panel,title,help)=>{
   const div=document.createElement('div');div.className='aur-area-heading';
   const h=document.createElement('h2');h.textContent=title;
   const p=document.createElement('p');p.textContent=help;
   div.append(h,p);panel.append(div);
 };
 try{
   for(const key of keys){
     const panel=document.createElement('section');panel.id='aur-panel-'+key;
     panel.className='aur-panel';panel.dataset.aurPanel=key;
     panel.setAttribute('role','tabpanel');
     panel.setAttribute('aria-labelledby','aur-tab-'+key);
     tabs.parentNode.insertBefore(panel,rfq);
     panels[key]=panel;
   }
   // The single original RFQ; no supplier-campaign "versions" on the landing.
   intro(panels.rfq,'The RFQ','The original buyer document and its original item list. All other work remains attached to this case.');
   move(rfq,panels.rfq);
   const caseCard=get('destination-screen')?.closest('.card');
   move('original-rfq-equipment',panels.rfq);
   drawer(panels.rfq,'Country checks and commercial information',[caseCard]);
   drawer(panels.rfq,'Open another saved RFQ or import a new one',['saved-cases','aur-upload']);

   // Existing line->vendor research is the source of truth; show per-item matches.
   intro(panels.sources,'Potential suppliers by item','Open any buyer item to see its documented potential sources, contact details and links to original evidence.');
   const itemSource=move('item-sources',panels.sources);
   if(itemSource)itemSource.open=true;
   drawer(panels.sources,'Search more sources for this RFQ',['supplier-search-controls']);
   const selectSuppliers=get('supplier-dashboard');
   // Selecting suppliers prepares draft email records but does not send.
   drawer(panels.drafts,'Prepare additional supplier emails',[selectSuppliers]);
   drawer(panels.sources,'Additional supplier intelligence and saved evidence',['supplier-workspace']);

   // Sent records and replies stay tied to the existing signed mail ledgers.
   intro(panels.mail,'Sent and received emails','The RFQ correspondence ledger and the live Hostinger inbox and sent folder.');
   move('supplier-replies',panels.mail);
   move('aur-mail-center',panels.mail);
   if(!get('supplier-replies')&&!get('aur-mail-center')){
     const p=document.createElement('p');p.textContent='No correspondence recorded yet.';
     panels.mail.append(p);
   }

   intro(panels.drafts,'Prepared emails — review and send','Review the recipient, message and attachments. Use the original authorization controls to send only approved emails.');
   // A saved current batch is the review/action surface. Historical campaigns
   // remain available in More, not as separate buyer RFQs.
   const approved=get('aur-rfq-approval');
   const recentIds=['aur-nov11-reply-followups','aur-nov11-source-round',
     'aur-next-sourcing-round','aur-latest-supplier-rfq',
     'aur-nov05-researched-rfq','aur-next-stock-rfq'];
   const current=recentIds.map(get).find(Boolean);
   if(current)move(current,panels.drafts);
   move(approved,panels.drafts);
   if(!current&&!approved){
     const p=document.createElement('p');
     p.textContent='No prepared emails in the selected case. Open Prepare additional supplier emails above to create drafts for review.';
     panels.drafts.append(p);
   }

   intro(panels.more,'History and other tools','System diagnostics, earlier supplier campaigns, administration and research history.');
   drawer(panels.more,'Original step-by-step status',['aur-workflow']);
   const oldHistory=get('aur-legacy-functions'),diagnostics=get('aur-diagnostics-fallback');
   if(oldHistory){oldHistory.open=false;move(oldHistory,panels.more);}
   if(diagnostics){diagnostics.open=false;move(diagnostics,panels.more);}
   for(const id of ['aur-commercial-intelligence','aur-automation-health','aur-deepfetcher',
     'aur-wp09-verification','aur-deepfetcher-inbox','aur-brand-brochure','supplier-history-counts']){
     const node=get(id);
     if(node&&!node.closest('#aur-panel-more'))
       drawer(panels.more,'Additional '+id.replace(/^aur-/,'').replaceAll('-',' '),[node]);
   }
   const setting=get('aur-mail-settings')?.closest('.card');
   if(setting&&!setting.closest('#aur-panel-more'))drawer(panels.more,'Mail account settings',[setting]);
   const active=(key,push=false)=>{
     if(!panels[key])key='rfq';
     for(const name of keys){
       panels[name].classList.toggle('is-active',name===key);
       const btn=tabs.querySelector('[data-aur-tab="'+name+'"]');
       if(btn){btn.setAttribute('aria-selected',String(name===key));btn.tabIndex=name===key?0:-1;}
     }
     if(push){
       history.replaceState(null,'',location.pathname+location.search+'#tab-'+key);
       tabs.scrollIntoView({behavior:'smooth',block:'start'});
     }
   };
   // Legacy deep links remain routable after consolidation.
   const aliases={overview:'rfq',enquiry:'rfq',suppliers:'sources',
       intelligence:'sources',settings:'more',messages:'mail'};
   const openHash=()=>{
     const hash=decodeURIComponent(location.hash.slice(1));
     const requested=hash.startsWith('tab-')?hash.slice(4):'';
     if(requested){active(aliases[requested]||requested);return;}
     const target=hash?get(hash):null;
     const panel=target?.closest('[data-aur-panel]');
     active(panel?.dataset.aurPanel||'rfq');
     if(target&&panel){
       let d=target.closest('details');
       while(d){d.open=true;d=d.parentElement?.closest('details')||null;}
       requestAnimationFrame(()=>target.scrollIntoView({block:'start'}));
     }
   };
   tabs.addEventListener('click',e=>{
     const b=e.target.closest('button[data-aur-tab]');
     if(b)active(b.dataset.aurTab,true);
   });
   tabs.addEventListener('keydown',e=>{
     if(!['ArrowLeft','ArrowRight'].includes(e.key))return;
     const current=keys.findIndex(k=>tabs.querySelector('[data-aur-tab="'+k+'"]')?.getAttribute('aria-selected')==='true');
     const next=(current+(e.key==='ArrowRight'?1:-1)+keys.length)%keys.length;
     e.preventDefault();active(keys[next],true);
     tabs.querySelector('[data-aur-tab="'+keys[next]+'"]')?.focus();
   });
   document.addEventListener('click',e=>{
     const link=e.target.closest('a[href^="#"]');
     if(!link)return;
     const id=decodeURIComponent(link.hash.slice(1));
     const target=get(id),panel=target?.closest('[data-aur-panel]');
     if(!panel)return;
     active(panel.dataset.aurPanel);
     let d=target.closest('details');
     while(d){d.open=true;d=d.parentElement?.closest('details')||null;}
     requestAnimationFrame(()=>target.scrollIntoView({block:'start',behavior:'smooth'}));
   });
   document.documentElement.classList.add('aur-tabs-enabled');
   window.addEventListener('hashchange',openHash);
   openHash();
 }catch(error){
   // Original forms and immutable evidence remain accessible if the optional
   // layout cannot initialize; no records are dropped or moved to a new store.
   console.error('AURENOEVA workbench presentation unavailable',error);
   document.documentElement.classList.remove('aur-tabs-enabled');
   for(const id of ['aur-legacy-functions','aur-diagnostics-fallback']){
     const d=get(id);if(d)d.open=true;
   }
 }
});
})();