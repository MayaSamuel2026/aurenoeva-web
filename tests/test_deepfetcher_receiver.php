<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/deepfetcher_source_inbox.php';
function df_must(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
$root=sys_get_temp_dir().'/aur-df-test-'.bin2hex(random_bytes(5));
mkdir($root.'/public_html',0700,true);
$store=aur_store_root($root.'/public_html');
$secret=str_repeat('s',64);

$validSigning=str_repeat('a',64);
$validAck=str_repeat('b',64);
aur_df_provision($store,$validSigning,$validAck);
$storedConfig=aur_df_config($store);
df_must($storedConfig['signing_secret']===$validSigning
    && $storedConfig['ack_token']===$validAck
    && $storedConfig['workspace_id']==='aurenoeva'
    && $storedConfig['api_base']==='https://app.deepfetcher.com',
    'private receiver provisioning or readback failed');
$configPath=aur_private_directory($store,'deepfetcher-inbox').'/receiver-config.json';
df_must(is_file($configPath) && !is_link($configPath),'config absent or unsafe');
df_must((fileperms($configPath)&0777)===0600,'private credentials permissions are not 0600');
try {
    aur_df_provision($store,'unsafe-secret',$validAck);
    throw new RuntimeException('invalid secret accepted');
} catch (InvalidArgumentException $e) {
    df_must($e->getMessage()==='invalid_deepfetcher_credentials','unexpected invalid secret error');
}
df_must(aur_df_config($store)['signing_secret']===$validSigning,
    'invalid configuration changed an existing receiver');
aur_df_provision($store,str_repeat('c',64),str_repeat('d',64));
df_must(aur_df_config($store)['signing_secret']===str_repeat('c',64),
    'credential rotation did not atomically replace private config');

$record=[
    'schema_version'=>'1.0','delivery_id'=>431,'mission_id'=>'df-qa-7',
    'workspace_id'=>'aurenoeva','client_reference'=>'test-source-only',
    'evidence'=>[
        'id'=>'ev-real-1','source_url'=>'https://www.iana.org/domains/reserved',
        'source_hash'=>str_repeat('a',64),'field'=>'published_heading',
        'value'=>'IANA-managed Reserved Domains',
        'quotation'=>'IANA-managed Reserved Domains','verification'=>'source_backed'
    ]
];
$raw=json_encode($record,JSON_THROW_ON_ERROR);
$stamp=(string)time();
$sig='v1='.hash_hmac('sha256',$stamp.'.'.$raw,$secret);
$parsed=aur_df_validate($raw,$stamp,$sig,$secret);
df_must($parsed['delivery_id']===431,'signed valid payload refused');
$path=aur_private_directory($store,'deepfetcher-inbox').'/delivery-431.json';
$id=aur_df_commit($store,$parsed,'aurenoeva');
df_must($id==='source-inbox-431' && is_file($path),'durable record missing');
$bytes=file_get_contents($path);
$again=aur_df_commit($store,$parsed,'aurenoeva');
df_must($again===$id && file_get_contents($path)===$bytes,'redelivery was not idempotent');
$stored=json_decode($bytes,true);
df_must($stored['status']==='SOURCE_CANDIDATE_NOT_COMMERCIALLY_VERIFIED','unverified evidence was promoted');
df_must($stored['evidence']['source_url']===$record['evidence']['source_url'],'source provenance lost');
$recent=aur_df_recent($store,12);
df_must($recent['total']===1 && count($recent['items'])===1,'staff view receipt was lost');
df_must($recent['items'][0]['delivery_id']===431 && $recent['items'][0]['field']==='published_heading','staff view did not retain attributed field');
df_must($recent['items'][0]['source_url']==='https://www.iana.org/domains/reserved','staff view source link incorrect');

$bad=$record;$bad['delivery_id']=432;$bad['evidence']['value']='Modified';
try{
    aur_df_validate(json_encode($bad,JSON_THROW_ON_ERROR),$stamp,$sig,$secret);
    throw new RuntimeException('tampered payload accepted');
}catch(InvalidArgumentException $e){df_must($e->getMessage()==='invalid_signature','unexpected integrity rejection');}
try{
    aur_df_validate($raw,(string)((int)$stamp-3600),$sig,$secret);
    throw new RuntimeException('expired signed request accepted');
}catch(InvalidArgumentException $e){df_must($e->getMessage()==='expired_timestamp','timestamp validation failed');}
try{
    aur_df_commit($store,$parsed,'wrong-workspace');
    throw new RuntimeException('wrong workspace was accepted');
}catch(InvalidArgumentException $e){df_must($e->getMessage()==='wrong_workspace','workspace isolation failed');}
$conflict=$parsed;
$conflict['evidence']['id']='another-evidence';
try{
    aur_df_commit($store,$conflict,'aurenoeva');
    throw new RuntimeException('conflicting delivery reused existing record');
}catch(RuntimeException $e){df_must($e->getMessage()==='delivery_id_conflict','replay conflict not rejected');}
df_must(count(glob(aur_private_directory($store,'deepfetcher-inbox').'/delivery-*.json'))===1,'inbox duplicate');

$vendor=$record;
$vendor['delivery_id']=433;
$vendor['evidence']=[
    'id'=>'ev-supplier-433','source_url'=>'https://example-supplier.de/products/s260s',
    'source_hash'=>str_repeat('f',64),'field'=>'supplier.name','value'=>'Sample Supplier GmbH',
    'quotation'=>'Sample Supplier GmbH offers medical equipment','verification'=>'source_backed'
];
df_must(aur_df_commit($store,$vendor,'aurenoeva')==='source-inbox-433','supplier intake failed');
$product=$vendor;
$product['delivery_id']=434;
$product['evidence']['id']='ev-product-434';
$product['evidence']['field']='product.model';
$product['evidence']['value']='S260S';
$product['evidence']['quotation']='Medical equipment model S260S offered for enquiry';
df_must(aur_df_commit($store,$product,'aurenoeva')==='source-inbox-434','product intake failed');
$intel=aur_df_supplier_recent($store);
df_must($intel['total_vendors']===1,'one supplier dossier expected');
df_must($intel['items'][0]['evidence_count']===2,'supplier and product evidence not grouped');
df_must(in_array('S260S',$intel['items'][0]['product_models'],true),'product model not visible');
df_must(in_array('Sample Supplier GmbH',$intel['items'][0]['supplier_names'],true),'supplier name not visible');
df_must($intel['items'][0]['status']==='SOURCE_CANDIDATE_NOT_COMMERCIALLY_VERIFIED',
    'candidate was falsely promoted to a commercial confirmation');
$vendorPath=aur_private_directory(aur_private_directory($store,'supplier-intelligence'),'deepfetcher-vendors')
    .'/'.$intel['items'][0]['vendor_id'].'.json';
$vendorBytes=file_get_contents($vendorPath);
aur_df_commit($store,$vendor,'aurenoeva');
df_must(file_get_contents($vendorPath)===$vendorBytes,'replayed evidence changed supplier dossier');
$noMatch=$product;
$noMatch['delivery_id']=435;
$noMatch['evidence']['id']='ev-unsubstantiated';
$noMatch['evidence']['value']='NONEXISTENT-MODEL';
$noMatch['evidence']['quotation']='This page has no model assertion';
aur_df_commit($store,$noMatch,'aurenoeva');
df_must(file_get_contents($vendorPath)===$vendorBytes,'unsupported product assertion was promoted');

// Prove the existing receiver never hides older supplier dossiers.
for($i=0;$i<4;$i++){
    $extra=$product;
    $extra['delivery_id']=440+$i;
    $extra['evidence']['id']='ev-page-'.$i;
    $extra['evidence']['source_url']='https://supplier'.$i.'.example.com/catalogue';
    $extra['evidence']['quotation']='Medical device model S260S research catalogue';
    aur_df_commit($store,$extra,'aurenoeva');
}
$seen=[];$before='';
do{
    $page=aur_df_supplier_recent($store,2,$before);
    foreach($page['items'] as $row){
        df_must(!isset($seen[$row['vendor_id']]),'supplier page duplicated entry');
        $seen[$row['vendor_id']]=true;
    }
    $before=$page['next_before']??'';
}while($before!=='');
df_must(count($seen)===5,'supplier page traversal omitted source dossiers');
echo "AURENOEVA_DEEPFETCHER_REAL_CONSUMER_CONTRACT=PASS\n";


// Match quoted product models to existing ORIGINAL RFQ lines without creating a
// second case record or promoting a dealer page to confirmed supply.
$caseRef='AUR-UZ-'.str_repeat('A',16);
$cases=aur_private_directory($store,'cases');
file_put_contents($cases.'/'.$caseRef.'.json',json_encode([
    'reference'=>$caseRef,'country'=>'UZ','source_sha256'=>str_repeat('c',64),
    'payload'=>['sourcing_lines'=>[
        ['source_row'=>7,'customer_item_number'=>12,
         'description_original'=>'Huvitz HRK-1 Autorefractor / Keratometer'],
        ['source_row'=>8,'customer_item_number'=>13,
         'description_original'=>'Another unrelated optical instrument'],
    ]]
],JSON_THROW_ON_ERROR));
$matched=aur_df_supplier_case_matches($store,['Huvitz HRK-1 Autorefractor/Keratometer']);
df_must(count($matched)===1 && $matched[0]['case_reference']===$caseRef
    && $matched[0]['source_row']===7 && $matched[0]['matched_model']==='HRK1',
    'original RFQ matching lost exact model identity');
df_must(aur_df_supplier_case_matches($store,['Huvitz HRK-2'])===[],
    'a different model was incorrectly linked to the original enquiry');
df_must(aur_df_model_keys('Sample S260S and Huvitz HRK 1')===['S260S','HRK1'],
    'model token normalization must preserve model distinctions');

$hrk=$product;
$hrk['delivery_id']=450;
$hrk['evidence']['id']='ev-hrk-450';
$hrk['evidence']['source_url']='https://huvitz.example.com/hrk-1';
$hrk['evidence']['value']='Huvitz HRK-1';
$hrk['evidence']['quotation']='Huvitz HRK-1';
aur_df_commit($store,$hrk,'aurenoeva');
$filtered=aur_df_supplier_recent($store,20,'','HRK-1');
df_must($filtered['matching_vendors']===1 && count($filtered['items'])===1,
    'supplier model search failed to locate the linked product');
df_must(count($filtered['items'][0]['evidence_quotes'])===1,
    'source quotation was not exposed for staff review');
df_must($filtered['items'][0]['evidence_quotes'][0]['source_hash']===str_repeat('f',64),
    'source hash lost in review projection');
df_must(aur_df_supplier_recent($store,20,'','DOES-NOT-EXIST')['matching_vendors']===0,
    'source search invented results');
echo "AURENOEVA_DEEPFETCHER_PRODUCT_CASE_WORKFLOW=PASS\n";
