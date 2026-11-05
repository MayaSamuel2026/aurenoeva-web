<?php
declare(strict_types=1);
/**
 * Research-backed 5 November supplier shortlist for the existing imported RFQ.
 * No sending or public case storage. Uses the canonical case-owned RFQ outbox.
 */
require_once __DIR__.'/urgent_supplier_drafts.php';

function aur_researched_20261105_leads():array {
    return [
        ['id'=>'duomed-skema','name'=>'Duo Med GmbH','email'=>'info@duo-med.de',
         'country'=>'DE','language'=>'de','items'=>[32,33],
         'model'=>'Castellini Skema 6 / Skema 8',
         'request'=>'eine Castellini Skema 6 (1 Stück) sowie zwei Skema 8 Behandlungseinheiten',
         'question'=>'Könnten Sie bitte auch prüfen, ob die Schwebetisch-Ausführungen den gewünschten Konfigurationen SD-9099 (Skema 6) und 6X-9056 (Skema 8) entsprechen?',
         'evidence_url'=>'https://www.duo-med.com/c/behandlungseinheiten/behandlungseinheiten-neu/behandlungseinheiten-castellini/skema-familie',
         'listing'=>'Beide Modelle im Händlerkatalog; Konfigurationscodes noch unbestätigt',
         'source_grade'=>'TWO_EXACT_MODEL_FAMILIES'],
        ['id'=>'mtc-ls10','name'=>'Medical Trade Center Hamburg','email'=>'info@mtc-hamburg.com',
         'country'=>'DE','language'=>'de','items'=>[11],
         'model'=>'Covidien Valleylab LigaSure LS10',
         'request'=>'einen Covidien / Valleylab LigaSure LS10 Generator (VLLS10GEN, 1 Stück)',
         'question'=>'Auf Ihrer Website ist ein gebrauchter LS10 mit Artikelnummer FE012025 als verfügbar aufgeführt. Können Sie Modellkennung, Geräteumfang und Verkaufsbereitschaft bestätigen?',
         'evidence_url'=>'https://mtc-hamburg.com/shop/valleylab-ligasure-ls10-ls-10-gefassversiegelungsgenerator/',
         'listing'=>'Gebrauchter LS10, Artikel FE012025, laut Händlerseite 1 Stück angeboten',
         'source_grade'=>'MODEL_LISTED_STOCK_UNCONFIRMED'],
        ['id'=>'dentalquality-vatech','name'=>'Dental Quality SRL','email'=>'info@dentalquality.be',
         'country'=>'BE','language'=>'en','items'=>[30],
         'model'=>'Vatech Green X 16 · 16 × 9 CBCT',
         'request'=>'one Vatech 16 × 9 CBCT system including a cephalostat',
         'question'=>'Your catalogue lists Green X 16 SP (16 × 9) and Green X 16 SC. Which available configuration includes the requested cephalostat?',
         'evidence_url'=>'https://www.dentalquality.be/shop/abw00003-vatech-green-x-16-sp-16x9-64281',
         'listing'=>'Green X 16 SP 16×9 advertised; cephalostat not confirmed',
         'source_grade'=>'MODEL_SPECIFIC_CONFIGURATION_TO_CONFIRM'],
        ['id'=>'sonomed-samsung','name'=>'SONOMED GmbH','email'=>'info@sonomed.de',
         'country'=>'DE','language'=>'de','items'=>[4,5],
         'model'=>'Samsung V8 / V6 Ultraschall',
         'request'=>'ein Samsung V8 (vier Sonden, Shear Wave, ECG, Heart Assist und alle angefragten Optionen) sowie ein Samsung V6 (drei Sonden, Grundausstattung)',
         'question'=>'Bitte nennen Sie die Preise getrennt nach Modell und bestätigen Sie, ob die genannten Sonden und Softwareoptionen angeboten werden können.',
         'evidence_url'=>'https://sonomed.de/ultraschallgeraete/samsung/',
         'listing'=>'Fachhändler führt Samsung V-Serie einschließlich V6 und V8; Ausstattung offen',
         'source_grade'=>'BOTH_MODEL_FAMILIES_RESELLER'],
        ['id'=>'liamed-neuron','name'=>'LIAMED SRL','email'=>'office@liamed.ro',
         'country'=>'RO','language'=>'en','items'=>[24],
         'model'=>'Neurosoft Neuron-Spectrum-1',
         'request'=>'one Neurosoft Neuron-Spectrum-1 diagnostic system',
         'question'=>'Your listing describes an eight-channel EEG system. Can it be configured for the requested EEG, evoked-potential and EMG examinations? Please itemise any required software and accessories.',
         'evidence_url'=>'https://liamed.ro/electroencefalograf-8-canale-neuronspectrum1-p-42',
         'listing'=>'Specific Neurosoft Neuron-Spectrum-1 product page; EP/EMG configuration not verified',
         'source_grade'=>'EXACT_MODEL_FUNCTIONS_TO_CONFIRM'],
        ['id'=>'ultramedical-v8','name'=>'Ultra Medical GmbH','email'=>'info@ultra-medical.de',
         'country'=>'DE','language'=>'de','items'=>[4],
         'model'=>'Samsung V8 Ultraschallsystem',
         'request'=>'ein Samsung V8 Ultraschallsystem mit vier Schallköpfen, Shear Wave, ECG, Heart Assist und den angefragten Optionen',
         'question'=>'Sie bieten ein generalüberholtes Samsung V8 an. Welche Sonden und Optionen sind verfügbar, und wie sieht die Gerätekonfiguration aus?',
         'evidence_url'=>'https://www.ultra-medical.de/angebote/5470266-ueberholt-samsung-v8-ultraschallsystem',
         'listing'=>'Refurbished V8 offered; probes on request, full specification unconfirmed',
         'source_grade'=>'USED_EXACT_MODEL_OPTIONS_TO_CONFIRM'],
        ['id'=>'eggert-anthos','name'=>'dental EGGERT GmbH','email'=>'anfrage@dental-eggert.de',
         'country'=>'DE','language'=>'de','items'=>[2],
         'model'=>'Anthos A5 Continental',
         'request'=>'eine Anthos A5 Continental Behandlungseinheit mit Schwebetisch',
         'question'=>'Auf Ihrer Website ist eine Anthos A5 Ausstellungseinheit genannt. Ist die gewünschte Continental-Ausführung lieferbar und welche Ausstattung ist im Angebot enthalten?',
         'evidence_url'=>'https://www.praxiseinrichtung-zahnarzt.de/onlineshop/behandlungseinheiten/anthos-/-anthos-a5/',
         'listing'=>'Anthos A5 Händlereintrag, Ausstellungsgerät; Continental-Ausführung zu bestätigen',
         'source_grade'=>'MODEL_DEALER_VARIANT_TO_CONFIRM'],
        ['id'=>'dentalgreen-ac300','name'=>'Dentalgreen','email'=>'info@dentalgreen.it',
         'country'=>'IT','language'=>'en','items'=>[36],
         'model'=>'CATTANI AC300 013330',
         'request'=>'one CATTANI AC300 compressor, article 013330, three cylinders, 45 L tank, integrated dryer',
         'question'=>'Your listing shows the AC300 at EUR 2,790 before VAT. Can you confirm available stock, the required 230 V / 50 Hz version (your listing says 220 V), 238 L/min at 5 bar and warranty?',
         'evidence_url'=>'https://www.dentalgreen.it/it/brand/24192-cattani',
         'listing'=>'CATTANI 013330 at €2,790 excluding VAT; listing indicates 220 V, requested 230 V',
         'source_grade'=>'PRODUCT_LISTED_VOLTAGE_TO_CONFIRM'],
        ['id'=>'primus-v6','name'=>'PrimUS Medical Instruments GmbH','email'=>'kontakt@primus-ultraschall.de',
         'country'=>'DE','language'=>'de','items'=>[5],
         'model'=>'Samsung V6 Ultraschall',
         'request'=>'ein Samsung V6 Ultraschallsystem mit drei Schallköpfen in der Grundkonfiguration',
         'question'=>'Könnten Sie die lieferbare Samsung-V6-Konfiguration einschließlich der drei Sonden und deren Preise nennen?',
         'evidence_url'=>'https://primus-ultraschall.de/',
         'listing'=>'Samsung-Fachhändler nennt V6 ausdrücklich; Angebot und Lagerbestand offen',
         'source_grade'=>'EXACT_MODEL_FAMILY_DEALER'],
    ];
}

