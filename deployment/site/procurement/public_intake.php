<?php
declare(strict_types=1);
/**
 * WP09: website RFQ -> the SAME private procurement case store used by
 * supplier research, approved supplier RFQs, inbox matching and quotations.
 * A public visitor can only CREATE a case through the validated public
 * requirement-submit endpoint. No public case reads, approval or dispatch.
 */
require_once __DIR__.'/private_cases.php';

function aur_public_case_lines(array $contract): array {
    $requirement=$contract['requirement']??[];
    if(!is_array($requirement))throw new InvalidArgumentException('invalid_public_requirement');
    $selected=$contract['selected_products']??[];
    if(!is_array($selected) || count($selected)>20)throw new InvalidArgumentException('invalid_public_products');
    $lines=[];
    foreach($selected as $index=>$part){
        if(!is_array($part))throw new InvalidArgumentException('invalid_public_part');
        $maker=trim((string)($part['manufacturer']??''));
        $model=trim((string)($part['part_number']?:($part['model']??'')));
        $canonical=trim((string)($part['canonical_id']??''));
        $description=trim($maker.' '.$model);
        if($description==='')$description=trim((string)($part['description']??''));
        if($description==='')throw new InvalidArgumentException('public_part_missing_identity');
        $qty=$part['quantity']??null;
        if($qty!==null&&(!is_int($qty)||$qty<1||$qty>999999))
            throw new InvalidArgumentException('invalid_public_part_quantity');
        $i=$index+1;
        $lines[]=[
            'source_row'=>$i+1,'customer_item_number'=>$i,
            'option_group'=>sprintf('ITEM-%02d',$i),'option_type'=>'REQUESTED',
            'description_original'=>mb_substr($description,0,500,'UTF-8'),
            'quantity'=>$qty,
            'canonical_part_id'=>preg_match('/^AUR-P-[A-F0-9]{20}$/D',$canonical)?$canonical:null,
            'technical_status'=>'UNRESOLVED','rfq_status'=>'NOT_SENT',
            'public_product'=>[
                'manufacturer'=>substr($maker,0,220),'model'=>substr((string)($part['model']??''),0,220),
                'part_number'=>substr((string)($part['part_number']??''),0,220),
                'source_ref'=>substr((string)($part['source_ref']??''),0,500)
            ]
        ];
    }
    if(!$lines){
        $description=trim((string)($requirement['description']??''));
        if($description==='')$description=trim((string)($requirement['raw_identifier']??''));
        if($description==='')throw new InvalidArgumentException('public_requirement_empty');
        // A free-text sourcing request is still a usable, reviewable case line.
        $lines[]=[
            'source_row'=>2,'customer_item_number'=>1,'option_group'=>'ITEM-01',
            'option_type'=>'REQUESTED','description_original'=>mb_substr($description,0,500,'UTF-8'),
            'quantity'=>null,'canonical_part_id'=>null,
            'technical_status'=>'UNRESOLVED','rfq_status'=>'NOT_SENT'
        ];
    }
    return $lines;
}

function aur_public_case_capture(string $store,array $contract): array {
    if(($contract['schema']??'')!=='aurenoeva-requirement-contract/1.0'
       || ($contract['source']??'')!=='PUBLIC_WEBSITE'
       || !is_array($contract['customer']??null)
       || !filter_var((string)($contract['customer']['business_email']??''),FILTER_VALIDATE_EMAIL)
       || trim((string)($contract['customer']['organisation_name']??''))===''
       || trim((string)($contract['customer']['contact_name']??''))===''
       || !preg_match('/^aurenoeva:[a-f0-9]{64}$/D',(string)($contract['idempotency_key']??'')))
       throw new InvalidArgumentException('invalid_public_case_contract');

    $lines=aur_public_case_lines($contract);
    $digest=substr((string)$contract['idempotency_key'],strlen('aurenoeva:'));
    // "ZZ" means unknown destination, NOT a real ISO country or sanctions clearance.
    // The signed-in administrator must select the actual destination before dispatch.
    $country='ZZ';
    $location=strtoupper(trim((string)($contract['requirement']['delivery_location']??'')));
    if(preg_match('/^[A-Z]{2}$/D',$location)){
        require_once __DIR__.'/embargo_screen.php';
        if(isset(aur_embargo_countries()[$location]))$country=$location;
    }
    $reference='AUR-'.$country.'-'.strtoupper(substr($digest,0,16));
    $records=aur_private_directory($store,'cases');
    $path=$records.'/'.$reference.'.json';
    $lock=@fopen($store.'/case.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('public_case_lock_unavailable');
    try{
        if(is_file($path)){
            $existing=aur_case_read($store,$reference);
            if(($existing['source_sha256']??'')!==$digest
               || ($existing['public_contract_id']??'')!==($contract['contract_id']??''))
                throw new RuntimeException('public_case_reference_conflict');
            return $existing;
        }
        $count=count($lines);
        $payload=[
            'schema'=>'aurenoeva-procurement-case-import/1.0',
            'case_reference'=>$reference,'enquiry_country'=>$country,'delivery_country'=>$country,
            'source_file_name'=>'PUBLIC_WEBSITE_RFQ','source_sha256'=>$digest,
            'import_state'=>'PERSISTED_PRIVATE_APP_CASE','catalogue_publication'=>false,
            'supplier_offers_received'=>0,'supplier_contact_conducted'=>false,
            'buyer_contracts_vendor_directly'=>true,'legal_status'=>'REVIEW_REQUIRED',
            'counts'=>['numbered_requirements'=>$count,'equipment_entries'=>$count,'alternative_options'=>0],
            'sourcing_lines'=>$lines
        ];
        $case=[
            'ok'=>true,'reference'=>$reference,'country'=>$country,
            'source_sha256'=>$digest,'original_name'=>'PUBLIC_WEBSITE_RFQ',
            'created_at'=>gmdate('c'),'line_count'=>$count,'requirements'=>$count,
            'alternatives'=>0,'import_state'=>'PERSISTED_PRIVATE_APP_CASE',
            'payload'=>$payload,'legal_review'=>null,'source'=>'PUBLIC_WEBSITE',
            'public_reference'=>substr((string)($contract['reference']??''),0,120),
            'public_contract_id'=>substr((string)($contract['contract_id']??''),0,120),
            'destination_review_required'=>$country==='ZZ',
            'buyer'=>[
                'company'=>substr((string)$contract['customer']['organisation_name'],0,180),
                'contact'=>substr((string)$contract['customer']['contact_name'],0,120),
                'email'=>substr((string)$contract['customer']['business_email'],0,190)
            ],
            'public_requirements'=>[
                'condition'=>substr((string)($contract['requirement']['condition']??''),0,120),
                'delivery_location'=>substr((string)($contract['requirement']['delivery_location']??''),0,180),
                'notes'=>substr((string)($contract['requirement']['description']??''),0,5000)
            ]
        ];
        $tmp=$records.'/.web-'.bin2hex(random_bytes(12));
        $bytes=json_encode($case,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes)){
            @unlink($tmp);throw new RuntimeException('public_case_write_failed');
        }
        @chmod($tmp,0600);
        if(!rename($tmp,$path)){
            @unlink($tmp);throw new RuntimeException('public_case_commit_failed');
        }
        $readback=aur_case_read($store,$reference);
        if(($readback['source_sha256']??'')!==$digest||($readback['line_count']??0)!==$count)
            throw new RuntimeException('public_case_readback_failed');
        return $readback;
    }finally{
        flock($lock,LOCK_UN);fclose($lock);
    }
}
