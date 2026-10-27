'use strict';
// The existing 7 illustrated hero cards are checked by test_catalogue_discovery.js.
// This checks the new SVG-backed secondary discovery tiles without network services.
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const [jsFile,cssFile,svgFile]=process.argv.slice(2);
const js=fs.readFileSync(jsFile,'utf8');
const css=fs.readFileSync(cssFile,'utf8');
const svg=fs.readFileSync(svgFile,'utf8');
const from=js.indexOf('  const industryIcons=['),to=js.indexOf('  const industryDescriptions=[',from);
assert(from>=0&&to>from,'separate industry and product family icon assignments required');
const ctx={};
vm.runInNewContext(js.slice(from,to).replace('const industryIcons=','var industryIcons=').replace('const familyIcons=','var familyIcons='),ctx);
assert.equal(ctx.industryIcons.length,12,'12 industry illustrations');
assert.equal(ctx.familyIcons.length,18,'18 technical product family illustrations');
const expected=[...ctx.industryIcons,...ctx.familyIcons].map(name=>'aur-'+name);
assert.equal(new Set(expected).size,30,'each family/industry has its own unique illustration');
const actual=[...svg.matchAll(/<symbol id="(aur-[a-z]+)"/g)].map(m=>m[1]);
assert.deepEqual(actual,expected,'every icon must exist in the shipped sprite in assignment order');
assert(js.includes('document.createElementNS'),'real accessible SVG icon elements must be created');
assert(js.includes('use.setAttribute(\'href\',asset+icon)'),'each tile must load its distinct symbol');
assert(js.includes("art.setAttribute('aria-hidden','true')"),'decorative icons hidden from assistive technology');
assert(js.includes('industryDescriptions[i]'),'industry cards must show bilingual one-line summaries');
assert(js.includes("families.forEach((item,i)=>familyGrid.append(linkChip(item,familyIcons[i])))"),'product families get corresponding icons');
assert(css.includes('.aur-discovery-art svg'),'the icons must have a consistent miniature size');
assert(css.includes('#0E3A33'),'approved forest-green corporate identity retained');
assert(!/<script|<foreignObject|xlink:href=|href="https?:/i.test(svg),'shipped icon artwork is static and self-contained');
console.log('AURENOEVA_30_CATEGORY_ICONS=PASS');
