<?php
declare(strict_types=1);
/**
 * Private supplier quotation register for the existing RFQ outbox.
 * Each entry must match a sent supplier email AND an original sourcing-line ID.
 * Human-entered responses are never represented as mailbox-synchronised.
 */
function aur_reply_path(string $store,array $case):string {
    $ref=(string)($case['reference']??'');
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))throw new InvalidArgumentException('invalid_case_reference');
    return aur_private_directory($store,'supplier-rfqs').'/'.$ref.'-responses.json';
}
function aur_reply_read(string $store,array $case):array {
    $path=aur_reply_path($store,$case);
    if(!is_file($path))return [];
    if(is_link($path))throw new RuntimeException('unsafe_response_file');
    $saved=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($saved)||($saved['case_reference']??'')!==($case['reference']??'')
       ||($saved['source_sha256']??'')!==($case['source_sha256']??''))
        throw new RuntimeException('response_case_mismatch');
    return is_array($saved['entries']??null)?$saved['entries']:[];
}
function aur_reply_choices(array $draft):array {
    $out=[];
    foreach(($draft['messages']??[]) as $message){
        if(($message['status']??'')!=='ACCEPTED_BY_MAIL_TRANSPORT')continue;
        $email=strtolower(trim((string)($message['to']??'')));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))continue;
        foreach(($message['items']??[]) as $item){
            $row=(int)($item['source_row']??0);
            if($row<1)continue;
            $key=hash('sha256',$email).'-'.$row;
            $out[$key]=['email'=>$email,'source_row'=>$row,
                'supplier'=>(string)($message['vendor_name']??$email),
                'description'=>(string)($item['description']??'')];
        }
    }
    return $out;
}
function aur_reply_record(string $store,array $case,string $draftId,string $choice,array $input):array {
    $draft=aur_vendor_rfq_read($store,$case,$draftId);
    $allowed=aur_reply_choices($draft);
    if(!isset($allowed[$choice]))throw new InvalidArgumentException('response_supplier_item_not_sent');
    $candidate=$allowed[$choice];
    $item=null;
    foreach(($case['payload']['sourcing_lines']??[]) as $line){
        if((int)($line['source_row']??0)===$candidate['source_row']){$item=$line;break;}
    }
    if($item===null)throw new InvalidArgumentException('response_item_not_in_original_case');
    $status=(string)($input['availability']??'');
    if(!in_array($status,['QUOTED','NOT_AVAILABLE','ALTERNATIVE_OFFERED','DETAILS_PENDING'],true))
        throw new InvalidArgumentException('invalid_response_status');
    $price=trim((string)($input['unit_price']??''));
    if($price!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D',$price) || (float)$price<=0))
        throw new InvalidArgumentException('invalid_response_price');
    $currency=strtoupper(trim((string)($input['currency']??'')));
    if(($price!==''&&!preg_match('/^[A-Z]{3}$/D',$currency))||($price===''&&$currency!==''))
        throw new InvalidArgumentException('invalid_response_currency');
    $lead=trim((string)($input['lead_time']??''));
    $reference=trim((string)($input['reply_reference']??''));
    $notes=trim((string)($input['notes']??''));
    if(strlen($lead)>100||strlen($reference)>180||strlen($notes)>900)
        throw new InvalidArgumentException('response_input_too_long');
    $record=['supplier'=>$candidate['supplier'],'email'=>$candidate['email'],
        'source_row'=>$candidate['source_row'],
        'item_number'=>(int)($item['customer_item_number']??0),
        'description'=>(string)($item['description_original']??''),
        'availability'=>$status,'unit_price'=>$price,'currency'=>$currency,
        'lead_time'=>$lead,'reply_reference'=>$reference,'notes'=>$notes,
        'recorded_at'=>gmdate('c'),'evidence'=>'MANUALLY_RECORDED_NOT_MAILBOX_VERIFIED'];
    $path=aur_reply_path($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('response_lock_unavailable');
    try{
        $entries=aur_reply_read($store,$case);
        $key=hash('sha256',$candidate['email']).'-'.$candidate['source_row'];
        $entries[$key]=$record;
        aur_vendor_rfq_write($path,['schema'=>'aurenoeva-supplier-replies/1',
           'case_reference'=>$case['reference'],'source_sha256'=>$case['source_sha256'],
           'entries'=>$entries,'updated_at'=>gmdate('c')]);
        $back=aur_reply_read($store,$case);
        if(($back[$key]['source_row']??null)!==$candidate['source_row'])
            throw new RuntimeException('response_readback_failed');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    return $record;
}
