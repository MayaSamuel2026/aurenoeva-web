<?php
declare(strict_types=1);
/**
 * Case-scoped urgent supplier extension for the Uzbekistan RFQ.
 * Uses the existing source-backed supplier directory and original case lines.
 * Preparation is an explicit, authenticated staff POST; it NEVER sends mail.
 * One immutable supplier outbox is written into the established case-owned
 * supplier-rfqs directory and uses the existing SMTP/compliance dispatch path.
 */
require_once __DIR__.'/supplier_rfq.php';
require_once __DIR__.'/supplier_routes.php';

const AUR_URGENT_RFQ_REF='AUR-UZ-1A04A7909185FACD';
const AUR_URGENT_RFQ_VERSION='2026-10-28.1';

/** Same canonical buyer item numbers as the 44-row imported customer schedule. */
function aur_urgent_supplier_targets():array {
    return [
      'global-drgem-global-kr'=>[1],
      'global-medicinos-lt'=>[3,4,5],
      'global-opptica-lv'=>[19,20],
      'global-heinemann-de'=>[16],
      'global-uandi-nl'=>[21],
      'global-pentax-emea-de'=>[14],
      'global-smdmedical-ch'=>[3],
      'global-bhmedical-ae'=>[27],
      'global-phase-us'=>[26],
      'global-sakomed-us'=>[11],
      'global-mandarin-sg'=>[21],
      'global-haag-uk'=>[21]
    ];
}
/** Explicit model checks prevent a wrong customer row being used silently. */
function aur_urgent_expected_model(int $buyerItem):string {
    return match($buyerItem) {
        1 => '/\bDRGEM\b.*\bDIAMOND\b/iu',
        3 => '/\bZ20\b/iu',
        4 => '/\bV8\b/iu',
        5 => '/\bV6\b/iu',
        11 => '/\b(?:LS10|VLLS10GEN|LigaSure)\b/iu',
        14 => '/\b(?:PENTAX|CP[\s.-]*1000|VNL9[\s.-]*CP)\b/iu',
        16 => '/\b(?:Heinemann|MODULA[\s.-]*Europa)\b/iu',
        19 => '/\bHRK[\s.-]*1\b/iu',
        20 => '/\bHNT[\s.-]*1\b/iu',
        21 => '/\bOctopus\s*600\b/iu',
        26 => '/\bWATO[\s.-]*EX[\s.-]*35\b/iu',
        27 => '/\bSavina\b/iu',
        default => throw new InvalidArgumentException('unknown_urgent_buyer_item')
    };
}
function aur_urgent_supplier_plans(array $case):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return [];
    $source=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $line) {
        if(!is_array($line) || ($line['option_type']??'REQUESTED')==='ALTERNATIVE')continue;
        $buyer=(int)($line['customer_item_number']??0);
        $description=(string)($line['description_original']??'');
        if($buyer>0 && $description!=='')$source[$buyer][]=$line;
    }
    $plans=[];$emails=[];
    foreach(aur_urgent_supplier_targets() as $vendorId=>$buyers) {
        $rows=[];$supplier=null;
        foreach($buyers as $buyer) {
            $matches=[];
            foreach(($source[$buyer]??[]) as $candidate)
                if(preg_match(aur_urgent_expected_model($buyer),(string)$candidate['description_original'])===1)
                    $matches[]=$candidate;
            // Buyer item 16 has alternatives; never include an unrelated
            // version or silently add a second unit.
            if(count($matches)!==1)
                throw new RuntimeException('urgent_original_buyer_item_missing_or_ambiguous_'.$buyer);
            $item=$matches[0];
            $found=null;
            foreach(aur_supplier_international_contacts((string)$item['description_original']) as $lead)
                if(($lead['id']??'')===$vendorId){$found=$lead;break;}
            if($found===null)
                throw new RuntimeException('urgent_supplier_model_match_unavailable_'.$buyer);
            if($supplier===null)$supplier=$found;
            elseif(strtolower((string)$supplier['email'])!==strtolower((string)$found['email']))
                throw new RuntimeException('urgent_supplier_contact_mismatch');
            $rows[]=[
                'source_row'=>(int)$item['source_row'],
                'customer_item_number'=>$buyer,
                'description'=>(string)$item['description_original'],
                'quantity'=>$item['quantity']??null,
                'option_type'=>'REQUESTED'
            ];
        }
        if(!is_array($supplier)||filter_var($supplier['email']??'',FILTER_VALIDATE_EMAIL)===false
            || !preg_match('~^https://~', (string)($supplier['url']??'')))
            throw new RuntimeException('urgent_unverified_contact');
        $email=strtolower(trim((string)$supplier['email']));
        if(isset($emails[$email]))throw new RuntimeException('urgent_duplicate_supplier_email');
        $emails[$email]=true;
        $vendor=[
            'id'=>(string)$supplier['id'],
            'name'=>(string)$supplier['name'],
            'email'=>$email,'country'=>(string)$supplier['country'],
            'website'=>(string)parse_url((string)$supplier['url'],PHP_URL_HOST),
            'evidence_url'=>(string)$supplier['url'],
            'match_basis'=>(string)$supplier['grade'],
            'status'=>'PUBLISHED_CONTACT_RESEARCH_CANDIDATE',
            'authorization'=>'NOT_VERIFIED',
            'availability'=>'NOT_VERIFIED',
            'quotation'=>'NOT_RECEIVED'
        ];
        aur_rfq_assert_no_duplicate_supplier_lines($case,$rows);
        $plans[]=['vendor'=>$vendor,'rows'=>$rows,
            'language'=>in_array(strtoupper($vendor['country']),['DE','AT','CH'],true)?'de':'en'];
    }
    return $plans;
}
function aur_urgent_supplier_compose(array $case,array $plan):array {
    $vendor=$plan['vendor'];$rows=$plan['rows'];
    $de=($plan['language']==='de');
    $ref=(string)$case['reference'];
    $message=aur_vendor_rfq_compose($case,$vendor,$rows);
    if($de) {
        $subject='Dringende Preisanfrage | AURENOEVA '.$ref.' | Medizintechnik für Usbekistan';
        $body="Sehr geehrtes Vertriebsteam,\n\n"
            ."für einen konkreten Einkaufsbedarf in Usbekistan bitten wir um eine kurze, unverbindliche Preisauskunft zu den folgenden Geräten. "
            ."Eine Teilantwort ist ausdrücklich willkommen.\n\n"
            ."ANGEFRAGTE GERÄTE – ".$ref."\n".str_repeat('-',55)."\n";
    } else {
        $subject='Urgent equipment price enquiry | AURENOEVA '.$ref.' | Uzbekistan';
        $body="Dear Sales / Export Team,\n\n"
            ."We are sourcing equipment for an active buyer enquiry in Uzbekistan. "
            ."Could you kindly provide indicative pricing and delivery lead times for the exact products below? "
            ."A partial quotation is very welcome.\n\n"
            ."REQUESTED EQUIPMENT – ".$ref."\n".str_repeat('-',55)."\n";
    }
    foreach($rows as $n=>$row) {
        $desc=trim(preg_replace('/\s+/u',' ',(string)$row['description'])??(string)$row['description']);
        $qty=($row['quantity']??null)===null
            ?($de?'zu bestätigen':'to confirm'):(string)$row['quantity'];
        $body.=($n+1).'. '.($de?'Kundenposition ':'Buyer item ')
            .(int)$row['customer_item_number']."\n  ".$desc."\n"
            .'  '.($de?'Menge: ':'Quantity: ').$qty."\n"
            .'  '.($de?'Einzelpreis/Währung: __________  Lieferzeit: __________'
                :'Unit price/currency: __________  Lead time: __________')."\n\n";
    }
    if($de) {
        $body.="Bitte nennen Sie uns vorerst den Einzelpreis mit Währung, die voraussichtliche Lieferzeit, "
            ."den Zustand (neu/gebraucht/generalüberholt) sowie die tatsächliche Lieferbarkeit. "
            ."Falls eine Lieferung nach Usbekistan nicht möglich ist, freuen wir uns über den Kontakt Ihres zuständigen Exportpartners.\n\n"
            ."Eine kurze Antwort innerhalb von zwei Arbeitstagen wäre sehr hilfreich. "
            ."Alternativen bitte separat kennzeichnen; sie sind keine zusätzliche Bestellmenge.\n\n"
            ."Dies ist eine unverbindliche Informationsanfrage und keine Bestellung. "
            ."Jeder spätere Kauf erfolgt unmittelbar zwischen Käufer und Verkäufer und setzt alle erforderlichen Export-, Sanktions-, "
            ."Import- und Medizinprodukteprüfungen voraus.\n\n"
            .aur_procurement_signed_closing('de',(string)$vendor['email'])."\n";
    } else {
        $body.="For now, we need only unit price and currency, estimated lead time, "
            ."equipment condition (new/used/refurbished) and actual availability. "
            ."Please confirm if supply/export to Uzbekistan is possible, or identify your appropriate export partner.\n\n"
            ."A short reply within two working days would be greatly appreciated. "
            ."Please quote alternatives separately; they are not additional confirmed quantities.\n\n"
            ."This is a non-binding information enquiry, not an order. Any subsequent purchase would be made directly "
            ."between buyer and supplier, subject to all applicable sanctions, export, import and medical-device controls.\n\n"
            .aur_procurement_signed_closing('en',(string)$vendor['email'])."\n";
    }
    $message['subject']=$subject;
    $message['body']=$body;
    $message['language']=$de?'de':'en';
    $message['supplier_country']=(string)$vendor['country'];
    $message['source_grade']=(string)$vendor['match_basis'];
    $message['urgent_followup_20261028']=true;
    return $message;
}
function aur_urgent_supplier_index_path(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)
        throw new InvalidArgumentException('wrong_urgent_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-urgent-supplier-index.json';
}
function aur_urgent_supplier_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $file=aur_urgent_supplier_index_path($store,$case);
    if(!is_file($file))return null;
    if(is_link($file))throw new RuntimeException('unsafe_urgent_index');
    $saved=json_decode((string)file_get_contents($file),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($saved) || ($saved['case_source_sha256']??'')!==($case['source_sha256']??'')
        || ($saved['schema']??'')!=='aurenoeva-urgent-suppliers/1')
        throw new RuntimeException('urgent_case_source_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$saved['draft_id']);
    $saved['messages']=$draft['messages'];
    $saved['status']=$draft['status'];
    return $saved;
}
function aur_urgent_supplier_prepare(string $store,array $case):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)throw new InvalidArgumentException('wrong_urgent_case');
    $path=aur_urgent_supplier_index_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('urgent_supplier_lock_unavailable');
    try{
        $old=aur_urgent_supplier_saved($store,$case);
        if($old!==null)return $old; // Idempotent; no duplicate drafts or sends.
        $plans=aur_urgent_supplier_plans($case);
        if(count($plans)!==12)throw new RuntimeException('urgent_supplier_count_mismatch');
        $contacted=[];
        foreach(aur_vendor_rfq_list($store,$case) as $historical) {
            $existing=aur_vendor_rfq_read($store,$case,(string)$historical['id']);
            foreach(($existing['messages']??[]) as $msg) {
                $to=strtolower(trim((string)($msg['to']??'')));
                // Do not initiate a duplicate new RFQ to an existing supplier,
                // including previously attempted or uncertain recipients.
                if(filter_var($to,FILTER_VALIDATE_EMAIL))$contacted[$to]=true;
            }
        }
        $messages=[];$skipped=[];
        foreach($plans as $plan) {
            $to=strtolower((string)$plan['vendor']['email']);
            if(isset($contacted[$to])){$skipped[]=$to;continue;}
            $messages[]=aur_urgent_supplier_compose($case,$plan);
        }
        if(!$messages)throw new RuntimeException('all_urgent_suppliers_already_in_outbox');
        $draftId=bin2hex(random_bytes(12));
        $draft=[
            'version'=>'aurenoeva-rfq-outbox/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'destination_country'=>$case['country'],
            'information_only'=>true,
            'template_version'=>'pricing-rfq-v3',
            'draft_id'=>$draftId,
            'created_at'=>gmdate('c'),
            'approval'=>null,
            'status'=>'DRAFT_REVIEW',
            'source'=>'CURATED_EXACT_MODEL_FOLLOWUP_2026_10_28',
            'messages'=>$messages
        ];
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$draftId),$draft);
        $index=[
            'schema'=>'aurenoeva-urgent-suppliers/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'version'=>AUR_URGENT_RFQ_VERSION,
            'draft_id'=>$draftId,
            'prepared_at'=>gmdate('c'),
            'supplier_count'=>count($messages),
            'skipped_existing_recipients'=>$skipped,
            'origin'=>'Original case source rows + supplier_routes.php curated primary product evidence',
            'stock_confirmed'=>false,
            'export_permitted_confirmed'=>false
        ];
        aur_vendor_rfq_write($path,$index);
        return aur_urgent_supplier_saved($store,$case)??$index;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}


