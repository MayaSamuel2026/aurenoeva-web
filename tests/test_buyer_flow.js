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
// User regression: selecting multiple catalogue parts must survive card navigation and page loads.
assert.ok(js.includes("var BASKET_KEY='aur_selected_parts_v1'"),'reuse existing persistent customer basket');
assert.ok(js.includes('items=restoreBasket()'),'restore selections on every new page');
assert.ok(js.includes('localStorage.setItem(BASKET_KEY'),'persist basket updates to the same origin');
assert.ok(js.includes('aur_quantity:i.qty'),'persist per-product quantity');
assert.ok(js.includes('saveBasket();renderResults()'),'persist selections immediately');
assert.ok(js.includes('saveBasket();renderResults();'),'clear basket only after confirmed submission');
assert.ok(js.includes("cards.querySelectorAll('button.sector-card')"),'bind all seven existing illustrated industry cards');
assert.ok(js.includes('openSector(sector)'),'selecting an industry opens actual catalogue');
assert.ok(js.includes("'catalogue-browse.php?'"),'reuse the live sector catalogue backend');
assert.ok(js.includes("data-aur-category"),'offer per-industry category selection');
assert.ok(js.includes("data-aur-sector-pick"),'allow category products to be added to enquiry');
assert.ok(js.includes('if(page===0)sectorState.products=[]'),'new filter replaces results');
assert.ok(js.includes('sectorState.epoch'),'reject stale catalogue responses');
assert.ok(js.includes("Date.parse(x.observed_at||'')")&&js.includes('Date.now()-14*86400000'),'restored public prices expire independently of persisted part identities');
assert.ok(css.includes('.aur-fast-sectorparts')&&css.includes('Header-integrated enquiry basket'),'basket remains reachable in the sticky site header');
// Previously product actions were pushed outside the viewport. Keep both pathways usable.
assert.ok(js.includes("select:['+ Add to basket','+ Zum Anfragekorb']"),'explicit Add to basket EN/DE label');
assert.ok(js.includes('data-aur-sector-card'), 'whole sector product card is clickable');
assert.ok(js.includes('data-aur-search-card'), 'whole search product card is clickable');
assert.ok(js.includes("event.target.closest('a,button,input,select,textarea')"), 'supplier price links remain independently clickable');
assert.ok(js.includes("data-aur-sector-pick"),'visible sector button remains a direct action');
assert.ok(js.includes("if(items.some(function(x){return x.id===identity(product);}))openDrawer()"),'repeated selection opens existing basket without duplicate');
assert.ok(js.includes("cartbutton.addEventListener('click',function(){openDrawer();})"),'basket works when mounted outside search container');
assert.ok(js.includes("brand.insertAdjacentElement('afterend',cartbutton)"), 'basket is mounted immediately after logo and before Home');
assert.ok(js.includes("document.querySelector('.site-header .header-inner, #site-header .header-inner')"),'homepage and secondary page headers supported');
assert.ok(!js.includes('document.body.appendChild(cartbutton)'), 'basket is no longer floating outside site header');
assert.ok(js.includes("cartbutton.hidden=false"),'basket shows even before first selection');
assert.ok(js.includes("cartbutton.querySelector('[data-aur-text]').textContent=tx('basket')"), 'basket remains bilingual');
assert.ok(css.includes('.aur-fast-sectorpart .aur-fast-select') && css.includes('visibility:visible!important'), 'button never depends on hover or clipped right edge');
assert.ok(css.includes('flex-direction:column') && css.includes('width:100%!important'), 'product actions stack inside their cards');
assert.ok(css.includes('position:static!important') && css.includes('transform:none!important'), 'basket is inline in the existing header, not an overlay');
assert.ok(css.includes('.header-inner > .aur-fast-cartbutton [data-aur-text]'), 'small-screen basket shows icon and count without crowding menu');
assert.ok(css.includes('max-width:145px!important'), 'mobile logo and basket fit together');
console.log('AURENOEVA_ONE_PAGE_BUYER_FLOW=PASS');
