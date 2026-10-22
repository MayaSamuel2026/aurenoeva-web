<?php
declare(strict_types=1);
require_once __DIR__.'/trade_compliance.php';
/**
 * Human-approved supplier enquiry outbox in the SAME private case store.
 * Draft -> review -> explicit approve -> SMTP/mail acceptance -> ledger.
 * No bulk emailing search results, arbitrary addresses, automatic outreach
 * on scan, or claim of legal/stock/authorization clearance.
 */

function aur_vendor_rfq_safe_error(Throwable $e):string {
    // Return an actionable error code without file paths or customer data.
    $allowed=[
      'selected_suppliers_have_no_published_email','unknown_supplier_selection',
      'invalid_supplier_selection','too_many_supplier_selections',
      'select_between_1_and_1500_routes','maximum_300_suppliers_per_batch',
      'outbox_write_failed','outbox_commit_failed',
      'supplier_research_lock_unavailable','supplier_research_save_failed',
      'supplier_research_commit_failed','invalid_dashboard_vendor_selection',
      'private_directory_unavailable','rfq_send_lock_unavailable'
    ];
    return in_array($e->getMessage(),$allowed,true)?$e->getMessage() :
      ($e instanceof InvalidArgumentException?'invalid_supplier_selection':'private_rfq_storage_or_research_error');
}
function aur_vendor_rfq_allowed(array $case,array $intel):array {
    $opts=[];
    foreach(($intel['rows']??[]) as $row) {
        $key=(string)($row['source_row']??'');
        $description=(string)($row['description']??'');
        // Mirror the dashboard fallback: a malformed historical crawl record
        // cannot make a visible, source-backed supplier impossible to select.
        try{$vendors=aur_worldwide_case_candidates($intel,$key,$description);}
        catch(Throwable $e){
            error_log('AURENOEVA vendor selection fallback: '.get_class($e));
            $vendors=aur_worldwide_seed_candidates($description);
        }
        foreach($vendors as $v) {
            if(empty($v['email'])||filter_var($v['email'],FILTER_VALIDATE_EMAIL)===false)continue;
            // Only a published supplier email (known directory or extracted from
            // the fetched source) may be used for an automated send.
            if(!in_array(($v['status']??''),['PUBLISHED_CONTACT_RESEARCH_CANDIDATE','SOURCE_CHECKED_EMAIL_PUBLISHED'],true))continue;
            $id=$key.'~'.$v['id'];
            $opts[$id]=['row'=>$row,'vendor'=>$v,'key'=>$key];
        }
    }
    return $opts;
}
/**
 * Dashboard selection: a supplier is identified by its normalized published
 * email, not by transient crawler/vendor IDs or product/source counts.
 */
function aur_vendor_rfq_expand_vendors(array $case,array $intel,array $selectedVendors):array {
    if(count($selectedVendors)>300)throw new InvalidArgumentException('too_many_supplier_selections');
    $wanted=[];
    foreach($selectedVendors as $token) {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))
            throw new InvalidArgumentException('invalid_supplier_selection');
        $wanted[$token]=true;
    }
    $selected=[];
    foreach(aur_vendor_rfq_allowed($case,$intel) as $key=>$pair) {
        $email=strtolower(trim((string)$pair['vendor']['email']));
        if(isset($wanted[hash('sha256',$email)]))$selected[]=$key;
    }
    if($wanted && !$selected)throw new InvalidArgumentException('selected_suppliers_have_no_published_email');
    return array_values(array_unique($selected));
}
function aur_vendor_rfq_list(string $store,array $case):array {
    $dir=aur_private_directory($store,'supplier-rfqs');
    $ref=(string)($case['reference']??'');
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))return [];
    $drafts=[];
    foreach(glob($dir.'/'.$ref.'-*.json')?:[] as $path) {
        if(is_link($path))continue;
        $id=substr(basename($path),strlen($ref)+1,-5);
        if(!preg_match('/^[a-f0-9]{24}$/D',$id))continue;
        try {
            $draft=aur_vendor_rfq_read($store,$case,$id);
            $drafts[]=['id'=>$id,'created_at'=>(string)($draft['created_at']??''),
                'status'=>(string)($draft['status']??'UNKNOWN'),
                'vendors'=>count($draft['messages']??[])];
        } catch(Throwable $e) {continue;}
    }
    usort($drafts,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
    return array_slice($drafts,0,100);
}
/**
 * Quotation requested within five working days of actual dispatch.
 * Counts Monday-Friday in Europe/Berlin; public holidays may require
 * the recipient to confirm a different applicable business calendar.
 */