/**
 * Explicit administrator-only recompose of STILL-UNSENT urgent RFQ messages.
 * Preserve original recipients, source-row mappings, previous transport states
 * and any accepted/ambiguous attempt; do not create a new RFQ or send email.
 */
function aur_urgent_supplier_refresh_signatures(string $store,array $case):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)
        throw new InvalidArgumentException('wrong_urgent_case');
    $indexFile=aur_urgent_supplier_index_path($store,$case);
    $lock=@fopen($indexFile.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))
        throw new RuntimeException('urgent_supplier_lock_unavailable');
    try {
        $index=aur_urgent_supplier_saved($store,$case);
        if($index===null)throw new InvalidArgumentException('urgent_supplier_draft_missing');
        $draftId=(string)$index['draft_id'];
        $draftPath=aur_vendor_rfq_path($store,(string)$case['reference'],$draftId);
        $sendLock=@fopen($draftPath.'.lock','c');
        if(!$sendLock||!flock($sendLock,LOCK_EX))
            throw new RuntimeException('urgent_supplier_send_lock_unavailable');
        try {
            $draft=aur_vendor_rfq_read($store,$case,$draftId);
            $byEmail=[];
            foreach(aur_urgent_supplier_plans($case) as $plan)
                $byEmail[strtolower(trim((string)$plan['vendor']['email']))]=$plan;
            $changed=0;$unchangedSent=0;$unmatched=0;
            foreach($draft['messages'] as $i=>$old) {
                if(!is_array($old))continue;
                if(($old['status']??'')!=='DRAFT'
                    ||!empty($old['attempted_at'])
                    ||!empty($old['sent_at'])
                    ||!empty($old['transport_result'])) {
                    $unchangedSent++;continue;
                }
                $email=strtolower(trim((string)($old['to']??'')));
                $plan=$byEmail[$email]??null;
                if($plan===null){$unmatched++;continue;}
                $oldRows=array_map(static fn($line)=>(int)($line['source_row']??0),
                    (array)($old['items']??[]));
                $expectedRows=array_map(static fn($line)=>(int)($line['source_row']??0),
                    $plan['rows']);
                sort($oldRows);sort($expectedRows);
                if($oldRows!==$expectedRows){$unmatched++;continue;}
                $fresh=aur_urgent_supplier_compose($case,$plan);
                // Revision is limited to wording, never routing, status or provenance.
                if(($old['body']??'')!==$fresh['body']
                    ||($old['subject']??'')!==$fresh['subject']) {
                    $draft['messages'][$i]['body']=$fresh['body'];
                    $draft['messages'][$i]['subject']=$fresh['subject'];
                    $draft['messages'][$i]['language']=$fresh['language'];
                    $draft['messages'][$i]['signature_revision']='LEGAL_FOOTER_CITY_V2';
                    $changed++;
                }
            }
            if($changed>0)aur_vendor_rfq_write($draftPath,$draft);
            return [
                'draft_id'=>$draftId,
                'updated_unsent'=>$changed,
                'untouched_attempted'=>$unchangedSent,
                'unmatched_draft_lines'=>$unmatched,
                'total'=>count($draft['messages']??[])
            ];
        }finally{flock($sendLock,LOCK_UN);fclose($sendLock);}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}


