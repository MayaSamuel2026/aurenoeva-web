/* AURENOEVA buyer journey: one search, one basket, one guest RFQ.
   Reuses catalogue-search.php and requirement-submit.php; no new customer database. */
(function(){
'use strict';
function begin(){
  var home=document.querySelector('section.hero .hero-grid > div:first-child');
  var req=document.querySelector('main .form-shell');
  if(!home&&!req)return;
  var isHome=!!home;
  var host=isHome?home:req;
  if(document.getElementById('aur-fast'))return;
  var root=document.createElement('section');
  root.id='aur-fast';root.className='aur-fast';
  root.setAttribute('aria-label','Search parts and request a quotation');
  root.innerHTML='<div class="aur-fast-search"><label for="aur-fast-query" data-aur-text="searchLabel"></label>'+
    '<div class="aur-fast-inputrow"><span class="aur-fast-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10.7" cy="10.7" r="6.7"/><path d="m16 16 5 5"/></svg></span>'+
    '<input id="aur-fast-query" type="search" autocomplete="off" spellcheck="false" maxlength="180" aria-controls="aur-fast-results" aria-autocomplete="list">'+
    '<button class="aur-fast-searchbtn" type="button" data-aur-action="search"><span data-aur-text="searchButton"></span><span aria-hidden="true">→</span></button></div>'+
    '<p class="aur-fast-sub" data-aur-text="intro"></p></div>'+
    '<div id="aur-fast-feedback" class="aur-fast-feedback" role="status" aria-live="polite"></div>'+
    '<div id="aur-fast-results" class="aur-fast-results" aria-live="polite"></div>'+
    '<div class="aur-fast-lower"><button class="aur-fast-free" type="button" data-aur-action="free"><span data-aur-text="noPart"></span> <span aria-hidden="true">↗</span></button>'+
    '<button class="aur-fast-cartbutton" type="button" data-aur-action="basket" hidden><span data-aur-text="basket"></span> <b id="aur-fast-count">0</b> <span aria-hidden="true">→</span></button></div>';
  if(isHome){
    var headline=home.querySelector('h1');
    headline.insertAdjacentElement('afterend',root);
    document.body.classList.add('aur-fast-home');
    var old=document.querySelector('section.procurement#procurement');
    var hero=document.querySelector('section.hero');
    if(old)old.id='browse-sectors';
    if(hero)hero.id='procurement';
    var stage=document.querySelector('#sectors .stage-title');
    if(stage){stage.dataset.en='Explore by industry (optional)';stage.dataset.de='Branchen entdecken (optional)';}
    var help=document.querySelector('#sectors .stage-help');
    if(help){help.dataset.en='Search above for the fastest route, or browse the industries and illustrated categories below.';help.dataset.de='Am schnellsten geht es über die Suche oben. Alternativ können Sie Branchen und Kategorien durchstöbern.';}
    var intro=document.querySelector('.sector-feature-intro h3');
    if(intro){intro.dataset.en='Explore technical industries';intro.dataset.de='Technische Branchen entdecken';}
  }else{
    host.insertBefore(root,host.firstChild);
    document.body.classList.add('aur-fast-requirement');
    var heading=document.querySelector('.page-hero h1');
    if(heading){heading.dataset.en='Find your part. Request a quotation.';heading.dataset.de='Teil finden. Kostenloses Angebot anfragen.';}
    var kicker=document.querySelector('.page-hero .eyebrow');
    if(kicker){kicker.dataset.en='One search · One free enquiry';kicker.dataset.de='Eine Suche · Eine kostenlose Anfrage';}
    var blurb=document.querySelector('.page-hero p');
    if(blurb){blurb.dataset.en='Enter a part number, manufacturer or description. Choose a match or request sourcing without registering.';blurb.dataset.de='Teilenummer, Hersteller oder Beschreibung eingeben. Treffer auswählen oder ohne Registrierung anfragen.';}
  }
  var backdrop=document.createElement('div');
  backdrop.className='aur-fast-backdrop';backdrop.hidden=true;
  backdrop.innerHTML='<div class="aur-fast-drawer" role="dialog" aria-modal="true" aria-labelledby="aur-fast-drawertitle">'+
    '<div class="aur-fast-drawerhead"><div><span class="aur-fast-eyebrow" data-aur-text="freeBadge"></span><h2 id="aur-fast-drawertitle" data-aur-text="drawerTitle"></h2></div>'+
    '<button class="aur-fast-close" type="button" data-aur-action="close" aria-label="Close">×</button></div>'+
    '<form id="aur-fast-form"><div id="aur-fast-selected" class="aur-fast-selected"></div>'+
    '<button type="button" class="aur-fast-another" data-aur-action="another"><span data-aur-text="addMore"></span> +</button>'+
    '<div class="aur-fast-fields"><label><span data-aur-text="email"></span><input name="email" type="email" autocomplete="email" maxlength="190" required placeholder="name@company.com"></label>'+
    '<label><span data-aur-text="company"></span><input name="company" type="text" autocomplete="organization" maxlength="180" required></label>'+
    '<label><span data-aur-text="name"></span><input name="name" type="text" autocomplete="name" maxlength="120" required></label></div>'+
    '<details class="aur-fast-details"><summary data-aur-text="optional"></summary><div class="aur-fast-extras">'+
    '<label><span data-aur-text="delivery"></span><input name="delivery_location" maxlength="180"></label>'+
    '<label><span data-aur-text="condition"></span><select name="condition"><option value="any" data-aur-text="anyCondition"></option><option value="new" data-aur-text="newCondition"></option><option value="refurbished" data-aur-text="refurbCondition"></option><option value="used" data-aur-text="usedCondition"></option></select></label>'+
    '<label><span data-aur-text="notes"></span><textarea name="requirement" maxlength="2500" rows="3"></textarea></label></div></details>'+
    '<p class="aur-fast-privacy" data-aur-text="privacy"></p>'+
    '<p id="aur-fast-sendstatus" class="aur-fast-sendstatus" role="status" aria-live="polite"></p>'+
    '<button type="submit" class="aur-fast-send"><span data-aur-text="send"></span><span aria-hidden="true">→</span></button></form></div>';
  document.body.appendChild(backdrop);
  var input=root.querySelector('#aur-fast-query'),results=root.querySelector('#aur-fast-results'),
      feedback=root.querySelector('#aur-fast-feedback'),cartbutton=root.querySelector('.aur-fast-cartbutton'),
      cartcount=root.querySelector('#aur-fast-count'),form=backdrop.querySelector('form'),
      selected=backdrop.querySelector('#aur-fast-selected'),sendstatus=backdrop.querySelector('#aur-fast-sendstatus');
  var found=[],items=[],freeText='',timer=null,seq=0,requestInProgress=false,lastFocus=null;
  var strings={
   searchLabel:['Find industrial and technical parts','Industrie- und technische Ersatzteile finden'],
   searchButton:['Search','Suchen'],
   intro:['Search any part number, manufacturer or description. No account needed.','Teilenummer, Hersteller oder Beschreibung eingeben. Ohne Registrierung.'],
   loading:['Searching our catalogue…','Katalog wird durchsucht …'],
   searchHint:['Type at least 2 characters to search.','Mindestens 2 Zeichen eingeben.'],
   noMatch:['No exact result? Send us your description and we will source it.','Kein eindeutiger Treffer? Wir suchen anhand Ihrer Beschreibung.'],
   searchError:['Catalogue search is temporarily unavailable. You can still send a sourcing request.','Katalogsuche vorübergehend nicht erreichbar. Sie können dennoch eine Anfrage senden.'],
   found:['Matching catalogue parts','Passende Katalogteile'],
   noPart:["Can't find your part? Request sourcing","Teil nicht gefunden? Beschaffung anfragen"],
   basket:['Your enquiry','Ihre Anfrage'],
   freeBadge:['FREE · NO REGISTRATION','KOSTENLOS · OHNE REGISTRIERUNG'],
   drawerTitle:['Request a quotation','Angebot anfragen'],
   addMore:['Add another part','Weiteres Teil hinzufügen'],
   email:['Business email *','Geschäftliche E-Mail *'],
   company:['Company *','Unternehmen *'],
   name:['Contact name *','Kontaktperson *'],
   optional:['Delivery, condition and other details (optional)','Lieferort, Zustand und weitere Angaben (optional)'],
   delivery:['Delivery country / location','Lieferland / Lieferort'],
   condition:['Preferred condition','Gewünschter Zustand'],
   anyCondition:['Any condition','Zustand offen'],
   newCondition:['New','Neu'],
   refurbCondition:['Refurbished acceptable','Überholt möglich'],
   usedCondition:['Used acceptable','Gebraucht möglich'],
   notes:['Additional specifications or urgency','Weitere Angaben oder Dringlichkeit'],
   privacy:['Free, non-binding B2B enquiry. We process your details to respond and contact relevant suppliers. See our Privacy Policy.','Kostenlose, unverbindliche B2B-Anfrage. Wir verarbeiten Ihre Angaben zur Bearbeitung und Lieferantenansprache. Siehe Datenschutzerklärung.'],
   send:['Send free enquiry','Kostenlose Anfrage senden'],
   sending:['Sending your enquiry…','Anfrage wird gesendet …'],
   submitted:['Enquiry sent. Your reference: ','Anfrage versendet. Ihre Referenz: '],
   sendError:['Your enquiry was not confirmed as sent. Please retry, or contact info@aurenoeva.com.','Der Versand wurde nicht bestätigt. Bitte erneut versuchen oder info@aurenoeva.com kontaktieren.'],
   select:['Request quote','Angebot anfragen'],
   added:['Added','Hinzugefügt'],
   price:['Price on request','Preis auf Anfrage'],
   publicPrice:['Public seller price','Öffentlicher Anbieterpreis'],
   qty:['Qty','Menge'],
   remove:['Remove','Entfernen'],
   freeItem:['Sourcing request','Beschaffungsanfrage'],
   description:['Your part number or description','Teilenummer oder Beschreibung'],
   empty:['Add a part or describe what you need first.','Bitte zuerst ein Teil auswählen oder Ihren Bedarf beschreiben.'],
   maxItems:['Maximum 20 products per enquiry.','Maximal 20 Produkte pro Anfrage.'],
   sentNotice:['We will use your enquiry reference when responding.','Wir verwenden Ihre Referenz bei der Rückmeldung.']
  };
  function de(){return document.documentElement.lang==='de';}
  function tx(k){return (strings[k]||[k,k])[de()?1:0];}
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function short(s,n){return String(s||'').trim().slice(0,n);}
  function identity(p){return short(p.canonical_id,60)||[short(p.manufacturer,100),short(p.model||p.part_number,160)].join('|');}
  function fmtPrice(p){
    var a=Array.isArray(p.public_offers)?p.public_offers:[];
    var o=a.find(function(x){return x&&Number(x.amount)>0&&/^[A-Z]{3}$/.test(x.currency||'')&&/^https:\/\//.test(x.source_url||'');});
    if(!o)return '<strong>'+esc(tx('price'))+'</strong>';
    var price=String(o.currency)+' '+Number(o.amount).toFixed(2);
    try{price=new Intl.NumberFormat(de()?'de-DE':'en-GB',{style:'currency',currency:o.currency}).format(Number(o.amount));}catch(e){}
    return '<span class="aur-fast-price-label">'+esc(tx('publicPrice'))+'</span> <strong>'+esc(price)+'</strong>'+
      '<a href="'+esc(o.source_url)+'" target="_blank" rel="noopener noreferrer">'+esc(short(o.seller_name||o.seller_website||'Source',100))+'</a>';
  }
  function renderResults(){
    var markup='';
    if(found.length){
      markup='<div class="aur-fast-resulttitle">'+esc(tx('found'))+'</div>';
      found.forEach(function(p,i){
        var exists=items.some(function(x){return x.id===identity(p);});
        markup+='<article class="aur-fast-result"><div class="aur-fast-resultcopy"><b>'+esc(p.manufacturer||'')+' · '+esc(p.model||p.part_number||'')+'</b>'+
          '<small>'+esc(short(p.description||p.category,180))+'</small><div class="aur-fast-price">'+fmtPrice(p)+'</div></div>'+
          '<button type="button" class="aur-fast-select" data-aur-pick="'+i+'">'+esc(tx(exists?'added':'select'))+' <span aria-hidden="true">→</span></button></article>';
      });
    }
    results.innerHTML=markup;
  }
  function status(s){feedback.textContent=s||'';}
  async function search(){
    var q=input.value.trim();seq+=1;var my=seq;
    if(q.length<2){found=[];renderResults();status(q?tx('searchHint'):'');return;}
    status(tx('loading'));
    try{
      var controller=new AbortController();
      var timeout=setTimeout(function(){controller.abort();},12000);
      var res;
      try{res=await fetch('catalogue-search.php?q='+encodeURIComponent(q),{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});}
      finally{clearTimeout(timeout);}
      var data=await res.json();
      if(my!==seq)return;
      if(!res.ok||!data.ok||data.available===false)throw Error('unavailable');
      found=(Array.isArray(data.items)?data.items:[]).filter(function(p){return p&&p.canonical_id&&p.manufacturer&&p.model;}).slice(0,8);
      renderResults();
      status(found.length?'':tx('noMatch'));
    }catch(e){
      if(my!==seq)return;
      found=[];renderResults();status(tx('searchError'));
    }
  }
  function schedule(){clearTimeout(timer);timer=setTimeout(search,350);}
  function renderBasket(){
    cartbutton.hidden=items.length===0&&!freeText;
    cartcount.textContent=String(items.length||(freeText?1:0));
    var html='';
    items.forEach(function(i,k){
      html+='<div class="aur-fast-line"><div class="aur-fast-linecopy"><b>'+esc(i.p.manufacturer)+' · '+esc(i.p.model||i.p.part_number)+'</b>'+
        '<span>'+esc(tx('price'))+'</span></div><label>'+esc(tx('qty'))+'<input type="number" min="1" max="999999" step="1" value="'+i.qty+'" data-aur-qty="'+k+'"></label>'+
        '<button type="button" class="aur-fast-remove" data-aur-remove="'+k+'" aria-label="'+esc(tx('remove'))+'">×</button></div>';
    });
    if(freeText)html+='<div class="aur-fast-freetext"><b>'+esc(tx('freeItem'))+'</b><label>'+esc(tx('description'))+
      '<textarea id="aur-fast-freevalue" maxlength="1000" rows="2">'+esc(freeText)+'</textarea></label></div>';
    selected.innerHTML=html;
  }
  function openDrawer(){if(!items.length&&!freeText){freeText=short(input.value,1000);}if(!items.length&&!freeText){status(tx('empty'));input.focus();return;}
    renderBasket();sendstatus.textContent='';lastFocus=document.activeElement;backdrop.hidden=false;document.body.classList.add('aur-fast-dialog-open');
    var focus= form.querySelector('[name="email"]');if(focus)focus.focus();
  }
  function closeDrawer(){if(requestInProgress)return;var f=selected.querySelector('#aur-fast-freevalue');if(f)freeText=short(f.value,1000);backdrop.hidden=true;document.body.classList.remove('aur-fast-dialog-open');if(lastFocus&&lastFocus.isConnected)lastFocus.focus();}
  function choose(i){var p=found[i];if(!p)return;var id=identity(p);if(!items.some(function(x){return x.id===id;})){if(items.length>=20){status(tx('maxItems'));return;}items.push({id:id,p:p,qty:1});}renderResults();openDrawer();}
  function i18n(){
    root.querySelectorAll('[data-aur-text]').forEach(function(x){x.textContent=tx(x.dataset.aurText);});
    backdrop.querySelectorAll('[data-aur-text]').forEach(function(x){x.textContent=tx(x.dataset.aurText);});
    input.placeholder=de()?'z. B. Siemens 6ES7, Festo, Dichtung, Laborgerät':'e.g. Siemens 6ES7, Festo, seal, laboratory equipment';
    root.querySelector('.aur-fast-cartbutton').hidden=items.length===0&&!freeText;
    renderResults();renderBasket();
  }
  root.addEventListener('click',function(e){
    var pick=e.target.closest('[data-aur-pick]');
    if(pick){choose(Number(pick.dataset.aurPick));return;}
    var action=e.target.closest('[data-aur-action]');if(!action)return;
    if(action.dataset.aurAction==='search'){clearTimeout(timer);search();}
    if(action.dataset.aurAction==='free'){freeText=short(input.value,1000);openDrawer();}
    if(action.dataset.aurAction==='basket')openDrawer();
  });
  backdrop.addEventListener('click',function(e){
    if(e.target===backdrop){closeDrawer();return;}
    var btn=e.target.closest('[data-aur-action]');if(btn){if(btn.dataset.aurAction==='close')closeDrawer();if(btn.dataset.aurAction==='another'){closeDrawer();input.focus();}return;}
    var remove=e.target.closest('[data-aur-remove]');if(remove){items.splice(Number(remove.dataset.aurRemove),1);renderResults();renderBasket();}
  });
  backdrop.addEventListener('change',function(e){
    if(e.target.matches('[data-aur-qty]')){var i=Number(e.target.dataset.aurQty);if(items[i])items[i].qty=Math.max(1,Math.min(999999,parseInt(e.target.value,10)||1));e.target.value=String(items[i]?items[i].qty:1);}
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!backdrop.hidden)closeDrawer();});
  input.addEventListener('input',schedule);
  input.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();clearTimeout(timer);search();}});
  form.addEventListener('submit',async function(e){
    e.preventDefault();if(requestInProgress)return;
    var free=selected.querySelector('#aur-fast-freevalue');if(free)freeText=short(free.value,1000);
    if(!items.length&&!freeText){sendstatus.textContent=tx('empty');return;}
    if(!form.reportValidity())return;
    var detailLines=items.map(function(i){return short(i.p.manufacturer,80)+' '+short(i.p.model||i.p.part_number,100)+' — '+i.qty+' pcs';});
    var note=short(form.elements.requirement.value,2500);
    var description=[freeText,detailLines.join('\n'),note].filter(Boolean).join('\n').slice(0,4800);
    var parts=items.map(function(i){var p=i.p;return {
      canonical_id:short(p.canonical_id,220),manufacturer:short(p.manufacturer,220),part_number:short(p.part_number,220),
      model:short(p.model,220),description:short(p.description,500),source_ref:short(p.source_ref,500),
      identifier_type:short(p.identifier_type||'MODEL',80),original_search_query:short(input.value,180)
    };});
    var payload=new URLSearchParams({
      lang:de()?'de':'en',mode:items.length?'exact':'requirement',industry:'',category:'',
      identifier:short(input.value,250),selected_parts:JSON.stringify(parts),
      requirement:description,condition:form.elements.condition.value,
      quantity:items.length===1?String(items[0].qty):(items.length?'See item quantities':'1'),
      delivery_location:short(form.elements.delivery_location.value,180),need_by:'',budget:'',evidence:'',
      company:short(form.elements.company.value,180),name:short(form.elements.name.value,120),
      email:short(form.elements.email.value,190),website:''
    });
    var button=form.querySelector('[type="submit"]');requestInProgress=true;button.disabled=true;sendstatus.textContent=tx('sending');
    try{
      var res=await fetch('requirement-submit.php',{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',Accept:'application/json'},body:payload.toString()});
      var data=await res.json();
      if(!res.ok||!data.ok||!data.delivery||data.delivery.email!==true)throw Error('delivery_not_confirmed');
      var reference=short(data.reference||data.requirement_contract_id,100);
      selected.innerHTML='<div class="aur-fast-success"><span aria-hidden="true">✓</span><strong>'+esc(tx('submitted'))+esc(reference)+'</strong><p>'+esc(tx('sentNotice'))+'</p></div>';
      form.querySelector('.aur-fast-fields').hidden=true;form.querySelector('.aur-fast-details').hidden=true;
      form.querySelector('.aur-fast-another').hidden=true;button.hidden=true;
      sendstatus.textContent='';items=[];freeText='';renderResults();cartbutton.hidden=true;
    }catch(e){sendstatus.textContent=tx('sendError');}
    finally{requestInProgress=false;button.disabled=false;}
  });
  var params=new URLSearchParams(location.search);
  var q=params.get('q')||params.get('identifier')||'';
  if(q){input.value=q.slice(0,180);schedule();}
  i18n();
  document.addEventListener('aur:lang',i18n);
  document.querySelectorAll('[data-lang]').forEach(function(el){el.addEventListener('click',function(){setTimeout(i18n,0);});});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',begin);else begin();
})();