function aur_vendor_rfq_due_date(?string $sentAt=null):string {
    $zone=new DateTimeZone('Europe/Berlin');
    $day=$sentAt===null?new DateTimeImmutable('now',$zone):
        (new DateTimeImmutable($sentAt))->setTimezone($zone);
    $n=0;
    while($n<5) {
        $day=$day->modify('+1 day');
        if((int)$day->format('N')<=5)$n++;
    }
    return $day->format('Y-m-d');
}
function aur_vendor_rfq_compose(array $case,array $vendor,array $lines,string $rfqCode=''):array {
    $ref=(string)$case['reference'];
    $name=(string)$vendor['name'];
    $country=(string)($case['country']??'');
    $subject='RFQ | '.$ref.($rfqCode!==''?' | '.$rfqCode:'').' | Medical Equipment - '.$country;
    $body="Dear Sales Team,\n\n";
    $body.="We are contacting you from AURENOEVA Procurement, operated by NOEVA Systems e.K., Germany.\n";
    $body.="We are coordinating a non-binding medical-equipment sourcing enquiry for a project in ".$country.".\n";
    $body.="Please provide your quotation for the following items you can supply, or clearly describe a comparable alternative:\n\n";
    foreach($lines as $row) {
        $desc=trim(preg_replace('/\s+/u',' ',(string)$row['description'])??(string)$row['description']);
        $number=(int)($row['customer_item_number']??0);
        $sourceRow=(int)($row['source_row']??0);
        $type=(string)($row['option_type']??'REQUESTED');
        $qty=$row['quantity']??null;
        $body.="Line L".str_pad((string)$sourceRow,2,'0',STR_PAD_LEFT)." | Customer item #".$number.
            ($type==='ALTERNATIVE'?' | Alternative option':'')."\n";
        $body.="Original specification: ".$desc."\n";
        $body.="Requested quantity: ".($qty===null?'To be confirmed':(string)$qty)."\n\n";
    }
    $body.="For EACH item you can quote, please provide:\n";
    $body.="- Manufacturer, exact model/configuration and any technical deviations\n";
    $body.="- Itemized unit price, currency, available quantity and equipment condition\n";
    $body.="- Earliest availability and delivery lead time\n";
    $body.="- Incoterms / named delivery place, export origin and freight costs if available\n";
    $body.="- Payment terms, quotation validity and warranty / after-sales service\n";
    $body.="- Relevant technical datasheets, registration and certification where applicable\n\n";
    $body.="Please respond within FIVE WORKING DAYS of this request, quoting the case, RFQ and item-line references above. ".
        "If a full quotation is not ready, please acknowledge the enquiry and advise when it can be provided.\n\n";
    $body.="Kindly disclose the supplier's full legal name, country of registration and whether any applicable ".
        "sanctions, export restrictions, licences or end-use requirements could affect supply. ".
        "No transaction should circumvent applicable restrictions.\n\n";
    $body.="This RFQ is non-binding and is not a purchase order. AURENOEVA is acting as a disclosed sourcing ".
        "intermediary; a future transaction is subject to commercial, technical and trade-compliance review.\n\n";
    $body.="Case: ".$ref."\n";
    if($rfqCode!=='')$body.="Supplier RFQ reference: ".$rfqCode."\n";
    $body.="\nKind regards,\nAURENOEVA Procurement\n".
        "Operated by NOEVA Systems e.K.\n".
        "Marienburger Straße 16\n56112 Lahnstein, Germany\n".
        "Amtsgericht Koblenz, HRA 23554\n".
        "info@aurenoeva.com\nhttps://aurenoeva.com\n";
    return ['vendor_id'=>(string)$vendor['id'],'vendor_name'=>$name,
        'to'=>(string)$vendor['email'],'from'=>'info@aurenoeva.com',
        'rfq_reference'=>$rfqCode,
        'source_url'=>(string)($vendor['evidence_url']??''),'subject'=>$subject,'body'=>$body,
        'items'=>array_map(static fn($r)=>[
            'source_row'=>(int)($r['source_row']??0),
            'customer_item_number'=>(int)($r['customer_item_number']??0),
            'option_type'=>(string)($r['option_type']??'REQUESTED'),
            'quantity'=>$r['quantity']??null,
            'description'=>(string)$r['description']],$lines),
        'status'=>'DRAFT','sent_at'=>null,'response_due_on'=>null];
}
function aur_vendor_rfq_path(string $store,string $caseRef,string $draftId):string {
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$caseRef)||!preg_match('/^[a-f0-9]{24}$/D',$draftId))throw new InvalidArgumentException('invalid_rfq_reference');
    return aur_private_directory($store,'supplier-rfqs').'/'.$caseRef.'-'.$draftId.'.json';
}
function aur_vendor_rfq_write(string $path,array $data):void {
    $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $tmp=dirname($path).'/.rfq-'.bin2hex(random_bytes(8));
    if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('outbox_write_failed');
    chmod($tmp,0600);
    if(!rename($tmp,$path))throw new RuntimeException('outbox_commit_failed');
}
function aur_vendor_rfq_prepare(string $store,array $case,array $intel,array $selected):array {
    if(count($selected)<1 || count($selected)>1500)throw new InvalidArgumentException('select_between_1_and_1500_routes');
    $allowed=aur_vendor_rfq_allowed($case,$intel);
    $groups=[];$dedup=[];
    foreach($selected as $key) {
        if(!is_string($key) || !isset($allowed[$key]))throw new InvalidArgumentException('unknown_supplier_selection');
        if(isset($dedup[$key]))continue;$dedup[$key]=1;
        $pair=$allowed[$key];$v=$pair['vendor'];
        $id=strtolower(trim((string)$v['email']));
        if(!isset($groups[$id]))$groups[$id]=['vendor'=>$v,'rows'=>[]];
        $row=$pair['row'];
        // Source row canonical specification; quantities read from the case, never from POST.
        foreach(($case['payload']['sourcing_lines']??[]) as $source) {
            if((int)($source['source_row']??-1)===(int)$pair['key']) {
                $row['quantity']=$source['quantity']??null;break;
            }
        }
        // A supplier selected via two different catalogue URLs gets the
        // equipment line only once; preserve the source item + quantity.
        $groups[$id]['rows'][(string)$pair['key']]=$row;
    }
    if(count($groups)>300)throw new InvalidArgumentException('maximum_300_suppliers_per_batch');
    $draftId=bin2hex(random_bytes(12));
    $messages=[];$supplierNo=0;
    foreach($groups as $g){
        $rfqCode=strtoupper(substr($draftId,0,8)).'-S'.str_pad((string)++$supplierNo,3,'0',STR_PAD_LEFT);
        $messages[]=aur_vendor_rfq_compose($case,$g['vendor'],array_values($g['rows']),$rfqCode);
    }
    $draft=['version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
        'case_source_sha256'=>$case['source_sha256'],'draft_id'=>$draftId,'created_at'=>gmdate('c'),
        'approval'=>null,'status'=>'DRAFT_REVIEW','messages'=>$messages];
    $path=aur_vendor_rfq_path($store,(string)$case['reference'],$draftId);
    aur_vendor_rfq_write($path,$draft);
    return $draft;
}
function aur_vendor_rfq_read(string $store,array $case,string $id):array {
    $file=aur_vendor_rfq_path($store,(string)$case['reference'],$id);
    if(!is_file($file)||is_link($file))throw new RuntimeException('rfq_draft_missing');
    $data=json_decode((string)file_get_contents($file),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['case_reference']??'')!==$case['reference']
       ||($data['case_source_sha256']??'')!==$case['source_sha256'])throw new RuntimeException('rfq_draft_mismatch');
    return $data;
}
function aur_vendor_rfq_send_transport(string $to,string $subject,string $body):bool {
    if(filter_var($to,FILTER_VALIDATE_EMAIL)===false||strpbrk($to,"\r\n")!==false)return false;
    $from='info@aurenoeva.com';
    // Existing site mail transport can be reused on Hostinger.
    $common=dirname(__DIR__).'/_form_common.php';
    if(is_file($common)) {
        require_once $common;
        if(function_exists('aur_send_via_smtp') && aur_send_via_smtp($to,$subject,$body,$from,$from))return true;
    }
    if(!function_exists('mail'))return false;
    $headers=[
      'From: AURENOEVA Procurement <'.$from.'>',
      'Reply-To: '.$from,
      'MIME-Version: 1.0',
      'Content-Type: text/plain; charset=UTF-8',
      'Content-Transfer-Encoding: 8bit'
    ];
    return @mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=', $body,implode("\r\n",$headers),'-f'.$from);
}
/**
 * One explicit approval sends the selected, consolidated vendor messages in
 * short resumable batches. A message is saved IN_FLIGHT before transport, so
 * a lost HTTP response cannot cause the same recipient to be mailed again.
 */