/* November sourcing round: concise, destination-neutral first enquiries.
 * A private, case-bound outbox is created only on staff CSRF-protected POST.
 * Manufacturer contact-form leads are shown separately; no synthetic email addresses.
 */
function aur_nov_short_leads():array {
    return [
      ['id'=>'bms-de','name'=>'BMS Ultraschall','email'=>'info@bms-ultraschall.de','country'=>'DE','language'=>'de','items'=>[3,4,5],'subject'=>'Preisanfrage – Samsung Z20 / V8 / V6 Ultraschallgeräte','request'=>'Samsung Z20 (Konfiguration 2, CA1-7S/LA2-14A/EA2-11AR/CMV1-10), V8 (4 Sonden, Scherwelle/ECG/Heart Assist) und V6 (3 Sonden), jeweils 1 Gerät','url'=>'https://www.bms-ultraschall.de/samsung-sonoace-medison'],
      ['id'=>'cattani-it','name'=>'Cattani S.p.A.','email'=>'info@cattani.it','country'=>'IT','language'=>'en','items'=>[36],'subject'=>'Price enquiry – CATTANI dental compressor','request'=>'one CATTANI dental compressor for 3–4 dental units (230 V/50 Hz, dryer, 45 L, 238 L/min at 5 bar; AC 300 or equivalent)','url'=>'https://www.cattani.it/categorie-prodotti/compressori-a-secco-cattani-air-care/'],
      ['id'=>'euronda-it','name'=>'Euronda S.p.A.','email'=>'info@euronda.com','country'=>'IT','language'=>'en','items'=>[34],'subject'=>'Price enquiry – Euronda E8 / Euroseal / Aquadis','request'=>'one set comprising an Euronda E8 24 L Class B autoclave, Euroseal 2001 Plus sealer and Aquadis distiller','url'=>'https://www.euronda.com/'],
      ['id'=>'dentreal-tr','name'=>'Dentrealmarket / Anka Tibbi','email'=>'info@dentrealmarket.com','country'=>'TR','language'=>'en','items'=>[34],'subject'=>'Price enquiry – Euronda E8 / Euroseal / Aquadis','request'=>'one set comprising an Euronda E8 24 L Class B autoclave, Euroseal 2001 Plus sealer and Aquadis distiller','url'=>'https://dentrealmarket.com/'],
      ['id'=>'mediworks-cn','name'=>'Shanghai MediWorks Precision Instruments','email'=>'marketing@mediworks.biz','country'=>'CN','language'=>'en','items'=>[17],'subject'=>'Price enquiry – S260S ophthalmic slit lamp','request'=>'one MediWorks / Dixion S260S ophthalmic slit lamp','url'=>'https://www.mediworks.biz/'],
      ['id'=>'united-cn','name'=>'United Imaging Healthcare','email'=>'inquiries@united-imaging.com','country'=>'CN','language'=>'en','items'=>[35],'subject'=>'Price enquiry – 1.5 T MRI system','request'=>'one new 1.5 T MRI system','url'=>'https://global.united-imaging.com/'],
      ['id'=>'steelco-it','name'=>'Steelco','email'=>'info@steelcogroup.com','country'=>'IT','language'=>'en','items'=>[6,7,38],'subject'=>'Price enquiry – Endoscope reprocessing / CSSD','request'=>'one single-endoscope washer-disinfector, one endoscope drying/storage cabinet, and one CSSD autoclave (quantity to be confirmed); comparable alternatives to the specified models are welcome','url'=>'https://www.steelcogroup.com/'],
      ['id'=>'endomed-de','name'=>'ENDOMED Endoskopie + Hygiene GmbH','email'=>'info@endomed.de','country'=>'DE','language'=>'de','items'=>[6,7],'subject'=>'Preisanfrage – Endoskopaufbereitung / Trocknungsschrank','request'=>'einen Endoskop-Waschdesinfektor für 1 Endoskop und einen Endoskop-Trocknungs-/Lagerschrank, je 1 Stück (auch vergleichbare Alternativen)','url'=>'https://www.endomed.de/'],
      ['id'=>'immed-gb','name'=>'iM Med Ltd','email'=>'sales@im-med.com','country'=>'GB','language'=>'en','items'=>[6,38],'subject'=>'Price enquiry – Endoscope washer / CSSD','request'=>'one single-endoscope washer-disinfector and a CSSD autoclave (quantity to be confirmed); comparable alternatives are welcome','url'=>'https://www.im-med.com/'],
      ['id'=>'sogliq-uz','name'=>"Sog'liq Industriyasi",'email'=>'info@sogliqindustriyasi.uz','country'=>'UZ','language'=>'en','items'=>[26,27,38],'subject'=>'Price enquiry – Mindray / Dräger / CSSD','request'=>'two Mindray WATO EX-35 anaesthesia systems, one refurbished Dräger Savina ventilator (2010–2013), and a CSSD autoclave (quantity to be confirmed)','url'=>'https://sogliqindustriyasi.uz/'],
      ['id'=>'tesla-uz','name'=>'Tesla Medical System','email'=>'sales@teslamed.uz','country'=>'UZ','language'=>'en','items'=>[35],'subject'=>'Price enquiry – 1.5 T MRI system','request'=>'one 1.5 T MRI system','url'=>'https://teslamed.uz/'],
      ['id'=>'remma-fr','name'=>'Remma','email'=>'contact@remma.fr','country'=>'FR','language'=>'en','items'=>[19,20],'subject'=>'Price enquiry – Huvitz HRK-1 / HNT-1','request'=>'one Huvitz HRK-1 autorefractor/keratometer and one HNT-1 non-contact tonometer (state new/used/refurbished condition)','url'=>'https://remma.fr/en/brand/huvitz']
    ];
}
function aur_nov_short_contact_forms():array {
    return [
      ['name'=>'Cefla Medical Equipment','items'=>'2, 32, 33','url'=>'https://www.cefla.com/en/contact/','request'=>'Anthos A5 Continental (1); Castellini Skema 6 SD-9099 (1); Skema 8 6X-9056 (2)'],
      ['name'=>'VATECH Global','items'=>'30','url'=>'https://www.vatechglobal.com/inquiry','request'=>'new 16 × 9 dental CBCT system with cephalostat (1)'],
      ['name'=>'Huvitz','items'=>'19, 20','url'=>'https://huvitz.com/ophthalmology-and-optometry/','request'=>'HRK-1 autorefractor/keratometer (1); HNT-1 non-contact tonometer (1)']
    ];
}
function aur_nov_short_plans(array $case):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF) return [];
    $source=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $line) {
        if(!is_array($line)||($line['option_type']??'REQUESTED')!=='REQUESTED')continue;
        $key=(int)($line['customer_item_number']??0);
        if($key>0)$source[$key][]=$line;
    }
    $plans=[];$known=[];
    foreach(aur_nov_short_leads() as $lead) {
        $to=strtolower($lead['email']);
        if(filter_var($to,FILTER_VALIDATE_EMAIL)===false||isset($known[$to]))throw new RuntimeException('nov_supplier_invalid_or_duplicate_contact');
        $known[$to]=true;$rows=[];
        foreach($lead['items'] as $item) {
            if(count($source[$item]??[])!==1)throw new RuntimeException('nov_supplier_buyer_item_missing_or_ambiguous_'.$item);
            $row=$source[$item][0];
            $rows[]=['source_row'=>(int)$row['source_row'],
               'customer_item_number'=>$item,
               'description'=>(string)($row['description_original']??''),
               'quantity'=>$row['quantity']??null,'option_type'=>'REQUESTED'];
        }
        aur_rfq_assert_no_duplicate_supplier_lines($case,$rows);
        $plans[]=['lead'=>$lead,'rows'=>$rows];
    }
    return $plans;
}
function aur_nov_short_compose(array $case,array $plan):array {
    $lead=$plan['lead'];
    $vendor=['id'=>'nov-20261101-'.$lead['id'],'name'=>$lead['name'],
             'email'=>$lead['email'],'evidence_url'=>$lead['url']];
    $mail=aur_vendor_rfq_compose($case,$vendor,$plan['rows']);
    if($lead['language']==='de') {
        $body="Guten Tag,\n\nwir interessieren uns für ".$lead['request']." und würden uns über ein unverbindliches Angebot freuen.\n\n"
            ."Die Geräte sind für den Einsatz in einem Krankenhaus vorgesehen. Der Käufer übernimmt die Installation, Einweisung und Schulung sowie Wartung und technischen Service vor Ort.\n\n"
            ."Über eine kurze Rückmeldung zu Verfügbarkeit, Preis und Lieferzeit würden wir uns sehr freuen.\n\n"
            ."Herzliche Grüße\n\n"
            ."AURENOEVA Purchasing\nNOEVA Systems e.K.\nMarienburger Straße 16\n56112 Lahnstein, Deutschland\n"
            ."Amtsgericht Koblenz · HRA 23554\ninfo@aurenoeva.com\nwww.aurenoeva.com";
    } else {
        $body="Dear Sales Team,\n\nPlease quote ".$lead['request'].".\n\n"
            ."The equipment is intended for use in a hospital environment. The purchaser will arrange installation, staff training, maintenance and local technical service.\n\n"
            ."Could you confirm availability, unit price and delivery time?\n\n"
            ."Thank you and kind regards,\n\n"
            ."AURENOEVA Purchasing\nNOEVA Systems e.K.\nMarienburger Straße 16\n56112 Lahnstein, Germany\n"
            ."Amtsgericht Koblenz · HRA 23554\ninfo@aurenoeva.com\nwww.aurenoeva.com";
    }
    $mail['body']=$body;$mail['subject']=$lead['subject'];
    $mail['language']=$lead['language'];
    $mail['supplier_country']=$lead['country'];
    $mail['source_grade']='EVIDENCE_BACKED_RESEARCH_CANDIDATE';
    if(preg_match('/Uzbekistan|Usbekistan|AUR-UZ-1A04A7909185FACD/ui',$body.' '.$mail['subject']))
        throw new RuntimeException('destination_exposed_in_short_message');
    return $mail;
}
function aur_nov_short_index_path(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)throw new InvalidArgumentException('wrong_short_supplier_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-nov-short-supplier-index.json';
}
function aur_nov_short_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $path=aur_nov_short_index_path($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('unsafe_short_supplier_index');
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??'')!=='aurenoeva-short-suppliers/1'
       ||($data['case_source_sha256']??'')!==($case['source_sha256']??''))
        throw new RuntimeException('short_supplier_case_source_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$data['draft_id']);
    $data['messages']=$draft['messages'];$data['status']=$draft['status'];
    return $data;
}
function aur_nov_short_prepare(string $store,array $case):array {
    $path=aur_nov_short_index_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('short_supplier_lock_unavailable');
    try {
        $old=aur_nov_short_saved($store,$case);
        if($old!==null)return $old;
        $contacted=[];
        foreach(aur_vendor_rfq_list($store,$case) as $historical) {
            $prior=aur_vendor_rfq_read($store,$case,(string)$historical['id']);
            foreach(($prior['messages']??[]) as $msg) {
                $email=strtolower(trim((string)($msg['to']??'')));
                if(filter_var($email,FILTER_VALIDATE_EMAIL))$contacted[$email]=true;
            }
        }
        $messages=[];$skipped=[];
        foreach(aur_nov_short_plans($case) as $plan) {
            $to=strtolower($plan['lead']['email']);
            if(isset($contacted[$to])){$skipped[]=$to;continue;}
            $messages[]=aur_nov_short_compose($case,$plan);
        }
        if(!$messages)throw new RuntimeException('short_supplier_all_already_contacted');
        $id=bin2hex(random_bytes(12));
        $draft=['version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
           'case_source_sha256'=>$case['source_sha256'],'destination_country'=>$case['country'],
           'information_only'=>true,'template_version'=>'pricing-rfq-v3',
           'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
           'status'=>'DRAFT_REVIEW','source'=>'CURATED_SHORT_ROUND_2026_11_01','messages'=>$messages];
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),$draft);
        $index=['schema'=>'aurenoeva-short-suppliers/1','case_reference'=>$case['reference'],
          'case_source_sha256'=>$case['source_sha256'],'draft_id'=>$id,
          'prepared_at'=>gmdate('c'),'supplier_count'=>count($messages),
          'skipped_existing_recipients'=>$skipped];
        aur_vendor_rfq_write($path,$index);
        return aur_nov_short_saved($store,$case)??$index;
    }finally {flock($lock,LOCK_UN);fclose($lock);}
}

