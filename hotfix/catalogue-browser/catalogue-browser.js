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
    const chips=wrap.querySelector('#aur-browse-categories');
    const list=wrap.querySelector('#aur-browse-products');
    const filter=wrap.querySelector('#aur-browse-filter');
    const status=wrap.querySelector('#aur-browse-status');
    const more=wrap.querySelector('#aur-browse-more');
    const total=wrap.querySelector('#aur-browse-total');
    const tally=wrap.querySelector('#aur-browse-result-count');
    let selected=category.value || '__all';
    let page=0, current=null, seq=0, timer=null;
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
        const option=[...category.options].find(x=>x.value===name);
        if(option) {
          category.value=name;
          category.dispatchEvent(new Event('change',{bubbles:true}));
        } else {
          load(0);
        }
      }));
    }
    function drawParts(data,append) {
      const results=(Array.isArray(data.items)?data.items:[]);
      const html=results.map((p,i)=>
        '<article class="aur-browse-part"><div class="aur-browse-part-copy">'+
        '<b>'+esc([p.manufacturer,p.model].filter(Boolean).join(' · '))+'</b>'+
        '<span>'+esc(p.description||categoryText(p.category||'__other'))+'</span></div>'+
        '<button type="button" class="aur-browse-add" data-index="'+i+'">'+
        esc(label('+ Add to enquiry','+ Zur Anfrage hinzufügen'))+'</button></article>'
      ).join('');
      if(append)list.insertAdjacentHTML('beforeend',html);else list.innerHTML=html;
      const cards=append?[...list.children].slice(-results.length):[...list.children];
      cards.forEach((card,i)=>{
        card.querySelector('button')?.addEventListener('click',event=>{
          const part=results[i];
          if(!part)return;
          document.dispatchEvent(new CustomEvent('aur:add-catalogue-part',{detail:{part}}));
          event.currentTarget.textContent=label('✓ Added','✓ Übernommen');
          event.currentTarget.disabled=true;
        });
      });
      more.hidden=!data.has_more;
      tally.textContent=label(format(data.total)+' matching parts',format(data.total)+' passende Teile');
      if(!data.total)status.textContent=filter.value.trim()
        ?label('No matches. Try another model or continue with your description.',
          'Keine Treffer. Suchen Sie nach einem anderen Modell oder beschreiben Sie Ihren Bedarf.')
        :label('No parts indexed in this category yet. Try All categories or describe your requirement.',
          'Für diese Kategorie sind noch keine Teile erfasst. Wählen Sie Alle Kategorien oder beschreiben Sie Ihren Bedarf.');
      else status.textContent='';
    }
    async function load(nextPage=0) {
      const ticket=++seq;
      const append=nextPage>0;
      if(!append){list.innerHTML='';more.hidden=true}
      page=nextPage;
      status.textContent=label('Loading catalogue…','Katalog wird geladen …');
      status.dataset.state='loading';
      const url=new URL('catalogue-browse.php',location.href);
      url.searchParams.set('sector',sector.value);
      url.searchParams.set('category',selected);
      url.searchParams.set('page',String(nextPage));
      if(filter.value.trim())url.searchParams.set('q',filter.value.trim());
      try {
        const res=await fetch(url.toString(),{cache:'no-store',headers:{Accept:'application/json'}});
        const data=await res.json();
        if(ticket!==seq)return;
        if(!res.ok || data.ok!==true || !Array.isArray(data.categories) || !Array.isArray(data.items))
          throw new Error('catalogue_unavailable');
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
    sector.addEventListener('change',()=>{
      // The established wizard fills the category dropdown first.
      selected=category.value||'__all';
      filter.value='';
      load(0);
    });
    category.addEventListener('change',()=>{
      selected=category.value||'__all';
      filter.value='';
      load(0);
    });
    filter.addEventListener('input',()=>{
      clearTimeout(timer);
      timer=setTimeout(()=>load(0),320);
    });
    more.addEventListener('click',()=>{if(current?.has_more)load(page+1)});
    document.addEventListener('aur:lang',()=>{
      text();
      if(current){drawCategories(current);tally.textContent=label(format(current.total)+' matching parts',format(current.total)+' passende Teile')}
      // Update part labels without losing selections or triggering another API call.
      list.querySelectorAll('.aur-browse-add:not(:disabled)').forEach(b=>b.textContent=label('+ Add to enquiry','+ Zur Anfrage hinzufügen'));
    });
    text();
    load(0);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
  else start();
})();
