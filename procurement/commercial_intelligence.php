<?php
declare(strict_types=1);
/**
 * AURENOEVA commercial intelligence. Reads the existing private catalogue,
 * cases and supplier ledgers. Only additive daily aggregates/outcome evidence.
 * No customer information, raw search query, or supplier quote is published.
 */
require_once __DIR__.'/private_cases.php';
require_once __DIR__.'/supplier_rfq.php';
require_once __DIR__.'/supplier_replies.php';
require_once __DIR__.'/supplier_inbox_store.php';
require_once __DIR__.'/supplier_worldwide.php';

function aur_ci_industries():array {
    return [
      'laboratory'=>'Laboratory', 'test'=>'Test & Measurement',
      'medical'=>'Medical', 'industrial'=>'Industrial', 'energy'=>'Energy',
      'marine'=>'Marine', 'aviation'=>'Aviation',
      'oil_gas'=>'Oil, Gas & Petrochemicals',
      'chemical'=>'Chemicals & Process Industries',
      'pharma'=>'Pharmaceutical & Biotechnology',
      'automotive'=>'Automotive & Electric Mobility',
      'rail'=>'Rail & Transportation', 'food'=>'Food & Beverage',
      'agriculture'=>'Agriculture & Forestry', 'mining'=>'Mining & Heavy Equipment',
      'construction'=>'Construction & Building Technology',
      'water'=>'Water, Waste & Environmental',
      'semiconductor'=>'Electronics & Semiconductors',
      'logistics'=>'Logistics & Warehousing'
    ];
}
function aur_ci_read_json(string $file,array $fallback=[]):array {
    if(!is_file($file)||is_link($file))return $fallback;
    $value=json_decode((string)@file_get_contents($file),true);
    return is_array($value)?$value:$fallback;
}
function aur_ci_atomic_write(string $file,array $value):void {
    if(is_link($file))throw new RuntimeException('unsafe_intelligence_path');
    $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $temp=dirname($file).'/.ci-'.bin2hex(random_bytes(8));
    if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes))
        throw new RuntimeException('intelligence_write_failed');
    @chmod($temp,0600);
    if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('intelligence_write_failed');}
}
/** Persist a dated product count only AFTER successful catalogue publication.
 * Called inside the catalogue's existing exclusive merge lock.
 */