function aur_researched_20261105_plans(array $case,?array $selectedLeads=null):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return [];
    $wanted=[2=>'/\bAnthos\b.*\bA5\b/ui',4=>'/\bV8\b/u',5=>'/\bV6\b/u',
        11=>'/\bLigaSure\b.*\bLS10\b/ui',24=>'/\bNeuron[\s.-]*Spectrum[\s.-]*1\b/ui',
        30=>'/\bVatech\b/ui',32=>'/\bSkema\s*6\b/ui',
        33=>'/\bSkema\s*8\b/ui',36=>'/\bCATTANI\b/ui',
        26=>'/\bWATO\s*EX[\s.-]*35\b/ui',
        27=>'/\bSavina\b/ui',
        34=>'/\bEuronda\b.*\bE8\b/ui',
        35=>'/\b1[.,]5\s*T\b.*\bMRI\b/ui'];
    $byItem=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $line){
        if(!is_array($line)||($line['option_type']??'REQUESTED')!=='REQUESTED')continue;
        $n=(int)($line['customer_item_number']??0);
        if($n>0)$byItem[$n][]=$line;
    }
    $seen=[];$out=[];
    foreach(($selectedLeads??aur_researched_20261105_leads()) as $lead){
        $email=strtolower(trim((string)$lead['email']));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||isset($seen[$email])||
            !preg_match('~^https://~',$lead['evidence_url']))
            throw new RuntimeException('researched_supplier_invalid_or_duplicate_contact');
        $seen[$email]=true;$rows=[];
        foreach($lead['items'] as $number){
            $matches=$byItem[$number]??[];
            if(count($matches)!==1 || preg_match($wanted[$number]??'/^$/',
                (string)($matches[0]['description_original']??''))!==1)
                throw new RuntimeException('researched_supplier_original_spec_mismatch_'.$number);
            $line=$matches[0];
            $rows[]=['source_row'=>(int)$line['source_row'],
                'customer_item_number'=>$number,
                'description'=>(string)$line['description_original'],
                'quantity'=>$line['quantity']??null,'option_type'=>'REQUESTED'];
        }
        aur_rfq_assert_no_duplicate_supplier_lines($case,$rows);
        $out[]=['lead'=>$lead,'rows'=>$rows];
    }
    return $out;
}

