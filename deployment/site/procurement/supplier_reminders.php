<?php
declare(strict_types=1);
/**
 * Short, supplier-specific quotation reminders for the existing procurement
 * control center. One private ledger per case, derived ONLY from originally
 * accepted RFQ recipients and their saved source-row assignments.
 * Never dispatch during draft preparation, page reads or inbox sync.
 */
require_once __DIR__.'/supplier_rfq.php';
require_once __DIR__.'/supplier_inbox_store.php';

function aur_price_reminder_path(string $store,array $case):string {
    $ref=(string)($case['reference']??'');
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))
        throw new InvalidArgumentException('invalid_case_reference');
    return aur_private_directory($store,'supplier-rfqs').'/'.$ref.'-price-reminders.json';
}
function aur_price_reminder_read(string $store,array $case):?array {
    $path=aur_price_reminder_path($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('unsafe_reminder_ledger');
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??'')!=='aurenoeva-price-reminders/1'
      ||($data['case_reference']??'')!==($case['reference']??'')
      ||($data['case_source_sha256']??'')!==($case['source_sha256']??''))
        throw new RuntimeException('reminder_case_mismatch');
    return $data;
}
/**
 * Exact duplicate specifications get ONE unit-price line, referencing every
 * buyer number and explicitly leaving the aggregate quantity unresolved.
 * Different models (including alternatives) remain separately visible.
 */
function aur_price_reminder_lines(array $case,array $sentItems):array {
    $canonical=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $line)
        $canonical[(int)($line['source_row']??0)]=$line;
    $unique=[];
    foreach($sentItems as $sent) {
        $row=(int)($sent['source_row']??0);
        if($row>0&&isset($canonical[$row]))$unique[$row]=$canonical[$row];
    }
    $groups=[];$order=[];
    foreach($unique as $row=>$line) {
        $description=trim((string)($line['description_original']??''));
        $norm=aur_rfq_line_normalize($description);
        if($norm==='')continue;
        $type=(string)($line['option_type']??'REQUESTED');
        $key=$norm; // Same exact model cannot become two purchasing lines.
        if(!isset($groups[$key])) {
            $groups[$key]=['source_rows'=>[],'buyer_items'=>[],
              'description'=>$description,'quantity'=>$line['quantity']??null,
              'option_type'=>$type,'duplicate_quantity_unresolved'=>false];
            $order[]=$key;
        }
        $groups[$key]['source_rows'][]=$row;
        $groups[$key]['buyer_items'][]=(int)($line['customer_item_number']??0);
        if(count($groups[$key]['source_rows'])>1) {
            $groups[$key]['quantity']=null;
            $groups[$key]['duplicate_quantity_unresolved']=true;
        }
    }
    $lines=[];
    foreach($order as $key) {
        $group=$groups[$key];
        $group['buyer_items']=array_values(array_unique($group['buyer_items']));
        $lines[]=$group;
    }
    $review=aur_rfq_line_review(array_values($unique));
    return ['lines'=>$lines,'duplicate_pairs'=>$review['duplicate_pairs'],
      'original_selected_rows'=>count($unique),
      'distinct_quotation_lines'=>count($lines)];
}
function aur_price_reminder_text(array $case,array $original,array $selection):array {
    $ref=(string)$case['reference'];
    $email=strtolower(trim((string)($original['to']??'')));
    $name=trim((string)($original['vendor_name']??'Supplier'));
    $subject='Reminder | AURENOEVA RFQ '.$ref.' | Price and delivery times';
    $body="Dear Sales Team,\n\n"
        ."We are following up on our earlier enquiry ".$ref." for delivery to "
        .(string)($case['country']??'UZ').". We would appreciate a brief quotation for "
        ."the items below. A partial reply is absolutely fine.\n\n"
        ."PLEASE QUOTE — UNIT PRICE (WITH CURRENCY) AND DELIVERY LEAD TIME\n"
        .str_repeat('-',62)."\n";
    foreach($selection['lines'] as $n=>$item) {
        $refs=implode(', ',array_map('strval',$item['buyer_items']));
        $option=$item['option_type']==='ALTERNATIVE'?' [ALTERNATIVE OPTION]':'';
        $qty=$item['duplicate_quantity_unresolved']
          ?'to confirm (repeated in original list)'
          :($item['quantity']===null?'to confirm':(string)$item['quantity']);
        $body.=($n+1).'. Customer item'.(count($item['buyer_items'])>1?'s ':' ')
            .$refs.$option."\n"
            ."   ".$item['description']."\n"
            ."   Quantity: ".$qty."\n"
            ."   Unit price / currency: __________   Delivery lead time: __________\n\n";
    }
    $body.="Please identify any unavailable items; a clearly labelled equivalent may "
        ."be suggested. Alternative models are options, not additional units.\n";
    if(count($selection['duplicate_pairs'])>0) {
        $body.="Identical specifications appearing more than once in the original "
            ."enquiry are shown only once for unit pricing; total required quantity "
            ."is awaiting buyer confirmation.\n";
    }
    $body.="\nFor now, we need only the unit price, currency and estimated delivery "
        ."time. Please reply to this email, quoting the RFQ reference.\n\n"
        ."This is a non-binding information request, not an order. Any eventual "
        ."purchase would be agreed directly between buyer and supplier, subject "
        ."to the necessary trade and regulatory checks.\n\n"
        ."Thank you for your assistance.\n\n"
        ."Kind regards,\nAURENOEVA Purchasing\n"
        ."Operated by NOEVA Systems e.K.\ninfo@aurenoeva.com\n";
    return ['subject'=>$subject,'body'=>$body,'supplier_name'=>$name,'to'=>$email];
}

