/* AURENOEVA cross-industry discovery: navigation on the existing catalogue and RFQ.
   No new product identities, claimed inventory, paid gate, or independent search backend. */
(function(){
  'use strict';
  const stage=document.getElementById('sectors');
  const container=stage&&stage.parentElement;
  const intro=container&&container.querySelector('.sector-feature-intro');
  const cards=container&&container.querySelector('.card-grid');
  if(!intro||!cards||document.getElementById('aur-discovery-tabs'))return;
  const industries=[
    ['Oil, Gas & Petrochemicals','Öl, Gas & Petrochemie','oil gas'],
    ['Chemicals & Process Industries','Chemie & Verfahrenstechnik','chemical'],
    ['Pharmaceutical & Biotechnology','Pharma & Biotechnologie','pharma'],
    ['Automotive & Electric Mobility','Automobil & Elektromobilität','automotive'],
    ['Rail & Transportation','Bahn & Verkehrstechnik','rail'],
    ['Food & Beverage','Lebensmittel & Getränke','food'],
    ['Agriculture & Forestry','Land- & Forstwirtschaft','agriculture'],
    ['Mining & Heavy Equipment','Bergbau & Schwertechnik','mining'],
    ['Construction & Building Technology','Bau- & Gebäudetechnik','construction'],
    ['Water, Waste & Environmental','Wasser, Abfall & Umwelttechnik','water'],
    ['Electronics & Semiconductors','Elektronik & Halbleiter','semiconductor'],
    ['Logistics & Warehousing','Logistik & Lagertechnik','logistics']
  ];
  const families=[
    ['Automation & Control Systems','Automation & Steuerungstechnik','PLC'],
    ['Sensors, Test & Measurement','Sensorik, Mess- & Prüftechnik','sensor'],
    ['Electrical & Electronic Components','Elektro- & Elektronikkomponenten','electrical'],
    ['Motors, Drives & Power Transmission','Motoren, Antriebe & Kraftübertragung','motor'],
    ['Pneumatics & Hydraulics','Pneumatik & Hydraulik','hydraulic'],
    ['Pumps, Valves & Fluid Handling','Pumpen, Ventile & Fluidtechnik','pump'],
    ['Bearings, Seals, Gaskets & Fasteners','Lager, Dichtungen & Verbindungselemente','bearing'],
    ['Mechanical Components','Mechanische Komponenten','gearbox'],
    ['Industrial Machines & Machine Tools','Industriemaschinen & Werkzeugmaschinen','machine'],
    ['Process Equipment & Filtration','Prozessanlagen & Filtration','filter'],
    ['Heating, Cooling & HVAC','Heizung, Kühlung & Klimatechnik','HVAC'],
    ['Laboratory & Scientific Equipment','Labor- & Wissenschaftstechnik','chromatography'],
    ['Medical Equipment & Components','Medizintechnik & Komponenten','medical'],
    ['Power Generation & Energy Storage','Energieerzeugung & -speicherung','generator'],
    ['Material Handling & Packaging','Fördertechnik & Verpackung','conveyor'],
    ['Safety & Environmental Equipment','Sicherheits- & Umwelttechnik','safety'],
    ['Industrial Computing & Networks','Industrie-IT & Netzwerke','industrial computer'],
    ['Materials, Chemicals & Consumables','Materialien, Chemikalien & Verbrauchsgüter','chemical']
  ];
  const language=()=>document.documentElement.lang==='de'?'de':'en';
  const phrase=(en,de)=>language()==='de'?de:en;
  const make=(tag,klass)=>{const el=document.createElement(tag);if(klass)el.className=klass;return el};
  const bilingual=(el,en,de)=>{el.dataset.en=en;el.dataset.de=de;el.textContent=phrase(en,de);return el};
  const currentScript=[...document.scripts].find(x=>x.src.includes('catalogue-discovery.js'));
  const rev=currentScript ? new URL(currentScript.src).searchParams.get('v') : '';
  function searchRoute(q){
    const args=new URLSearchParams({mode:'exact',q:String(q).slice(0,120)});
    if(rev)args.set('rev',rev);
    return 'requirement.html?'+args.toString();
  }
  function linkChip(item){
    const a=make('a','aur-discovery-chip');
    a.href=searchRoute(item[2]);
    a.setAttribute('aria-label',phrase('Search catalogue: ','Katalog durchsuchen: ')+phrase(item[0],item[1]));
    bilingual(make('span'),item[0],item[1]);
    const label=make('span');
    bilingual(label,item[0],item[1]);
    const arrow=make('span','aur-discovery-arrow');arrow.setAttribute('aria-hidden','true');arrow.textContent='→';
    a.append(label,arrow);
    return a;
  }
  const tabs=make('div','aur-discovery-tabs');
  tabs.id='aur-discovery-tabs';tabs.setAttribute('role','tablist');
  tabs.setAttribute('aria-label',phrase('Catalogue discovery','Katalog entdecken'));
  const tabNames=[
    ['industries','Industries','Branchen'],
    ['families','Product families','Produktfamilien'],
    ['makers','Manufacturers','Hersteller']
  ];
  const buttons={};
  tabNames.forEach(([key,en,de])=>{
    const button=make('button','aur-discovery-tab');
    button.type='button';button.id='aur-discovery-tab-'+key;
    button.setAttribute('role','tab');button.setAttribute('aria-controls','aur-discovery-'+key);
    bilingual(button,en,de);tabs.append(button);buttons[key]=button;
  });
  intro.insertAdjacentElement('afterend',tabs);
  cards.id='aur-discovery-industries';
  cards.setAttribute('role','tabpanel');cards.setAttribute('aria-labelledby',buttons.industries.id);
  const extra=make('details','aur-discovery-more');
  const summary=make('summary');
  bilingual(summary,'Explore 12 more industries','12 weitere Branchen entdecken');
  extra.append(summary);
  const additional=make('div','aur-discovery-chip-grid');
  industries.forEach(item=>additional.append(linkChip(item)));
  extra.append(additional);
  cards.insertAdjacentElement('afterend',extra);
  const familyPanel=make('div','aur-discovery-panel');
  familyPanel.id='aur-discovery-families';familyPanel.setAttribute('role','tabpanel');
  familyPanel.setAttribute('aria-labelledby',buttons.families.id);
  const familyIntro=make('p','aur-discovery-helper');
  bilingual(familyIntro,'Explore cross-industry components. Each choice searches the existing catalogue; classifications and stock are never assumed.','Branchenübergreifende Komponenten entdecken. Jede Auswahl durchsucht den bestehenden Katalog; Klassifikation und Bestand werden nicht vorausgesetzt.');
  familyPanel.append(familyIntro);
  const familyGrid=make('div','aur-discovery-chip-grid');
  families.forEach(item=>familyGrid.append(linkChip(item)));
  familyPanel.append(familyGrid);
  extra.insertAdjacentElement('afterend',familyPanel);
  const makerPanel=make('div','aur-discovery-panel');
  makerPanel.id='aur-discovery-makers';makerPanel.setAttribute('role','tabpanel');
  makerPanel.setAttribute('aria-labelledby',buttons.makers.id);
  const makerIntro=make('p','aur-discovery-helper');
  bilingual(makerIntro,'Search a manufacturer or brand across all catalogue sectors. Add a part number for a more precise match.','Hersteller oder Marken über alle Katalogbereiche hinweg suchen. Eine Teilenummer verbessert die Treffer.');
  makerPanel.append(makerIntro);
  const form=make('form','aur-discovery-maker-form');
  form.setAttribute('role','search');
  const input=make('input');input.type='search';input.maxLength=120;input.required=true;
  input.setAttribute('aria-label',phrase('Manufacturer or part number','Hersteller oder Teilenummer'));
  input.setAttribute('autocomplete','off');
  const go=make('button');go.type='submit';
  bilingual(go,'Search catalogue →','Katalog durchsuchen →');
  form.append(input,go);makerPanel.append(form);
  const examples=make('p','aur-discovery-examples');
  bilingual(examples,'Examples: Siemens · Festo · ABB · SKF · Bosch Rexroth','Beispiele: Siemens · Festo · ABB · SKF · Bosch Rexroth');
  makerPanel.append(examples);
  familyPanel.insertAdjacentElement('afterend',makerPanel);
  form.addEventListener('submit',event=>{
    event.preventDefault();
    const q=input.value.trim();
    if(q.length>=2)location.assign(searchRoute(q));
    else input.focus();
  });
  function select(key){
    for(const [name,button] of Object.entries(buttons)){
      const active=name===key;
      button.setAttribute('aria-selected',String(active));
      button.tabIndex=active?0:-1;
      button.classList.toggle('active',active);
    }
    cards.hidden=key!=='industries';
    extra.hidden=key!=='industries';
    familyPanel.hidden=key!=='families';
    makerPanel.hidden=key!=='makers';
  }
  tabNames.forEach(([key])=>{
    buttons[key].addEventListener('click',()=>select(key));
    buttons[key].addEventListener('keydown',event=>{
      const idx=tabNames.findIndex(item=>item[0]===key);
      const next=event.key==='ArrowRight'?(idx+1)%tabNames.length:
        event.key==='ArrowLeft'?(idx+tabNames.length-1)%tabNames.length:-1;
      if(next>=0){event.preventDefault();const name=tabNames[next][0];select(name);buttons[name].focus();}
    });
  });
  const relabel=()=>{
    const de=language()==='de';
    input.placeholder=de?'Hersteller, Marke oder Teilenummer eingeben':'Enter manufacturer, brand or part number';
    input.setAttribute('aria-label',de?'Hersteller oder Teilenummer':'Manufacturer or part number');
    tabs.setAttribute('aria-label',de?'Katalog entdecken':'Catalogue discovery');
    container.querySelectorAll('.aur-discovery-chip').forEach(a=>{
      const label=a.querySelector('span');
      if(label)a.setAttribute('aria-label',phrase('Search catalogue: ','Katalog durchsuchen: ')+label.textContent);
    });
  };
  new MutationObserver(relabel).observe(document.documentElement,{attributes:true,attributeFilter:['lang']});
  select('industries');relabel();
})();
