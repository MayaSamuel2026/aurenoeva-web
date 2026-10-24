/* AURENOEVA — browsable indexed parts inside the existing four-step sourcing wizard.
   No duplicate catalogue or checkout. The existing wizard owns selected parts. */
(function () {
  'use strict';
  const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g,
    char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const label = (en,de) => document.documentElement.lang === 'de' ? de : en;
  const format = count => new Intl.NumberFormat(document.documentElement.lang === 'de' ? 'de-DE' : 'en-GB').format(count || 0);
  function start() {
    const wizard=document.querySelector('[data-wizard]');
    const category=wizard?.querySelector('#req-category');
    const sector=wizard?.querySelector('#req-industry');
    if(!category || !sector || document.getElementById('aur-browse')) return;
    const wrap=document.createElement('section');
    wrap.id='aur-browse';
    wrap.className='aur-browse';
    wrap.setAttribute('aria-label','Browse catalogued parts');
    wrap.innerHTML=
      '<div class="aur-browse-head"><div><span class="aur-browse-eyebrow" id="aur-browse-eyebrow"></span>'+
      '<h3 id="aur-browse-title"></h3><p id="aur-browse-desc"></p></div>'+
      '<div class="aur-browse-total" id="aur-browse-total" aria-live="polite"></div></div>'+
      '<div id="aur-browse-categories" class="aur-browse-categories" aria-label="Catalogue categories"></div>'+
      '<div class="aur-browse-bar"><label class="aur-browse-filter-label" for="aur-browse-filter"></label>'+
      '<input id="aur-browse-filter" type="search" maxlength="120" autocomplete="off"/>'+
      '<span id="aur-browse-result-count" aria-live="polite"></span></div>'+
      '<div id="aur-browse-status" class="aur-browse-status" aria-live="polite"></div>'+
      '<div id="aur-browse-products" class="aur-browse-products"></div>'+
      '<button class="aur-browse-more" id="aur-browse-more" type="button" hidden></button>'+
      '<p class="aur-browse-disclaimer" id="aur-browse-disclaimer"></p>';
    const field=category.closest('.field');
    field?.insertAdjacentElement('afterend',wrap);
    // Use the wizard's real enquiry tray, directly below the catalogue;
    // it is the same element used for validation and RFQ submission.
    const enquiryTray=wizard.querySelector('#req-selected-parts');
    if(enquiryTray)wrap.insertAdjacentElement('afterend',enquiryTray);
    const chips=wrap.querySelector('#aur-browse-categories');
    const list=wrap.querySelector('#aur-browse-products');
    const filter=wrap.querySelector('#aur-browse-filter');
    const status=wrap.querySelector('#aur-browse-status');
    const more=wrap.querySelector('#aur-browse-more');
    const total=wrap.querySelector('#aur-browse-total');
    const tally=wrap.querySelector('#aur-browse-result-count');
    let selected=category.value || '__all';
    let page=0, current=null, seq=0, timer=null, liveSnapshot='';
    // The sourcing wizard may apply URL/sector presets programmatically after
    // this widget starts. A programmatic select.value change fires no event.
    let observedScope=sector.value+'\u0000'+category.value;
    function text() {
      wrap.querySelector('#aur-browse-eyebrow').textContent=label('DISCOVER THE CATALOGUE','KATALOG ENTDECKEN');
      wrap.querySelector('#aur-browse-title').textContent=label('Browse the parts in this sector','Teile in diesem Bereich entdecken');
      wrap.querySelector('#aur-browse-desc').textContent=label('Explore indexed product models, then add relevant parts to your enquiry.','Durchsuchen Sie erfasste Produkte und übernehmen Sie passende Teile direkt in Ihre Anfrage.');
      wrap.querySelector('.aur-browse-filter-label').textContent=label('Filter by manufacturer or model','Nach Hersteller oder Modell filtern');
      filter.placeholder=label('e.g. Agilent, 1260, centrifuge…','z. B. Agilent, 1260, Zentrifuge …');
      wrap.querySelector('#aur-browse-disclaimer').textContent=label(
        'Catalogue entries are product identities, not confirmed supplier stock. We check availability and pricing once you enquire.',
        'Katalogeinträge beschreiben Produkte, keine bestätigten Lagerbestände. Verfügbarkeit und Preis klären wir auf Anfrage.');
      more.textContent=label('Show more parts ↓','Weitere Teile anzeigen ↓');
    }
    function categoryText(name) {
      if(name==='__all')return label('All categories','Alle Kategorien');
      if(name==='__other')return label('Not yet classified','Noch nicht zugeordnet');
      const item=[...category.options].find(x=>x.value===name);
      return item?.textContent || name;
    }
    function drawCategories(data) {
      const names=[{name:'__all',count:data.sector_total},...data.categories];
      chips.innerHTML=names.map(c=>
        '<button type="button" class="aur-browse-chip'+(selected===c.name?' active':'')+
        '" data-cat="'+esc(c.name)+'" aria-pressed="'+(selected===c.name)+'">'+
        '<span>'+esc(categoryText(c.name))+'</span><b>'+format(c.count)+'</b></button>'
      ).join('');
      chips.querySelectorAll('[data-cat]').forEach(button=>button.addEventListener('click',()=>{
        const name=button.dataset.cat;
        selected=name;
        filter.value='';
        // Keep the original enquiry's category selector in sync wherever possible.
        if(name==='__all') {
          category.value='';
          category.dispatchEvent(new Event('change',{bubbles:true}));
        } else {
          let option=[...category.options].find(x=>x.value===name);
          if(!option) {
            option=new Option(categoryText(name),name);
            category.add(option);
          }
          category.value=name;
          category.dispatchEvent(new Event('change',{bubbles:true}));
        }
      }));
    }
    function chosenIds() {
      try {
        const parts=JSON.parse(localStorage.getItem('aur_selected_parts_v1')||'[]');
        return new Set(Array.isArray(parts)?parts.map(p=>String(p.canonical_id||'').toLowerCase()):[]);
      }catch(_e){return new Set()}
    }
    function refreshButtons() {
      const chosen=chosenIds();
      list.querySelectorAll('.aur-browse-add').forEach(button=>{
        const added=chosen.has(String(button.dataset.partId||'').toLowerCase());
        button.disabled=added;
        button.textContent=added?label('✓ Added','✓ Übernommen'):label('+ Add to enquiry','+ Zur Anfrage hinzufügen');
      });
    }
    function drawParts(data,append) {
      const results=(Array.isArray(data.items)?data.items:[]);
      const html=results.map((p,i)=>
        '<article class="aur-browse-part"><div class="aur-browse-part-copy">'+
        '<b><span class="aur-browse-brand">'+esc(p.manufacturer||'')+' · </span>'+
        '<span class="aur-browse-model">'+esc(p.model||'')+'</span></b>'+
        '<span class="aur-browse-description">'+esc(p.description||categoryText(p.category||'__other'))+'</span></div>'+
        '<button type="button" class="aur-browse-add" data-part-id="'+esc(p.canonical_id||'')+'" data-index="'+i+'">'+
        esc(label('+ Add to enquiry','+ Zur Anfrage hinzufügen'))+'</button></article>'
      ).join('');
      if(append)list.insertAdjacentHTML('beforeend',html);else list.innerHTML=html;
      const cards=append?[...list.children].slice(-results.length):[...list.children];
      cards.forEach((card,i)=>{
        card.querySelector('button')?.addEventListener('click',event=>{
          const part=results[i];
          if(!part)return;
          // The wizard must acknowledge the transfer before showing success.
          // Previously this button said Added even when no RFQ part existed.
          const request={part,accepted:false};
          document.dispatchEvent(new CustomEvent('aur:add-catalogue-part',{detail:request}));
          if(!request.accepted){
            status.dataset.state='error';
            status.textContent=label('Unable to add this part to the enquiry. Please refresh and try again.',
              'Dieses Teil konnte nicht übernommen werden. Bitte aktualisieren Sie die Seite und versuchen Sie es erneut.');
            return;
          }
          status.dataset.state='';
          status.textContent='';
          event.currentTarget.textContent=label('✓ Added','✓ Übernommen');
          event.currentTarget.disabled=true;
        });
      });
      refreshButtons();
      more.hidden=!data.has_more;
      tally.textContent=label(format(data.total)+' matching parts',format(data.total)+' passende Teile');
      if(!data.total)status.textContent=filter.value.trim()
        ?label('No matches. Try another model or continue with your description.',
          'Keine Treffer. Suchen Sie nach einem anderen Modell oder beschreiben Sie Ihren Bedarf.')
        :label('No parts indexed in this category yet. Try All categories or describe your requirement.',
          'Für diese Kategorie sind noch keine Teile erfasst. Wählen Sie Alle Kategorien oder beschreiben Sie Ihren Bedarf.');
      else status.textContent='';
    }
    function syncScope() {
      const scope=sector.value+'\u0000'+category.value;
      if(scope===observedScope)return;
      observedScope=scope;
      selected=category.value||'__all';
      filter.value='';
      load(0);
    }
    async function load(nextPage=0) {
      const ticket=++seq;
      const requestedSector=sector.value;
      const requestedCategory=selected;
      const append=nextPage>0;
      if(!append){list.innerHTML='';more.hidden=true}
      page=nextPage;
      status.textContent=label('Loading catalogue…','Katalog wird geladen …');
      status.dataset.state='loading';
      const url=new URL('catalogue-browse.php',location.href);
      url.searchParams.set('sector',requestedSector);
      url.searchParams.set('category',requestedCategory);
      url.searchParams.set('page',String(nextPage));
      if(filter.value.trim())url.searchParams.set('q',filter.value.trim());
      try {
        const res=await fetch(url.toString(),{cache:'no-store',headers:{Accept:'application/json'}});
        const data=await res.json();
        if(ticket!==seq)return;
        // Never paint stale laboratory results underneath a new sector label.
        if(requestedSector!==sector.value || requestedCategory!==(category.value||'__all')) {
          syncScope();return;
        }
        if(!res.ok || data.ok!==true || !Array.isArray(data.categories) || !Array.isArray(data.items))
          throw new Error('catalogue_unavailable');
        if(data.sector!==requestedSector || (data.category||'__all')!==requestedCategory)
          throw new Error('catalogue_scope_mismatch');
        current=data;
        total.innerHTML='<strong>'+format(data.sector_total)+'</strong><small>'+
          esc(label('indexed products','erfasste Produkte'))+'</small>';
        drawCategories(data);
        drawParts(data,append);
        status.dataset.state='';
      }catch(e) {
        if(ticket!==seq)return;
        more.hidden=true;
        status.dataset.state='error';
        status.textContent=label('Catalogue browsing is temporarily unavailable. You can still describe your part or use the Search catalogue button.',
          'Die Katalogübersicht ist vorübergehend nicht erreichbar. Sie können Ihr Teil weiterhin beschreiben oder die Katalogsuche nutzen.');
      }
    }
    sector.addEventListener('change',syncScope);
    category.addEventListener('change',syncScope);
    filter.addEventListener('input',()=>{
      clearTimeout(timer);
      timer=setTimeout(()=>load(0),320);
    });
    more.addEventListener('click',()=>{if(current?.has_more)load(page+1)});
    document.addEventListener('aur:selection-changed',refreshButtons);
    document.addEventListener('aur:lang',()=>{
      text();
      if(current){drawCategories(current);tally.textContent=label(format(current.total)+' matching parts',format(current.total)+' passende Teile')}
      // Update part labels without losing selections or triggering another API call.
      refreshButtons();
    });
    text();
    load(0);
    // Cheap consistency check catches wizard preset changes without events.
    // Polling does not fetch unless the sector/category actually changed.
    const scopeWatch=setInterval(()=>{
      if(!wrap.isConnected){clearInterval(scopeWatch);return;}
      syncScope();
    },350);
    // The VPS now publishes from SQLite automatically. A user who keeps
    // sourcing open also gets the updated snapshot without a page reload.
    // Never interrupt typing, paging, or an open selector.
    async function checkPublishedSnapshot(){
      if(!wrap.isConnected || document.visibilityState==='hidden' ||
         filter===document.activeElement || !current)return;
      try{
        const response=await fetch('catalogue-count.php?refresh='+Date.now(),
          {cache:'no-store',headers:{Accept:'application/json'}});
        if(!response.ok)return;
        const update=await response.json();
        if(update.ok!==true || !update.snapshot_updated_at_utc)return;
        if(!liveSnapshot){liveSnapshot=update.snapshot_updated_at_utc;return;}
        if(liveSnapshot!==update.snapshot_updated_at_utc){
          liveSnapshot=update.snapshot_updated_at_utc;
          // The newest catalogue is loaded without touching the wizard's
          // chosen manufacturer, product IDs or RFQ selections.
          if(page===0)load(0);
          else status.textContent=label(
            'New catalogue products are available. The next filter or category selection refreshes results.',
            'Neue Katalogteile sind verfügbar. Die nächste Filter- oder Kategorieauswahl aktualisiert die Liste.');
        }
      }catch(_e){/* background freshness is best-effort; normal browsing continues */}
    }
    const snapshotWatch=setInterval(()=>{
      if(!wrap.isConnected){clearInterval(snapshotWatch);return;}
      void checkPublishedSnapshot();
    },120000);
    void checkPublishedSnapshot();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
  else start();
})();
