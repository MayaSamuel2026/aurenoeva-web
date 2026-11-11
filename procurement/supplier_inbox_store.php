<?php
declare(strict_types=1);
/** Persisted, read-only mailbox evidence and conservative sent-RFQ matching. */
require_once __DIR__.'/private_cases.php';
require_once __DIR__.'/supplier_rfq.php';
require_once __DIR__.'/supplier_replies.php';
require_once __DIR__.'/supplier_inbox_mime.php';
function aur_mail_dir(string $store):string {return aur_private_directory($store,'supplier-inbox');}
function aur_mail_read_json(string $path,array $default=[]):array {
    if(!is_file($path)||is_link($path))return $default;
    $json=json_decode((string)file_get_contents($path),true);
    return is_array($json)?$json:$default;
}
function aur_mail_write_json(string $path,array $data):void {
    if(is_link($path))throw new RuntimeException('unsafe_mailbox_path');
    $temp=dirname($path).'/.tmp-'.bin2hex(random_bytes(8));
    $value=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(file_put_contents($temp,$value,LOCK_EX)!==strlen($value))throw new RuntimeException('mailbox_write_failed');
    chmod($temp,0600);
    if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('mailbox_commit_failed');}
}
function aur_mail_state(string $store):array {
    return aur_mail_read_json(aur_mail_dir($store).'/state.json',
        ['uidvalidity'=>0,'last_uid'=>0,'checked_at'=>null,'status'=>'NOT_SYNCED']);
}
function aur_mail_events(string $store):array {
    $data=aur_mail_read_json(aur_mail_dir($store).'/events.json');
    return is_array($data['events']??null)?$data['events']:[];
}
function aur_mail_case_events(string $store,string $reference):array {
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$reference))return [];
    $events=[];
    foreach(aur_mail_events($store) as $event){
        if(!is_array($event))continue;
        $savedReference=(string)($event['case_reference']??'');
        if($savedReference===$reference){
            $events[]=$event;
        }elseif($savedReference==='' &&
                str_contains((string)($event['subject']??''),$reference)){
            // Historical inbox messages predating full batch matching stay
            // source-immutable. Show a read-only case reference projection;
            // sender, actual batch and quoted items remain unverified.
            $view=$event;
            $view['case_reference']=$reference;
            $view['matched_to_sent_rfq']=false;
            $view['requires_sender_review']=true;
            $view['case_reference_projection_only']=true;
            $events[]=$view;
        }
    }
    usort($events,static fn($a,$b)=>strcmp((string)($b['processed_at']??''),(string)($a['processed_at']??'')));
    return $events;
}
function aur_mail_match(string $store,array $mail,array $classification):array {
    $reference='';
    if(preg_match('/AUR-[A-Z]{2}-[A-F0-9]{16}/',(string)($mail['subject']??''),$m))
        $reference=$m[0];
    $failedEmail='';
    if(($classification['status']??'')==='DELIVERY_FAILED'){
        if(preg_match('/<?([a-z0-9._%+\\-]+@[a-z0-9.\\-]+\\.[a-z]{2,})>?\\s*:\\s*(?:host|delivery|[0-9]{3})/i',
            (string)($mail['text']??''),$m))
            $failedEmail=strtolower($m[1]);
    }
    $sender=strtolower(trim((string)($mail['from']??'')));
    $hits=[];$referencedCase='';
    // An explicit RFQ reference identifies exactly one original saved case.
    // Avoid scanning unrelated cases or losing older cases in list pagination.
    $records=$reference!==''?[['reference'=>$reference]]
        :(aur_case_list($store)['cases']??[]);
    foreach($records as $record){
        if($reference!==''&&($record['reference']??'')!==$reference)continue;
        try{$case=aur_case_read($store,(string)$record['reference']);}
        catch(Throwable $error){continue;}
        if($reference!==''&&$case['reference']===$reference)
            $referencedCase=$reference;
        // All saved batches belong to ONE original RFQ. The previous matcher
        // read only the preferred batch, losing replies to later suppliers.
        foreach(aur_vendor_rfq_list($store,$case) as $version){
            try{$draft=aur_vendor_rfq_read($store,$case,(string)$version['id']);}
            catch(Throwable $error){continue;}
            foreach($draft['messages']??[] as $message){
                if(!is_array($message)||!in_array(($message['status']??''),
                    ['ACCEPTED_BY_MAIL_TRANSPORT','TRANSPORT_FAILED_OR_UNCONFIRMED','IN_FLIGHT'],true))continue;
                $recipient=strtolower(trim((string)($message['to']??'')));
                $matched=($classification['status']??'')==='DELIVERY_FAILED'
                    ?($failedEmail!==''&&$failedEmail===$recipient)
                    :($sender!==''&&$sender===$recipient);
                if($matched)$hits[]=['matched'=>true,'case'=>$case,'draft'=>$draft,
                    'message'=>$message,'email'=>$recipient];
            }
        }
    }
    // No arbitrary first match: two saved batches for the same sender can
    // carry different items. Leave quote attribution to owner review.
    if(count($hits)===1)return $hits[0];
    return ['matched'=>false,'case_reference'=>$referencedCase,
            'email'=>$failedEmail!==''?$failedEmail:$sender,
            'ambiguous'=>count($hits)>1];
}