function aur_vendor_rfq_dispatch(string $store,array $case,string $draftId,bool $manualComplianceReview,?callable $sender=null,int $batchLimit=3):array {
    // Mandatory for both browser-initiated sends and unattended CLI batches.
    // Previous approvals do not override a missing, expired or held country review.
    aur_trade_review_require($store,$case);
    if($batchLimit<1||$batchLimit>10)throw new InvalidArgumentException('invalid_outbox_batch_size');
    $path=aur_vendor_rfq_path($store,(string)$case['reference'],$draftId);
    $lock=@fopen($path.'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('rfq_send_lock_unavailable');
    try{
        $draft=aur_vendor_rfq_read($store,$case,$draftId);
        if(($draft['status']??'')==='DRAFT_REVIEW') {
            if(!$manualComplianceReview)
                throw new InvalidArgumentException('supplier_legal_review_acknowledgement_required');
            foreach(($draft['messages']??[]) as $pending) {
                if(empty($pending['rfq_reference'])||
                    !str_contains((string)($pending['body']??''),'FIVE WORKING DAYS'))
                    throw new RuntimeException('prepare_new_five_day_rfq_draft');
            }
            $draft['status']='DISPATCHING';
            $draft['approval']=['reviewed_at'=>gmdate('c'),'acknowledged_by'=>'AUTHENTICATED_ADMIN',
                'statement'=>'I reviewed supplier identities, destination, intended end use, sanctions/embargoes and export-control requirements before authorizing these non-binding RFQs. No clearance is implied.'];
            aur_vendor_rfq_write($path,$draft);
        } elseif(($draft['status']??'')!=='DISPATCHING' || !is_array($draft['approval']??null)) {
            throw new RuntimeException('draft_already_processed_create_new_draft');
        }
        $sentThisRequest=0;
        foreach($draft['messages'] as $i=>$message) {
            if(($message['status']??'')!=='DRAFT')continue;
            if($sentThisRequest>=$batchLimit)break;
            // Persist first. Unknown/failed outcomes never retry automatically.
            $draft['messages'][$i]['status']='IN_FLIGHT';
            $sentAt=gmdate('c');
            $draft['messages'][$i]['attempted_at']=$sentAt;
            aur_vendor_rfq_write($path,$draft);
            $ok=false;
            try {
                $ok=$sender ? (bool)$sender($message['to'],$message['subject'],$message['body'])
                            : aur_vendor_rfq_send_transport($message['to'],$message['subject'],$message['body']);
            } catch(Throwable $e) {$ok=false;}
            $draft['messages'][$i]['status']=$ok?'ACCEPTED_BY_MAIL_TRANSPORT':'TRANSPORT_FAILED_OR_UNCONFIRMED';
            $draft['messages'][$i]['sent_at']=$ok?$sentAt:null;
            $draft['messages'][$i]['response_due_on']=$ok?aur_vendor_rfq_due_date($sentAt):null;
            $sentThisRequest++;
            aur_vendor_rfq_write($path,$draft);
        }
        $remaining=0;$accepted=0;
        foreach($draft['messages'] as $message) {
            if(($message['status']??'')==='DRAFT')$remaining++;
            if(($message['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT')$accepted++;
        }
        if($remaining===0) {
            $draft['status']=$accepted===count($draft['messages'])?'TRANSPORT_ACCEPTED':'PARTIAL_OR_FAILED';
        }
        $draft['dispatch_progress']=['accepted'=>$accepted,'remaining'=>$remaining,
            'total'=>count($draft['messages']),'processed_this_request'=>$sentThisRequest];
        aur_vendor_rfq_write($path,$draft);
        return $draft;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/**
 * Owner-triggered LIVE mail smoke test. It exercises the identical transport
 * as selected supplier RFQs, but only to the fixed owner's Gmail address.
 * It is never triggered by supplier scanning or page loading.
 */
function aur_vendor_rfq_smoke_status(string $store): ?array {
    $file=aur_private_directory($store,'supplier-rfqs').'/aurenoeva-mail-smoke.json';
    if(!is_file($file)||is_link($file))return null;
    $data=json_decode((string)file_get_contents($file),true);
    return is_array($data)?$data:null;
}
function aur_vendor_rfq_send_smoke_test(string $store,?callable $sender=null): array {
    $dir=aur_private_directory($store,'supplier-rfqs');
    $file=$dir.'/aurenoeva-mail-smoke.json';
    $lock=fopen($dir.'/.mail-smoke.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('email_test_lock_unavailable');
    try {
        $previous=aur_vendor_rfq_smoke_status($store);
        if(is_array($previous)&&time()-(int)($previous['timestamp']??0)<900){
            $previous['recent_test_preserved']=true;
            return $previous;
        }
        $recipient='fredericksamuel1965@gmail.com';
        $subject='TEST — AURENOEVA Automated RFQ — No Action Required';
        $body="AURENOEVA PROCUREMENT — EMAIL AUTOMATION TEST\n\n"
            ."This is a test message generated by the AURENOEVA procurement website.\n"
            ."Please do not treat it as a real request for quotation or a purchase order.\n\n"
            ."TEST PRODUCT REQUEST\n"
            ."Product: Mindray WATO EX-35 anesthesia workstation\n"
            ."Description: Sample medical anesthesia system with accessories; configuration for illustration only.\n"
            ."Quantity: 2 units\n"
            ."Delivery destination: Uzbekistan (test destination)\n\n"
            ."Please provide in a real quotation:\n"
            ."- Exact model and available configuration\n"
            ."- Equipment price and currency\n"
            ."- Lead time and shipping terms\n"
            ."- Product certification, warranty and installation support\n"
            ."- Availability of local servicing and spare parts\n\n"
            ."REFERENCE: AURENOEVA-DUMMY-RFQ-TEST\n"
            ."STATUS: TEST ONLY — NO CUSTOMER OR VENDOR COMMITMENT\n\n"
            ."AURENOEVA Procurement\n"
            ."info@aurenoeva.com\n";
        $record=['timestamp'=>time(),'checked_at'=>gmdate('c'),'status'=>'DISPATCHING',
            'recipient'=>$recipient,'sender'=>'info@aurenoeva.com',
            'subject'=>$subject,'scenario'=>'ONE_DUMMY_RFQ_NO_VENDOR_CONTACT'];
        aur_vendor_rfq_write($file,$record);
        $accepted=false;
        try{
            $accepted=$sender ? (bool)$sender($recipient,$subject,$body)
                : aur_vendor_rfq_send_transport($recipient,$subject,$body);
        }catch(Throwable $e){$accepted=false;}
        $record['status']=$accepted?'ACCEPTED_BY_MAIL_TRANSPORT':'TRANSPORT_FAILED_OR_UNCONFIRMED';
        $record['finished_at']=gmdate('c');
        aur_vendor_rfq_write($file,$record);
        return $record;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
