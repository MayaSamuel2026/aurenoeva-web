<?php
declare(strict_types=1);
require_once __DIR__.'/embargo_screen.php';
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
      'private_directory_unavailable','rfq_send_lock_unavailable',
      'destination_requires_sanctions_review','destination_changed_redraft_required',
      'commercial_draft_redraft_required','rfq_template_outdated_redraft_required',
      'rfq_smtp_not_configured','rfq_smtp_invalid_configuration',
      'rfq_smtp_test_required','smtp_configuration_unavailable',
      'rfq_duplicate_risk_acknowledgement_required','rfq_recovery_not_available'
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
function aur_vendor_rfq_compose(array $case,array $vendor,array $lines):array {
    $ref=(string)$case['reference'];
    $name=(string)$vendor['name'];
    $country=(string)($case['country']??'');
    $destination=aur_embargo_countries()[$country]??$country;
    $subject='AURENOEVA Purchasing | Request for Quotation | '.$ref.' | '.count($lines).' item(s)';
    $body="Dear Supplier Team,\n\n";
    $body.="AURENOEVA Purchasing, operated by NOEVA Systems e.K. in Germany, invites your company to provide a non-binding quotation for the equipment listed below. The proposed final destination is ".$destination." (".$country.").\n\n";
    $body.="We would appreciate your best indicative pricing, confirmation of actual product availability and expected delivery times. You are welcome to quote only the items you can supply. Please identify any equivalent or alternative model separately, including the differences from the requested specification.\n\n";
    $body.="EQUIPMENT REQUESTED — RFQ ".$ref."\n";
    $body.=str_repeat('-',52)."\n";
    foreach($lines as $n=>$row) {
        $desc=trim(preg_replace('/\s+/u',' ',(string)$row['description'])??(string)$row['description']);
        $number=(int)($row['customer_item_number']??0);
        $type=(string)($row['option_type']??'REQUESTED');
        $qty=$row['quantity']??null;
        $body.=($n+1).". Buyer item ".$number.($type==='ALTERNATIVE'?' (alternative option)':'')."\n";
        $body.="   Specification: ".$desc."\n";
        $body.="   Quantity: ".($qty===null?'Not specified':(string)$qty)."\n\n";
    }
    $body.="PLEASE INCLUDE IN YOUR QUOTATION\n";
    $body.="1. Manufacturer, exact model/configuration and technical datasheet or product link; clearly describe any proposed substitute.\n";
    $body.="2. Unit price, currency, total price for the requested quantity, applicable VAT/taxes, and any minimum order quantity.\n";
    $body.="3. Confirmed stock/available quantity, equipment condition (new/refurbished/used), estimated dispatch date and delivery lead time.\n";
    $body.="4. Delivery terms (Incoterms, where applicable), dispatch location and separately itemised freight, insurance or handling costs, if available.\n";
    $body.="5. Quotation validity and proposed payment terms, for comparison purposes only.\n";
    $body.="6. Warranty, certifications, and installation, training or local service support where relevant.\n";
    $body.="7. Registered selling entity, business address, website and a contact person or sales email; please identify whether you are manufacturer or distributor.\n";
    $body.="8. Country of origin, export origin, applicable export classification if known and any sanctions, embargoes, licence, end-user or import restrictions affecting delivery to ".$destination.".\n\n";
    $body.="Please return your quotation within five working days, if possible, and cite RFQ ".$ref." and the buyer item numbers in your reply. If information is not available, please indicate this rather than assuming stock or regulatory clearance.\n\n";
    $body.="This is a request for price and availability information only. It is not a purchase order, acceptance of an offer, commitment to buy or instruction to ship. AURENOEVA does not purchase or resell the goods, negotiate a sale, collect buyer payments or arrange transport. Any subsequent purchase, if pursued, will be contracted directly between the independent buyer and seller and remains subject to all applicable sanctions, export-control, medical-device and import requirements. No restricted transaction or circumvention is authorised by this enquiry.\n\n";
    $body.="Thank you for your consideration. We look forward to your reply.\n\n";
    $body.="Kind regards,\nAURENOEVA Purchasing\n";
    $body.="Operated by NOEVA Systems e.K.\n";
    $body.="Marienburger Straße 16 | 56112 Lahnstein | Germany\n";
    $body.="Email: info@aurenoeva.com | https://aurenoeva.com\n";
    $body.="Registered with Amtsgericht Koblenz | HRA 23554\n";
    return ['vendor_id'=>(string)$vendor['id'],'vendor_name'=>$name,'to'=>(string)$vendor['email'],
        'source_url'=>(string)($vendor['evidence_url']??''),'subject'=>$subject,'body'=>$body,
        'items'=>array_map(static fn($r)=>['source_row'=>(int)($r['source_row']??0),'description'=>(string)$r['description']],$lines),
        'status'=>'DRAFT','sent_at'=>null];
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
    $messages=[];
    foreach($groups as $g)$messages[]=aur_vendor_rfq_compose($case,$g['vendor'],array_values($g['rows']));
    $draftId=bin2hex(random_bytes(12));
    $draft=['version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
        'case_source_sha256'=>$case['source_sha256'],'destination_country'=>$case['country'],'information_only'=>true,'template_version'=>'pricing-rfq-v3','draft_id'=>$draftId,'created_at'=>gmdate('c'),
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
/**
 * Supplier RFQs use ONE authenticated SMTP route. Never fall back to PHP mail():
 * a false/ambiguous local MTA result could cause a second supplier message.
 * All diagnostics are written to the existing private, per-recipient outbox.
 */
function aur_vendor_rfq_smtp_config():array {
    $host=trim((string)(getenv('AURENOEVA_SMTP_HOST')?:'smtp.hostinger.com'));
    $user=trim((string)(getenv('AURENOEVA_SMTP_USER')?:'info@aurenoeva.com'));
    $pass=(string)(getenv('AURENOEVA_SMTP_PASSWORD')?:'');
    if($pass===''&&function_exists('aur_store_root')&&function_exists('aur_private_directory')) {
        // Private hosting directory (outside public_html), never in source code.
        $secret=aur_private_directory(aur_store_root(),'supplier-rfqs').'/smtp-credential.json';
        if(is_file($secret)&&!is_link($secret)) {
            $saved=json_decode((string)file_get_contents($secret),true);
            if(is_array($saved)&&($saved['mailbox']??'')==='info@aurenoeva.com')
                $pass=(string)($saved['password']??'');
        }
    }
    $port=(int)(getenv('AURENOEVA_SMTP_PORT')?:465);
    $security=strtolower(trim((string)(getenv('AURENOEVA_SMTP_SECURITY')?:($port===465?'ssl':'tls'))));
    if($user!=='info@aurenoeva.com'||$pass===''||$host==='')
        throw new RuntimeException('rfq_smtp_not_configured');
    if(!preg_match('/^[a-z0-9.-]+$/iD',$host)||
       !(($port===465&&$security==='ssl')||($port===587&&$security==='tls')))
        throw new RuntimeException('rfq_smtp_invalid_configuration');
    return ['host'=>$host,'user'=>$user,'pass'=>$pass,'port'=>$port,'security'=>$security];
}
/**
 * One-time authenticated admin mailbox setup. The SMTP password is entered
 * directly into the website, never passed through chat or committed to GitHub.
 */
function aur_vendor_rfq_store_smtp_password(string $store,string $password):void {
    if($password===''||strlen($password)>512||strpbrk($password,"\r\n")!==false)
        throw new InvalidArgumentException('invalid_smtp_password');
    $dir=aur_private_directory($store,'supplier-rfqs');
    $file=$dir.'/smtp-credential.json';
    if(is_link($file))throw new RuntimeException('smtp_configuration_unavailable');
    $payload=json_encode(['mailbox'=>'info@aurenoeva.com','password'=>$password],
        JSON_THROW_ON_ERROR);
    $tmp=$dir.'/.smtp-'.bin2hex(random_bytes(12));
    if(file_put_contents($tmp,$payload,LOCK_EX)!==strlen($payload))
        throw new RuntimeException('smtp_configuration_unavailable');
    chmod($tmp,0600);
    if(!rename($tmp,$file)) {
        @unlink($tmp);
        throw new RuntimeException('smtp_configuration_unavailable');
    }
}
function aur_vendor_rfq_smtp_ready():bool {
    try {aur_vendor_rfq_smtp_config();return true;}
    catch(Throwable $e){return false;}
}
function aur_vendor_rfq_smtp_exchange($socket,?string $command,array $expected,string $stage):array {
    if($command!==null) {
        $bytes=strlen($command)+2;
        $sent=@fwrite($socket,$command."\r\n");
        $written=0;
        while($written<$bytes) {
            $sent=@fwrite($socket,substr($command."\r\n",$written));
            if($sent===false||$sent===0)
                return ['ok'=>false,'stage'=>$stage,'code'=>0,'response'=>'socket_write_failed'];
            $written+=$sent;
        }
    }
    $response='';
    for($n=0;$n<40&&!feof($socket);$n++) {
        $line=@fgets($socket,1024);
        if($line===false)break;
        $response.=$line;
        if(strlen($line)>=4&&$line[3]===' ')break;
    }
    $code=(int)substr($response,0,3);
    $clean=substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/',' ',$response)),0,350);
    return ['ok'=>in_array($code,$expected,true),'stage'=>$stage,'code'=>$code,
        'response'=>$clean!==''?$clean:'smtp_no_response'];
}
function aur_vendor_rfq_send_transport_detail(string $to,string $subject,string $body,string $messageId=''):array {
    $base=['transport'=>'AUTHENTICATED_SMTP','accepted'=>false,'stage'=>'PREFLIGHT',
        'code'=>0,'response'=>'','outcome'=>'NOT_SENT'];
    if(filter_var($to,FILTER_VALIDATE_EMAIL)===false||strpbrk($to,"\r\n")!==false)
        return $base+['reason'=>'invalid_recipient'];
    try {$cfg=aur_vendor_rfq_smtp_config();}
    catch(Throwable $e) {$base['response']=$e->getMessage();return $base;}
    if(!preg_match('/^[a-f0-9]{32}@aurenoeva\.com$/D',$messageId))
        $messageId=bin2hex(random_bytes(16)).'@aurenoeva.com';
    $base['message_id']=$messageId;
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,
        'verify_peer_name'=>true,'peer_name'=>$cfg['host'],'SNI_enabled'=>true]]);
    $address=($cfg['security']==='ssl'?'ssl://':'tcp://').$cfg['host'].':'.$cfg['port'];
    $errno=0;$errstr='';
    $socket=@stream_socket_client($address,$errno,$errstr,12,STREAM_CLIENT_CONNECT,$context);
    if(!is_resource($socket)) {
        $base['stage']='CONNECT';
        $base['response']='smtp_connect_failed_'.(int)$errno;
        return $base;
    }
    stream_set_timeout($socket,12);
    try {
        $steps=[
            [null,[220],'GREETING'],
            ['EHLO aurenoeva.com',[250],'EHLO']
        ];
        foreach($steps as [$command,$codes,$stage]) {
            $r=aur_vendor_rfq_smtp_exchange($socket,$command,$codes,$stage);
            if(!$r['ok'])return array_merge($base,$r);
        }
        if($cfg['security']==='tls') {
            $r=aur_vendor_rfq_smtp_exchange($socket,'STARTTLS',[220],'STARTTLS');
            if(!$r['ok'])return array_merge($base,$r);
            if(@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true)
                return array_merge($base,['stage'=>'TLS_HANDSHAKE','response'=>'tls_validation_failed']);
            $r=aur_vendor_rfq_smtp_exchange($socket,'EHLO aurenoeva.com',[250],'EHLO_TLS');
            if(!$r['ok'])return array_merge($base,$r);
        }
        $commands=[
            ['AUTH LOGIN',[334],'AUTH_START'],
            [base64_encode($cfg['user']),[334],'AUTH_USER'],
            [base64_encode($cfg['pass']),[235],'AUTH_PASSWORD'],
            ['MAIL FROM:<info@aurenoeva.com>',[250],'MAIL_FROM'],
            ['RCPT TO:<'.$to.'>',[250,251],'RCPT_TO'],
            ['DATA',[354],'DATA']
        ];
        foreach($commands as [$command,$codes,$stage]) {
            $r=aur_vendor_rfq_smtp_exchange($socket,$command,$codes,$stage);
            if(!$r['ok'])return array_merge($base,$r);
        }
        $headers=[
            'From: AURENOEVA Purchasing <info@aurenoeva.com>',
            'To: '.$to,
            'Reply-To: info@aurenoeva.com',
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'Date: '.date(DATE_RFC2822),
            'Message-ID: <'.$messageId.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64'
        ];
        $payload=implode("\r\n",$headers)."\r\n\r\n".
            chunk_split(base64_encode($body),76,"\r\n").'.';
        $r=aur_vendor_rfq_smtp_exchange($socket,$payload,[250],'DATA_ACCEPTANCE');
        $base=array_merge($base,$r);
        $base['accepted']=$r['ok'];
        $base['outcome']=$r['ok']?'SMTP_ACCEPTED':
            ($r['code']>=400?'SMTP_REJECTED':'UNCERTAIN_AFTER_DATA');
        return $base;
    } finally {
        @fwrite($socket,"QUIT\r\n");
        @fclose($socket);
    }
}
function aur_vendor_rfq_send_transport(string $to,string $subject,string $body):bool {
    return aur_vendor_rfq_send_transport_detail($to,$subject,$body)['accepted']===true;
}
/**
 * One explicit approval sends the selected, consolidated vendor messages in
 * short resumable batches. A message is saved IN_FLIGHT before transport, so
 * a lost HTTP response cannot cause the same recipient to be mailed again.
 */