function aur_mail_save_offer(string $store,array $match,array $cls,array $event):bool {
    if(!in_array($cls['status'],['QUOTE_RECEIVED','NOT_AVAILABLE','ALTERNATIVE_OFFERED'],true)
       ||count($cls['items'])!==1)return false;
    if($cls['status']==='QUOTE_RECEIVED'&&($cls['price']===''||$cls['currency']===''))return false;
    $item=(int)$cls['items'][0];
    $case=$match['case'];$message=$match['message'];$found=[];
    foreach($message['items']??[] as $sent){
        foreach($case['payload']['sourcing_lines']??[] as $line){
            if((int)($sent['source_row']??0)===(int)($line['source_row']??-1)
                &&(int)($line['customer_item_number']??0)===$item)
                $found[(int)$line['source_row']]=$line;
        }
    }
    // Ambiguous buyer numbers that have several alternatives need human review.
    if(count($found)!==1)return false;
    $line=reset($found);
    $key=hash('sha256',$match['email']).'-'.(int)$line['source_row'];
    $path=aur_reply_path($store,$case);
    $lock=fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('mail_quote_lock_failed');
    try {
        $existing=aur_reply_read($store,$case);
        if(isset($existing[$key]))return false; // Manual and earlier offers stay authoritative.
        $entry=['supplier'=>(string)($message['vendor_name']??$match['email']),
            'email'=>$match['email'],'source_row'=>(int)$line['source_row'],
            'item_number'=>$item,'description'=>(string)$line['description_original'],
            'availability'=>match($cls['status']){
                'NOT_AVAILABLE'=>'NOT_AVAILABLE','ALTERNATIVE_OFFERED'=>'ALTERNATIVE_OFFERED',
                default=>'QUOTED'},
            'unit_price'=>$cls['price'],'currency'=>$cls['currency'],
            'lead_time'=>substr((string)$cls['lead_time'],0,100),
            'reply_reference'=>substr((string)$event['subject'],0,180),
            'notes'=>'Extracted from supplier email; original message retained for verification.',
            'recorded_at'=>gmdate('c'),'evidence'=>'MAILBOX_IMAP_UID_VERIFIED',
            'source_message_key'=>$event['key'],'source_message_id'=>$event['message_id']];
        $existing[$key]=$entry;
        aur_vendor_rfq_write($path,['schema'=>'aurenoeva-supplier-replies/1',
            'case_reference'=>$case['reference'],'source_sha256'=>$case['source_sha256'],
            'entries'=>$existing,'updated_at'=>gmdate('c')]);
        return true;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function aur_mail_import(string $store,int $uid,int $uidvalidity,string $raw):array {
    $key=hash('sha256',$uidvalidity.':'.$uid);
    $all=aur_mail_events($store);
    if(isset($all[$key]))return $all[$key];
    $mail=$raw!==''?aur_mail_parse($raw):
        ['from'=>'','subject'=>'Oversized email','message_id'=>'','received_at'=>'','text'=>''];
    $cls=$raw!==''?aur_mail_classify($mail):
        ['status'=>'TOO_LARGE','body'=>'','items'=>[],'price'=>'','currency'=>'','lead_time'=>''];
    $match=aur_mail_match($store,$mail,$cls);
    $ref=$match['matched']?(string)$match['case']['reference']
        :(string)($match['case_reference']??'');
    $event=['key'=>$key,'uid'=>$uid,'uidvalidity'=>$uidvalidity,
        'case_reference'=>$ref,'draft_id'=>$match['matched']?(string)$match['draft']['draft_id']:'',
        'from'=>$mail['from'],'subject'=>$mail['subject'],
        'supplier'=>(string)($match['message']['vendor_name']??''),
        'supplier_email'=>$match['email'],'message_id'=>$mail['message_id'],
        'received_at'=>$mail['received_at'],'processed_at'=>gmdate('c'),
        'classification'=>$cls['status'],'buyer_items'=>$cls['items'],
        'unit_price'=>$cls['price'],'currency'=>$cls['currency'],
        'lead_time'=>$cls['lead_time'],
        'preview'=>substr(preg_replace('/\s+/u',' ',(string)$cls['body'])??(string)$cls['body'],0,450),
        'matched_to_sent_rfq'=>$match['matched'],
        'requires_sender_review'=>!$match['matched']&&$ref!=='',
        'quote_recorded'=>false,
        'evidence_file'=>$raw!==''?$key.'.eml':''];
    if($raw!==''){
        $evidence=aur_mail_dir($store).'/'.$key.'.eml';
        if(!is_file($evidence)){
            if(file_put_contents($evidence,$raw,LOCK_EX)!==strlen($raw))throw new RuntimeException('mail_evidence_write_failed');
            chmod($evidence,0600);
        }
    }
    if($match['matched'])$event['quote_recorded']=aur_mail_save_offer($store,$match,$cls,$event);
    $all[$key]=$event;
    aur_mail_write_json(aur_mail_dir($store).'/events.json',
        ['schema'=>'aurenoeva-mailbox-events/1','mailbox'=>'info@aurenoeva.com',
            'events'=>$all,'updated_at'=>gmdate('c')]);
    return $event;
}