/**
 * German supplier-specific reminder. Always use the saved, originally selected
 * source rows. This is a LANGUAGE transformation, not another supplier search.
 * Identical DS.Surg 150 references 12/22 are represented ONCE without
 * assuming that either the buyer's aggregate quantity is one or two.
 */
function aur_price_reminder_kombimed_german(array $case,array $msg):array {
    if(strtolower(trim((string)($msg['to']??'')))!=='sales@kombi-med.com')
        throw new InvalidArgumentException('german_version_only_for_kombimed');
    $original=$msg['lines']??[];
    if(!is_array($original)||!$original)throw new InvalidArgumentException('empty_supplier_item_mapping');
    $lines=[];$dssurg=null;
    foreach($original as $item) {
        if(!is_array($item))continue;
        $spec=(string)($item['description']??'');
        if(preg_match('/\bds[\W_]*surg[\W_]*150\b/iu',$spec)) {
            if($dssurg===null) {
                $dssurg=$item;
                $dssurg['duplicate_quantity_unresolved']=
                    ($item['duplicate_quantity_unresolved']??false)
                    ||count(array_unique((array)($item['buyer_items']??[])))>1;
            } else {
                $dssurg['buyer_items']=array_values(array_unique(array_merge(
                    (array)$dssurg['buyer_items'],(array)($item['buyer_items']??[]))));
                $dssurg['source_rows']=array_values(array_unique(array_merge(
                    (array)$dssurg['source_rows'],(array)($item['source_rows']??[]))));
                $dssurg['quantity']=null;
                $dssurg['duplicate_quantity_unresolved']=true;
            }
        }else $lines[]=$item;
    }
    if($dssurg!==null)$lines[]=$dssurg;
    usort($lines,static function($a,$b):int {
        $aItems=(array)($a['buyer_items']??[]);
        $bItems=(array)($b['buyer_items']??[]);
        return ((int)($aItems[0]??0))<=>((int)($bItems[0]??0));
    });
    $ref=(string)$case['reference'];
    $body="Sehr geehrtes KombiMED-Vertriebsteam,\n\n"
        ."wir möchten freundlich an unsere Anfrage ".$ref." erinnern.\n"
        ."Für die nachfolgend aufgeführten Medizingeräte benötigen wir "
        ."vorerst lediglich den Einzelpreis mit Währung sowie die "
        ."voraussichtliche Lieferzeit für die Lieferung nach Usbekistan.\n\n"
        ."Auch ein Teilangebot für die von Ihnen lieferbaren Positionen "
        ."wäre uns sehr willkommen.\n\n"
        ."GEWÜNSCHTE ANGABEN: EINZELPREIS / WÄHRUNG / LIEFERZEIT\n"
        .str_repeat('-',61)."\n";
    foreach($lines as $i=>$item) {
        $refs=array_values(array_unique(array_map('intval',(array)($item['buyer_items']??[]))));
        $option=(string)($item['option_type']??'REQUESTED')==='ALTERNATIVE'?' (OPTION/ALTERNATIVE)':'';
        $qty=($item['duplicate_quantity_unresolved']??false)
            ?'noch zu bestätigen (Doppeleintrag in der Ursprungsliste)'
            :(($item['quantity']??null)===null?'noch zu bestätigen':(string)$item['quantity']);
        $body.=($i+1).'. Kundenposition'.(count($refs)>1?'en ':' ')
            .implode(', ',$refs).$option."\n"
            ."   ".trim((string)($item['description']??''))."\n"
            ."   Menge: ".$qty."\n"
            ."   Einzelpreis / Währung: __________   Lieferzeit: __________\n\n";
    }
    $body.="Die aufgeführten Alternativmodelle sind Wahlmöglichkeiten und "
        ."keine zusätzlichen Bestellmengen.\n";
    $ambig=array_filter($lines,static fn($item)=>($item['duplicate_quantity_unresolved']??false));
    if($ambig)$body.="Der doppelt aufgeführte Artikel DS.Surg 150 "
        ."(Kundenpositionen 12 und 22) ist für die Preisangabe zusammengefasst. "
        ."Die insgesamt benötigte Menge wird noch mit dem Käufer geklärt.\n";
    $body.="\nFalls einzelne Artikel nicht verfügbar sind, genügt uns auch "
        ."eine kurze Rückmeldung; gleichwertige Alternativen können gerne "
        ."separat gekennzeichnet werden.\n\n"
        ."Vielen Dank im Voraus. Wir würden uns über eine kurze Antwort "
        ."unter Angabe der RFQ-Referenz freuen.\n\n"
        ."Diese Anfrage ist unverbindlich und stellt keine Bestellung dar. "
        ."Etwaige Geschäfte erfolgen direkt zwischen Käufer und Verkäufer, "
        ."vorbehaltlich erforderlicher handels- und regulatorischer Prüfungen.\n\n"
        ."Mit freundlichen Grüßen\nAURENOEVA Einkauf\n"
        ."Ein Geschäftsbereich von NOEVA Systems e.K.\ninfo@aurenoeva.com\n";
    return [
      'subject'=>'Freundliche Erinnerung | AURENOEVA RFQ '.$ref.' | Preise und Lieferzeiten',
      'body'=>$body,
      'language'=>'de',
      'german_lines'=>$lines,
    ];
}
/**
 * An authenticated owner action replaces one UNSENT draft with its German
 * variant. Locked, audited, idempotent; no message is dispatched here.
 */
