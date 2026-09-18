const translations={
 en:{navHow:'How it works',navIndustries:'Sectors',navTrust:'Evidence & methodology',navSuppliers:'For suppliers',navAbout:'About',navContact:'Contact',cta:'Start a requirement',brandSub:'PROCUREMENT · COMPONENTS · INTELLIGENCE',footerIntro:'Intelligent, evidence-led procurement for scarce, obsolete, refurbished and high-value technical components and equipment.',footerPlatform:'How Aurenoeva works',footerCompany:'Company',footerLegal:'Legal',privacy:'Privacy',terms:'Terms of use',accessibility:'Accessibility',imprint:'Imprint',cookies:'Cookies & storage',cookieSettings:'Cookie settings'},
 de:{navHow:'So funktioniert es',navIndustries:'Bereiche',navTrust:'Nachweise & Methodik',navSuppliers:'Für Lieferanten',navAbout:'Über uns',navContact:'Kontakt',cta:'Beschaffung starten',brandSub:'PROCUREMENT · COMPONENTS · INTELLIGENCE',footerIntro:'Intelligente, nachvollziehbare Beschaffung für schwer verfügbare, abgekündigte, überholte und hochwertige technische Komponenten und Anlagen.',footerPlatform:'So arbeitet Aurenoeva',footerCompany:'Unternehmen',footerLegal:'Rechtliches',privacy:'Datenschutz',terms:'Nutzungsbedingungen',accessibility:'Barrierefreiheit',imprint:'Impressum',cookies:'Cookies & Speicher',cookieSettings:'Cookie-Einstellungen'}
};
const pageLinks=[['how-it-works.html','navHow'],['industries.html','navIndustries'],['trust-methodology.html','navTrust'],['suppliers.html','navSuppliers'],['about.html','navAbout']];
const industries={
 laboratory:[
  {v:'Chromatography / HPLC / GC',en:'Chromatography / HPLC / GC',de:'Chromatographie / HPLC / GC'},
  {v:'Mass spectrometry',en:'Mass spectrometry',de:'Massenspektrometrie'},
  {v:'Spectroscopy',en:'Spectroscopy',de:'Spektroskopie'},
  {v:'Sample preparation',en:'Sample preparation',de:'Probenvorbereitung'},
  {v:'Balances & weighing',en:'Balances & weighing',de:'Waagen & Wägetechnik'},
  {v:'Incubation / ovens / environmental',en:'Incubation / ovens / environmental',de:'Inkubation / Öfen / Umwelttechnik'},
  {v:'General laboratory equipment',en:'General laboratory equipment',de:'Allgemeine Laborausstattung'}
 ],
 test:[
  {v:'Oscilloscopes',en:'Oscilloscopes',de:'Oszilloskope'},
  {v:'Spectrum / signal analyzers',en:'Spectrum / signal analyzers',de:'Spektrum- / Signalanalysatoren'},
  {v:'Signal sources / generators',en:'Signal sources / generators',de:'Signalquellen / Generatoren'},
  {v:'Meters & power analyzers',en:'Meters & power analyzers',de:'Messgeräte & Leistungsanalysatoren'},
  {v:'RF / microwave',en:'RF / microwave',de:'HF / Mikrowelle'},
  {v:'Calibration equipment',en:'Calibration equipment',de:'Kalibriertechnik'},
  {v:'Data acquisition',en:'Data acquisition',de:'Datenerfassung'}
 ],
 medical:[
  {v:'Diagnostic equipment',en:'Diagnostic equipment',de:'Diagnostikgeräte'},
  {v:'Imaging & visualization',en:'Imaging & visualization',de:'Bildgebung & Visualisierung'},
  {v:'Patient monitoring',en:'Patient monitoring',de:'Patientenmonitoring'},
  {v:'Laboratory / pathology',en:'Laboratory / pathology',de:'Labor / Pathologie'},
  {v:'Surgical / procedural',en:'Surgical / procedural',de:'Chirurgie / Eingriffsgeräte'},
  {v:'Sterilization & reprocessing',en:'Sterilization & reprocessing',de:'Sterilisation & Aufbereitung'},
  {v:'Other medical equipment',en:'Other medical equipment',de:'Weitere Medizintechnik'}
 ],
 industrial:[
  {v:'PLC / control modules',en:'PLC / control modules',de:'SPS / Steuerungsmodule'},
  {v:'Drives / servo / motion',en:'Drives / servo / motion',de:'Antriebe / Servo / Motion'},
  {v:'Motors / gearboxes',en:'Motors / gearboxes',de:'Motoren / Getriebe'},
  {v:'Sensors / instrumentation',en:'Sensors / instrumentation',de:'Sensorik / Instrumentierung'},
  {v:'Pneumatics / hydraulics',en:'Pneumatics / hydraulics',de:'Pneumatik / Hydraulik'},
  {v:'Robotics / automation',en:'Robotics / automation',de:'Robotik / Automation'},
  {v:'Machine / process equipment',en:'Machine / process equipment',de:'Maschinen- / Prozessequipment'}
 ],
 energy:[
  {v:'Transformers',en:'Transformers',de:'Transformatoren'},
  {v:'Switchgear & protection',en:'Switchgear & protection',de:'Schaltanlagen & Schutztechnik'},
  {v:'Generators',en:'Generators',de:'Generatoren'},
  {v:'Power conversion / inverters',en:'Power conversion / inverters',de:'Leistungsumwandlung / Wechselrichter'},
  {v:'Power quality / monitoring',en:'Power quality / monitoring',de:'Netzqualität / Monitoring'},
  {v:'Turbine / plant components',en:'Turbine / plant components',de:'Turbinen- / Anlagenkomponenten'},
  {v:'Storage / auxiliary systems',en:'Storage / auxiliary systems',de:'Speicher- / Hilfssysteme'}
 ],
 marine:[
  {v:'Engines & propulsion',en:'Engines & propulsion',de:'Motoren & Antrieb'},
  {v:'Pumps / fluid systems',en:'Pumps / fluid systems',de:'Pumpen / Fluidsysteme'},
  {v:'Electrical & switchgear',en:'Electrical & switchgear',de:'Elektrik & Schaltanlagen'},
  {v:'Navigation / instrumentation',en:'Navigation / instrumentation',de:'Navigation / Instrumentierung'},
  {v:'Deck machinery',en:'Deck machinery',de:'Deckmaschinen'},
  {v:'HVAC / refrigeration',en:'HVAC / refrigeration',de:'Klima / Kälte'},
  {v:'Safety / auxiliary systems',en:'Safety / auxiliary systems',de:'Sicherheits- / Hilfssysteme'}
 ],
 aviation:[
  {v:'Avionics',en:'Avionics',de:'Avionik'},
  {v:'Electrical components',en:'Electrical components',de:'Elektrische Komponenten'},
  {v:'Hydraulic / pneumatic',en:'Hydraulic / pneumatic',de:'Hydraulik / Pneumatik'},
  {v:'Engine / powerplant components',en:'Engine / powerplant components',de:'Triebwerkskomponenten'},
  {v:'Landing gear / actuation',en:'Landing gear / actuation',de:'Fahrwerk / Aktuatoren'},
  {v:'Ground support / test equipment',en:'Ground support / test equipment',de:'Boden- / Prüfequipment'},
  {v:'Traceable specialist components',en:'Traceable specialist components',de:'Rückverfolgbare Spezialkomponenten'}
 ]
};
const decisionData={
 en:[
  ['REQUIREMENT RESOLUTION','72% structured','Application, configuration, geography, budget and evidence needs are explicit. One material question remains open: software or licence transferability.','Requirement active'],
  ['DISCOVERY RESOLUTION','14 plausible candidates','The discovery layer has consolidated plausible supply from approved sources and supplier relationships before deeper qualification.','Discovery active'],
  ['EVIDENCE RESOLUTION','91% confidence','Identity, service history, configuration and documentary claims remain linked to their sources, including gaps and conflicts.','Evidence review'],
  ['RISK RESOLUTION','Low–moderate','Technical, evidence, supplier, fulfilment and commercial risks remain separate, so the trade-offs are easy to inspect.','Risk reviewed'],
  ['ECONOMICS RESOLUTION','€23,440 comparable cost','Asking price, shipping, inspection, remediation and known acquisition adjustments have been reconciled into a comparable picture.','Economics reconciled'],
  ['DECISION RESOLUTION','Proceed with condition','The strongest candidate is selected with explicit conditions — not simply because it has the lowest visible price.','Decision recorded']
 ],
 de:[
  ['BEDARFSAUFLÖSUNG','72 % strukturiert','Anwendung, Konfiguration, Lieferregion, Budget und Nachweisanforderungen sind klar. Eine wesentliche Frage ist noch offen: Übertragbarkeit von Software oder Lizenzen.','Bedarf aktiv'],
  ['LIEFERQUELLEN','14 plausible Kandidaten','Die Suche hat plausible Lieferquellen aus freigegebenen Quellen und bestehenden Lieferantenbeziehungen zusammengeführt, bevor die vertiefte Qualifizierung beginnt.','Lieferquellen geprüft'],
  ['NACHWEISAUFLÖSUNG','91 % Nachweisstärke','Identität, Servicehistorie, Konfiguration und dokumentierte Aussagen bleiben mit ihren Quellen verbunden – einschließlich Lücken und Widersprüchen.','Nachweise in Prüfung'],
  ['RISIKOAUFLÖSUNG','Niedrig–mittel','Technische, nachweisbezogene, lieferantenbezogene, logistische und kommerzielle Risiken bleiben getrennt und damit prüfbar.','Risiko bewertet'],
  ['KOSTENAUFLÖSUNG','23.440 € vergleichbare Kosten','Angebotspreis, Versand, Prüfung, bekannte Aufbereitung und weitere Beschaffungsfaktoren wurden zu einem vergleichbaren Kostenbild zusammengeführt.','Kosten abgeglichen'],
  ['ENTSCHEIDUNGSAUFLÖSUNG','Mit Bedingung fortfahren','Der stärkste Kandidat wird mit klaren Bedingungen ausgewählt – nicht einfach, weil sein sichtbarer Preis am niedrigsten ist.','Entscheidung dokumentiert']
 ]
};
function currentPage(){return location.pathname.split('/').pop()||'index.html'}
function getLang(){try{return localStorage.getItem('aur_lang')||'en'}catch(e){return 'en'}}
function escapeHTML(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
function textFor(el,lang){return el?.dataset?.[lang]||el?.dataset?.en||''}
function applyLang(lang){
 try{localStorage.setItem('aur_lang',lang)}catch(e){}
 document.documentElement.lang=lang;
 document.querySelectorAll('[data-en]').forEach(el=>{el.innerHTML=el.dataset[lang]||el.dataset.en});
 document.querySelectorAll('[data-placeholder-en]').forEach(el=>{el.placeholder=el.dataset[`placeholder${lang==='de'?'De':'En'}`]||el.dataset.placeholderEn||''});
 document.querySelectorAll('[data-t]').forEach(el=>{const t=translations[lang];if(t[el.dataset.t])el.textContent=t[el.dataset.t]});
 document.querySelectorAll('[data-lang]').forEach(el=>el.classList.toggle('active',el.dataset.lang===lang));
 document.querySelectorAll('[data-lang-panel]').forEach(el=>el.hidden=el.dataset.langPanel!==lang);
 document.dispatchEvent(new CustomEvent('aur:lang',{detail:{lang}}));
}
function shell(){
 const current=currentPage();
 const header=document.querySelector('#site-header');
 if(header)header.innerHTML=`<div class="wrap header-inner"><a class="brand brand-digital brand-primary-lockup" href="index.html" aria-label="Aurenoeva home"><img class="brand-primary-logo" src="assets/img/brand/aurenoeva-logo-approved.png" alt="Aurenoeva — Procurement · Components · Intelligence"></a><nav class="nav" id="primary-nav">${pageLinks.map(([href,key])=>`<a class="${current===href?'active':''}" href="${href}" data-t="${key}"></a>`).join('')}</nav><div class="header-actions"><div class="lang"><button type="button" data-lang="en" aria-label="English">EN</button><span>/</span><button type="button" data-lang="de" aria-label="Deutsch">DE</button></div><a class="btn small" href="requirement.html" data-t="cta"></a></div><button class="menu-toggle" type="button" aria-expanded="false" aria-controls="primary-nav">Menu</button></div>`;
 const footer=document.querySelector('#site-footer');
 if(footer)footer.innerHTML=`<div class="wrap"><div class="footer-grid"><div class="footer-brand"><a class="footer-lockup footer-primary-lockup" href="index.html" aria-label="Aurenoeva home"><img class="footer-primary-logo" src="assets/img/brand/aurenoeva-logo-approved.png" alt="Aurenoeva — Procurement · Components · Intelligence"></a><p data-t="footerIntro"></p><p><span data-en="A business of" data-de="Ein Geschäftsbereich von"></span> NOEVA Systems e.K.<br>Marienburger Straße 16 · 56112 Lahnstein · Germany<br><a href="mailto:info@aurenoeva.com">info@aurenoeva.com</a></p></div><div class="footer-col"><span data-t="footerPlatform"></span><a href="how-it-works.html" data-t="navHow"></a><a href="trust-methodology.html" data-t="navTrust"></a><a href="requirement.html" data-t="cta"></a></div><div class="footer-col"><span data-t="navIndustries"></span><a href="industries.html#laboratory" data-en="Laboratory" data-de="Labor & Analytik"></a><a href="industries.html#test" data-en="Test & Measurement" data-de="Mess- & Prüftechnik"></a><a href="industries.html#industrial" data-en="Industrial" data-de="Industrie & Automation"></a><a href="industries.html#aviation" data-en="Aviation" data-de="Luftfahrt"></a></div><div class="footer-col"><span data-t="footerCompany"></span><a href="suppliers.html" data-t="navSuppliers"></a><a href="about.html" data-t="navAbout"></a><a href="about.html#contact" data-t="navContact"></a></div><div class="footer-col"><span data-t="footerLegal"></span><a href="imprint.html" data-t="imprint"></a><a href="privacy.html" data-t="privacy"></a><a href="cookies.html" data-t="cookies"></a><a href="terms.html" data-t="terms"></a><a href="accessibility.html" data-t="accessibility"></a><button type="button" data-cookie-open data-t="cookieSettings"></button></div></div><div class="footer-bottom"><span>© 2026 Aurenoeva · NOEVA Systems e.K.</span><span>PROCUREMENT · COMPONENTS · INTELLIGENCE</span></div></div>`;
 document.querySelector('.menu-toggle')?.addEventListener('click',e=>{const nav=document.querySelector('#primary-nav');nav.classList.toggle('open');e.currentTarget.setAttribute('aria-expanded',nav.classList.contains('open'))});
 document.querySelectorAll('[data-lang]').forEach(b=>b.addEventListener('click',()=>applyLang(b.dataset.lang)));
 applyLang(getLang());
}
function initReveal(){if(!('IntersectionObserver'in window)){document.querySelectorAll('.reveal').forEach(el=>el.classList.add('in'));return}const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting)e.target.classList.add('in')}),{threshold:.07});document.querySelectorAll('.reveal').forEach(el=>io.observe(el))}
function initDecision(){
 const buttons=[...document.querySelectorAll('[data-stage]')]; if(!buttons.length)return; let active=0;
 function render(){const lang=getLang(),d=decisionData[lang][active];document.querySelector('#d-label').textContent=d[0];document.querySelector('#d-value').textContent=d[1];document.querySelector('#d-text').textContent=d[2];document.querySelector('#d-state').textContent=d[3]}
 buttons.forEach((b,i)=>b.addEventListener('click',()=>{active=i;buttons.forEach(x=>x.classList.toggle('active',x===b));render()}));
 document.addEventListener('aur:lang',render);render();
}
function query(params){return new URLSearchParams(params).toString()}
function categoryLabel(industry,value,lang){const item=industries[industry]?.find(x=>x.v===value);return item?.[lang]||value}
function conditionLabel(value,lang){const m={any:{en:'Any condition',de:'Zustand offen'},new:{en:'New only',de:'Nur neu'},refurbished:{en:'Refurbished accepted',de:'Überholt möglich'},used:{en:'Used accepted',de:'Gebraucht möglich'}};return m[value]?.[lang]||value}
function industryLabel(industry,lang){const m={laboratory:{en:'Laboratory',de:'Labor & Analytik'},test:{en:'Test & Measurement',de:'Mess- & Prüftechnik'},medical:{en:'Medical',de:'Medizintechnik'},industrial:{en:'Industrial',de:'Industrie & Automation'},energy:{en:'Energy',de:'Energie'},marine:{en:'Marine',de:'Marine'},aviation:{en:'Aviation',de:'Luftfahrt'}};return m[industry]?.[lang]||industry}
function initSearchStudio(){
 const studio=document.querySelector('[data-search-studio]');if(!studio)return;
 const modeTabs=[...studio.querySelectorAll('[data-mode]')], pills=[...studio.querySelectorAll('[data-industry]')], cat=studio.querySelector('#studio-category'), condition=studio.querySelector('#studio-condition'), exact=studio.querySelector('#studio-query'), summary=studio.querySelector('#studio-summary'), cta=studio.querySelector('#studio-continue');
 let mode='category',industry='laboratory',selectedCategory='';
 function fillCategories(){const lang=getLang();const prior=selectedCategory||cat.value;cat.innerHTML=industries[industry].map(x=>`<option value="${escapeHTML(x.v)}">${escapeHTML(x[lang])}</option>`).join('');if(prior&&industries[industry].some(x=>x.v===prior))cat.value=prior;selectedCategory=cat.value;update()}
 function update(){const lang=getLang();selectedCategory=cat.value;summary.textContent=`${industryLabel(industry,lang)} / ${categoryLabel(industry,cat.value,lang)} / ${conditionLabel(condition.value,lang)}`;const params={mode,industry,category:cat.value,condition:condition.value};if(exact.value.trim())params.q=exact.value.trim();cta.href='requirement.html?'+query(params)}
 function setPlaceholder(){const lang=getLang();exact.placeholder=mode==='exact'?(lang==='de'?'Teilenummer, Modell oder Hersteller eingeben':'Enter part number, model or manufacturer'):(lang==='de'?'Produkt, Funktion oder bekannte Kennung beschreiben':'Describe the item, function or known identifier')}
 modeTabs.forEach(t=>t.addEventListener('click',()=>{mode=t.dataset.mode;modeTabs.forEach(x=>x.classList.toggle('active',x===t));setPlaceholder();update()}));
 pills.forEach(p=>p.addEventListener('click',()=>{industry=p.dataset.industry;pills.forEach(x=>x.classList.toggle('active',x===p));selectedCategory='';fillCategories()}));
 cat.addEventListener('change',update);condition.addEventListener('change',update);exact.addEventListener('input',update);studio.querySelector('#studio-go')?.addEventListener('click',()=>cta.click());exact.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();cta.click()}});
 document.addEventListener('aur:lang',()=>{fillCategories();setPlaceholder();update()});fillCategories();setPlaceholder();
}
function initRequirementWizard(){
 const wizard=document.querySelector('[data-wizard]');if(!wizard)return;
 const params=new URLSearchParams(location.search);let step=1;
 const fields={mode:wizard.querySelector('#req-mode'),industry:wizard.querySelector('#req-industry'),category:wizard.querySelector('#req-category'),query:wizard.querySelector('#req-query'),description:wizard.querySelector('#req-description'),condition:wizard.querySelector('#req-condition'),quantity:wizard.querySelector('#req-quantity'),geography:wizard.querySelector('#req-geography'),needBy:wizard.querySelector('#req-needby'),budget:wizard.querySelector('#req-budget'),company:wizard.querySelector('#req-company'),name:wizard.querySelector('#req-name'),email:wizard.querySelector('#req-email')};
 let selectedCategory=params.get('category')||'';
 function fillCats(){const lang=getLang(),prior=selectedCategory||fields.category.value;fields.category.innerHTML=industries[fields.industry.value].map(x=>`<option value="${escapeHTML(x.v)}">${escapeHTML(x[lang])}</option>`).join('');if(prior&&industries[fields.industry.value].some(x=>x.v===prior))fields.category.value=prior;selectedCategory=fields.category.value}
 fields.mode.value=params.get('mode')||'category';fields.industry.value=params.get('industry')||'laboratory';fillCats();if(params.get('category')&&industries[fields.industry.value].some(x=>x.v===params.get('category')))fields.category.value=params.get('category');fields.condition.value=params.get('condition')||'any';fields.query.value=params.get('q')||'';selectedCategory=fields.category.value;
 fields.industry.addEventListener('change',()=>{selectedCategory='';fillCats()});fields.category.addEventListener('change',()=>selectedCategory=fields.category.value);
 const errorBox=wizard.querySelector('#wizard-error');
 const sendStatus=wizard.querySelector('#requirement-status');
 function setError(message,focusEl){if(errorBox){errorBox.textContent=message;errorBox.hidden=!message}wizard.querySelectorAll('[aria-invalid="true"]').forEach(el=>el.removeAttribute('aria-invalid'));if(focusEl){focusEl.setAttribute('aria-invalid','true');focusEl.focus()}}
 function clearError(){setError('')}
 function emailValid(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim())}
 function evidenceValues(){const lang=getLang();return [...wizard.querySelectorAll('[data-evidence]:checked')].map(el=>lang==='de'?(el.dataset.deValue||el.value):el.value)}
 function evidenceText(){const values=evidenceValues();return values.length?values.join(', '):(getLang()==='de'?'Keine besonderen Nachweise angegeben':'No specific evidence requested')}
 function validateStep(n){
  const lang=getLang();clearError();
  if(n===1 && !fields.query.value.trim() && !fields.description.value.trim()){setError(lang==='de'?'Bitte nennen Sie mindestens eine Kennung oder beschreiben Sie kurz, was Sie benötigen.':'Please provide at least one identifier or a short description of what you need.',fields.query);return false}
  if(n===3){
   if(!fields.company.value.trim()){setError(lang==='de'?'Bitte nennen Sie Ihr Unternehmen. Aurenoeva bearbeitet geschäftliche B2B-Anfragen.':'Please enter your company. Aurenoeva handles business-to-business enquiries.',fields.company);return false}
   if(!fields.name.value.trim()){setError(lang==='de'?'Bitte nennen Sie eine Ansprechperson.':'Please enter a contact name.',fields.name);return false}
   if(!emailValid(fields.email.value)){setError(lang==='de'?'Bitte geben Sie eine gültige geschäftliche E-Mail-Adresse ein.':'Please enter a valid business email address.',fields.email);return false}
  }
  return true
 }
 function render(){wizard.querySelectorAll('[data-wizard-step]').forEach(el=>el.hidden=Number(el.dataset.wizardStep)!==step);wizard.querySelectorAll('.wizard-progress i').forEach((el,i)=>el.classList.toggle('active',i<step));wizard.querySelector('[data-step-label]').textContent=`0${step} / 04`;clearError();if(step===3)renderBrief();if(step===4)renderBrief(true)}
 function val(v){const lang=getLang();return v&&v.trim()?v.trim():(lang==='de'?'Nicht angegeben':'Not specified')}
 function modeText(){const lang=getLang(),m={category:{en:'Category known',de:'Kategorie bekannt'},exact:{en:'Part / model known',de:'Teil / Modell bekannt'},requirement:{en:'Application / outcome known',de:'Anwendung / Funktion bekannt'}};return m[fields.mode.value][lang]}
 function renderBrief(final=false){const target=wizard.querySelector(final?'#final-brief':'#brief-preview');if(!target)return;const lang=getLang();const labels=lang==='de'?['Ausgangspunkt','Bereich','Kategorie','Kennung','Bedarf','Zustand','Menge','Lieferort','Benötigt bis','Budget','Nachweise']:['Starting point','Sector','Category','Identifier','Requirement','Condition','Quantity','Delivery location','Need by','Budget','Evidence requested'];const values=[modeText(),industryLabel(fields.industry.value,lang),categoryLabel(fields.industry.value,fields.category.value,lang),val(fields.query.value),val(fields.description.value),conditionLabel(fields.condition.value,lang),val(fields.quantity.value),val(fields.geography.value),val(fields.needBy.value),val(fields.budget.value),evidenceText()];target.innerHTML=labels.map((l,i)=>`<div class="brief-row"><span>${escapeHTML(l)}</span><b>${escapeHTML(values[i])}</b></div>`).join('')}
 function summaryText(){const lang=getLang();return lang==='de'?`AURENOEVA BESCHAFFUNGSANFRAGE
Bereich: ${industryLabel(fields.industry.value,lang)}
Kategorie: ${categoryLabel(fields.industry.value,fields.category.value,lang)}
Kennung: ${val(fields.query.value)}
Bedarf: ${val(fields.description.value)}
Zustand: ${conditionLabel(fields.condition.value,lang)}
Menge: ${val(fields.quantity.value)}
Lieferort: ${val(fields.geography.value)}
Benötigt bis: ${val(fields.needBy.value)}
Budget: ${val(fields.budget.value)}
Nachweise: ${evidenceText()}
Unternehmen: ${val(fields.company.value)}
Name: ${val(fields.name.value)}
E-Mail: ${val(fields.email.value)}`:`AURENOEVA PROCUREMENT REQUIREMENT
Sector: ${industryLabel(fields.industry.value,lang)}
Category: ${categoryLabel(fields.industry.value,fields.category.value,lang)}
Identifier: ${val(fields.query.value)}
Requirement: ${val(fields.description.value)}
Condition: ${conditionLabel(fields.condition.value,lang)}
Quantity: ${val(fields.quantity.value)}
Delivery location: ${val(fields.geography.value)}
Need by: ${val(fields.needBy.value)}
Budget: ${val(fields.budget.value)}
Evidence requested: ${evidenceText()}
Company: ${val(fields.company.value)}
Name: ${val(fields.name.value)}
Email: ${val(fields.email.value)}`}
 wizard.querySelectorAll('[data-next]').forEach(b=>b.addEventListener('click',()=>{if(step<4&&validateStep(step)){step++;render()}}));wizard.querySelectorAll('[data-prev]').forEach(b=>b.addEventListener('click',()=>{if(step>1){step--;render()}}));
 wizard.querySelector('#copy-brief')?.addEventListener('click',async e=>{const b=e.currentTarget;const lang=getLang();try{await navigator.clipboard.writeText(summaryText());const old=b.textContent;b.textContent=lang==='de'?'Kopiert ✓':'Copied ✓';setTimeout(()=>b.textContent=old,1800)}catch(err){alert(summaryText())}});
 function mailFallback(){const lang=getLang();const subject=encodeURIComponent(lang==='de'?`Aurenoeva Beschaffungsanfrage — ${categoryLabel(fields.industry.value,fields.category.value,lang)}`:`Aurenoeva requirement — ${categoryLabel(fields.industry.value,fields.category.value,lang)}`);location.href=`mailto:info@aurenoeva.com?subject=${subject}&body=${encodeURIComponent(summaryText())}`}
 wizard.querySelector('#email-brief')?.addEventListener('click',async e=>{
  if(!validateStep(3))return;
  const button=e.currentTarget,lang=getLang();button.disabled=true;const original=button.textContent;button.textContent=lang==='de'?'Wird gesendet …':'Sending …';if(sendStatus){sendStatus.hidden=false;sendStatus.className='form-status neutral';sendStatus.textContent=lang==='de'?'Ihre Anfrage wird sicher an Aurenoeva übermittelt.':'Sending your requirement securely to Aurenoeva.'}
  if(location.protocol==='file:'){button.disabled=false;button.textContent=original;mailFallback();return}
  const payload=new URLSearchParams({lang,mode:fields.mode.value,industry:industryLabel(fields.industry.value,lang),category:categoryLabel(fields.industry.value,fields.category.value,lang),identifier:fields.query.value.trim(),requirement:fields.description.value.trim(),condition:conditionLabel(fields.condition.value,lang),quantity:fields.quantity.value.trim(),delivery_location:fields.geography.value.trim(),need_by:fields.needBy.value.trim(),budget:fields.budget.value.trim(),evidence:evidenceText(),company:fields.company.value.trim(),name:fields.name.value.trim(),email:fields.email.value.trim(),website:''});
  try{
   const res=await fetch('requirement-submit.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:payload.toString()});const data=await res.json().catch(()=>({ok:false}));
   if(!res.ok||!data.ok)throw new Error(data.error||'delivery');
   if(sendStatus){sendStatus.className='form-status success';sendStatus.innerHTML=lang==='de'?`Anfrage gesendet. Ihre Referenz: <strong>${escapeHTML(data.reference||'')}</strong>. Wir melden uns unter ${escapeHTML(fields.email.value.trim())}.`:`Enquiry sent. Your reference: <strong>${escapeHTML(data.reference||'')}</strong>. We will respond to ${escapeHTML(fields.email.value.trim())}.`}
   button.textContent=lang==='de'?'Gesendet ✓':'Sent ✓';
  }catch(err){
   button.disabled=false;button.textContent=original;
   if(sendStatus){sendStatus.className='form-status error';sendStatus.innerHTML=lang==='de'?`Die direkte Übermittlung war nicht möglich. Bitte versuchen Sie es erneut oder senden Sie die Zusammenfassung an <a href="mailto:info@aurenoeva.com">info@aurenoeva.com</a>.`:`The direct submission could not be completed. Please try again or send the summary to <a href="mailto:info@aurenoeva.com">info@aurenoeva.com</a>.`}
  }
 });
 document.addEventListener('aur:lang',()=>{selectedCategory=fields.category.value;fillCats();render()});render();
}
function initContactForm(){
 const form=document.querySelector('[data-contact-form]');if(!form)return;
 const langInput=form.querySelector('input[name="lang"]'),status=document.querySelector('#contact-form-status'),submit=form.querySelector('button[type="submit"]');
 function syncLang(){if(langInput)langInput.value=getLang()}
 function renderStatus(){if(!status)return;const p=new URLSearchParams(location.search),lang=getLang(),state=p.get('contact');if(!state){status.hidden=true;return}status.hidden=false;if(state==='sent'){status.className='form-status success';status.textContent=lang==='de'?'Vielen Dank. Ihre Nachricht wurde an Aurenoeva gesendet.':'Thank you. Your message has been sent to Aurenoeva.'}else{status.className='form-status error';status.innerHTML=lang==='de'?`Die Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es erneut oder schreiben Sie an <a href="mailto:info@aurenoeva.com">info@aurenoeva.com</a>.`:`Your message could not be sent. Please try again or email <a href="mailto:info@aurenoeva.com">info@aurenoeva.com</a>.`}}
 form.addEventListener('submit',e=>{syncLang();const privacy=form.querySelector('[name="privacy_ack"]');if(privacy&&!privacy.checked){e.preventDefault();const lang=getLang();status.hidden=false;status.className='form-status error';status.textContent=lang==='de'?'Bitte bestätigen Sie, dass Sie die Datenschutzerklärung gelesen haben.':'Please confirm that you have read the Privacy Policy.';privacy.focus();return}if(submit){submit.disabled=true;submit.textContent=getLang()==='de'?'Wird gesendet …':'Sending …'}});
 document.addEventListener('aur:lang',()=>{syncLang();renderStatus()});syncLang();renderStatus();
}
function initCookies(){
 const key='aur_privacy_v6';let saved=null;try{saved=JSON.parse(localStorage.getItem(key)||'null')}catch(e){}
 const banner=document.createElement('div');banner.className='cookie-banner';banner.hidden=!!saved;document.body.appendChild(banner);
 const modal=document.createElement('div');modal.className='modal-backdrop';modal.hidden=true;document.body.appendChild(modal);
 function save(){try{localStorage.setItem(key,JSON.stringify({necessary:true,ts:new Date().toISOString()}))}catch(e){}banner.hidden=true;modal.hidden=true}
 function open(){modal.hidden=false}
 function copy(){const lang=getLang();const t=lang==='de'?{h:'Datenschutz & Browserspeicher',p:'Aurenoeva verwendet derzeit nur notwendigen Browserspeicher für Sprache und Datenschutzhinweise. Es sind keine Analyse-, Werbe- oder Tracking-Dienste eingebunden.',details:'Details',ok:'Verstanden',ey:'Datenschutz',mh:'Cookie- & Speicher-Einstellungen',mp:'Aktuell ist nur notwendiger Browserspeicher aktiv. Wenn später nicht notwendige Technologien hinzukommen, werden sie erst nach einer dann erforderlichen, konkreten Einwilligung aktiviert.',essential:'Notwendig',essentialText:'Sprachauswahl und Speicherung, dass dieser Hinweis bereits angezeigt wurde.',close:'Schließen'}:{h:'Privacy & browser storage',p:'Aurenoeva currently uses only necessary browser storage for language and this privacy notice. No analytics, advertising or tracking services are active.',details:'Details',ok:'Got it',ey:'Privacy',mh:'Cookie & storage settings',mp:'Only necessary browser storage is active today. If non-essential technologies are added later, they will require a new, specific choice before activation where legally required.',essential:'Necessary',essentialText:'Language selection and remembering that this notice has already been shown.',close:'Close'};
  banner.innerHTML=`<div><h3>${t.h}</h3><p>${t.p}</p></div><div class="cookie-actions"><button class="btn ghost" type="button" data-cookie-open>${t.details}</button><button class="btn" type="button" data-cookie-ok>${t.ok}</button></div>`;
  modal.innerHTML=`<div class="cookie-modal" role="dialog" aria-modal="true" aria-label="${t.mh}"><div class="eyebrow">${t.ey}</div><h2>${t.mh}</h2><p>${t.mp}</p><div class="pref-row"><div><b>${t.essential}</b><span>${t.essentialText}</span></div><label class="switch"><input type="checkbox" checked disabled><span class="slider"></span></label></div><div class="wizard-actions"><span></span><button class="btn" type="button" data-cookie-close>${t.close}</button></div></div>`;
  bind();
 }
 function bind(){banner.querySelector('[data-cookie-open]')?.addEventListener('click',open);banner.querySelector('[data-cookie-ok]')?.addEventListener('click',save);modal.querySelector('[data-cookie-close]')?.addEventListener('click',()=>{save()})}
 copy();
 document.querySelectorAll('[data-cookie-open]').forEach(b=>b.addEventListener('click',open));modal.addEventListener('click',e=>{if(e.target===modal)modal.hidden=true});document.addEventListener('aur:lang',()=>{const wasOpen=!modal.hidden;copy();if(wasOpen)open()});
}
document.addEventListener('DOMContentLoaded',()=>{shell();initSearchStudio();initRequirementWizard();initContactForm();initDecision();initReveal();initCookies()});
