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
  console.log('AURENOEVA_TRANSLATION_EN_DE_AND_ORIGINAL_PRESERVATION=PASS');
})().catch(err=>{console.error(err);process.exitCode=1;});
