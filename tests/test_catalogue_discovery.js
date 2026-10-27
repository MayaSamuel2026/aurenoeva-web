/* Fast release regression for independent cross-industry catalogue navigation. */
'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const [homepagePath,scriptPath]=process.argv.slice(2);
if(!homepagePath||!scriptPath)throw new Error('homepage and discovery script are required');
const html=fs.readFileSync(homepagePath,'utf8');
const js=fs.readFileSync(scriptPath,'utf8');
assert.equal((html.match(/class="sector-card(?: active)?"/g)||[]).length,7,'approved seven photographic cards must remain');
assert.equal((html.match(/id="sectors"/g)||[]).length,1);
assert(html.includes('assets/js/catalogue-discovery.js?v='),'homepage must load new discovery');
assert(html.includes('assets/css/catalogue-discovery.css?v='),'homepage must load new styles');
assert(html.includes('assets/img/v8264/sector-lab.webp'),'approved sector photography must remain');
assert(html.includes('assets/img/v8264/sector-aviation.webp'),'approved sector photography must remain');
assert(js.includes("mode:'exact'"),'search route must allow all-sector search');
assert(!js.includes("args.set('industry'"),'new keyword discovery must not invent sector classification');
assert(js.includes('MutationObserver(relabel)'),'EN/DE manufacturer controls must stay translated');
assert(js.includes("form.addEventListener('submit'"),'manufacturer query must be functional');
assert(js.includes("cards.hidden=key!=='industries'"),'existing photographic cards must remain default tab');
const start=js.indexOf('  const industries=[');
const end=js.indexOf('  const language=',start);
assert(start!==-1&&end>start,'taxonomy arrays present');
const context={};
vm.runInNewContext(js.slice(start,end).replace('const industries=','var industries=').replace('const families=','var families='),context);
assert.equal(context.industries.length,12,'12 supplementary industry categories');
assert.equal(context.families.length,18,'18 cross-industry product families');
for(const [type,values] of Object.entries({industries:context.industries,families:context.families})){
 const seen=new Set();
 for(const group of values){
   assert.equal(group.length,3,'each '+type+' has bilingual labels and an actual search term');
   group.forEach(x=>assert(typeof x==='string'&&x.trim().length>1,'nonempty taxonomy entry'));
   assert(!seen.has(group[0]),'no duplicate '+type+' labels');
   seen.add(group[0]);
   const url=new URL('https://aurenoeva.com/requirement.html?'+new URLSearchParams({mode:'exact',q:group[2]}));
   assert.equal(url.searchParams.get('q'),group[2]);
   assert.equal(url.searchParams.get('industry'),null,'unproven industry must not be applied as exact filter');
 }
}
console.log('AURENOEVA_CROSS_INDUSTRY_DISCOVERY=PASS');
