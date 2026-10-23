/* AURENOEVA — translate displayed supplier descriptions, never product IDs.
 * Chrome/Edge on-device Translator + LanguageDetector. No new API/service.
 * Missing browser support or language packs cannot change the underlying RFQ.
 */
(function () {
  'use strict';
  const SELECTORS = [
    '#aur-browse-products .aur-browse-part-copy span',
    '#req-matches .catalogue-match p',
    '#studio-matches .catalogue-match p'
  ];
  const areas = ['#aur-browse-products','#req-matches','#studio-matches'];
  const cache = new Map();
  const translatorPromises = new Map();
  let detectorPromise = null;
  let enabled = false;
  let message = null;
  const pendingPairs = new Set();
  let refreshId = 0;

  const targetLanguage = () => document.documentElement.lang?.toLowerCase().startsWith('de') ? 'de' : 'en';
  const localized = (en, de) => targetLanguage() === 'de' ? de : en;
  const supported = () => Boolean(window.LanguageDetector?.create && window.Translator?.create);
  const examples = () => document.querySelectorAll(SELECTORS.join(','));

  function status(text) {
    if (message) message.textContent = text;
  }
  function makeControls() {
    const heading=document.querySelector('#aur-browse .aur-browse-head');
    if(!heading || document.getElementById('aur-translation-controls')) return;
    const controls=document.createElement('div');
    controls.id='aur-translation-controls';
    controls.className='aur-translation-controls';
    message=document.createElement('small');
    message.className='aur-translation-status';
    message.setAttribute('role','status');
    message.setAttribute('aria-live','polite');
    controls.append(message);
    heading.insertAdjacentElement('afterend',controls);
    status('');
  }
  function unavailable(reason) {
    if(!supported()){
      status(localized('Original supplier descriptions shown where automatic translation is not supported.',
        'Originalbeschreibungen werden angezeigt, sofern der Browser keine automatische Übersetzung unterstützt.'));
    } else if(reason === 'permission') {
      status(localized('Translation will start automatically as you browse the catalogue.',
        'Die Übersetzung startet automatisch beim Durchsuchen des Katalogs.'));
    } else {
      status(localized('Original descriptions are shown for languages this device cannot translate.',
        'Für nicht unterstützte Sprachen werden die Originalbeschreibungen angezeigt.'));
    }
  }
  function original(el) {
    if (!el.hasAttribute('data-aur-original'))
      el.setAttribute('data-aur-original',el.textContent?.trim() || '');
    return el.getAttribute('data-aur-original') || '';
  }
  function restore(el) {
    const raw=original(el);
    if(el.textContent!==raw)el.textContent=raw;
    el.lang='';
    const details=el.closest('article')?.querySelector('.aur-source-description');
    if(details)details.remove();
  }
  function keepOriginal(el,raw,sourceLanguage) {
    const article=el.closest('article');
    if(!article)return;
    let details=article.querySelector('.aur-source-description');
    if(!details){
      details=document.createElement('details');
      details.className='aur-source-description';
      const summary=document.createElement('summary');
      const source=document.createElement('p');
      source.className='aur-source-description-text';
      details.append(summary,source);
      el.insertAdjacentElement('afterend',details);
    }
    details.querySelector('summary').textContent=localized('Show original description','Originalbeschreibung anzeigen');
    const source=details.querySelector('.aur-source-description-text');
    source.textContent=raw;
    if(sourceLanguage)source.lang=sourceLanguage;
  }
  async function detector() {
    if(!detectorPromise){
      detectorPromise=window.LanguageDetector.create({
        monitor(m) {
          m.addEventListener('downloadprogress', event => {
            status(localized('Preparing translation model…','Übersetzungsmodell wird vorbereitet …') +
              ' '+Math.round(event.loaded*100)+'%');
          });
        }
      }).catch(e=>{detectorPromise=null;throw e;});
    }
    return detectorPromise;
  }
  function getTranslator(source,target) {
    const key=source+'>'+target;
    if(!translatorPromises.has(key)){
      // Create synchronously while a normal page gesture is active. A first-time
      // language-pack download may otherwise be rejected by the browser.
      const promise=window.Translator.create({
        sourceLanguage:source,targetLanguage:target,
        monitor(m) {
          m.addEventListener('downloadprogress', event=>{
            status(localized('Preparing automatic translation…','Automatische Übersetzung wird vorbereitet …')+
              ' '+Math.round(event.loaded*100)+'%');
          });
        }
      }).then(instance=>{
        pendingPairs.delete(key);
        return instance;
      }).catch(e=>{
        translatorPromises.delete(key);
        if(e?.name==='NotAllowedError'||e?.name==='SecurityError')
          pendingPairs.add(key);
        throw e;
      });
      translatorPromises.set(key,promise);
    }
    return translatorPromises.get(key);
  }
  function tryPendingOnNormalGesture() {
    if(!supported())return;
    // Called in the capture phase of the user's ordinary click, tap or typing.
    // No translation-specific control or extra click is required.
    for(const pair of [...pendingPairs]){
      const [source,target]=pair.split('>');
      if(source&&target)void getTranslator(source,target).then(()=>render()).catch(()=>{});
    }
    void detector().then(()=>render()).catch(()=>unavailable('permission'));
  }
  async function convert(el,run){
    const raw=original(el), target=targetLanguage();
    if(!raw || raw.length<18 || raw.length>1600)return;
    // Always start from original; a language toggle must never retranslate
    // an already translated sentence.
    el.dataset.aurTarget=target;
    const cached=cache.get(target+'\u0000'+raw);
    if(cached){
      if(run===refreshId && el.isConnected && el.dataset.aurTarget===target){
        el.textContent=cached.text;el.lang=target;keepOriginal(el,raw,cached.source);
      }
      return;
    }
    try{
      const d=await detector();
      const languages=await d.detect(raw);
      const top=languages?.[0];
      const source=String(top?.detectedLanguage||'').split('-')[0].toLowerCase();
      if(!source || source==='und' || (top.confidence||0)<0.45 || source===target)return;
      const engine=await getTranslator(source,target);
      const translated=(await engine.translate(raw)).trim();
      if(!translated || translated===raw)return;
      cache.set(target+'\u0000'+raw,{text:translated,source});
      if(run!==refreshId || !el.isConnected || el.dataset.aurTarget!==target)return;
      el.textContent=translated;
      el.lang=target;
      keepOriginal(el,raw,source);
    }catch(e) {
      if(e?.name==='NotAllowedError' || e?.name==='SecurityError'){
        unavailable('permission');
      }else {
        unavailable('language');
      }
    }
  }
  async function render(){
    if(!supported())return;
    const run=++refreshId;
    const nodes=[...examples()];
    if(!nodes.length)return;
    status(localized('Translating catalogue descriptions…',
      'Katalogbeschreibungen werden übersetzt …'));
    await Promise.all(nodes.map(node=>convert(node,run)));
    if(run===refreshId){
      const untranslated=nodes.some(el=>el.lang!==targetLanguage());
      status(untranslated?'':localized('Descriptions translated automatically · Originals available',
        'Beschreibungen automatisch übersetzt · Originale verfügbar'));
    }
  }
  function start(){
    makeControls();
    if(!supported()){
      unavailable('language');
      return;
    }
    // Models already cached by the browser can be used immediately.
    // When first-time downloads require activation, any ordinary interaction
    // with the existing sourcing form resumes translation automatically.
    void detector().then(()=>render()).catch(()=>unavailable('permission'));
    for(const eventName of ['pointerdown','keydown']){
      document.addEventListener(eventName,tryPendingOnNormalGesture,{capture:true,passive:true});
    }
    for(const selector of areas){
      const element=document.querySelector(selector);
      if(!element)continue;
      const observer=new MutationObserver(records=>{
        if(records.some(r=>r.addedNodes.length>0))void render();
      });
      observer.observe(element,{childList:true});
    }
    document.addEventListener('aur:lang',()=>{
      ++refreshId;
      for(const el of examples())restore(el);
      void detector().then(()=>render()).catch(()=>unavailable('permission'));
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
  else start();
})();