function aur_researched_20261105_compose(array $case,array $plan):array {
    $lead=$plan['lead'];$de=$lead['language']==='de';
    $vendor=['id'=>'researched-20261105-'.$lead['id'],
        'name'=>$lead['name'],'email'=>$lead['email'],
        'evidence_url'=>$lead['evidence_url']];
    $mail=aur_vendor_rfq_compose($case,$vendor,$plan['rows']);
    if($de){
        $body="Guten Tag,\n\nwir interessieren uns für ".$lead['request']
            ." und sind dabei auf Ihr Angebot für ".$lead['model']." aufmerksam geworden.\n\n"
            .$lead['question']."\n\n"
            ."Über Ihre Preisangabe (mit Währung) und die voraussichtliche Lieferzeit würden wir uns sehr freuen.\n\n"
            ."AURENOEVA ist als Vermittler tätig; der Endkunde kauft bei einer Einigung direkt bei Ihnen. "
            ."Die Geräte sind für ein Krankenhaus vorgesehen. Installation, Einweisung und Wartung übernimmt der Käufer vor Ort.\n\n"
            ."Falls Sie weitere Positionen der beigefügten Geräteliste anbieten können, freuen wir uns auch über einen kurzen Hinweis.\n\n"
            .aur_procurement_signed_closing('de',(string)$lead['email'])."\n";
        $subject='Freundliche Preisanfrage – '.$lead['model'];
    }else{
        $body="Dear Sales Team,\n\nWe came across your listing for ".$lead['model']
            ." and are looking for ".$lead['request'].".\n\n"
            .$lead['question']."\n\n"
            ."Would you kindly share your unit price, currency and estimated dispatch lead time?\n\n"
            ."AURENOEVA acts as an intermediary, not the end purchaser. The customer would purchase directly from you and arrange local installation, training and maintenance for hospital use.\n\n"
            ."If you can also supply any other items from the attached equipment list, we would welcome that information.\n\n"
            .aur_procurement_signed_closing('en',(string)$lead['email'])."\n";
        $subject='Price and delivery enquiry – '.$lead['model'];
    }
    if(preg_match('/Uzbekistan|Usbekistan|Tashkent|AUR-UZ-/ui',$subject.' '.$body))
        throw new RuntimeException('researched_supplier_destination_visible');
    $mail['subject']=$subject;$mail['body']=$body;
    // Retain the original case item and quantity for downstream quotation matching.
    $mail['items']=array_map(static fn(array $row):array=>[
        'source_row'=>(int)$row['source_row'],
        'customer_item_number'=>(int)$row['customer_item_number'],
        'description'=>(string)$row['description'],
        'quantity'=>$row['quantity'],'option_type'=>$row['option_type']
    ],$plan['rows']);
    $mail['language']=$lead['language'];
    $mail['supplier_country']=$lead['country'];
    $mail['listing_claim']=$lead['listing'];
    $mail['listing_evidence_url']=$lead['evidence_url'];
    $mail['source_grade']=$lead['source_grade'];
    $mail['previously_contacted']=false;
    return $mail;
}

