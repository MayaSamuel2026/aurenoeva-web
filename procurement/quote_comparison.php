<?php
declare(strict_types=1);
/**
 * Original-RFQ-linked internal price comparison.
 * Supplier correspondence remains in the private Hostinger mailbox; prices
 * extracted from it are never committed to the public source repository.
 */
function aur_prices_path(string $store,array $case):string {
    $ref=(string)($case['reference']??'');
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))throw new InvalidArgumentException('invalid_price_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.$ref.'-price-comparison.json';
}
function aur_prices_read(string $store,array $case):array {
    $path=aur_prices_path($store,$case);
    if(!is_file($path))return [];
    if(is_link($path))throw new RuntimeException('unsafe_price_file');
    $json=json_decode((string)file_get_contents($path),true);
    if(!is_array($json)||($json['case_reference']??'')!==($case['reference']??'')||
        ($json['source_sha256']??'')!==($case['source_sha256']??''))throw new RuntimeException('price_case_mismatch');
    return is_array($json['offers']??null)?$json['offers']:[];
}
function aur_prices_record(string $store,array $case,array $input):void {
    $row=(int)($input['source_row']??0);$found=false;
    foreach($case['payload']['sourcing_lines']??[] as $line)
        if((int)($line['source_row']??0)===$row){$found=true;break;}
    if(!$found)throw new InvalidArgumentException('price_item_not_in_original_rfq');
    $seller=trim((string)($input['supplier']??''));
    $condition=(string)($input['condition']??'');
    $status=(string)($input['offer_status']??'');
    $price=trim((string)($input['unit_price']??''));
    $currency=strtoupper(trim((string)($input['currency']??'')));
    $reference=trim((string)($input['evidence']??''));
    $notes=trim((string)($input['notes']??''));
    if($seller===''||mb_strlen($seller)>120||mb_strlen($notes)>350||mb_strlen($reference)>350)
        throw new InvalidArgumentException('price_entry_too_long');
    if(!in_array($status,['SUPPLIER_REPLY','ADVERTISED','PENDING','NO_STOCK'],true)||
       !in_array($condition,['NEW','REFURBISHED','USED','UNKNOWN'],true))
        throw new InvalidArgumentException('invalid_price_status');
    if($price!==''&&(!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D',$price)||(float)$price<=0||
       !preg_match('/^[A-Z]{3}$/D',$currency)))throw new InvalidArgumentException('invalid_price_value');
    if($price===''&&$currency!=='')throw new InvalidArgumentException('currency_without_price');
    if($reference===''||!preg_match('~^(?:https://|INBOX\s+UID\s+\d+|Supplier email\b|Email\b|Research lead:)~i',$reference))
        throw new InvalidArgumentException('evidence_reference_required');
    $record=['id'=>bin2hex(random_bytes(12)),'source_row'=>$row,'supplier'=>$seller,
       'condition'=>$condition,'status'=>$status,'price'=>$price,'currency'=>$currency,
       'evidence'=>$reference,'notes'=>$notes,'recorded_at'=>gmdate('c')];
    $path=aur_prices_path($store,$case);$lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('price_lock_failed');
    try {
        $entries=aur_prices_read($store,$case);
        $entries[]=$record;
        aur_vendor_rfq_write($path,['schema'=>'aurenoeva-rfq-price-comparison/1',
            'case_reference'=>$case['reference'],'source_sha256'=>$case['source_sha256'],
            'offers'=>$entries,'updated_at'=>gmdate('c')]);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function aur_prices_delete(string $store,array $case,string $id):void {
    if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new InvalidArgumentException('invalid_price_id');
    $path=aur_prices_path($store,$case);$lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('price_lock_failed');
    try {
        $offers=array_values(array_filter(aur_prices_read($store,$case),
            static fn(array $row):bool=>($row['id']??'')!==$id));
        aur_vendor_rfq_write($path,['schema'=>'aurenoeva-rfq-price-comparison/1',
            'case_reference'=>$case['reference'],'source_sha256'=>$case['source_sha256'],
            'offers'=>$offers,'updated_at'=>gmdate('c')]);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
/** Parse a price from the actual supplier's current email, never from quoted outbound text. */
function aur_prices_number(string $text,string $expression):string {
    if(!preg_match($expression,$text,$m))return '';
    $raw=preg_replace('/\s+/u','',$m[1])??'';
    if(preg_match('/^\d{1,3}(?:\.\d{3})+,\d{2}$/D',$raw))
        $raw=str_replace(',','.',str_replace('.','',$raw));
    elseif(preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D',$raw))
        $raw=str_replace(',','',$raw);
    elseif(preg_match('/^\d{1,3}(?:\.\d{3})+$/D',$raw))
        $raw=str_replace('.','',$raw);
    elseif(preg_match('/^\d+,\d{1,2}$/D',$raw))
        $raw=str_replace(',','.',$raw);
    return preg_match('/^\d+(?:\.\d{1,2})?$/D',$raw)?$raw:'';
}
function aur_prices_live_mail(string $store,array $case):array {
    if(($case['reference']??'')!=='AUR-UZ-1A04A7909185FACD')return [];
    $path=aur_private_directory($store,'supplier-rfqs').'/'.$case['reference'].'-mail-prices-cache.json';
    if(is_file($path)&&!is_link($path)&&time()-(int)filemtime($path)<1800){
        $saved=json_decode((string)@file_get_contents($path),true);
        if(is_array($saved)&&isset($saved['offers'])&&is_array($saved['offers']))return $saved['offers'];
    }
    $definitions=[
       [84,24,'sky-optic.de','Sky Optic','USED','Huvitz HRK-1 · 2019','EUR','/Preis:\s*([\d.,]+)\s*€/u'],
       [80,25,'remma.fr','REMMA','UNKNOWN','Huvitz HNT-1 · requested new; seller has not reconfirmed condition · ex. VAT · Germany 3–4 weeks free or express €199 / 10 business days','EUR','/price is confirmed at\s*\*?€\s*([\d,.]+)/i'],
       [81,32,'sakomed.com','SakoMed','REFURBISHED','Dräger Savina · production year and export not confirmed','USD','/Refurbished:\s*\$([\d,]+)/i'],
       [81,32,'sakomed.com','SakoMed','USED','Alternative used Savina; RFQ requests refurbished 2010–2013','USD','/Working\s+USED:\s*\$([\d,]+)/i'],
       [82,26,'uandiopticinstrument.com','U & I Optic Instrument','USED','Octopus 600 · 2018 · printer/table · video received; no price in supplier reply','',''],
       [79,5,'bms-ultraschall.de','BMS Ultraschall','UNKNOWN','Samsung V8 · requested callback; configuration and price pending','',''],
       [74,22,'mediworks.biz','MediWorks','NEW','S260S · referred German distributor Schneider Optoshop; no direct quotation','','']
    ];
    $byUid=[];$offers=[];
    foreach($definitions as $entry){
        [$uid,$row,$domain,$seller,$condition,$note,$currency,$pattern]=$entry;
        if(!array_key_exists($uid,$byUid)){
            try{
                $parsed=aur_mail_parse(aur_mailcenter_source('INBOX',(string)$uid));
                $byUid[$uid]=['text'=>(string)($parsed['text']??''),'from'=>(string)($parsed['from']??'')];
            }catch(Throwable $e){$byUid[$uid]=null;}
        }
        $mail=$byUid[$uid];
        if(!is_array($mail)||stripos($mail['from'],$domain)===false)continue;
        $body=preg_split('/(?:\r?\n(?:On (?:Tue|Wed|Thu|Fri|Mon|Sat|Sun)|Am Di\.|Le AURENOEVA Purchasing|AURENOEVA Purchasing schreef|> Dear|-----Original Message-----))/i',$mail['text'],2)[0]??'';
        $price=$pattern!==''?aur_prices_number($body,$pattern):'';
        if($pattern!==''&&$price==='')continue;
        $offers[]=['source_row'=>$row,'supplier'=>$seller,'status'=>'SUPPLIER_REPLY',
            'condition'=>$condition,'price'=>$price,'currency'=>$price!==''?$currency:'',
            'evidence'=>'INBOX UID '.$uid,'notes'=>$note,
            'recorded_at'=>'2026-11-03','id'=>'inbox-'.$uid.'-'.$condition];
    }
    if($offers) {
        $temporary=$path.'.'.bin2hex(random_bytes(5)).'.tmp';
        $json=json_encode(['offers'=>$offers,'at'=>gmdate('c')],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        if(@file_put_contents($temporary,$json,LOCK_EX)!==false){@chmod($temporary,0600);@rename($temporary,$path);}
    }
    return $offers;
}
function aur_prices_public_sources(array $case):array {
    if(($case['reference']??'')!=='AUR-UZ-1A04A7909185FACD')return [];
    // URLs and advertised prices are public research leads, not supplier quotations.
    return [
       6=>[['Medical Business Community — Huons MT-5000S','https://mbcmed.ru/catalog/moechnye_mashiny_dlya_endoskopov/ustanovka_dlya_moyki_gibkikh_endoskopov_mt_5000s_77529935/','','','UNKNOWN','Exact MT-5000S single-endoscope washer product entry; local listing only, new stock and export not confirmed']],
       10=>[['RF-Magic II producer','https://rf-magic.ru/','RUB','980000','NEW','Producer published RF-Magic II price; country-specific sale and export eligibility unconfirmed']],
       25=>[['Universal Medisystems — Mindray uMED 20','https://universalmedisystems.com/umed-20/','','','UNKNOWN','Exact uMED 20 monitor-defibrillator product; no current supplier price or stock confirmation']],
       37=>[['Teh-Med — Kler KGEM-01 chair','https://teh-med.ru/kreslo_ginekologisheskoe_kgem_01_1_7','RUB','313000','NEW','Advertised KGEM-01 3-electric-actuator chair; confirm specified upholstery, accessories, export eligibility']],
       38=>[['CISA Healthcare steam sterilizers','https://cisagroup.it/en/high-temperature-steam-sterilizers/','','','NEW','Representative hospital CSSD sterilizer manufacturer; no precise requested model, capacity or installation specified']],
       39=>[['Memmert SN260 medical dry-heat sterilizer','https://www.memmert.com/en/products/medical-devices/steriliser/sn260-2','','','NEW','Example 256 L +250°C dry-heat medical sterilizer; compatibility with requested specification must be confirmed']],
       40=>[['JeCoNet Germany ASP STERRAD 100NX','https://www.jeconet.de/en/products/johnson-und-johnson-asp-sterrad-100nx-sterilisator-plasma','EUR','9500','USED','Listed used hydrogen-peroxide plasma sterilizer; service due; explicit availability and specifications to confirm'],
              ['MedCore Poland Sanqiang SQ-DS60','https://www.medcoregroup.pl/en/product/sanqiang-medical-sq-ds60','','','NEW','Representative new 68 L plasma unit, price on request, configuration comparison only']],
       1=>[['DRGEM manufacturer','https://www.drgemhealthcare.com/','','','NEW','Original 52 kW DIAMOND Classic Expert configuration; manufacturer reply pending compliance details']],
       2=>[['Anthos A5 manufacturer','https://www.anthos.it/','','','NEW','Specify Continental over-patient configuration, not A5 Cart']],
       26=>[['Mindray manufacturer','https://www.mindray.com/','','','NEW','WATO EX-35 · two systems with 3-gas, compressor and requested options; no seller offer yet']],
       28=>[['Meditsinoff E-8 manufacturer','https://www.medicinoff.ru/category/telezhki_meditsinskie_dlya_transportirovki_patsientov/673/','','','UNKNOWN','Exact model E-8; verify requested 200 mm wheels; some listings differ']],
       35=>[['European Siemens MRI seller','https://www.globalstocks.eu/stock-offers/europe/health-beauty/mri-offers-available-immediately-from-siemens-europe/67858','EUR','165000','USED','Starting advertised used Siemens Magnetom Espree 1.5 T, EXW Europe; other models priced higher; confirm condition, 1.5 T specification, availability']],
       3=>[['Art Medical Suisse Z20','https://www.artmedicalsuisse.ch/listings/8814049-refurbished-samsung-z20-ultrasound','CHF','63500','REFURBISHED','Advertised refurbished 2024 with two probes, not the required four-probe specification']],
       8=>[['DS.Med CystoFiber manufacturer','https://ds-med.info/','','','UNKNOWN','Exact CystoFiber product family; request 4.9/2.2 configuration']],
       9=>[['DS.Med DS.Vision manufacturer','https://ds-med.info/','','','UNKNOWN','Manufacturer family; confirm FHD 3-in-1 and light-guide supply'],
              ['R-Medical FHD 3-in-1','https://run-med.ru/products/ds-vision-fhd-3-in-1','','','UNKNOWN','Specific listed product; quotation on request']],
       12=>[['DS.Med DS.Surg 150','https://ds-med.info/electrosurgical-units.html','','','NEW','Manufacturer exact model; also item 22, check whether separate units are needed']],
       13=>[['LAKHTA-MILON manufacturer','https://www.milon.ru/index.phtml?tid=131','','','UNKNOWN','Manufacturer product family; confirm proctology set and compliance']],
       18=>[['Vidacom VS-2000','https://vidacom.ru/katalog-tovarov/oftalmologicheskie_kombajny/vs-2000-rabochee-mesto-oftal-mologa-s-prinadlezhnostyami/','RUB','299000','UNKNOWN','Advertised price; Russian source and export feasibility unconfirmed']],
       22=>[['DS.Med DS.Surg 150','https://ds-med.info/electrosurgical-units.html','','','NEW','Appears also as original item 12; buyer must confirm separate quantity']],
       27=>[['Bimedis Dräger Savina seller marketplace','https://bimedis.com/drager-savina-m38533','','','REFURBISHED','Multiple used/refurbished offers; target documented 2010–2013 manufacturing year']],
       31=>[['Goldident GD-0046 exact set','https://goldident.ru/catalog/komplekty-oborudovanie-dlya-rentgenologii/komplekt-oborudovaniya-gd-0046/','RUB','362000','UNKNOWN','Published exact part number; Russian seller and export feasibility unconfirmed']],
       19=>[['Sky Optic via Bimedis','https://bimedis.com/a-item/autorefractors-huvitz-hrk-1-1876592','EUR','6490','NEW','Advertised when contacted; new-unit price not reaffirmed']],
       20=>[['REMMA model page','https://remma.fr/en/model/non-contact-tonometer-hnt-1','','','NEW','Supplier reply contains the confirmed price']],
       21=>[['U & I via DOTmed','https://www.dotmed.com/listing/visual-field-analyzer/haag-streit/octopus-600/5625555','EUR','5500','USED','Advertised used 2018; supplier reply did not confirm price']],
       4=>[['BMS via Bimedis','https://bimedis.com/a-item/ultrasound-machines-samsung-v8-2196471','','','NEW','Exact requested probes/options unconfirmed']],
       5=>[['Samsung V6 listing','https://bimedis.com/a-item/ultrasound-machines-samsung-v6-2789720','EUR','40120','NEW','Advertised; three-transducer configuration unconfirmed']],
       7=>[['EVORAY EC-5BD manufacturer','https://evoray.ru/ec-5bd/','RUB','1650000','NEW','Russian published asking price; trade/export feasibility unconfirmed']],
       11=>[['SakoMed LS10 product listing','https://sakomed.com/products/electrosurgical-units/covidien-valleylab-ls10-vessel-sealing-generator/','USD','12999','UNKNOWN','Published asking price; model VLLS10GEN and stock need confirmation']],
       14=>[['PENTAX VNL9-CP official catalogue','https://webcatalogue.mypentaxmedical.eu/ccrz__ProductDetails?cclcl=en_GB&sku=84678','','','UNKNOWN','Scope only; CP-1000, monitor and trolley separate'],
              ['JR Medical VIVIDEO supplier','https://jrmedical.co.nz/pentax-ent/','','','UNKNOWN','Identified contact sales@jrmedical.co.nz; bundle to confirm']],
       16=>[['Heinemann MODULA Europa','https://www.heinemann-ent.de/en/behandlungseinheiten/modula-europa/','','','NEW','Manufacturer product route; requested code/chair/endoscopy configuration to confirm']],
       17=>[['MediWorks / Dixion S260S','https://www.mediworks.biz/','','','NEW','Manufacturer directs to as@optoshop.de; previous mail typo as@optishop.de bounced']],
       23=>[['POLYUS-2M producer','https://polus2m.ru/apparaty-magnitoterapii/polyus-2m-apparat-magnitoterapii/','RUB','277420','UNKNOWN','Domestic advertised price; confirm model and export compliance']],
       24=>[['Neurosoft Neuron-Spectrum-1','https://neurosoft.com/en/catalog/eeg/neuron-spectrum-1','','','NEW','Manufacturer; EEG/EMG/evoked-potential capabilities to confirm']],
       29=>[['Neurosoft Spiro-Spectrum','https://neurosoft.com/en/catalog/spirometers/spiro-spectrum','','','NEW','Manufacturer product lead; quantities two']],
       30=>[['Vatech Green 16 SP / Dentreal','https://www.dentrealstore.com/en-de/products/vatech-pax-i3d-green-16-sp-cbct-panoramic-imaging-16-9-fov','USD','115501','NEW','Advertised with VAT; confirm cephalostat and 16×9 configuration']],
       32=>[['Duo-Med Skema 6','https://www.duo-med.com/c/behandlungseinheiten','EUR','37980','NEW','From-price only; requested SD-9099 configuration not confirmed']],
       33=>[['Duo-Med Skema 8','https://www.duo-med.com/c/behandlungseinheiten','EUR','44540','NEW','From-price only; requested 6X-9056 configuration not confirmed']],
       34=>[['DentrealStore Euronda 24 L set','https://www.dentrealstore.com/en-de/products/euronda-e8-autoclave-24-l-aquadist-euroseal-set','USD','4713','NEW','Advertised with VAT; exact Euroseal 2001 Plus variant unconfirmed']],
       36=>[['Embra AC300 SKU 013330','https://riunitidentaliembra.it/prodotto/ac300-compressore-cattani-tre-cilindri-con-essicatore/','EUR','3230.25','NEW','Advertised excluding VAT; confirm full receiver/dryer specs'],
              ['Dental Edge UK AC300','https://www.dentaledgeuk.co.uk/products/cattani-ac300-4-6-surgeries-compressor','GBP','4386','NEW','Advertised excluding VAT; seller requests stock confirmation']]
    ];
}
/** Alternative options are shown on their own original source rows, not as duplicate buyer purchases. */
function aur_prices_alternative_sources(array $line):array {
    $desc=(string)($line['description_original']??'');
    $case=[
       ['/MT[\s.-]*5000L/iu',['Huons MT-5000L two-endoscope washer','https://mbcmed.ru/catalog/moechno_dezinfektsionnye_mashiny/ustanovka_dlya_moyki_gibkikh_endoskopov_mt_5000l/','UNKNOWN','Huons two-endoscope alternative, model confirmation and export pending']],
       ['/СПДС[\s.-]*5[\s.-]*Ш|SPDS[\s.-]*5[\s.-]*SH/iu',['Meditronics SPDS-5-Sh endoscope cabinet','https://meditronics.ru/product/shkaf-dlya-hraneniya-sterilnyh-endoskopov-spds-5-sh','UNKNOWN','Exact five-endoscope alternative cabinet; stock and export unconfirmed']],
       ['/ЦуО[\s.-]*ВС[\s.-]*11|TsUO[\s.-]*VS[\s.-]*11/iu',['PROFMT TsUO-VS-11 cystourethroscope','https://www.profmt.ru/catalog/view/endoskopy/tcuo-vs-11/','UNKNOWN','Exact-model Russian-listed alternative; optical tube and sheath configurations differ']],
       ['/KLEVER|УОРЛ[\s.-]*1/iu',['DV-LAB KLEVER UORL-1 ENT workstation','https://dv-lab.ru/oborudovanie/meditsinskoe-oborudovanie/lor-combine/lor-kombain-yorl-1yorl-2-mk-klever/','UNKNOWN','Requested alternative KLEVER UORL-1; ensure complete 4-endoscope kit']],
       ['/UE[\s.-]*3000/iu',['MEDSTAR UE-3000 producer','https://www.medstar.co.kr/en/products/product/read.jsp?no=70&reqPageNo=1&scategory_fk=1','NEW','Korean manufacturer model-specific ENT workstation alternative']]
    ];
    foreach($case as [$pattern,$item]) if(preg_match($pattern,$desc)===1)
        return [[$item[0],$item[1],'','',$item[2],$item[3]]];
    return [];
}

function aur_prices_panel(string $store,array $case,string $csrf):void {
    $lines=(array)($case['payload']['sourcing_lines']??[]);
    $manual=aur_prices_read($store,$case);
    try{$recorded=aur_reply_read($store,$case);}catch(Throwable $e){$recorded=[];}
    $offers=$manual;
    foreach($recorded as $entry)if(is_array($entry)){
        $offers[]=['source_row'=>(int)($entry['source_row']??0),
           'supplier'=>(string)($entry['supplier']??''),'condition'=>'UNKNOWN',
           'status'=>'MANUAL_RFQ_REPLY','price'=>(string)($entry['unit_price']??''),
           'currency'=>(string)($entry['currency']??''),'evidence'=>(string)($entry['reply_reference']??''),
           'notes'=>(string)($entry['notes']??''),
           'stock_quantity'=>(string)($entry['stock_quantity']??''),
           'delivery_terms'=>(string)($entry['delivery_terms']??''),
           'incoterm'=>(string)($entry['incoterm']??''),
           'freight_terms'=>(string)($entry['freight_terms']??''),
           'warranty_terms'=>(string)($entry['warranty_terms']??''),
           'lead_time'=>(string)($entry['lead_time']??''),
           'id'=>'reply-'.hash('sha256',json_encode($entry))];
    }
    try{$offers=array_merge($offers,aur_prices_live_mail($store,$case));}
    catch(Throwable $e){error_log('AURENOEVA quote extraction: '.get_class($e));}
    $public=aur_prices_public_sources($case);
    $byRow=[];foreach($offers as $offer){$row=(int)($offer['source_row']??0);if($row>0)$byRow[$row][]=$offer;}
    $covered=0;$priced=0;
    foreach($lines as $line){
        $i=(int)($line['customer_item_number']??0);$r=(int)($line['source_row']??0);
        if((strtoupper((string)($line['option_type']??'REQUESTED'))!=='ALTERNATIVE'&&isset($public[$i]))||isset($byRow[$r]))$covered++;
        foreach($byRow[$r]??[] as $o)if(($o['price']??'')!==''&&($o['status']??'')==='SUPPLIER_REPLY'){$priced++;break;}
    }
    ?>
    <section class="card" id="aur-quote-comparison" style="margin-top:16px;border:1px solid #bad4c5">
      <h2>Price comparison · original RFQ</h2>
      <p class="muted">Every original line retains its buyer item, source row, quantity and alternative status. Supplier replies, published asking prices and missing quotations are separated. Currencies and equipment conditions are never combined into misleading ranges.</p>
      <p><strong><?=count($lines)?> original entries</strong> · <?= $priced ?> entries with priced supplier replies · <?= $covered ?> entries with individual source or reply evidence. Remaining products stay visible for the next supplier search.</p>
      <?php if(isset($_GET['price_saved'])):?><p role="status">Supplier price record saved in this case's private comparison ledger.</p><?php endif;?>
      <?php if(isset($_GET['price_deleted'])):?><p role="status">Selected price entry removed from the comparison. Original RFQ and supplier correspondence are unchanged.</p><?php endif;?>
      <?php if(($case['reference']??'')==='AUR-UZ-1A04A7909185FACD'):
        $priorityLeads=[
          ['Huvitz HRK-1','Sky Optic','NEW','https://bimedis.com/a-item/autorefractors-huvitz-hrk-1-1876592','Published EUR 6,490 new-unit listing; not a confirmed supplier quotation'],
          ['Huvitz HNT-1','REMMA','NEW','https://remma.fr/en/model/non-contact-tonometer-hnt-1','Supplier reply in mailbox; review price, condition and delivery against original message'],
          ['NIDEK LM-7P','Labaid','USED','','Supplier lead; stock, price and delivery not verified'],
          ['Tomey RT-700','VisionCare','NEW','','Supplier lead; stock, price and delivery not verified']
        ];
      ?>
      <details open style="margin:12px 0"><summary><strong>Priority supplier follow-up · 4 equipment leads</strong></summary>
      <p class="muted">Evidence-backed sourcing leads are distinct from confirmed offers. Match to the original RFQ line before recording a quotation. Supplier replies and terms are recorded in the private comparison ledger below.</p>
      <div class="scroll"><table><thead><tr><th>Equipment / RFQ association</th><th>Supplier</th><th>Evidence and condition</th><th>Next confirmation</th></tr></thead><tbody>
      <?php foreach($priorityLeads as [$model,$supplier,$condition,$url,$basis]):
        $matches=[];foreach($lines as $line)if(str_contains(preg_replace('/[^A-Z0-9]/','',strtoupper((string)($line['description_original']??'')))??'',preg_replace('/[^A-Z0-9]/','',strtoupper($model))??'') )$matches[]=$line;
      ?>
      <tr><td><b><?=aur_escape($model)?></b><small style="display:block"><?=aur_escape($matches?implode(', ',array_map(static fn($l)=>'#'.(int)$l['customer_item_number'].' / row '.(int)$l['source_row'],$matches)):'No exact model match in this RFQ — association pending')?></small></td>
      <td><?=aur_escape($supplier)?></td><td><?=aur_escape($condition)?> · <?=aur_escape($basis)?><?php if($url!==''):?> <a href="<?=aur_escape($url)?>" target="_blank" rel="noopener noreferrer">Source</a><?php endif;?></td>
      <td>
      <?php if(count($matches)===1):
        $row=(int)$matches[0]['source_row'];$already=false;
        foreach($byRow[$row]??[] as $existing)if(strcasecmp((string)($existing['supplier']??''),$supplier)===0)$already=true;
      ?>
      <?php if($already):?><b>Recorded in this RFQ's private offer ledger</b>
      <?php else:?>
      <form action="/procurement/" method="post">
        <input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
        <input type="hidden" name="case_ref" value="<?=aur_escape((string)$case['reference'])?>">
        <input type="hidden" name="source_row" value="<?=$row?>">
        <input type="hidden" name="supplier" value="<?=aur_escape($supplier)?>">
        <input type="hidden" name="condition" value="<?=aur_escape($condition)?>">
        <input type="hidden" name="offer_status" value="PENDING">
        <input type="hidden" name="evidence" value="<?=aur_escape($url!==''?$url:'Research lead: supplied company and model; independent evidence pending')?>">
        <input type="hidden" name="notes" value="<?=aur_escape($basis)?>">
        <button type="submit" name="save_price_offer" value="1">Track this supplier against RFQ item</button>
      </form><?php endif;?>
      <?php elseif(!$matches):?><small>Exact model not found in this RFQ; do not misassociate to unrelated product</small>
      <?php else:?><small>Multiple matching original lines; select the precise source row in the form below</small><?php endif;?>
      </td></tr>
      <?php endforeach;?></tbody></table></div></details>
      <?php endif;?>
      <div class="scroll"><table><thead><tr><th>RFQ item / source row</th><th>Requested model / part number and specification</th><th>Qty</th><th>Supplier offers &amp; price basis</th><th>Specific product sources</th></tr></thead><tbody>
      <?php foreach($lines as $line):
         $item=(int)($line['customer_item_number']??0);$row=(int)($line['source_row']??0);
         $option=strtoupper((string)($line['option_type']??'REQUESTED'));
         $targetSources=$option==='ALTERNATIVE'?aur_prices_alternative_sources($line):($public[$item]??[]);
         $priceGroups=[];
         foreach($byRow[$row]??[] as $priceEntry){
           if(($priceEntry['price']??'')===''||!in_array(($priceEntry['status']??''),['SUPPLIER_REPLY','MANUAL_RFQ_REPLY'],true))continue;
           $group=(string)($priceEntry['currency']??'').' | '.(string)($priceEntry['condition']??'UNKNOWN');
           $priceGroups[$group][]=(float)$priceEntry['price'];
         }
      ?>
      <tr>
        <td><b>#<?=$item?></b><small style="display:block">Source row <?=$row?></small><?php if($option==='ALTERNATIVE'):?><small>Alternative only</small><?php endif;?></td>
        <td><?=aur_escape((string)($line['description_original']??''))?></td>
        <td><?=isset($line['quantity'])?(int)$line['quantity']:'To confirm'?></td>
        <td>
          <?php foreach($priceGroups as $basis=>$values): if(count($values)<2)continue; ?>
             <div style="margin:0 0 7px;font-weight:700">Comparable quote range · <?=aur_escape($basis)?>: <?=aur_escape(number_format(min($values),2,'.',','))?>–<?=aur_escape(number_format(max($values),2,'.',','))?></div>
          <?php endforeach; ?>
          <?php if(empty($byRow[$row])):?><span class="muted">No supplier reply priced yet</span><?php else:?>
          <?php foreach($byRow[$row] as $offer):?>
            <div style="padding:6px 0;border-bottom:1px solid #e3eae5">
              <b><?=aur_escape((string)($offer['supplier']??''))?></b>
              <small><?=aur_escape(str_replace('_',' ',(string)($offer['status']??'')))?> · <?=aur_escape((string)($offer['condition']??''))?></small>
              <?php if(($offer['price']??'')!==''):?>
                <strong style="display:block"><?=aur_escape((string)($offer['currency']??''))?> <?=aur_escape(number_format((float)$offer['price'],2,'.',','))?></strong>
              <?php endif;?>
              <small><?=aur_escape((string)($offer['notes']??''))?></small>
              <?php $terms=[];
                foreach(['stock_quantity'=>'Stock quantity','lead_time'=>'Lead time','delivery_terms'=>'Delivery','incoterm'=>'Incoterm','freight_terms'=>'Freight','warranty_terms'=>'Warranty'] as $field=>$label)
                  if((string)($offer[$field]??'')!=='')$terms[]=$label.': '.(string)$offer[$field];
              ?>
              <?php if($terms):?><small style="display:block"><?=aur_escape(implode(' · ',$terms))?></small><?php endif;?>
              <small style="display:block">Evidence: <?=aur_escape((string)($offer['evidence']??''))?></small>
              <?php if(preg_match('/^[a-f0-9]{24}$/D',(string)($offer['id']??''))):?>
              <form method="post" action="/procurement/" style="display:inline"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>"><input type="hidden" name="case_ref" value="<?=aur_escape((string)$case['reference'])?>"><input type="hidden" name="offer_id" value="<?=aur_escape((string)$offer['id'])?>"><button type="submit" name="delete_price_offer" value="1" class="ghost" style="padding:3px 7px;font-size:11px">Remove entry</button></form>
              <?php endif;?>
            </div>
          <?php endforeach; endif;?>
        </td>
        <td>
          <?php foreach($targetSources as $lead):
             [$name,$url,$currency,$value,$condition,$note]=$lead;
          ?>
             <div style="padding:4px 0;border-bottom:1px solid #e3eae5">
              <a href="<?=aur_escape($url)?>" target="_blank" rel="noopener noreferrer"><?=aur_escape($name)?></a>
              <?php if($value!==''):?><small style="display:block">Published asking price: <?=aur_escape($currency.' '.$value)?> · <?=aur_escape($condition)?></small><?php endif;?>
              <small><?=aur_escape($note)?></small>
             </div>
          <?php endforeach;?>
          <?php if(!$targetSources):?><small class="muted"><?= $option==='ALTERNATIVE'?'Separate alternative — exact source to identify':'Exact listing to find; existing worldwide case search remains available' ?></small><?php endif;?>
        </td>
      </tr>
      <?php endforeach;?></tbody></table></div>
      <details style="margin:16px 0"><summary><strong>Add a supplier quotation or amend the comparison</strong></summary>
        <p class="muted">Use the exact price and currency from the supplier. Keep different conditions/configurations as separate offers. Records are private and are not forwarded to the buyer or automatically sent to suppliers.</p>
        <form method="post" action="/procurement/" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">
          <input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
          <input type="hidden" name="case_ref" value="<?=aur_escape((string)$case['reference'])?>">
          <label>Original RFQ line<select name="source_row" required>
            <?php foreach($lines as $l):?><option value="<?=(int)$l['source_row']?>">#<?=(int)$l['customer_item_number']?> · Row <?=(int)$l['source_row']?> <?=aur_escape(substr((string)$l['description_original'],0,55))?></option><?php endforeach;?>
          </select></label>
          <label>Supplier<input name="supplier" maxlength="120" required></label>
          <label>Source type<select name="offer_status"><option value="SUPPLIER_REPLY">Supplier reply</option><option value="ADVERTISED">Published listing</option><option value="PENDING">Details pending</option><option value="NO_STOCK">Unavailable</option></select></label>
          <label>Equipment condition<select name="condition"><option value="UNKNOWN">Not confirmed</option><option value="NEW">New</option><option value="REFURBISHED">Refurbished</option><option value="USED">Used</option></select></label>
          <label>Unit price<input name="unit_price" inputmode="decimal" placeholder="e.g. 3190.00" pattern="[0-9]+(\.[0-9]{1,2})?"></label>
          <label>Currency<input name="currency" maxlength="3" placeholder="EUR, USD, GBP"></label>
          <label>Evidence URL / mailbox reference<input name="evidence" maxlength="350" placeholder="INBOX UID 84 or https://…" required></label>
          <label>Condition, delivery or configuration notes<input name="notes" maxlength="350"></label>
          <div style="grid-column:1/-1"><button type="submit" name="save_price_offer" value="1">Save offer to RFQ comparison</button></div>
        </form>
      </details>
      <p class="muted">For the remaining original lines, use “Discover suppliers worldwide for entire case” below. Target the actual manufacturer/model and live-stock listing rather than emailing broad directories. Prices are indicative until exact specification, shipping terms and availability are confirmed.</p>
    </section>
    <?php
}