/** Staff-only: refresh the wording of unsent November emails, never an attempted message. */
function aur_nov_short_refresh_unsent(string $store,array $case):array {
    $saved=aur_nov_short_saved($store,$case);
    if($saved===null)throw new InvalidArgumentException('short_drafts_not_saved');
    $id=(string)$saved['draft_id'];
    $path=aur_vendor_rfq_path($store,(string)$case['reference'],$id);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('short_draft_lock_unavailable');
    try {
        $draft=aur_vendor_rfq_read($store,$case,$id);
        if(($draft['source']??'')!=='CURATED_SHORT_ROUND_2026_11_01')
            throw new RuntimeException('wrong_short_draft_source');
        $plans=[];
        foreach(aur_nov_short_plans($case) as $plan)
            $plans[strtolower($plan['lead']['email'])]=$plan;
        $changed=0;$attempted=0;
        foreach($draft['messages'] as $i=>$old) {
            if(($old['status']??'')!=='DRAFT'||!empty($old['attempted_at'])
                ||!empty($old['sent_at'])||!empty($old['transport_result'])
                ||!empty($old['smtp_message_id'])){$attempted++;continue;}
            $plan=$plans[strtolower((string)($old['to']??''))]??null;
            if($plan===null)continue;
            $had=array_map(static fn($x)=>(int)($x['source_row']??0),(array)($old['items']??[]));
            $expected=array_map(static fn($x)=>(int)$x['source_row'],$plan['rows']);
            sort($had);sort($expected);
            if($had!==$expected)continue;
            $new=aur_nov_short_compose($case,$plan);
            if(($new['subject']??'')!==($old['subject']??'')
                ||($new['body']??'')!==($old['body']??'')) {
                $draft['messages'][$i]['subject']=$new['subject'];
                $draft['messages'][$i]['body']=$new['body'];
                $changed++;
            }
        }
        if($changed>0)aur_vendor_rfq_write($path,$draft);
        return ['draft_id'=>$id,'updated_unsent'=>$changed,'untouched_attempted'=>$attempted];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}


/**
 * November 3: verified PUBLIC inventory adverts for the existing Uzbekistan
 * enquiry. This is a new, explicitly user-requested stock-confirmation round;
 * no recipient is sent automatically, including prior contacts.
 * Stock on a listing is not seller-confirmed warehouse stock.
 */
function aur_stock_listing_leads():array {
    return [
      ['id'=>'sky-optic-hrk1','name'=>'Sky Optic','country'=>'DE','language'=>'de',
       'email'=>'info@sky-optic.de','items'=>[19],'model'=>'Huvitz HRK-1',
       'evidence_url'=>'https://bimedis.com/a-item/autorefractors-huvitz-hrk-1-1876592',
       'listing'=>'neu; 6.490 EUR laut Inserat; 12 Monate Garantie',
       'request'=>'ein neues Huvitz HRK-1 Autorefraktometer/Keratometer (1 Stück)',
       'question'=>'Könnten Sie insbesondere den Inseratspreis von 6.490 EUR, den tatsächlichen Lagerbestand und die kurzfristige Versandbereitschaft bestätigen?',
       'previously_contacted'=>false],
      ['id'=>'remma-hnt1','name'=>'Remma','country'=>'FR','language'=>'en',
       'email'=>'contact@remma.fr','items'=>[20],'model'=>'Huvitz HNT-1',
       'evidence_url'=>'https://remma.fr/en/model/non-contact-tonometer-hnt-1',
       'listing'=>'Brand new; 1 unit shown; EUR 10,470 excluding VAT',
       'request'=>'one new Huvitz HNT-1 non-contact tonometer',
       'question'=>'Could you confirm that the advertised one unit at EUR 10,470 excluding VAT is physically in stock, and advise the warehouse location, total price and dispatch lead time?',
       'previously_contacted'=>true],
      ['id'=>'uandi-octopus','name'=>'U and i Optic Instrument','country'=>'NL','language'=>'en',
       'email'=>'info@uandiopticinstrument.com','items'=>[21],'model'=>'Haag-Streit Octopus 600 Basic',
       'evidence_url'=>'https://www.dotmed.com/listing/visual-field-analyzer/haag-streit/octopus-600/5625555',
       'listing'=>'Used 2018 listed EUR 5,500; seller also advertises a new unit',
       'request'=>'one Haag-Streit Octopus 600, Basic configuration',
       'question'=>'Your DOTmed listing shows a used 2018 device at EUR 5,500 and mentions a new unit. Could you confirm which unit is physically available, the Basic configuration, condition, accessories, price and dispatch date? Please provide actual equipment photographs.',
       'previously_contacted'=>true],
      ['id'=>'bms-v8','name'=>'BMS Ultraschall','country'=>'DE','language'=>'de',
       'email'=>'info@bms-ultraschall.de','items'=>[4],'model'=>'Samsung V8',
       'evidence_url'=>'https://bimedis.com/a-item/ultrasound-machines-samsung-v8-2196471',
       'listing'=>'2026 Neugerät; laut Inserat ab Deutschland lagernd; Preis auf Anfrage',
       'request'=>'ein Samsung V8 Ultraschallsystem (1 Stück) mit vier Sonden, Scherwellen-Elastografie, EKG, Heart Assist und allen angefragten Optionen',
       'question'=>'Könnten Sie bestätigen, ob das 2026er Neugerät tatsächlich in Ihrem Lager verfügbar ist und ob die vollständige gewünschte Konfiguration geliefert werden kann? Über Preis, Lagerort und Versandtermin freuen wir uns.',
       'previously_contacted'=>true],
      ['id'=>'sakomed-savina','name'=>'SakoMed','country'=>'US','language'=>'en',
       'email'=>'info@sakomed.com','items'=>[27],'model'=>'Dräger Savina',
       'evidence_url'=>'https://www.dotmed.com/listing/ventilator/draeger/savina/4930251',
       'listing'=>'2 refurbished units advertised; year not listed',
       'request'=>'one refurbished Dräger Savina ventilator manufactured in 2010–2013',
       'question'=>'Your DOTmed listing #4930251 advertises two refurbished Savina ventilators. Is one unit physically available from manufacturing years 2010–2013? Please confirm the manufacture year from the nameplate, technical condition, actual photos, price, warehouse and dispatch time.',
       'previously_contacted'=>true],
      ['id'=>'blackdown-cattani','name'=>'Blackdown Dental','country'=>'GB','language'=>'en',
       'email'=>'info@blackdowndental.co.uk','items'=>[36],'model'=>'CATTANI AC300',
       'evidence_url'=>'https://www.blackdowndental.co.uk/products/cattani-ac300-oil-free-45l-dental-compressor-with-dryer-4-6-surgeries',
       'listing'=>'AC300 unit-only; 45 L; 238 L/min; GBP 3,480.75 excl. VAT; in stock',
       'request'=>'one CATTANI AC300 oil-free dental compressor, 45 L receiver, dryer, three cylinders, 238 L/min at 5 bar, 230 V/50 Hz',
       'question'=>'The advertised unit-only price is GBP 3,480.75 excluding VAT. Is a complete matching unit physically in stock and ready for dispatch? Please confirm accessories, power specification, final price and dispatch time.',
       'previously_contacted'=>false],
      ['id'=>'harwind-wato','name'=>'Harwind','country'=>'UA','language'=>'en',
       'email'=>'info@harwind.com.ua','items'=>[26],'model'=>'Mindray WATO EX-35',
       'evidence_url'=>'https://harwind.ua/en/product/narkozno-dixalnii-aparat-mindray-wato-ex-35',
       'listing'=>'In stock label; 2 units and configuration unconfirmed',
       'request'=>'two Mindray WATO EX-35 anesthesia workstations, each with 3-gas supply, compressor, two vaporizer mounts, bypass, AG/O2 and passive AGSS (0619BPA00229)',
       'question'=>'Your site advertises the WATO EX-35 in stock. Can you confirm that two units in this exact configuration are physically available, with warehouse location, unit prices and dispatch lead time?',
       'previously_contacted'=>false],
      ['id'=>'oftas-hrk1','name'=>'Oftas S.r.l.','country'=>'IT','language'=>'en',
       'email'=>'commerciale@oftas.it','items'=>[19],'model'=>'Huvitz HRK-1',
       'evidence_url'=>'https://www.dotmed.com/listing/autorefractor-keratometer/huvitz/hrk-1/5605457',
       'listing'=>'Used refurbished 2023 unit; 1 advertised; EUR 4,400',
       'request'=>'one Huvitz HRK-1 autorefractor/keratometer',
       'question'=>'Your DOTmed listing #5605457 advertises a refurbished 2023 HRK-1 for EUR 4,400. Is that exact device physically available? Please confirm manufacturing year, price, warranty, photographs, warehouse and dispatch date.',
       'previously_contacted'=>false],
      ['id'=>'dentreal-e8','name'=>'DentrealStore','country'=>'TR','language'=>'en',
       'email'=>'info@dentrealmarket.com','items'=>[34],'model'=>'Euronda E8 24 L set',
       'evidence_url'=>'https://www.dentrealstore.com/en-de/products/euronda-e8-autoclave-24-l-aquadist-euroseal-set',
       'listing'=>'SKU EUR.E824LSET; USD 4,713 inc. tax shown; quantity 100 not verified',
       'request'=>'one complete Euronda E8 24 L Class B autoclave, Euroseal 2001 Plus pouch sealer and Aquadist distiller set',
       'question'=>'Your online listing for EUR.E824LSET shows USD 4,713 and stock availability. Is one complete set physically in stock, specifically with the Euroseal 2001 Plus? Please confirm all included models, the quoted currency/tax treatment, warehouse and dispatch time.',
       'previously_contacted'=>true],
    ];
}
function aur_stock_listing_plans(array $case,?array $roundLeads=null):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return [];
    $lines=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $entry) {
        if(!is_array($entry)||($entry['option_type']??'REQUESTED')!=='REQUESTED')continue;
        $item=(int)($entry['customer_item_number']??0);
        if($item>0)$lines[$item][]=$entry;
    }
    $patterns=[
      3=>'/\bZ20\b/ui',14=>'/\b(?:Pentax|CP[\s.-]*1000|VNL9[\s.-]*CP)\b/ui',
      16=>'/\b(?:Heinemann|Modula[\s.-]*Europa)\b/ui',17=>'/\bS260S\b/ui',
      24=>'/\bNeuron[\s.-]*Spectrum[\s.-]*1\b/ui',
      4=>'/\bV8\b/u',19=>'/\bHRK[\s.-]*1\b/ui',20=>'/\bHNT[\s.-]*1\b/ui',
      21=>'/\bOctopus\s*600\b/ui',26=>'/\bWATO[\s.-]*EX[\s.-]*35\b/ui',
      27=>'/\bSavina\b/ui',34=>'/\bEuronda\b.*\bE8\b/ui',36=>'/\bCATTANI\b/ui'
    ];
    $seen=[];$plans=[];
    foreach(($roundLeads??aur_stock_listing_leads()) as $lead){
        $email=strtolower($lead['email']);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||isset($seen[$email])||
           !preg_match('~^https://~',$lead['evidence_url']))
            throw new RuntimeException('stock_listing_invalid_supplier');
        $seen[$email]=true;
        $rows=[];
        foreach($lead['items'] as $item) {
            $matching=$lines[$item]??[];
            if(count($matching)!==1||!isset($patterns[$item])||
               preg_match($patterns[$item],(string)($matching[0]['description_original']??''))!==1)
                throw new RuntimeException('stock_listing_original_model_mismatch_'.$item);
            $entry=$matching[0];
            $rows[]=['source_row'=>(int)$entry['source_row'],
                'customer_item_number'=>$item,
                'description'=>(string)$entry['description_original'],
                'quantity'=>$entry['quantity']??null,'option_type'=>'REQUESTED'];
        }
        aur_rfq_assert_no_duplicate_supplier_lines($case,$rows);
        $plans[]=['lead'=>$lead,'rows'=>$rows];
    }
    return $plans;
}
function aur_stock_listing_compose(array $case,array $plan):array {
    $lead=$plan['lead'];
    $vendor=['id'=>'stock-20261103-'.$lead['id'],'name'=>$lead['name'],
        'email'=>$lead['email'],'evidence_url'=>$lead['evidence_url']];
    $message=aur_vendor_rfq_compose($case,$vendor,$plan['rows']);
    if($lead['language']==='de'){
        $body="Guten Tag,\n\nwir interessieren uns für ".$lead['request']." und haben Ihr konkretes Lagerangebot entdeckt:\n"
            .$lead['evidence_url']."\n\n".$lead['question']."\n\n"
            ."Die Geräte sind für den Einsatz im Krankenhaus vorgesehen. Der Käufer übernimmt Installation, Schulung, Wartung und technischen Service vor Ort.\n\n"
            ."Vielen Dank für Ihre Unterstützung.\n\nHerzliche Grüße nach "
            .($lead['id']==='sky-optic-hrk1'?'Köln':($lead['id']==='schneider-s260s'?'Ried':'Solingen'))."\n\n"
            ."AURENOEVA Einkauf\nNOEVA Systems e.K.\nMarienburger Straße 16\n56112 Lahnstein\n"
            ."Amtsgericht Koblenz · HRA 23554\ninfo@aurenoeva.com\nwww.aurenoeva.com";
        $subject='Lagerbestands- und Preisanfrage – '.$lead['model'];
    }else{
        $body="Dear Sales Team,\n\nWe saw your specific inventory listing for ".$lead['model'].":\n"
            .$lead['evidence_url']."\n\nWe are looking for ".$lead['request'].".\n\n"
            .$lead['question']."\n\n"
            ."The equipment is intended for a hospital environment. The purchaser will arrange installation, training, maintenance and local technical service.\n\n"
            ."Thank you very much for your assistance.\n\nKind regards,\n\n"
            ."AURENOEVA Purchasing\nNOEVA Systems e.K.\nMarienburger Straße 16\n56112 Lahnstein, Germany\n"
            ."Amtsgericht Koblenz · HRA 23554\ninfo@aurenoeva.com\nwww.aurenoeva.com";
        $subject='Stock confirmation and quotation – '.$lead['model'];
    }
    if(preg_match('/Uzbekistan|Usbekistan|AUR-UZ-1A04A7909185FACD/ui',$body.' '.$subject))
        throw new RuntimeException('stock_listing_destination_in_public_email');
    $message['body']=$body;
    $message['subject']=$subject;
    $message['language']=$lead['language'];
    $message['supplier_country']=$lead['country'];
    $message['listing_claim']=$lead['listing'];
    $message['listing_evidence_url']=$lead['evidence_url'];
    $message['previously_contacted']=$lead['previously_contacted'];
    $message['source_grade']=(string)($lead['source_grade']??'ADVERTISED_IN_STOCK_NOT_SELLER_CONFIRMED');
    return $message;
}
function aur_stock_listing_index_path(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)
        throw new InvalidArgumentException('stock_listing_wrong_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-stock-20261103-index.json';
}
function aur_stock_listing_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $path=aur_stock_listing_index_path($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('stock_listing_unsafe_index');
    $index=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($index)||($index['schema']??'')!=='aurenoeva-stock-suppliers/1'
       ||($index['case_source_sha256']??'')!==$case['source_sha256'])
        throw new RuntimeException('stock_listing_case_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$index['draft_id']);
    $index['messages']=$draft['messages'];$index['status']=$draft['status'];
    return $index;
}
function aur_stock_listing_prepare(string $store,array $case):array {
    $path=aur_stock_listing_index_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('stock_listing_lock_unavailable');
    try {
        $old=aur_stock_listing_saved($store,$case);
        if($old!==null)return $old;
        $plans=aur_stock_listing_plans($case);
        $messages=array_map(static fn($p)=>aur_stock_listing_compose($case,$p),$plans);
        if(count($messages)!==9)throw new RuntimeException('stock_listing_recipient_count');
        $id=bin2hex(random_bytes(12));
        $draft=['version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],'destination_country'=>$case['country'],
            'information_only'=>true,'template_version'=>'pricing-rfq-v3',
            'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
            'status'=>'DRAFT_REVIEW','source'=>'CURATED_STOCK_LISTING_ROUND_2026_11_03',
            'messages'=>$messages];
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),$draft);
        $index=['schema'=>'aurenoeva-stock-suppliers/1','case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],'draft_id'=>$id,
            'prepared_at'=>gmdate('c'),'supplier_count'=>count($messages)];
        aur_vendor_rfq_write($path,$index);
        return aur_stock_listing_saved($store,$case)??$index;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/* New independent enquiry batch. Explicit staff preparation, never automatic sending. */
