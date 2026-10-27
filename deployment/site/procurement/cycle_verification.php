<?php
declare(strict_types=1);
/**
 * WP09 independent evidence verification.
 * Real cycles require persisted buyer RFQ, verified SMTP 250 supplier send,
 * a separately matched and saved IMAP supplier quote, and independently
 * confirmed buyer-update SMTP acceptance. NEVER equate with a purchase.
 * This module only READS real histories; does not send messages or mutate cases.
 */
require_once __DIR__.'/supplier_inbox_store.php';
require_once __DIR__.'/mail_center.php';

function aur_wp09_smtp_250(array $result):bool {
    return ($result['transport']??'')==='AUTHENTICATED_SMTP'
        && ($result['stage']??'')==='DATA_ACCEPTANCE'
        && (int)($result['code']??0)===250
        && ($result['outcome']??'')==='SMTP_ACCEPTED';
}

function aur_wp09_supplier_evidence(string $store,array $case):array {
    $out=['supplier_smtp_confirmed'=>false,'smtp_recipients'=>[],
          'quote_confirmed'=>false,'quote_count'=>0,'quote_keys'=>[],
          'last_quote_at'=>''];
    $verifiedRecipients=[];
    foreach(aur_vendor_rfq_list($store,$case) as $saved){
        $draft=aur_vendor_rfq_read($store,$case,(string)$saved['id']);
        foreach($draft['messages']??[] as $message){
            if(($message['status']??'')!=='ACCEPTED_BY_MAIL_TRANSPORT')continue;
            $email=strtolower(trim((string)($message['to']??'')));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))continue;
            $evidence=$message['transport_result']??null;
            if(is_array($message['recovery']??null))
                $evidence=$message['recovery']['transport_result']??$evidence;
            if(!is_array($evidence)||!aur_wp09_smtp_250($evidence))continue;
            $verifiedRecipients[$email]=true;
        }
    }
    $out['supplier_smtp_confirmed']=count($verifiedRecipients)>0;
    // No supplier names, addresses or buyer data leave the private verifier.
    $out['smtp_recipient_count']=count($verifiedRecipients);
    $eventIndex=[];
    foreach(aur_mail_case_events($store,(string)$case['reference']) as $event){
        if(!empty($event['quote_recorded'])&&($event['classification']??'')==='QUOTE_RECEIVED'
           && !empty($event['matched_to_sent_rfq'])
           && is_string($event['key']??null))$eventIndex[$event['key']]=$event;
    }
    try{$entries=aur_reply_read($store,$case);}catch(Throwable $e){throw new RuntimeException('wp09_quote_register_unavailable',0,$e);}
    $count=0;$latest=0;
    foreach($entries as $reply){
        if(!is_array($reply)
           ||($reply['evidence']??'')!=='MAILBOX_IMAP_UID_VERIFIED')continue;
        $key=(string)($reply['source_message_key']??'');
        if(!isset($eventIndex[$key]))continue;
        $event=$eventIndex[$key];
        $supplier=strtolower(trim((string)($reply['email']??'')));
        if(!isset($verifiedRecipients[$supplier])
           ||strtolower((string)($event['supplier_email']??''))!==$supplier)continue;
        $file=(string)($event['evidence_file']??'');
        if($file!==$key.'.eml'||!preg_match('/^[a-f0-9]{64}\.eml$/D',$file)
           ||!is_file(aur_mail_dir($store).'/'.$file))continue;
        if((int)($reply['source_row']??0)<1 || !is_string($reply['unit_price']??null)
           ||!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',$reply['unit_price'])
           ||!preg_match('/^[A-Z]{3}$/D',(string)($reply['currency']??'')))continue;
        // Require an original request line matching this saved quotation.
        $matched=false;
        foreach(($case['payload']['sourcing_lines']??[]) as $line) {
            if((int)($line['source_row']??-1)===(int)$reply['source_row']){$matched=true;break;}
        }
        if(!$matched)continue;
        $count++;
        $latest=max($latest,strtotime((string)($reply['recorded_at']??''))?:0);
        $out['quote_keys'][]=$key;
    }
    $out['quote_confirmed']=$count>0;
    $out['quote_count']=$count;
    $out['last_quote_at']=$latest?gmdate('c',$latest):'';
    unset($out['smtp_recipients'],$out['quote_keys']);
    return $out;
}

