/* Private commercial intelligence — read-only measures and staff evidence register. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const panel=document.getElementById('aur-commercial-intelligence');
    if(!panel)return;
    const $=id=>document.getElementById(id);
    const fmt=n=>n===null||n===undefined?'—':new Intl.NumberFormat('en-GB').format(n);
    const pct=(n,d)=>d>0?(n*100/d).toFixed(1)+'%':'—';
    const day=d=>{const t=Date.parse(d);return Number.isFinite(t)?new Date(t).toLocaleDateString('en-GB',{day:'numeric',month:'short'}):'—';};
    const when=d=>d?new Date(d).toLocaleString('en-GB',{dateStyle:'medium',timeStyle:'short',timeZone:'UTC'})+' UTC':'Not yet available';
    const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
    let report=null,loaded=false,busy=false;
    function populate(){
      if(!report)return;
      const r=report,c=r.catalogue,f=r.funnel,s=r.suppliers;
      $('ci-asof').textContent='Calculated '+when(r.as_of)+' · Private Hostinger records';
      $('ci-products').textContent=fmt(c.products);
      $('ci-price').textContent=fmt(c.priced_products);
      $('ci-price-pct').textContent=pct(c.priced_products,c.products)+' of searchable products';
      $('ci-fresh').textContent=fmt(c.fresh_30d);
      $('ci-fresh-pct').textContent=pct(c.fresh_30d,c.products)+' last seen within 30 days';
      $('ci-enquiries').textContent=fmt(f.enquiries);
      $('ci-contactable').textContent=fmt(s.contactable);
      $('ci-transactions').textContent=fmt(f.verified_purchases);
      $('ci-no-sale').textContent=fmt(f.verified_no_purchase)+' confirmed without purchase';
      $('ci-metadata').textContent='Catalogue synchronized: '+when(c.snapshot_updated_at)+
        ' · Newest product evidence: '+when(c.latest_product_seen_at)+
        ' · '+fmt(c.classified_products)+' technically classified · '+fmt(c.multi_industry)+' cross-industry';
      $('ci-catalogue-warning').hidden=!!c.available;
      $('ci-history-note').textContent=(c.growth_history.length<2)?
        'Historical trend becomes available after at least two dated successful catalogue publications. Existing older counts cannot be reconstructed.':
        'Daily closing product identity counts, recorded after successful publication; not stock.';
      drawBars($('ci-growth'),c.growth_history.map(x=>({label:day(x.date),value:x.count})),true);
      const kinds=$('ci-industry-filter').value;
      const table=$('ci-industries');table.replaceChildren();
      r.industries.filter(i=>kinds==='all'||i.group===kinds).forEach(i=>{
        const tr=el('tr');
        const title=el('td');title.appendChild(el('strong',i.name));
        title.appendChild(el('small',i.group==='established'?'Established industry':'Additional discovery industry','ci-industry-type'));
        tr.appendChild(title);
        const primary=el('td',fmt(i.primary_count));
        primary.className='ci-number';tr.appendChild(primary);
        const classified=el('td',fmt(i.classified_count));
        classified.className='ci-number';tr.appendChild(classified);
        const state=el('td',i.group==='discovery'&&i.classified_count===null?
          'Discovery live · classification pending':i.classified_count===null?
          'No evidenced technical match':'Evidence-backed technical facets');
        state.className='ci-category-status';tr.appendChild(state);table.appendChild(tr);
      });
      const funnels=[
        ['Customer enquiries',f.enquiries],
        ['Supplier RFQs prepared',f.draft_prepared],
        ['Enquiries with supplier contact',f.supplier_contacted],
        ['Enquiries with supplier reply',f.supplier_replied],
        ['Verified purchases',f.verified_purchases]
      ];
      const funnel=$('ci-funnel');funnel.replaceChildren();
      funnels.forEach(([name,n],index)=>{
        const row=el('div',undefined,'ci-funnel-row');
        const heading=el('div',undefined,'ci-funnel-heading');
        heading.append(el('strong',name),el('span',fmt(n)+(index?' · '+pct(n,f.enquiries)+' of enquiries':'')));
        const bar=el('div',undefined,'ci-bar');const fill=el('span');
        fill.style.width=(f.enquiries?Math.min(100,100*n/f.enquiries):0)+'%';
        bar.appendChild(fill);row.append(heading,bar);funnel.appendChild(row);
      });
      $('ci-supplier-summary').textContent=fmt(s.discovered)+' supplier websites discovered · '+
        fmt(s.contactable)+' with published email · '+fmt(s.rfq_recipients)+
        ' distinct suppliers contacted · '+fmt(s.responded)+' with structured responses';
      $('ci-response-rate').textContent=pct(f.responded_supplier_pairs,f.sent_supplier_pairs);
      $('ci-response-note').textContent=fmt(f.responded_supplier_pairs)+' responding supplier–enquiry pairs / '+
        fmt(f.sent_supplier_pairs)+' SMTP-accepted supplier–enquiry pairs. Multiple parts and drafts do not inflate the denominator.';
      $('ci-case-cap').hidden=!f.coverage_limited;
      const current=$('ci-case-ref').value;
      $('ci-case-ref').replaceChildren(el('option','Select an enquiry'));
      $('ci-case-ref').firstChild.value='';
      r.outcome_case_options.forEach(id=>{const o=el('option',id);o.value=id;$('ci-case-ref').appendChild(o);});
      if([...$('ci-case-ref').options].some(o=>o.value===current))$('ci-case-ref').value=current;
      drawActivity();
    }
    function drawBars(container,entries,absolute){
      container.replaceChildren();
      if(!entries.length){container.appendChild(el('p','No historical observations available yet.','ci-empty'));return;}
      const sample=entries.slice(-30),max=Math.max(1,...sample.map(e=>Number(e.value)||0));
      const min=absolute?Math.min(...sample.map(e=>Number(e.value)||0)):0;
      const chart=el('div',undefined,'ci-bars');
      sample.forEach(e=>{
        const item=el('div',undefined,'ci-bar-item');item.title=e.label+' · '+fmt(e.value);
        const track=el('div',undefined,'ci-bar-track'),b=el('div',undefined,'ci-bar-fill');
        b.style.height=(absolute?Math.max(8,100*(Number(e.value)-min)/(max-min||1)):Math.max(2,100*Number(e.value)/max))+'%';
        track.appendChild(b);item.append(track,el('small',e.label));chart.appendChild(item);
      });
      container.appendChild(chart);
      container.appendChild(el('p','Latest: '+fmt(sample[sample.length-1].value)+' · '+sample[sample.length-1].label,'ci-chart-foot'));
    }
    function drawActivity(){
      if(!report)return;
      const days=Number($('ci-period').value)||30;
      const since=new Date();since.setUTCHours(0,0,0,0);since.setUTCDate(since.getUTCDate()-(days-1));
      const rows=report.activity.days.filter(r=>new Date(r.date+'T00:00:00Z')>=since);
      const searches=rows.reduce((n,x)=>n+x.search,0),adds=rows.reduce((n,x)=>n+x.basket_add,0);
      $('ci-searches').textContent=fmt(searches);
      $('ci-baskets').textContent=fmt(adds);
      $('ci-activity-scope').textContent='Recorded actions · last '+days+' calendar days (UTC). Event tracking begins with this release; prior behaviour cannot be inferred.';
      drawBars($('ci-activity-chart'),rows.map(x=>({label:day(x.date),value:x.search})),false);
    }
    async function refresh(){
      if(busy)return;
      busy=true;$('ci-refresh').disabled=true;
      $('ci-status').textContent='Reading current catalogue and saved procurement evidence…';
      try{
        const response=await fetch('/procurement/?intelligence=1',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
        if(!response.ok)throw Error('Data unavailable · HTTP '+response.status);
        const body=await response.json();if(body.ok!==true)throw Error('Data not available');
        report=body;loaded=true;populate();
        $('ci-status').textContent='Current private data loaded.';
      }catch(err){$('ci-status').textContent='Commercial intelligence could not be refreshed. Existing enquiries and quotations are unaffected.';}
      finally{busy=false;$('ci-refresh').disabled=false;}
    }
    $('ci-refresh').addEventListener('click',refresh);
    $('ci-period').addEventListener('change',drawActivity);
    $('ci-industry-filter').addEventListener('change',populate);
    document.getElementById('aur-tabs')?.addEventListener('click',ev=>{
      if(ev.target.closest('[data-aur-tab="intelligence"]'))refresh();
    });
    window.addEventListener('hashchange',()=>{if(location.hash==='#tab-intelligence'&&!loaded)refresh();});
    if(location.hash==='#tab-intelligence')refresh();
  });
})();