function aur_researched_20261105_index(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)
        throw new InvalidArgumentException('researched_supplier_wrong_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-research-20261105.json';
}
function aur_researched_20261105_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $path=aur_researched_20261105_index($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('researched_supplier_index_symlink');
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??'')!=='aurenoeva-researched-suppliers/1'||
        ($data['case_source_sha256']??'')!==($case['source_sha256']??''))
        throw new RuntimeException('researched_supplier_case_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$data['draft_id']);
    $data['messages']=$draft['messages'];$data['status']=$draft['status'];
    return $data;
}
function aur_researched_20261105_prepare(string $store,array $case):array {
    $path=aur_researched_20261105_index($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('researched_supplier_lock_failed');
    try{
        $old=aur_researched_20261105_saved($store,$case);
        if($old!==null)return $old;
        $seen=[];
        foreach(aur_vendor_rfq_list($store,$case) as $history){
            $previous=aur_vendor_rfq_read($store,$case,(string)$history['id']);
            foreach(($previous['messages']??[]) as $message){
                $address=strtolower(trim((string)($message['to']??'')));
                if(filter_var($address,FILTER_VALIDATE_EMAIL))$seen[$address]=true;
            }
        }
        // Also exclude suppliers already addressed through the direct Mail Center.
        if(function_exists('aur_mailcenter_local')){
            foreach(aur_mailcenter_local($store) as $entry){
                $address=strtolower(trim((string)($entry['to']??'')));
                if(filter_var($address,FILTER_VALIDATE_EMAIL))$seen[$address]=true;
            }
        }
        $messages=[];$skipped=[];
        foreach(aur_researched_20261105_plans($case) as $plan){
            $email=strtolower((string)$plan['lead']['email']);
            if(isset($seen[$email])){$skipped[]=$email;continue;}
            $messages[]=aur_researched_20261105_compose($case,$plan);
        }
        if(!$messages)throw new RuntimeException('researched_supplier_all_previously_contacted');
        $id=bin2hex(random_bytes(12));
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),[
            'version'=>'aurenoeva-rfq-outbox/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'destination_country'=>$case['country'],
            'information_only'=>true,'template_version'=>'pricing-rfq-v3',
            'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
            'status'=>'DRAFT_REVIEW',
            'source'=>'TARGETED_RESEARCH_ROUND_2026_11_05',
            'messages'=>$messages]);
        aur_vendor_rfq_write($path,[
            'schema'=>'aurenoeva-researched-suppliers/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'draft_id'=>$id,'supplier_count'=>count($messages),
            'prepared_at'=>gmdate('c'),
            'skipped_existing_recipients'=>$skipped]);
        return aur_researched_20261105_saved($store,$case)
            ??throw new RuntimeException('researched_supplier_readback_failed');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}


