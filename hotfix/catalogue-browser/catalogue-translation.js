/* AURENOEVA — translate displayed supplier descriptions, never product IDs.
 * Chrome/Edge on-device Translator + LanguageDetector. No new API/service.
 * Missing browser support or language packs cannot change the underlying RFQ.
 */
(function () {
  'use strict';
  const SELECTORS = [
    '#aur-browse-products .aur-browse-model',
    '#aur-browse-products .aur-browse-description',
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
    const kind=el.classList?.contains('aur-browse-model') ? 'model' : 'description';
    const details=el.closest('article')?.querySelector(
      '.aur-source-description[data-aur-kind="'+kind+'"]');
    if(details)details.remove();
  }
  function keepOriginal(el,raw,sourceLanguage) {
    const article=el.closest('article');
    if(!article)return;
    const kind=el.classList?.contains('aur-browse-model') ? 'model' : 'description';
    let details=article.querySelector(
      '.aur-source-description[data-aur-kind="'+kind+'"]');
    if(!details){
      details=document.createElement('details');
      details.className='aur-source-description';
      details.setAttribute('data-aur-kind',kind);
      const summary=document.createElement('summary');
      const source=document.createElement('p');
      source.className='aur-source-description-text';
      details.append(summary,source);
      // Put model originals beneath the title, not inside its inline <b>.
      // A block-level <details> nested inside the heading breaks card layout.
      const anchor=kind==='model' ? (el.closest('b') || el) : el;
      anchor.insertAdjacentElement('afterend',details);
    }
    details.querySelector('summary').textContent=kind==='model'
      ?localized('Show original model name','Originalbezeichnung anzeigen')
      :localized('Show original description','Originalbeschreibung anzeigen');
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
  async function translateEmbeddedScripts(text,target) {
    // Some supplier text mixes English model specifications with CJK phrases.
    // Translate only the foreign-language runs, preserving surrounding codes.
    const pattern=/[ぁ-ヿ㐀-鿿가-힣][ぁ-ヿ㐀-鿿가-힣0-9 ()（）,，.:-]{0,85}/g;
    const runs=[...text.matchAll(pattern)].slice(0,8);
    let result=text;
    let translatedAny=false;
    for(const match of runs){
      const fragment=match[0].trim();
      if(fragment.length<2)continue;
      const source=/[ぁ-ヿ]/.test(fragment)?'ja':
        /[가-힣]/.test(fragment)?'ko':'zh';
      try{
        const translator=await getTranslator(source,target);
        const replacement=(await translator.translate(fragment)).trim();
        if(replacement&&replacement!==fragment){
          result=result.replace(fragment,replacement);
          translatedAny=true;
        }
      }catch(_e){/* retain the original if translation is unsupported */}
    }
    return translatedAny?result:text;
  }

  async function serverFallback(el,raw,target,run) {
    if(typeof window.fetch!=='function')return false;
    try {
      const response=await window.fetch('catalogue-translate.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({text:raw,target})
      });
      if(!response.ok)return false;
      const data=await response.json();
      const translated=String(data?.text||'').trim();
      if(!data?.ok || !translated || translated===raw)return false;
      if(run!==refreshId || !el.isConnected || el.dataset.aurTarget!==target)return false;
      cache.set(target+'\u0000'+raw,{text:translated,source:'auto'});
      el.textContent=translated;
      el.lang=target;
      keepOriginal(el,raw,'auto');
      return true;
    }catch(_e){return false;}
  }

  async function convert(el,run){
    const raw=original(el), target=targetLanguage();
    if(!raw || raw.length<9 || raw.length>1600)return;
    // Preserve opaque model identifiers (including RFQ reference numbers).
    if(el.classList?.contains('aur-browse-model') &&
       /^[A-Z0-9][A-Z0-9._+–-]{2,}$/.test(raw))return;
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
    if(!supported()){
      if(!await serverFallback(el,raw,target,run))unavailable('language');
      return;
    }
    try{
      const d=await detector();
      const languages=await d.detect(raw);
      const top=languages?.[0];
      const source=String(top?.detectedLanguage||'').split('-')[0].toLowerCase();
      if(!source || source==='und' || (top.confidence||0)<0.45){
        await serverFallback(el,raw,target,run);
        return;
      }
      let translated=raw;
      if(source===target){
        translated=await translateEmbeddedScripts(raw,target);
      }else{
        const engine=await getTranslator(source,target);
        translated=(await engine.translate(raw)).trim();
      }
      if(!translated || translated===raw){
        await serverFallback(el,raw,target,run);
        return;
      }
      cache.set(target+'\u0000'+raw,{text:translated,source});
      if(run!==refreshId || !el.isConnected || el.dataset.aurTarget!==target)return;
      el.textContent=translated;
      el.lang=target;
      keepOriginal(el,raw,source);
    }catch(e) {
      if(!await serverFallback(el,raw,target,run)) {
        unavailable(e?.name==='NotAllowedError' || e?.name==='SecurityError'?'permission':'language');
      }
    }
  }
  async function render(){
    const run=++refreshId;
    const nodes=[...examples()];
    if(!nodes.length)return;
    status(localized('Translating product titles and descriptions…',
      'Produktbezeichnungen und Beschreibungen werden übersetzt …'));
    await Promise.all(nodes.map(node=>convert(node,run)));
    if(run===refreshId){
      const untranslated=nodes.some(el=>el.lang!==targetLanguage());
      status(untranslated?'':localized('Product text translated automatically · Originals available',
        'Produkttexte automatisch übersetzt · Originale verfügbar'));
    }
  }
  function start(){
    makeControls();
    // Use local models when available; otherwise use the public-text server
    // fallback on every browser, without asking visitors to enable translation.
    if(supported()) void detector().then(()=>render()).catch(()=>{void render();});
    else void render();
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
      if(supported()) void detector().then(()=>render()).catch(()=>{void render();});
      else void render();
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
  else start();
})();
