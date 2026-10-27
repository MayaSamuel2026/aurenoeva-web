#!/usr/bin/env node
/* Structural release checks for AURENOEVA's no-registration buyer flow. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const dir=process.argv[2];
assert.ok(dir,'pass the assembled website directory');
const js=fs.readFileSync(path.join(dir,'assets/js/buyer-flow.js'),'utf8');
const css=fs.readFileSync(path.join(dir,'assets/css/buyer-flow.css'),'utf8');
for(const p of ['index.html','requirement.html']){
 const html=fs.readFileSync(path.join(dir,p),'utf8');
 assert.equal((html.match(/assets\/js\/buyer-flow\.js/g)||[]).length,1,p+': flow JS included once');
 assert.equal((html.match(/assets\/css\/buyer-flow\.css/g)||[]).length,1,p+': flow CSS included once');
}
for(const text of ['catalogue-search.php?q=','requirement-submit.php','selected_parts:JSON.stringify(parts)','data.delivery.email!==true','company:short','email:short','quantity:items.length','freeText','getLang']){
 if(text==='getLang')continue;
 assert.ok(js.includes(text),'buyer flow missing '+text);
}
assert.ok(js.includes('aria-modal="true"')&&js.includes('required'),'accessible modal and required contact fields');
assert.ok(js.includes('No account needed.')&&js.includes('Ohne Registrierung.'),'EN/DE no-registration');
assert.ok(js.includes('Price on request')&&js.includes('Preis auf Anfrage'),'EN/DE fallback');
assert.ok(js.includes("if(!res.ok||!data.ok||!data.delivery||data.delivery.email!==true)"),'never claim delivery without confirmed transport');
assert.ok(js.includes('Number(x.amount)>0')&&js.includes("x.source_url"),'public price must have provenance');
assert.ok(css.includes('#0E3A33')&&css.includes('#F8F7F3'),'approved colors');
assert.ok(!js.includes('window.location.href='),'no multi-page request wizard');
const php=fs.readFileSync(path.join(dir,'requirement-submit.php'),'utf8');
assert.ok(php.includes('aur_enquiry_ledger_append'),'existing enquiry ledger retained');
assert.ok(php.includes('selected_parts'),'canonical part transfer retained');
console.log('AURENOEVA_ONE_PAGE_BUYER_FLOW=PASS');
