<?php
declare(strict_types=1);
/**
 * AURENOEVA_INBOX_THREADS_V1
 * Read-only projection from the original RFQ's SMTP batch records and
 * case-linked IMAP evidence. Never writes, sends or changes reply attribution.
 */
function aur_correspondence_threads(array $sent,array $attempts,array $inbound):array {
    $threads=[];
    $emailOf=static function(mixed $value):string {
        if(!is_string($value))return '';
        if(preg_match('/[a-z0-9._%+\\-]+@[a-z0-9.\\-]+\\.[a-z]{2,}/i',$value,$match))
            return strtolower($match[0]);
        return '';
    };
    $ensure=static function(string $email,string $fallback) use (&$threads):string {
        $key=$email!==''?'mail:'.$email:'unverified:'.$fallback;
        if(!isset($threads[$key]))$threads[$key]=[
            'id'=>hash('sha256',$key),'supplier'=>'','email'=>$email,
            'messages'=>[],'sent_count'=>0,'reply_count'=>0,
            'review_count'=>0,'unconfirmed_count'=>0,'last_at'=>'','_last'=>0,
            'status'=>'review'
        ];
        return $key;
    };
    $append=static function(string $key,array $entry) use (&$threads):void {
        $time=(string)($entry['time']??'');
        $stamp=$time!==''?(strtotime($time)?:0):0;
        if($stamp>$threads[$key]['_last']){
            $threads[$key]['_last']=$stamp;
            $threads[$key]['last_at']=$time;
        }
        $entry['_time']=$stamp;
        $threads[$key]['messages'][]=$entry;
    };
    foreach($sent as $i=>$message){
        if(!is_array($message))continue;
        $address=$emailOf($message['to']??'');
        $key=$ensure($address,'sent-'.(string)$i);
        $name=trim((string)($message['supplier']??''));
        if($name!==''&&$threads[$key]['supplier']==='')$threads[$key]['supplier']=$name;
        $threads[$key]['sent_count']++;
        $append($key,[
            'kind'=>'sent','subject'=>(string)($message['subject']??''),
            'body'=>(string)($message['body']??''),'time'=>(string)(
                ($message['sent_at']??'')?:($message['attempted_at']??'')?:($message['created_at']??'')),
            'item_numbers'=>(string)($message['item_numbers']??''),
            'label'=>'SMTP accepted — delivery not guaranteed'
        ]);
    }
    foreach($attempts as $i=>$message){
        if(!is_array($message))continue;
        $address=$emailOf($message['to']??'');
        $key=$ensure($address,'attempt-'.(string)$i);
        $name=trim((string)($message['supplier']??''));
        if($name!==''&&$threads[$key]['supplier']==='')$threads[$key]['supplier']=$name;
        $threads[$key]['unconfirmed_count']++;
        $append($key,[
            'kind'=>'attempt','subject'=>(string)($message['subject']??''),
            'body'=>(string)($message['body']??''),'time'=>(string)(
                ($message['attempted_at']??'')?:($message['created_at']??'')),
            'item_numbers'=>(string)($message['item_numbers']??''),
            'label'=>'Sending status unconfirmed — check before retrying'
        ]);
    }
    foreach($inbound as $i=>$event){
        if(!is_array($event))continue;
        $address=$emailOf($event['supplier_email']??'')?:$emailOf($event['from']??'');
        $fallback=(string)($event['key']??$event['uid']??'received-'.(string)$i);
        $key=$ensure($address,$fallback);
        $name=trim((string)($event['supplier']??''));
        if($name!==''&&$threads[$key]['supplier']==='')$threads[$key]['supplier']=$name;
        $classification=(string)($event['classification']??'UNCLASSIFIED');
        $deliveryIssue=$classification==='DELIVERY_FAILED';
        $trusted=!$deliveryIssue&&
            (!empty($event['matched_to_sent_rfq']) || !empty($event['supplier_case_match']))
            && empty($event['requires_sender_review']);
        $kind=$deliveryIssue?'delivery_issue':($trusted?'received':'review');
        if($trusted)$threads[$key]['reply_count']++;
        else $threads[$key]['review_count']++;
        $append($key,[
            'kind'=>$kind,'subject'=>(string)($event['subject']??''),
            'body'=>(string)($event['preview']??''),
            'time'=>(string)(($event['received_at']??'')?:($event['processed_at']??'')),
            'item_numbers'=>is_array($event['buyer_items']??null)
                ?implode(', ',array_map('strval',$event['buyer_items'])):'',
            'label'=>$deliveryIssue?'Delivery failure — inspect before retrying':
                ($trusted?(!empty($event['requires_batch_review'])
                    ?'Verified supplier reply for this RFQ — exact outbound batch still requires review'
                    :'Received, case and sender matched'):
                    'Inbound case reference — sender/batch needs review'),
            'classification'=>$classification,
            'preview_only'=>true
        ]);
    }
    $all=[];
    foreach($threads as $thread){
        if($thread['supplier']==='')$thread['supplier']=$thread['email']?:'Unknown sender — review required';
        $thread['status']=$thread['review_count']>0?'review':
            ($thread['reply_count']>0?'replied':
            ($thread['sent_count']>0?'awaiting':'unconfirmed'));
        usort($thread['messages'],static fn(array $a,array $b)=>$a['_time']<=>$b['_time']);
        foreach($thread['messages'] as &$entry)unset($entry['_time']);
        unset($entry,$thread['_last']);
        $all[]=$thread;
    }
    usort($all,static fn(array $a,array $b)=>strcmp((string)$b['last_at'],(string)$a['last_at'])
        ?:strcmp($a['supplier'],$b['supplier']));
    $summary=['all'=>count($all),'awaiting'=>0,'replied'=>0,'review'=>0,'unconfirmed'=>0];
    foreach($all as $thread)$summary[$thread['status']]++;
    return ['threads'=>$all,'summary'=>$summary];
}