/**
 * Reconcile the saved recipient ledger, never trust a browser progress label.
 * UNKNOWN/IN_FLIGHT cannot be retried automatically because the mail server
 * may have accepted a request before the HTTP connection was interrupted.
 */
function aur_vendor_rfq_status_summary(array $draft):array {
    $summary=['total'=>count($draft['messages']??[]),'accepted'=>0,
        'remaining'=>0,'failed_or_unconfirmed'=>0,'in_flight'=>0,'other'=>0];
    foreach(($draft['messages']??[]) as $message) {
        switch((string)($message['status']??'')) {
            case 'ACCEPTED_BY_MAIL_TRANSPORT':$summary['accepted']++;break;
            case 'DRAFT':$summary['remaining']++;break;
            case 'TRANSPORT_FAILED_OR_UNCONFIRMED':$summary['failed_or_unconfirmed']++;break;
            case 'IN_FLIGHT':$summary['in_flight']++;break;
            default:$summary['other']++;break;
        }
    }
    $summary['needs_reconciliation']=$summary['failed_or_unconfirmed']>0 ||
        $summary['in_flight']>0 || $summary['other']>0;
    return $summary;
}
/**
 * Read-only, source-of-truth delivery breakdown. Historical accepted sends
 * remain separate from follow-ups; SMTP acceptance does not prove delivery.
 */