function aur_ci_snapshot_observed(string $store,int $count,string $digest):void {
    if($count<0||!preg_match('/^[a-f0-9]{64}$/D',$digest))return;
    $path=$store.'/commercial-catalogue-history.json';
    $history=aur_ci_read_json($path,['days'=>[]]);
    $days=is_array($history['days']??null)?$history['days']:[];
    $today=gmdate('Y-m-d');
    $days[$today]=['date'=>$today,'count'=>$count,'sha256'=>$digest,'observed_at'=>gmdate('c')];
    ksort($days);
    aur_ci_atomic_write($path,['schema'=>'aurenoeva-catalogue-growth/1','days'=>array_slice($days,-365,null,true)]);
}
/** Aggregate intentionally anonymous buyer actions. No query, IP, cookie or part ID retained. */
function aur_ci_event(string $store,string $event):void {
    if(!in_array($event,['search','basket_add'],true))throw new InvalidArgumentException('invalid_event');
    $path=$store.'/commercial-buyer-activity.json';
    $lock=fopen($store.'/.commercial-activity.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('intelligence_lock_unavailable');
    try {
        $data=aur_ci_read_json($path,['days'=>[]]);
        $days=is_array($data['days']??null)?$data['days']:[];
        $day=gmdate('Y-m-d');
        $row=is_array($days[$day]??null)?$days[$day]:['search'=>0,'basket_add'=>0];
        $row[$event]=min(10000000,(int)($row[$event]??0)+1);
        $days[$day]=$row;
        ksort($days);
        aur_ci_atomic_write($path,['schema'=>'aurenoeva-buyer-activity/1','days'=>array_slice($days,-120,null,true)]);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function aur_ci_record_outcome(string $store,string $reference,string $outcome,string $evidence):void {
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$reference))
        throw new InvalidArgumentException('invalid_case_reference');
    if(!in_array($outcome,['PURCHASE_CONFIRMED','NO_PURCHASE_CONFIRMED'],true))
        throw new InvalidArgumentException('invalid_outcome');
    $evidence=trim($evidence);
    if(strlen($evidence)<8||strlen($evidence)>250)
        throw new InvalidArgumentException('evidence_reference_required');
    aur_case_read($store,$reference);
    $file=$store.'/commercial-transaction-outcomes.json';
    $lock=fopen($store.'/.commercial-outcomes.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('intelligence_lock_unavailable');
    try {
        $data=aur_ci_read_json($file,['records'=>[]]);
        $rows=is_array($data['records']??null)?$data['records']:[];
        // One independently confirmed latest result per original enquiry.
        $rows[$reference]=['case_reference'=>$reference,'outcome'=>$outcome,
            'evidence_reference'=>$evidence,'verified_by_staff'=>true,
            'verified_at'=>gmdate('c')];
        aur_ci_atomic_write($file,['schema'=>'aurenoeva-verified-outcomes/1','records'=>$rows]);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function aur_ci_report(string $store):array {
    $names=aur_ci_industries();
    $snapshotFile=$store.'/catalogue-public.json';
    $snapshot=aur_ci_read_json($snapshotFile);
    $hasSnapshot=isset($snapshot['items'])&&is_array($snapshot['items']);
    $industries=[];
    foreach($names as $key=>$name)$industries[$key]=[
        'key'=>$key,'name'=>$name,'group'=>count($industries)<7?'established':'discovery',
        'primary_count'=>null,'classified_count'=>null];
    $primary=array_fill_keys(array_slice(array_keys($names),0,7),0);
    $unclassified=0;$total=0;$priced=0;$fresh=0;$classified=0;$multi=0;$latestSeen=null;
    $now=time();$unique=[];
    if($hasSnapshot)foreach($snapshot['items'] as $item){
        if(!is_array($item))continue;
        $id=(string)($item['canonical_id']??'');
        if($id===''||isset($unique[$id]))continue;
        $unique[$id]=true;$total++;
        $sector=(string)($item['sector']??'');
        if(array_key_exists($sector,$primary))$primary[$sector]++;else $unclassified++;
        $last=strtotime((string)($item['last_seen']??''))?:0;
        if($last>0&&$last<=$now+300&&$last>=$now-30*86400)$fresh++;
        if($last>0&&($latestSeen===null||$last>$latestSeen))$latestSeen=$last;
        foreach(($item['public_offers']??[]) as $offer){
            if(!is_array($offer))continue;
            $seen=strtotime((string)($offer['observed_at']??''))?:0;
            if($seen>=($now-14*86400)&&$seen<=$now+300
               &&(float)($offer['amount']??0)>0
               &&preg_match('/^[A-Z]{3}$/D',(string)($offer['currency']??''))
               &&str_starts_with((string)($offer['source_url']??''),'https://')){$priced++;break;}
        }
        $supported=[];
        foreach(($item['classifications']??[]) as $facet){
            if(!is_array($facet))continue;
            $key=(string)($facet['industry']??'');
            if(!isset($industries[$key]))continue;
            if(($facet['confidence']??'')!=='MATCHED_TEXT'
               ||($facet['method']??'')!=='technical-facets/1'
               ||($facet['evidence_url']??'')!==($item['source_ref']??''))continue;
            $supported[$key]=true;
        }
        foreach(array_keys($supported) as $key){
            if($industries[$key]['classified_count']===null)$industries[$key]['classified_count']=0;
            $industries[$key]['classified_count']++;
        }
        if($supported)$classified++;
        if(count($supported)>1)$multi++;
    }
    foreach($primary as $key=>$count)$industries[$key]['primary_count']=$hasSnapshot?$count:null;
    // The 12 newer topics are discovery searches, not validated class assignments.
    // Show a null, never a fabricated zero, until supported independent facets exist.
    $history=aur_ci_read_json($store.'/commercial-catalogue-history.json');
    $series=[];
    foreach(($history['days']??[]) as $day=>$record)if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$day)
      &&is_array($record)&&isset($record['count']))
        $series[]=['date'=>$day,'count'=>(int)$record['count']];
    $activity=aur_ci_read_json($store.'/commercial-buyer-activity.json');
    $recent=[];
    foreach(($activity['days']??[]) as $day=>$row)if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$day)
      &&is_array($row))$recent[]=['date'=>$day,'search'=>(int)($row['search']??0),
        'basket_add'=>(int)($row['basket_add']??0)];
    $cases=[];$sent=0;$responded=0;$quoted=0;$casesSent=0;$casesReplied=0;$casesQuoted=0;
    $supplierSent=[];$supplierResponded=[];$supplierQuoted=[];$withDraft=0;$caseCap=false;
    $casePaths=glob($store.'/cases/AUR-??-*.json')?:[];
    if(count($casePaths)>1000)$caseCap=true;
    foreach(array_slice($casePaths,0,1000) as $casePath){
        if(is_link($casePath))continue;
        try{
            $case=aur_case_read($store,pathinfo($casePath,PATHINFO_FILENAME));
            $ref=(string)$case['reference'];
            $caseSent=[];$caseReplies=[];$caseQuotes=[];
            $drafts=aur_vendor_rfq_list($store,$case);
            if($drafts)$withDraft++;
            foreach($drafts as $row){
                $draft=aur_vendor_rfq_read($store,$case,(string)$row['id']);
                foreach(($draft['messages']??[]) as $msg){
                    if(($msg['status']??'')!=='ACCEPTED_BY_MAIL_TRANSPORT')continue;
                    $email=strtolower(trim((string)($msg['to']??'')));
                    if(!filter_var($email,FILTER_VALIDATE_EMAIL))continue;
                    $caseSent[$email]=true;$supplierSent[$email]=true;
                }
            }
            $replyRows=aur_reply_read($store,$case);
            foreach($replyRows as $entry){
                if(!is_array($entry))continue;
                $email=strtolower(trim((string)($entry['email']??'')));
                if(isset($caseSent[$email])){
                    $caseReplies[$email]=true;$supplierResponded[$email]=true;
                    if(in_array(($entry['availability']??''),['QUOTED','ALTERNATIVE_OFFERED'],true)
                       &&(float)($entry['unit_price']??0)>0
                       &&preg_match('/^[A-Z]{3}$/D',(string)($entry['currency']??''))){
                        $caseQuotes[$email]=true;$supplierQuoted[$email]=true;
                    }
                }
            }
            if($caseSent)$casesSent++;
            if($caseReplies)$casesReplied++;
            if($caseQuotes)$casesQuoted++;
            $sent+=count($caseSent);$responded+=count($caseReplies);$quoted+=count($caseQuotes);
            $cases[$ref]=['reference'=>$ref,'created_at'=>(string)($case['created_at']??'')];
        }catch(Throwable $e){error_log('AURENOEVA commercial case count: '.get_class($e));}
    }
    $outcomes=aur_ci_read_json($store.'/commercial-transaction-outcomes.json');
    $purchases=0;$noPurchases=0;
    foreach(($outcomes['records']??[]) as $ref=>$record){
        if(!isset($cases[$ref])||!is_array($record)||empty($record['verified_by_staff'])
           ||strlen((string)($record['evidence_reference']??''))<8)continue;
        if(($record['outcome']??'')==='PURCHASE_CONFIRMED')$purchases++;
        if(($record['outcome']??'')==='NO_PURCHASE_CONFIRMED')$noPurchases++;
    }
    $master=aur_worldwide_master_read($store);
    $suppliers=count($master['vendors']??[]);
    $contactable=0;
    foreach(($master['vendors']??[]) as $vendor)if(is_array($vendor)
        &&filter_var((string)($vendor['email']??''),FILTER_VALIDATE_EMAIL))$contactable++;
    return [
      'ok'=>true,'as_of'=>gmdate('c'),
      'catalogue'=>['available'=>$hasSnapshot,'products'=>$hasSnapshot?$total:null,
        'priced_products'=>$hasSnapshot?$priced:null,'fresh_30d'=>$hasSnapshot?$fresh:null,
        'classified_products'=>$hasSnapshot?$classified:null,'multi_industry'=>$hasSnapshot?$multi:null,
        'unclassified_primary'=>$hasSnapshot?$unclassified:null,
        'snapshot_updated_at'=>$hasSnapshot?gmdate('c',(int)filemtime($snapshotFile)):null,
        'latest_product_seen_at'=>$latestSeen?gmdate('c',$latestSeen):null,
        'growth_history'=>$series],
      'industries'=>array_values($industries),
      'activity'=>['days'=>$recent,'definition'=>'Anonymous recorded browser search submissions and additions; not unique visitors or successful purchases.'],
      'suppliers'=>['discovered'=>$suppliers,'contactable'=>$contactable,
        'rfq_recipients'=>count($supplierSent),'responded'=>count($supplierResponded),
        'quoted'=>count($supplierQuoted)],
      'funnel'=>['enquiries'=>count($cases),'draft_prepared'=>$withDraft,
        'supplier_contacted'=>$casesSent,'supplier_replied'=>$casesReplied,
        'cases_with_priced_quote'=>$casesQuoted,
        'sent_supplier_pairs'=>$sent,'responded_supplier_pairs'=>$responded,
        'quoted_supplier_pairs'=>$quoted,
        'verified_purchases'=>$purchases,'verified_no_purchase'=>$noPurchases,
        'coverage_limited'=>$caseCap],
      'outcome_case_options'=>array_values(array_map(static fn($c)=>$c['reference'],$cases)),
      'definitions'=>[
        'pricing'=>'Unique searchable products with a publicly evidenced seller offer observed within the last 14 days.',
        'freshness'=>'Unique searchable products last seen within the last 30 days.',
        'responses'=>'One responding supplier email per enquiry with a saved structured supplier reply; not every unrelated mailbox message.',
        'quotations'=>'Priced QUOTED or ALTERNATIVE_OFFERED supplier responses with ISO currency, counted once per supplier and enquiry.',
        'transactions'=>'Only staff-confirmed buyer outcomes with a retained private evidence reference; no transaction inferred from an RFQ.']
    ];
}