function aur_price_reminder_kombimed_translate(string $store,array $case,string $recipientToken):array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$recipientToken))
        throw new InvalidArgumentException('invalid_reminder_selection');
    $path=aur_price_reminder_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('reminder_lock_unavailable');
    try {
        $data=aur_price_reminder_read($store,$case);
        if($data===null)throw new InvalidArgumentException('reminder_not_prepared');
        $found=false;
        foreach($data['messages'] as $i=>$msg) {
            if(!hash_equals((string)($msg['recipient_token']??''),$recipientToken))continue;
            if(strtolower(trim((string)($msg['to']??'')))!=='sales@kombi-med.com')
                throw new InvalidArgumentException('german_version_only_for_kombimed');
            if(($msg['status']??'')!=='DRAFT')
                throw new InvalidArgumentException('reminder_already_attempted_immutable');
            $found=true;
            if(($msg['language']??'')==='de')break;
            $de=aur_price_reminder_kombimed_german($case,$msg);
            $data['messages'][$i]['original_english']=[
                'subject'=>(string)($msg['subject']??''),
                'body'=>(string)($msg['body']??'')];
            $data['messages'][$i]['subject']=$de['subject'];
            $data['messages'][$i]['body']=$de['body'];
            $data['messages'][$i]['language']='de';
            $data['messages'][$i]['localized_at']=gmdate('c');
            $data['messages'][$i]['german_lines']=$de['german_lines'];
            $data['messages'][$i]['review_status']='MANUAL_GERMAN_DRAFT_REVIEW';
            aur_vendor_rfq_write($path,$data);
            break;
        }
        if(!$found)throw new InvalidArgumentException('unknown_reminder_recipient');
        return aur_price_reminder_read($store,$case)??$data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function aur_price_reminder_prepare(string $store,array $case):array {
    $path=aur_price_reminder_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('reminder_lock_unavailable');
    try {
        $previous=aur_price_reminder_read($store,$case);
        if($previous!==null)return $previous; // No silent regeneration or resend.
        $primary=aur_vendor_rfq_primary($store,$case);
        if($primary===null)throw new InvalidArgumentException('no_original_rfq_ledger');
        $original=aur_vendor_rfq_read($store,$case,(string)$primary['id']);
        $events=aur_mail_case_events($store,(string)$case['reference']);
        $byEmail=[];
        foreach($events as $event) {
            if(!is_array($event))continue;
            $key=strtolower(trim((string)($event['supplier_email']??'')));
            if($key!==''&&filter_var($key,FILTER_VALIDATE_EMAIL))$byEmail[$key][]=$event;
        }
        $messages=[];$notConfirmed=0;$noItems=0;
        $seen=[];
        foreach(($original['messages']??[]) as $message) {
            if(!is_array($message))continue;
            $email=strtolower(trim((string)($message['to']??'')));
            if(($message['status']??'')!=='ACCEPTED_BY_MAIL_TRANSPORT') {
                $notConfirmed++;
                continue;
            }
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||isset($seen[$email]))continue;
            $seen[$email]=true;
            $selection=aur_price_reminder_lines($case,(array)($message['items']??[]));
            if(!$selection['lines']){$noItems++;continue;}
            $details=aur_price_reminder_text($case,$message,$selection);
            $statuses=array_values(array_unique(array_map(
                static fn($ev)=>(string)($ev['classification']??''),$byEmail[$email]??[]
            )));
            if(in_array('DELIVERY_FAILED',$statuses,true)) {
                $safety='DELIVERY_FAILED_REVIEW';
            } elseif(array_intersect($statuses,['QUOTE_RECEIVED','NOT_AVAILABLE','ALTERNATIVE_OFFERED','CLARIFICATION_REQUIRED'])) {
                $safety='SUPPLIER_ALREADY_RESPONDED_REVIEW';
            } else {
                $safety='READY_FOR_OWNER_REVIEW';
            }
            if($selection['duplicate_pairs'] && $safety!=='DELIVERY_FAILED_REVIEW')
                $safety='DUPLICATE_QUANTITY_REVIEW';
            $messages[]=$details+[
              'recipient_token'=>hash('sha256',$email),
              'source_draft_id'=>(string)$original['draft_id'],
              'original_sent_at'=>$message['sent_at']??null,
              'lines'=>$selection['lines'],
              'duplicate_pairs'=>$selection['duplicate_pairs'],
              'original_selected_rows'=>$selection['original_selected_rows'],
              'reply_classifications'=>$statuses,
              'review_status'=>$safety,
              'status'=>'DRAFT','attempted_at'=>null,'sent_at'=>null
            ];
        }
        if(!$messages)throw new InvalidArgumentException('no_confirmed_supplier_recipients');
        $data=['schema'=>'aurenoeva-price-reminders/1',
          'case_reference'=>$case['reference'],
          'case_source_sha256'=>$case['source_sha256'],
          'original_draft_id'=>$primary['id'],
          'created_at'=>gmdate('c'),
          'status'=>'DRAFT_REVIEW',
          'excluded_unconfirmed_original_recipients'=>$notConfirmed,
          'excluded_missing_line_mapping'=>$noItems,
          'messages'=>$messages];
        aur_vendor_rfq_write($path,$data);
        return aur_price_reminder_read($store,$case)??$data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/**
 * A fresh owner action is required for each bounded batch. SMTP uncertainty
 * is terminal for that recipient: never auto-retry or erase its attempt.
 * An injected transport is for isolated staging tests only.
 */
function aur_price_reminder_send(string $store,array $case,array $tokens,
                                 bool $reviewed,bool $risksReviewed,
                                 ?callable $sender=null,int $batchLimit=3):array {
    if(!$reviewed)throw new InvalidArgumentException('supplier_legal_review_acknowledgement_required');
    if(!$risksReviewed)throw new InvalidArgumentException('reminder_review_acknowledgement_required');
    if($batchLimit<1||$batchLimit>5)throw new InvalidArgumentException('invalid_outbox_batch_size');
    if(count($tokens)<1||count($tokens)>300)throw new InvalidArgumentException('invalid_reminder_selection');
    aur_embargo_assert_dispatch_ready($case);
    $wanted=[];
    foreach($tokens as $token) {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))
            throw new InvalidArgumentException('invalid_reminder_selection');
        $wanted[$token]=true;
    }
    $path=aur_price_reminder_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('reminder_lock_unavailable');
    try {
        $data=aur_price_reminder_read($store,$case);
        if($data===null)throw new InvalidArgumentException('reminder_not_prepared');
        if((string)($data['original_draft_id']??'')==='')throw new RuntimeException('missing_original_rfq_provenance');
        $available=[];
        foreach($data['messages'] as $msg)$available[(string)$msg['recipient_token']]=true;
        foreach($wanted as $token=>$_)
            if(!isset($available[$token]))throw new InvalidArgumentException('unknown_reminder_recipient');
        if($sender===null){
            aur_vendor_rfq_smtp_config();
            if(!aur_vendor_rfq_mail_verified($store))
                throw new RuntimeException('rfq_smtp_test_required');
        }
        $sent=0;
        foreach($data['messages'] as $i=>$msg) {
            if(!isset($wanted[(string)$msg['recipient_token']])||($msg['status']??'')!=='DRAFT')continue;
            if($sent>=$batchLimit)break;
            if(($msg['review_status']??'')==='DELIVERY_FAILED_REVIEW')continue;
            $msgId=bin2hex(random_bytes(16)).'@aurenoeva.com';
            $data['messages'][$i]['status']='IN_FLIGHT';
            $data['messages'][$i]['attempted_at']=gmdate('c');
            $data['messages'][$i]['smtp_message_id']=$msgId;
            aur_vendor_rfq_write($path,$data);
            $detail=['transport'=>$sender?'TEST_INJECTED':'AUTHENTICATED_SMTP',
                'accepted'=>false,'stage'=>'TRANSPORT_EXCEPTION','code'=>0,
                'response'=>'transport_exception','outcome'=>'UNCERTAIN'];
            try {
                $detail=$sender
                 ?['transport'=>'TEST_INJECTED',
                   'accepted'=>(bool)$sender($msg['to'],$msg['subject'],$msg['body']),
                   'stage'=>'INJECTED','code'=>0,'outcome'=>'TEST_ONLY','response'=>'test_sender']
                 :aur_vendor_rfq_send_transport_detail(
                     $msg['to'],$msg['subject'],$msg['body'],$msgId);
            }catch(Throwable $e){
                error_log('AURENOEVA price reminder SMTP exception: '.get_class($e));
            }
            $ok=($detail['accepted']??false)===true;
            $data['messages'][$i]['transport_result']=$detail;
            $data['messages'][$i]['status']=$ok?'ACCEPTED_BY_MAIL_TRANSPORT':'TRANSPORT_FAILED_OR_UNCONFIRMED';
            $data['messages'][$i]['sent_at']=$ok?gmdate('c'):null;
            aur_vendor_rfq_write($path,$data);
            $sent++;
        }
        $data['status']='REVIEW_OR_SENDING';
        $data['last_dispatch_at']=gmdate('c');
        aur_vendor_rfq_write($path,$data);
        $data['processed_this_request']=$sent;
        return $data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