function aur_next_stock_leads():array {
 return [
 ['id'=>'ams-z20','name'=>'Art Medical Suisse','country'=>'CH','language'=>'en','email'=>'info@artmedicalsuisse.ch','items'=>[3],
  'model'=>'Samsung HERA Z20','evidence_url'=>'https://www.artmedicalsuisse.ch/listings/8814049-refurbished-samsung-z20-ultrasound',
  'listing'=>'CHF 63,500 refurbished Z20 with two transducers, 2024, advertised',
  'request'=>'one Samsung Z20 including the four required probes CA1-7S, LA2-14A, EA2-11AR and CMV1-10',
  'question'=>'Your listing includes two probes. Could you quote the complete four-probe configuration and confirm stock, condition and dispatch date?',
  'source_grade'=>'MODEL_LISTING_WITH_PROBE_MISMATCH','previously_contacted'=>false],
 ['id'=>'jr-pentax','name'=>'JR Medical','country'=>'NZ','language'=>'en','email'=>'sales@jrmedical.co.nz','items'=>[14],
  'model'=>'Pentax VNL9-CP with CP-1000','evidence_url'=>'https://jrmedical.co.nz/pentax-ent/',
  'listing'=>'Supplier advertises the exact VIVIDEO platform, stock unconfirmed',
  'request'=>'one Pentax VNL9-CP and CP-1000 with the required monitor and trolley',
  'question'=>'Could you quote the complete system and confirm included components, condition, availability and lead time?',
  'source_grade'=>'MODEL_SPECIFIC_DISTRIBUTOR','previously_contacted'=>false],
 ['id'=>'schneider-s260s','name'=>'Schneider Optoshop','country'=>'DE','language'=>'de','email'=>'as@optoshop.de','items'=>[17],
  'model'=>'MediWorks S260S','evidence_url'=>'https://www.optoshop.de/',
  'listing'=>'Manufacturer MediWorks referred AURENOEVA to this dealer; previous typo-domain enquiry bounced',
  'request'=>'eine MediWorks / Dixion S260S Spaltlampe (1 Stück)',
  'question'=>'MediWorks hat uns freundlicherweise an Sie verwiesen. Könnten Sie uns Preis, Verfügbarkeit und Lieferzeit für das S260S mitteilen?',
  'source_grade'=>'MANUFACTURER_REFERRED_DISTRIBUTOR','previously_contacted'=>false],
 ['id'=>'embra-ac300','name'=>'Embra','country'=>'IT','language'=>'en','email'=>'info@embra.it','items'=>[36],
  'model'=>'CATTANI AC300 / 013330','evidence_url'=>'https://riunitidentaliembra.it/prodotto/ac300-compressore-cattani-tre-cilindri-con-essicatore/',
  'listing'=>'EUR 3,230.25 ex. VAT advertised for three-cylinder model 013330',
  'request'=>'one CATTANI AC300 / 013330, 230 V/50 Hz, 45 L with dryer, 238 L/min at 5 bar',
  'question'=>'Could you confirm the quoted listing price, physical availability, full specification, warranty and delivery time?',
  'source_grade'=>'SPECIFIC_ADVERTISED_MODEL','previously_contacted'=>false],
 ['id'=>'neuromed-eeg','name'=>'NEUROMED Neurosoft France','country'=>'FR','language'=>'en','email'=>'contact@neuromed-europe.com','items'=>[24],
  'model'=>'Neurosoft Neuron-Spectrum-1','evidence_url'=>'https://neurosoft.com/en/dealer',
  'listing'=>'Named European EEG distributor in the official manufacturer directory; stock not confirmed',
  'request'=>'one Neurosoft Neuron-Spectrum-1 EEG system with the original requested accessories',
  'question'=>'Would you kindly quote the exact configuration, availability, warranty and delivery time?',
  'source_grade'=>'OFFICIAL_MANUFACTURER_DISTRIBUTOR','previously_contacted'=>false],
 ['id'=>'heinemann-za','name'=>'Heinemann ENT South Africa','country'=>'ZA','language'=>'en','email'=>'info@heinemann-ent.co.za','items'=>[16],
  'model'=>'Heinemann MODULA Europa','evidence_url'=>'https://heinemann-ent.co.za/ent-treatment-units-pro-europa/',
  'listing'=>'Named MODULA Europa ENT workstation in supplier catalogue',
  'request'=>'one Heinemann MODULA Europa workstation matching requested code 0100-30000',
  'question'=>'Could you quote the specified workstation with full configuration, current availability and lead time?',
  'source_grade'=>'MODEL_SPECIFIC_DISTRIBUTOR','previously_contacted'=>false]
 ];
}
function aur_next_stock_index(string $store,array $case):string {
 if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)throw new InvalidArgumentException('wrong_stock_case');
 return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-next-stock-20261103.json';
}
function aur_next_stock_saved(string $store,array $case):?array {
 if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
 $path=aur_next_stock_index($store,$case);
 if(!is_file($path))return null;
 if(is_link($path))throw new RuntimeException('unsafe_supplier_index');
 $index=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
 if(!is_array($index)||($index['schema']??'')!=='aurenoeva-stock-next/1'||
   ($index['case_source_sha256']??'')!==$case['source_sha256'])throw new RuntimeException('supplier_case_mismatch');
 $draft=aur_vendor_rfq_read($store,$case,(string)$index['draft_id']);
 $index['messages']=$draft['messages'];$index['status']=$draft['status'];
 return $index;
}
function aur_next_stock_prepare(string $store,array $case):array {
 $path=aur_next_stock_index($store,$case);
 $lock=@fopen($path.'.lock','c');
 if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('supplier_lock_failed');
 try {
  $old=aur_next_stock_saved($store,$case);if($old!==null)return $old;
  $plans=aur_stock_listing_plans($case,aur_next_stock_leads());
  if(count($plans)!==count(aur_next_stock_leads()))throw new RuntimeException('supplier_round_incomplete');
  $messages=array_map(static fn(array $p):array=>aur_stock_listing_compose($case,$p),$plans);
  $id=bin2hex(random_bytes(12));
  aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),[
    'version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
    'case_source_sha256'=>$case['source_sha256'],'destination_country'=>$case['country'],
    'information_only'=>true,'template_version'=>'pricing-rfq-v3',
    'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
    'status'=>'DRAFT_REVIEW','source'=>'EXACT_MODEL_ROUND2_NOV_2026',
    'messages'=>$messages]);
  aur_vendor_rfq_write($path,['schema'=>'aurenoeva-stock-next/1',
    'case_reference'=>$case['reference'],'case_source_sha256'=>$case['source_sha256'],
    'draft_id'=>$id,'supplier_count'=>count($messages),'prepared_at'=>gmdate('c')]);
  return aur_next_stock_saved($store,$case)??throw new RuntimeException('stock_round_readback_failed');
 } finally {flock($lock,LOCK_UN);fclose($lock);}
}