/**
 * November 5 second supplier round. Seven sellers have publicly published
 * email addresses; two marketplace-only leads remain manual, never fabricated.
 * This uses the same original buyer rows, supplier-safe PDF, and review/send UI.
 */
function aur_researched_20261105_followup_leads():array {
    return [
      ['id'=>'steri-euronda','name'=>'STERI-TECH GmbH / Steri-Shop','country'=>'DE','language'=>'de',
       'email'=>'info@steri-shop.de','items'=>[34],'model'=>'Euronda E8 24 L / 221855',
       'request'=>'ein Euronda E8 B-Klasse-Autoklav-Set, 24 L, einschließlich Euroseal 2001 Plus und Aquadis Destillierer',
       'question'=>'Sie listen das E8-Starterpaket 221855 mit 4.549 EUR netto und 1–2 Wochen Lieferzeit. Sind Euroseal 2001 Plus und Aquadis enthalten? Falls nicht, würden wir uns über die getrennten Preise und Lieferzeiten freuen.',
       'evidence_url'=>'https://www.steri-shop.com/nach-hersteller/euronda/',
       'listing'=>'E8 starter package 221855; €4,549 net and 1–2 weeks advertised; full three-part set unconfirmed',
       'source_grade'=>'EXACT_MODEL_STOCK_ADVERTISED_SET_INCOMPLETE'],
      ['id'=>'kloess-euronda','name'=>'C. Klöss Dental GmbH & Co. Betriebs KG','country'=>'DE','language'=>'de',
       'email'=>'einrichtung@kloess-dental.de','items'=>[34],'model'=>'Euronda E8 24 L / 115716',
       'request'=>'ein Euronda E8 24 L Sterilisationsset mit Euroseal 2001 Plus und Aquadis Destillierer',
       'question'=>'Ihr E8 24 L (Artikel 115716) ist mit 5.290 EUR netto und drei Tagen Lieferzeit gelistet. Könnten Sie für das komplette dreiteilige Set ein Angebot erstellen und die Einzelpreise nennen?',
       'evidence_url'=>'https://shop.kloess-dental.de/euronda-e8-b-autoklav-24liter-validierbar.html',
       'listing'=>'E8 24 L 115716; €5,290 net and 3-day dispatch listed; extra components not confirmed',
       'source_grade'=>'EXACT_MODEL_ADVERTISED_STOCK_SET_INCOMPLETE'],
      ['id'=>'stuecker-euronda','name'=>'Stücker-MEDESIGN GmbH','country'=>'DE','language'=>'de',
       'email'=>'auftrag@stuecker-med.de','items'=>[34],'model'=>'Euronda E8 24 L / 24-115716',
       'request'=>'ein Euronda E8 24 L Sterilisationsset mit Euroseal 2001 Plus und Aquadis Destillierer',
       'question'=>'Sie bieten den E8 24 L unter Artikel 24-115716 für 4.905 EUR netto mit circa einer Woche Lieferzeit an. Können Sie auch die beiden ergänzenden Geräte anbieten und die jeweiligen Preise nennen?',
       'evidence_url'=>'https://www.stuecker-med.de/Euronda-E8.html',
       'listing'=>'E8 24 L item 24-115716; €4,905 net and ~1 week advertised; extras unconfirmed',
       'source_grade'=>'EXACT_MODEL_LISTING_SET_INCOMPLETE'],
      ['id'=>'pumed-mri','name'=>'P&U Medizintechnik','country'=>'DE','language'=>'de',
       'email'=>'info@pumed.de','items'=>[35],'model'=>'Siemens MAGNETOM Symphony 1.5 T MRI',
       'request'=>'ein Siemens MAGNETOM Symphony 1,5-T-MRT-System oder ein vergleichbares 1,5-T-System',
       'question'=>'Auf Ihrer Seite ist das MAGNETOM Symphony (Baujahr 2001, Revision 2023) für 65.000 EUR netto gelistet. Ist die Anlage weiterhin verfügbar? Bitte nennen Sie den Preis inklusive vorhandener Spulen, den Lieferumfang sowie Demontage-, Transport- und Wiederinbetriebnahmeanforderungen.',
       'evidence_url'=>'https://used-medical-equipment.de/produkt/siemens-magnetom-symphony-a-tim-system-mrt-komplettes-system-baujahr-2001-mri/',
       'listing'=>'Used Siemens Symphony 1.5T, made 2001, last revision 2023; €65,000 net; dismantled and stored',
       'source_grade'=>'EXACT_FIELD_STRENGTH_OLDER_DISMANTLED_SYSTEM'],
      ['id'=>'medicalprovider-ac300','name'=>'Medical Provider S.r.l.','country'=>'IT','language'=>'en',
       'email'=>'info@medicalprovider.it','items'=>[36],'model'=>'CATTANI AC300 / 013330',
       'request'=>'one CATTANI AC300, item 013330, 45 L tank, three cylinders, dryer, 238 L/min at 5 bar',
       'question'=>'Your listing shows EUR 2,930 excluding VAT and a 220 V version supplied on order. Can you confirm whether the exact requested 230 V / 50 Hz version is available, with the stated 3-year warranty and lead time?',
       'evidence_url'=>'https://medicalprovider.it/prodotto/cattani-compressore-ac300/',
       'listing'=>'AC300 item 013330; €2,930 ex VAT; supplied on order; website says 220 V, RFQ requests 230 V',
       'source_grade'=>'EXACT_MODEL_VOLTAGE_MISMATCH_CONFIRM'],
      ['id'=>'hospitalstore-wato','name'=>'Hospital Store / Medsun Biomedical Technologies','country'=>'IN','language'=>'en',
       'email'=>'support@hospitalstore.com','items'=>[26],'model'=>'Mindray WATO EX-35 / HS6218',
       'request'=>'two Mindray WATO EX-35 anaesthesia workstations with 3-gas configuration, compressor, two vaporizer mounts, bypass, AG/O₂ and passive AGSS',
       'question'=>'Your listing HS6218 shows a new WATO EX-35 at INR 1,172,640 including local tax. Can you quote two units with the full requested configuration, identify any options not included, and confirm actual dispatch lead time and supply terms?',
       'evidence_url'=>'https://www.hospitalstore.com/mindray-ex35-anesthesia-machine-price/',
       'listing'=>'WATO EX-35 HS6218, new, ₹1,172,640 incl local tax, 5–7-day domestic estimate; specified package not confirmed',
       'source_grade'=>'EXACT_MODEL_WITH_REQUIRED_OPTIONS_UNCONFIRMED'],
      ['id'=>'bemes-savina','name'=>'Bemes Inc.','country'=>'US','language'=>'en',
       'email'=>'sales@bemeseast.com','items'=>[27],'model'=>'Refurbished Dräger Savina / TD Savina REB',
       'request'=>'one refurbished Dräger Savina ventilator manufactured between 2010 and 2013',
       'question'=>'You list a refurbished Savina under SKU TD Savina REB with a 90-day warranty. Is one unit available with a documented manufacturing year 2010–2013? Please confirm service and functional-test records, accessories, warranty and lead time.',
       'evidence_url'=>'https://bemesonline.com/drager-savina-ventilator/',
       'listing'=>'Refurbished Savina SKU TD Savina REB; 90-day warranty; year and actual stock unconfirmed',
       'source_grade'=>'EXACT_MODEL_REFURBISHED_YEAR_UNKNOWN']
    ];
}
function aur_researched_20261105_followup_manual():array {
    return [
       ['name'=>'IMEXMED / Iulian Lazar','item'=>4,
        'description'=>'Samsung V8 2022: EA2-11AR, CA1-7S, LA2-14A, LA4-18A; used and advertised in stock, configuration and software to verify',
        'url'=>'https://www.dotmed.com/listing/ultrasound-ob-gyn-vascular/samsung/v8/5629172',
        'reason'=>'Seller contact is provided through DOTmed; no publicly verified direct email'],
       ['name'=>'Green Medical Company, Japan','item'=>21,
        'description'=>'Used Haag-Streit Octopus 600; ask seller to verify exact Basic configuration and current availability',
        'url'=>'https://www.green-medical.jp/en/how-to-buy',
        'reason'=>'Seller directs buyers to its website enquiry form; no product-linked direct email verified']
    ];
}
function aur_researched_20261105_followup_index(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)
        throw new InvalidArgumentException('research_followup_wrong_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-followup-20261105.json';
}
function aur_researched_20261105_followup_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $path=aur_researched_20261105_followup_index($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('followup_supplier_index_symlink');
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??'')!=='aurenoeva-followup-suppliers/1'||
       ($data['case_source_sha256']??'')!==($case['source_sha256']??''))
       throw new RuntimeException('followup_supplier_case_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$data['draft_id']);
    $data['messages']=$draft['messages'];$data['status']=$draft['status'];
    return $data;
}
function aur_researched_20261105_followup_prepare(string $store,array $case):array {
    $path=aur_researched_20261105_followup_index($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('research_followup_lock_failed');
    try {
        $old=aur_researched_20261105_followup_saved($store,$case);
        if($old!==null)return $old;
        $already=[];
        foreach(aur_vendor_rfq_list($store,$case) as $history){
            $draft=aur_vendor_rfq_read($store,$case,(string)$history['id']);
            foreach(($draft['messages']??[]) as $message) {
                $address=strtolower(trim((string)($message['to']??'')));
                if(filter_var($address,FILTER_VALIDATE_EMAIL))$already[$address]=true;
            }
        }
        if(function_exists('aur_mailcenter_local')){
            foreach(aur_mailcenter_local($store) as $message) {
                $address=strtolower(trim((string)($message['to']??'')));
                if(filter_var($address,FILTER_VALIDATE_EMAIL))$already[$address]=true;
            }
        }
        $messages=[];$skipped=[];
        foreach(aur_researched_20261105_plans($case,aur_researched_20261105_followup_leads()) as $plan) {
            $address=strtolower($plan['lead']['email']);
            if(isset($already[$address])){$skipped[]=$address;continue;}
            $messages[]=aur_researched_20261105_compose($case,$plan);
        }
        if(!$messages)throw new RuntimeException('research_followup_all_previously_contacted');
        $id=bin2hex(random_bytes(12));
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),[
            'version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'destination_country'=>$case['country'],'information_only'=>true,
            'template_version'=>'pricing-rfq-v3',
            'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
            'status'=>'DRAFT_REVIEW','source'=>'TARGETED_FOLLOWUP_ROUND_2026_11_05',
            'messages'=>$messages]);
        aur_vendor_rfq_write($path,[
            'schema'=>'aurenoeva-followup-suppliers/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'draft_id'=>$id,'supplier_count'=>count($messages),
            'prepared_at'=>gmdate('c'),
            'skipped_existing_recipients'=>$skipped]);
        return aur_researched_20261105_followup_saved($store,$case)
            ??throw new RuntimeException('research_followup_readback_failed');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