function aur_wp09_buyer_evidence(array $case,array $sent,string $notBefore):array {
    $buyer=strtolower(trim((string)($case['buyer']['email']??'')));
    $ref=trim((string)($case['public_reference']??''));
    if(!filter_var($buyer,FILTER_VALIDATE_EMAIL)||$ref==='')return ['confirmed'=>false,'accepted_at'=>''];
    $subject='AURENOEVA | Supplier update | '.substr($ref,0,120);
    $since=$notBefore!==''?(strtotime($notBefore)?:0):0;
    foreach($sent as $mail){
        if(!is_array($mail)||($mail['status']??'')!=='SMTP_ACCEPTED'
            ||strtolower(trim((string)($mail['to']??'')))!==$buyer
            ||trim((string)($mail['subject']??''))!==$subject
            ||(int)($mail['smtp_code']??0)!==250
            ||($mail['smtp_stage']??'')!=='DATA_ACCEPTANCE'
            ||($mail['smtp_outcome']??'')!=='SMTP_ACCEPTED')continue;
        $date=(string)($mail['created_at']??'');
        if(!$date||!(strtotime($date)?:0)|| (strtotime($date)?:0)<$since)continue;
        return ['confirmed'=>true,'accepted_at'=>$date];
    }
    return ['confirmed'=>false,'accepted_at'=>''];
}

function aur_wp09_cycle_case(string $store,array $case,?array $events=null,?array $sent=null):array {
    $ref=(string)($case['reference']??'');
    $valid=($case['source']??'')==='PUBLIC_WEBSITE'
        && preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref)
        && !empty($case['public_reference'])
        && filter_var((string)($case['buyer']['email']??''),FILTER_VALIDATE_EMAIL)
        && !empty($case['payload']['sourcing_lines']);
    $base=['reference'=>$ref,'created_at'=>(string)($case['created_at']??''),
        'source'=>'PUBLIC_WEBSITE','stage'=>'INCOMPLETE_INTAKE',
        'buyer_rfq_saved'=>(bool)$valid,'supplier_smtp_250'=>false,
        'supplier_quote_verified'=>false,'verified_quote_items'=>0,
        'buyer_update_smtp_250'=>false,'buyer_update_at'=>'',
        'purchase_confirmed'=>false,'commercial_transaction_verified'=>false,
        'next_action'=>'Review original website enquiry'];
    if(!$valid)return $base;
    $base['stage']='RFQ_CAPTURED';
    $base['next_action']='Review destination and supplier matches';
    $supplier=aur_wp09_supplier_evidence($store,$case);
    $base['supplier_smtp_250']=$supplier['supplier_smtp_confirmed'];
    if(!$base['supplier_smtp_250'])return $base;
    $base['stage']='SUPPLIER_SMTP_ACCEPTED';
    $base['next_action']='Await matched supplier quotation';
    $base['supplier_quote_verified']=$supplier['quote_confirmed'];
    $base['verified_quote_items']=$supplier['quote_count'];
    if(!$base['supplier_quote_verified'])return $base;
    $base['stage']='SUPPLIER_QUOTE_VERIFIED';
    $base['next_action']='Review and send buyer update';
    // No IMAP network read here. Only private SMTP records written by Mail Center.
    $sent=$sent??aur_mailcenter_local($store);
    $buyer=aur_wp09_buyer_evidence($case,$sent,$supplier['last_quote_at']);
    $base['buyer_update_smtp_250']=$buyer['confirmed'];
    $base['buyer_update_at']=$buyer['accepted_at'];
    if($buyer['confirmed']){
        $base['stage']='BUYER_UPDATE_SMTP_ACCEPTED';
        $base['next_action']='Optionally request direct transaction outcome confirmation';
    }
    return $base;
}

function aur_wp09_cycle_report(string $store,int $limit=250):array {
    $limit=max(1,min(250,$limit));
    $cases=aur_case_list($store)['cases']??[];
    // Reading local sent and persisted mailbox events has no outgoing network effects.
    $sent=aur_mailcenter_local($store);
    $summary=['website_rfqs'=>0,'supplier_smtp_250'=>0,'supplier_quotes_verified'=>0,
              'buyer_updates_smtp_250'=>0,'purchases_confirmed'=>0];
    $rows=[];
    foreach(array_slice($cases,0,$limit) as $rec){
        $case=aur_case_read($store,(string)$rec['reference']);
        if(($case['source']??'')!=='PUBLIC_WEBSITE')continue;
        $row=aur_wp09_cycle_case($store,$case,null,$sent);
        $rows[]=$row;
        if($row['buyer_rfq_saved'])$summary['website_rfqs']++;
        if($row['supplier_smtp_250'])$summary['supplier_smtp_250']++;
        if($row['supplier_quote_verified'])$summary['supplier_quotes_verified']++;
        if($row['buyer_update_smtp_250'])$summary['buyer_updates_smtp_250']++;
    }
    return ['schema'=>'aurenoeva-wp09-cycle-verification/1',
            'checked_at'=>gmdate('c'),'cases_scanned'=>count(array_slice($cases,0,$limit)),
            'limited'=>count($cases)>$limit,
            'summary'=>$summary,'cases'=>$rows,
            'note'=>'SMTP acceptance is not recipient inbox delivery. No purchase is inferred.'];
}
