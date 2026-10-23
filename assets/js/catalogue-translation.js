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
  let button = null;
  let message = null;
  let refreshId = 0;

  const targetLanguage = () => document.documentElement.lang?.toLowerCase().startsWith('de') ? 'de' : 'en';
  const localized = (en, de) => targetLanguage() === 'de' ? de : en;
  const supported = () => Boolean(window.LanguageDetector?.create && window.Translator?.create);
  const examples = () => document.querySelectorAll(SELECTORS.join(','));

  function status(text) {
    if (message) message.textContent = text;
  }
  function buttonText() {
    if (button) button.textContent = localized('Translate descriptions', 'Beschreibungen übersetzen');
  }
  function activationRequired(text) {
    enabled = false;
    if (button) {
      button.hidden = !supported();
      button.disabled = false;
      buttonText();
    }
    status(text || localized(
      'Translate supplier descriptions into English using your browser.',
      'Lieferantenbeschreibungen im Browser automatisch auf Deutsch übersetzen.'
    ));
  }
  function makeControls() {
    const heading=document.querySelector('#aur-browse .aur-browse-head');
    if (!heading || document.getElementById('aur-translation-controls')) return;
    const controls=document.createElement('div');
    controls.id='aur-translation-controls';
    controls.className='aur-translation-controls';
    button=document.createElement('button');
    button.type='button';
    button.className='aur-translate-button';
    buttonText();
    message=document.createElement('small');
    message.className='aur-translation-status';
    message.setAttribute('role','status');
    message.setAttribute('aria-live','polite');
    controls.append(button,message);
    heading.insertAdjacentElement('afterend',controls);
    if (!supported()) {
      button.hidden=true;
      status(localized('Original source descriptions shown. Automatic translation is not supported in this browser.',
        'Originalbeschreibungen angezeigt. Dieser Browser unterstützt keine automatische Übersetzung.'));
    } else {
      activationRequired();
      button.addEventListener('click',()=>{
        button.disabled=true;
        status(localized('Preparing on-device translation…','Lokale Übersetzung wird vorbereitet …'));
        void begin(true);
      });
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
  async function getTranslator(source,target){
    const key=source+'>'+target;
    if(!translatorPromises.has(key)){
      // The browser manages and downloads its own local language packs.
      const start=async()=>{
        const availability=await window.Translator.availability({sourceLanguage:source,targetLanguage:target});
        if(!availability || availability==='unavailable')throw new Error('LANGUAGE_UNSUPPORTED');
        return window.Translator.create({
          sourceLanguage:source,targetLanguage:target,
          monitor(m) {
            m.addEventListener('downloadprogress', event => {
              status(localized('Downloading local translation model…','Lokales Sprachmodell wird geladen …')+
                ' '+Math.round(event.loaded*100)+'%');
            });
          }
        });
      };
      translatorPromises.set(key,start().catch(e=>{translatorPromises.delete(key);throw e;}));
    }
    return translatorPromises.get(key);
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
        activationRequired(localized('Click Translate descriptions to enable translation on this device.',
          'Klicken Sie auf „Beschreibungen übersetzen“, um die lokale Übersetzung zu aktivieren.'));
      }else if(e?.message==='LANGUAGE_UNSUPPORTED') {
        status(localized('Some source languages are not supported; original descriptions remain visible.',
          'Einige Ausgangssprachen werden nicht unterstützt; Originaltexte bleiben sichtbar.'));
      }else {
        activationRequired(localized('Translation could not start. The original description is still available.',
          'Übersetzung nicht verfügbar. Die Originalbeschreibung bleibt sichtbar.'));
      }
    }
  }
  async function render(){
    if(!enabled)return;
    const run=++refreshId;
    const nodes=[...examples()];
    if(!nodes.length)return;
    status(localized('Translating descriptions…','Beschreibungen werden übersetzt …'));
    await Promise.all(nodes.map(node=>convert(node,run)));
    if(run===refreshId && enabled)status(localized(
      'Descriptions translated where supported; original text is available on each translated part.',
      'Beschreibungen werden nach Möglichkeit übersetzt. Der Originaltext bleibt am Produkt abrufbar.'
    ));
  }
  async function begin(fromClick){
    if(!supported())return;
    try{
      await detector();
      enabled=true;
      if(button)button.hidden=true;
      await render();
    }catch(e) {
      activationRequired(localized(
        'Select Translate descriptions to enable your browser’s language model.',
        'Bitte „Beschreibungen übersetzen“ auswählen, um das Sprachmodell zu aktivieren.'
      ));
    }
  }
  function start(){
    makeControls();
    if(!supported())return;
    // Cached browser models can often run without a prompt; the explicit
    // activation button handles browsers requiring a user gesture.
    void begin(false);
    for(const selector of areas){
      const element=document.querySelector(selector);
      if(!element)continue;
      const observer=new MutationObserver(records=>{
        if(records.some(r=>r.addedNodes.length>0))void render();
      });
      observer.observe(element,{childList:true});
    }
    document.addEventListener('aur:lang',()=>{
      buttonText();
      const run=++refreshId;
      for(const el of examples())restore(el);
      if(enabled)void render();
      else activationRequired();
    });
    // Help re-enable translations after a browser requested a gesture.
    document.addEventListener('click', event=>{
      if(!enabled && event.target?.closest?.('.lang button, .aur-browse-chip')){
        void begin(true);
      }
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);
  else start();
})();
