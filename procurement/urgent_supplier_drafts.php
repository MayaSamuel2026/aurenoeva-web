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
            ."Vielen Dank und freundliche Grüße\nAURENOEVA Einkauf\n"
            ."NOEVA Systems e.K. | info@aurenoeva.com\n";
    } else {
        $body.="For now, we need only unit price and currency, estimated lead time, "
            ."equipment condition (new/used/refurbished) and actual availability. "
            ."Please confirm if supply/export to Uzbekistan is possible, or identify your appropriate export partner.\n\n"
            ."A short reply within two working days would be greatly appreciated. "
            ."Please quote alternatives separately; they are not additional confirmed quantities.\n\n"
            ."This is a non-binding information enquiry, not an order. Any subsequent purchase would be made directly "
            ."between buyer and supplier, subject to all applicable sanctions, export, import and medical-device controls.\n\n"
            ."Thank you and kind regards,\nAURENOEVA Purchasing\n"
            ."Operated by NOEVA Systems e.K. | info@aurenoeva.com\n";
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