function aur_vendor_rfq_delivery_breakdown(array $draft):array {
    $result=['original_accepted'=>0,'followup_accepted'=>0,
        'followup_failed_or_unconfirmed'=>0,'followup_in_flight'=>0,
        'followup_attempted'=>0,'smtp_250_confirmed'=>0,
        'accepted_without_smtp_250_proof'=>0];
    foreach(($draft['messages']??[]) as $message) {
        if(!is_array($message))continue;
        $recovery=$message['recovery']??null;
        if(($message['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT') {
            $evidence=is_array($recovery)?($recovery['transport_result']??null):
                ($message['transport_result']??null);
            if(is_array($evidence)
               && ($evidence['transport']??'')==='AUTHENTICATED_SMTP'
               && ($evidence['stage']??'')==='DATA_ACCEPTANCE'
               && (int)($evidence['code']??0)===250
               && ($evidence['outcome']??'')==='SMTP_ACCEPTED')
                $result['smtp_250_confirmed']++;
            else $result['accepted_without_smtp_250_proof']++;
        }
        if(!is_array($recovery)) {
            if(($message['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT')
                $result['original_accepted']++;
            continue;
        }
        $result['followup_attempted']++;
        switch((string)($recovery['status']??'')) {
            case 'ACCEPTED_BY_MAIL_TRANSPORT':
                $result['followup_accepted']++;break;
            case 'IN_FLIGHT':
                $result['followup_in_flight']++;break;
            default:
                $result['followup_failed_or_unconfirmed']++;break;
        }
    }
    return $result;
}
function aur_vendor_rfq_dispatch(string $store,array $case,string $draftId,bool $manualComplianceReview,?callable $sender=null,int $batchLimit=3):array {
    aur_embargo_assert_dispatch_ready($case);
    if(!$manualComplianceReview)throw new InvalidArgumentException('supplier_legal_review_acknowledgement_required');
    if($batchLimit<1||$batchLimit>5)throw new InvalidArgumentException('invalid_outbox_batch_size');
    $path=aur_vendor_rfq_path($store,(string)$case['reference'],$draftId);
    $lock=@fopen($path.'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('rfq_send_lock_unavailable');
    try{
        $draft=aur_vendor_rfq_read($store,$case,$draftId);
        // Preserve old commercial workflow drafts but never revive them by mistake.
        // Pricing requests under the new non-binding template remain informational.
        if(($draft['information_only']??false)!==true)
            throw new InvalidArgumentException('commercial_draft_redraft_required');
        // A historical, unapproved availability-only draft does not contain
        // the owner's requested prices and registered business signature.
        // Keep the draft for the audit trail; require a fresh draft instead.
        if(($draft['status']??'')==='DRAFT_REVIEW'
           && ($draft['template_version']??'')!=='pricing-rfq-v3')
            throw new InvalidArgumentException('rfq_template_outdated_redraft_required');
        // Historic drafts imply the original destination in their case reference.
        $parts=explode('-', (string)$case['reference']);
        $draftCountry=(string)($draft['destination_country']??($parts[1]??''));
        if($draftCountry!==(string)($case['country']??''))
            throw new InvalidArgumentException('destination_changed_redraft_required');
        // Refuse dispatch before changing any draft if the verified SMTP
        // credentials have not been installed on the website host.
        if($sender===null) {
            foreach($draft['messages'] as $pending) {
                if(($pending['status']??'')==='DRAFT') {
                    aur_vendor_rfq_smtp_config();
                    if(!aur_vendor_rfq_mail_verified($store))
                        throw new RuntimeException('rfq_smtp_test_required');
                    break;
                }
            }
        }
        if(($draft['status']??'')==='DRAFT_REVIEW') {
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
            $draft['messages'][$i]['attempted_at']=gmdate('c');
            $draft['messages'][$i]['smtp_message_id']=bin2hex(random_bytes(16)).'@aurenoeva.com';
            aur_vendor_rfq_write($path,$draft);
            $detail=['transport'=>$sender?'TEST_INJECTED':'AUTHENTICATED_SMTP',
                'accepted'=>false,'stage'=>'TRANSPORT_EXCEPTION','code'=>0,
                'response'=>'transport_exception','outcome'=>'UNCERTAIN'];
            try {
                $detail=$sender
                    ? ['transport'=>'TEST_INJECTED',
                        'accepted'=>(bool)$sender($message['to'],$message['subject'],$message['body']),
                        'stage'=>'INJECTED','code'=>0,'response'=>'test_sender',
                        'outcome'=>'TEST_ONLY']
                    : aur_vendor_rfq_send_transport_detail($message['to'],$message['subject'],
                        $message['body'],$draft['messages'][$i]['smtp_message_id']);
            } catch(Throwable $e) {
                error_log('AURENOEVA supplier SMTP exception: '.get_class($e));
            }
            $ok=$detail['accepted']===true;
            $draft['messages'][$i]['transport_result']=$detail;
            $draft['messages'][$i]['status']=$ok?'ACCEPTED_BY_MAIL_TRANSPORT':'TRANSPORT_FAILED_OR_UNCONFIRMED';
            $draft['messages'][$i]['sent_at']=$ok?gmdate('c'):null;
            $sentThisRequest++;
            aur_vendor_rfq_write($path,$draft);
        }
        $summary=aur_vendor_rfq_status_summary($draft);
        if($summary['remaining']===0) {
            $draft['status']=$summary['accepted']===$summary['total']
                ?'TRANSPORT_ACCEPTED':'PARTIAL_OR_FAILED';
        }
        $draft['dispatch_progress']=$summary+['processed_this_request'=>$sentThisRequest];
        aur_vendor_rfq_write($path,$draft);
        return $draft;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/**
 * Only the failed/unconfirmed legacy recipients, not the ten accepted ones,
 * qualify for a single explicit recovery attempt. We retain the old result.
 */
function aur_vendor_rfq_recoverable_count(array $draft):int {
    $count=0;
    foreach(($draft['messages']??[]) as $message) {
        if(($message['status']??'')==='TRANSPORT_FAILED_OR_UNCONFIRMED'
            && !array_key_exists('recovery',$message))$count++;
    }
    return $count;
}
/**
 * Owner-approved recovery of prior ambiguous attempts. This is NOT an
 * automatic retry: a fresh human review and duplicate-risk acknowledgement
 * are mandatory, and each historical recipient has at most ONE recovery
 * attempt (including SMTP rejection or an interrupted HTTP request).
 */
function aur_vendor_rfq_recover(
    string $store,array $case,string $draftId,
    bool $manualComplianceReview,bool $duplicateRiskAcknowledged,
    ?callable $sender=null,int $batchLimit=3
):array {
    aur_embargo_assert_dispatch_ready($case);
    if(!$manualComplianceReview)
        throw new InvalidArgumentException('supplier_legal_review_acknowledgement_required');
    if(!$duplicateRiskAcknowledged)
        throw new InvalidArgumentException('rfq_duplicate_risk_acknowledgement_required');
    if($batchLimit<1||$batchLimit>5)
        throw new InvalidArgumentException('invalid_outbox_batch_size');
    $path=aur_vendor_rfq_path($store,(string)$case['reference'],$draftId);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('rfq_send_lock_unavailable');
    try {
        $draft=aur_vendor_rfq_read($store,$case,$draftId);
        if(($draft['information_only']??false)!==true||
           !is_array($draft['approval']??null)||
           !in_array(($draft['status']??''),['PARTIAL_OR_FAILED','RECOVERY_DISPATCHING','RECOVERY_PARTIAL'],true))
            throw new InvalidArgumentException('rfq_recovery_not_available');
        $parts=explode('-',(string)$case['reference']);
        $draftCountry=(string)($draft['destination_country']??($parts[1]??''));
        if($draftCountry!==(string)($case['country']??''))
            throw new InvalidArgumentException('destination_changed_redraft_required');
        if(($draft['recovery_approval']['duplicate_risk_acknowledged']??false)===false) {
            $draft['recovery_approval']=[
                'approved_at'=>gmdate('c'),
                'approved_by'=>'AUTHENTICATED_ADMIN',
                'duplicate_risk_acknowledged'=>true,
                'statement'=>'Prior SMTP outcomes are uncertain. A follow-up could be a duplicate. I have reviewed the destination and supplier restrictions and approve one additional attempt per failed/unconfirmed recipient.'
            ];
            aur_vendor_rfq_write($path,$draft);
        }
        if($sender===null&&aur_vendor_rfq_recoverable_count($draft)>0) {
            aur_vendor_rfq_smtp_config();
            if(!aur_vendor_rfq_mail_verified($store))
                throw new RuntimeException('rfq_smtp_test_required');
        }
        $draft['status']='RECOVERY_DISPATCHING';
        aur_vendor_rfq_write($path,$draft);
        $processed=0;
        foreach($draft['messages'] as $i=>$message) {
            if(($message['status']??'')!=='TRANSPORT_FAILED_OR_UNCONFIRMED'
               || array_key_exists('recovery',$message))continue;
            if($processed>=$batchLimit)break;
            $msgId=bin2hex(random_bytes(16)).'@aurenoeva.com';
            // Preserve the historical result and mark in-flight BEFORE SMTP.
            // A lost response will not cause a second email to this supplier.
            $draft['messages'][$i]['recovery']=[
                'attempted_at'=>gmdate('c'),
                'status'=>'IN_FLIGHT',
                'prior_status'=>(string)($message['status']??''),
                'prior_attempted_at'=>$message['attempted_at']??null,
                'prior_transport_result'=>$message['transport_result']??null,
                'message_id'=>$msgId
            ];
            $draft['messages'][$i]['status']='IN_FLIGHT';
            aur_vendor_rfq_write($path,$draft);
            $subject='Follow-up (same enquiry) — '.(string)$message['subject'];
            $body="DELIVERY CHECK — SAME RFQ REFERENCE, NOT A NEW REQUEST\n\n".
                "We experienced an outbound mail problem. If you have already received this RFQ, please disregard this duplicate and respond only once. No second quotation is required.\n\n".
                (string)$message['body'];
            $detail=['transport'=>$sender?'TEST_INJECTED':'AUTHENTICATED_SMTP',
                'accepted'=>false,'stage'=>'TRANSPORT_EXCEPTION','code'=>0,
                'response'=>'transport_exception','outcome'=>'UNCERTAIN'];
            try {
                $detail=$sender
                    ? ['transport'=>'TEST_INJECTED',
                       'accepted'=>(bool)$sender($message['to'],$subject,$body),
                       'stage'=>'INJECTED','code'=>0,'response'=>'test_sender',
                       'outcome'=>'TEST_ONLY']
                    : aur_vendor_rfq_send_transport_detail(
                        (string)$message['to'],$subject,$body,$msgId);
            } catch(Throwable $e) {
                error_log('AURENOEVA RFQ recovery SMTP exception: '.get_class($e));
            }
            $ok=($detail['accepted']??false)===true;
            $draft['messages'][$i]['recovery']['status']=$ok?'ACCEPTED_BY_MAIL_TRANSPORT':'FAILED_OR_UNCONFIRMED';
            $draft['messages'][$i]['recovery']['finished_at']=gmdate('c');
            $draft['messages'][$i]['recovery']['transport_result']=$detail;
            $draft['messages'][$i]['status']=$ok
                ?'ACCEPTED_BY_MAIL_TRANSPORT':'TRANSPORT_FAILED_OR_UNCONFIRMED';
            $draft['messages'][$i]['sent_at']=$ok?gmdate('c'):null;
            $processed++;
            aur_vendor_rfq_write($path,$draft);
        }
        $summary=aur_vendor_rfq_status_summary($draft);
        $recoverable=aur_vendor_rfq_recoverable_count($draft);
        $summary['recovery_remaining']=$recoverable;
        $summary['processed_this_request']=$processed;
        $draft['status']=$recoverable>0?'RECOVERY_DISPATCHING':
            ($summary['accepted']===$summary['total']?'TRANSPORT_ACCEPTED':'RECOVERY_PARTIAL');
        $draft['dispatch_progress']=$summary;
        aur_vendor_rfq_write($path,$draft);
        return $draft;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

/**
 * Owner-triggered LIVE mail smoke test. It exercises the identical transport
 * as selected supplier RFQs, but only to the fixed owner's Gmail address.
 * It is never triggered by supplier scanning or page loading.
 */
function aur_vendor_rfq_mail_verified(string $store):bool {
    $smoke=aur_vendor_rfq_smoke_status($store);
    if(!is_array($smoke)||($smoke['status']??'')!=='ACCEPTED_BY_MAIL_TRANSPORT')
        return false;
    if(($smoke['transport_result']['transport']??'')!=='AUTHENTICATED_SMTP')
        return false;
    if(time()-(int)($smoke['timestamp']??0)>86400)return false;
    $secret=aur_private_directory($store,'supplier-rfqs').'/smtp-credential.json';
    // Any credential rotation invalidates the last successful test.
    if(is_file($secret) && (int)filemtime($secret)>(int)($smoke['timestamp']??0))
        return false;
    return true;
}
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
        if(is_array($previous)&&($previous['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT'
           && ($sender!==null||aur_vendor_rfq_mail_verified($store))
           && time()-(int)($previous['timestamp']??0)<900){
            $previous['recent_test_preserved']=true;
            return $previous;
        }
        $recipient='info@aurenoeva.com';
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
        $detail=['transport'=>$sender?'TEST_INJECTED':'AUTHENTICATED_SMTP',
            'accepted'=>false,'stage'=>'TRANSPORT_EXCEPTION','code'=>0,
            'response'=>'transport_exception','outcome'=>'UNCERTAIN'];
        try{
            $detail=$sender
                ? ['transport'=>'TEST_INJECTED','accepted'=>(bool)$sender($recipient,$subject,$body),
                    'stage'=>'INJECTED','code'=>0,'response'=>'test_sender','outcome'=>'TEST_ONLY']
                : aur_vendor_rfq_send_transport_detail($recipient,$subject,$body);
        }catch(Throwable $e){error_log('AURENOEVA test SMTP exception: '.get_class($e));}
        $record['transport_result']=$detail;
        $record['status']=$detail['accepted']?'ACCEPTED_BY_MAIL_TRANSPORT':'TRANSPORT_FAILED_OR_UNCONFIRMED';
        $record['finished_at']=gmdate('c');
        aur_vendor_rfq_write($file,$record);
        return $record;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
