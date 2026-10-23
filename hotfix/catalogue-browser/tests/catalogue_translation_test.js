'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

(async () => {
  const original = 'La detección automática de configuraciones de prueba inadecuadas y condiciones anormales evita costosas desviaciones ✓ Integridad de datos';
  const english = 'Automatic detection of unsuitable test configurations and abnormal conditions prevents costly deviations ✓ Data integrity';
  const german = 'Die automatische Erkennung ungeeigneter Prüfkonfigurationen und ungewöhnlicher Bedingungen verhindert kostspielige Abweichungen ✓ Datenintegrität';
  const attribute = {};
  const description = {
    textContent: original,
    dataset: {},
    isConnected: true,
    lang: '',
    hasAttribute: key => Object.hasOwn(attribute,key),
    getAttribute: key => attribute[key],
    setAttribute: (key,val) => {attribute[key]=val;},
    closest: () => null
  };
  const events = {};
  const document = {
    readyState: 'complete',
    documentElement: {lang:'en'},
    querySelector: () => null,
    querySelectorAll: () => [description],
    addEventListener: (type,fn) => {events[type]=fn;}
  };
  const window = {
    LanguageDetector: {create: async () => ({
      detect: async () => [{detectedLanguage:'es',confidence:0.98}]
    })},
    Translator: {
      availability: async ({sourceLanguage,targetLanguage}) => {
        assert.equal(sourceLanguage,'es');
        assert.ok(['en','de'].includes(targetLanguage));
        return 'available';
      },
      create: async ({targetLanguage}) => ({
        translate: async value => {
          assert.equal(value,original);
          return targetLanguage==='de'?german:english;
        }
      })
    }
  };
  class MutationObserver { observe() {} }
  const script = fs.readFileSync(path.join(__dirname,'..','catalogue-translation.js'),'utf8');
  vm.runInNewContext(script,{document,window,MutationObserver,console},
    {filename:'catalogue-translation.js'});
  const settle=async()=>{for(let i=0;i<25;i++)await Promise.resolve();};
  await settle();
  assert.equal(description.textContent,english);
  assert.equal(description.getAttribute('data-aur-original'),original);
  assert.equal(description.lang,'en');
  document.documentElement.lang='de';
  events['aur:lang']();
  await settle();
  assert.equal(description.textContent,german);
  assert.equal(description.getAttribute('data-aur-original'),original);
  assert.equal(description.lang,'de');
  // A fresh device may need a normal user interaction before Chrome installs
  // its language model. No special "Translate" button must be necessary.
  const delayedAttrs = {};
  const delayedDescription = {
    textContent: original, dataset:{}, isConnected:true, lang:'',
    hasAttribute:key=>Object.hasOwn(delayedAttrs,key),
    getAttribute:key=>delayedAttrs[key],
    setAttribute:(key,val)=>{delayedAttrs[key]=val;},
    closest:()=>null
  };
  const delayedEvents = {};
  const delayedDocument = {
    readyState:'complete',documentElement:{lang:'en'},
    querySelector:()=>null,
    querySelectorAll:()=>[delayedDescription],
    addEventListener:(name,callback)=>{delayedEvents[name]=callback;}
  };
  let activated = false;
  const delayedWindow = {
    LanguageDetector: {create: async()=>{
      if(!activated){
        const denied=new Error('first-time model requires activation');
        denied.name='NotAllowedError';
        throw denied;
      }
      return {detect:async()=>[{detectedLanguage:'es',confidence:0.99}]};
    }},
    Translator: {create:async()=>({translate:async()=>english})}
  };
  vm.runInNewContext(script,{document:delayedDocument,window:delayedWindow,MutationObserver,console},
    {filename:'catalogue-translation.js'});
  await settle();
  assert.equal(delayedDescription.textContent,original);
  assert.ok(typeof delayedEvents.pointerdown==='function');
  activated=true;
  delayedEvents.pointerdown();
  await settle();
  assert.equal(delayedDescription.textContent,english);
  assert.doesNotMatch(script, /Translate descriptions to enable|buttonText\(/);
  // A product's descriptive model title also receives the selected language.
  // The underlying RFQ data is independent of this visible text.
  const titleAttrs={};
  const modelName='Logiciels des séries 4, 5 et 6';
  const title={
    textContent:modelName,dataset:{},isConnected:true,lang:'',
    classList:{contains:part=>part==='aur-browse-model'},
    hasAttribute:key=>Object.hasOwn(titleAttrs,key),
    getAttribute:key=>titleAttrs[key],
    setAttribute:(key,value)=>{titleAttrs[key]=value;},
    closest:()=>null
  };
  const titleEvents={};
  const titleDoc={
    readyState:'complete',documentElement:{lang:'en'},
    querySelector:()=>null,
    querySelectorAll:()=>[title],
    addEventListener:(name,fn)=>{titleEvents[name]=fn;}
  };
  const titleWindow={
    LanguageDetector:{create:async()=>({
      detect:async()=>[{detectedLanguage:'fr',confidence:0.98}]
    })},
    Translator:{
      create:async({sourceLanguage,targetLanguage})=>{
        assert.equal(sourceLanguage,'fr');
        return {translate:async(value)=>{
          assert.equal(value,modelName);
          return targetLanguage==='de'?'Software für die Serien 4, 5 und 6':
            'Software for Series 4, 5 and 6';
        }};
      }
    }
  };
  vm.runInNewContext(script,{document:titleDoc,window:titleWindow,MutationObserver,console},
    {filename:'catalogue-translation.js'});
  await settle();
  assert.equal(title.textContent,'Software for Series 4, 5 and 6');
  assert.equal(title.getAttribute('data-aur-original'),modelName);
  titleDoc.documentElement.lang='de';
  titleEvents['aur:lang']();
  await settle();
  assert.equal(title.textContent,'Software für die Serien 4, 5 und 6');
  assert.equal(title.getAttribute('data-aur-original'),modelName);
  // Mixed English/Japanese and English/Korean specifications retain their
  // model numbers and translate only the embedded foreign-language phrase.
  const mixedAttrs={};
  const mixedText='MSO54B oscilloscope, 4 analog and 最大32(オプション) digital channels';
  const mixedNode={
    textContent:mixedText,dataset:{},isConnected:true,lang:'',
    hasAttribute:key=>Object.hasOwn(mixedAttrs,key),
    getAttribute:key=>mixedAttrs[key],
    setAttribute:(key,val)=>{mixedAttrs[key]=val;},
    closest:()=>null
  };
  const mixedDoc={
    readyState:'complete',documentElement:{lang:'en'},
    querySelector:()=>null,querySelectorAll:()=>[mixedNode],addEventListener:()=>{}
  };
  const mixedWindow={
    LanguageDetector:{create:async()=>({
      detect:async()=>[{detectedLanguage:'en',confidence:0.95}]
    })},
    Translator:{create:async({sourceLanguage,targetLanguage})=>{
      assert.equal(sourceLanguage,'ja');
      assert.equal(targetLanguage,'en');
      return {translate:async()=> 'up to 32 (optional)'};
    }}
  };
  vm.runInNewContext(script,{document:mixedDoc,window:mixedWindow,MutationObserver,console},
    {filename:'catalogue-translation.js'});
  await settle();
  assert.match(mixedNode.textContent,/MSO54B oscilloscope, 4 analog and/);
  assert.match(mixedNode.textContent,/up to 32 \(optional\) digital channels/);
  assert.equal(mixedNode.getAttribute('data-aur-original'),mixedText);
  console.log('AURENOEVA_MIXED_LANGUAGE_MODEL_CODES_PRESERVED=PASS');
  console.log('AURENOEVA_MULTILINGUAL_PRODUCT_TITLES_EN_DE=PASS');
  console.log('AURENOEVA_AUTO_TRANSLATE_AFTER_ORDINARY_GESTURE=PASS');
  console.log('AURENOEVA_TRANSLATION_EN_DE_AND_ORIGINAL_PRESERVATION=PASS');
})().catch(err=>{console.error(err);process.exitCode=1;});
