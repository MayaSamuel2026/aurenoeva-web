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

// More suppliers and delivery receipts than one transport page must remain
// discoverable, with no omissions and no duplicate records across pages.
for($i=0;$i<28;$i++){
    $other=$vendor;
    $other['delivery_id']=1000+$i;
    $other['evidence']['id']='ev-paged-vendor-'.$i;
    $other['evidence']['source_url']='https://supplier-'.$i.'.example/catalogue';
    $other['evidence']['value']='Supplier '.$i;
    $other['evidence']['quotation']='Supplier '.$i.' research catalogue';
    aur_df_commit($store,$other,'aurenoeva');
}
$seenVendors=[];$offset=0;
do{
    $page=aur_df_supplier_recent($store,10,$offset);
    foreach($page['items'] as $item){
        df_must(!isset($seenVendors[$item['vendor_id']]),'supplier repeated across pages');
        $seenVendors[$item['vendor_id']]=true;
    }
    $offset=$page['next_offset']??null;
}while($offset!==null);
df_must(count($seenVendors)===29,'all source-backed vendors must be reachable');
for($i=0;$i<30;$i++){
    $other=$record;
    $other['delivery_id']=2000+$i;
    $other['evidence']['id']='ev-paged-inbox-'.$i;
    aur_df_commit($store,$other,'aurenoeva');
}
$seenDeliveries=[];$before=null;
do{
    $page=aur_df_recent($store,7,$before);
    foreach($page['items'] as $item){
        df_must(!isset($seenDeliveries[$item['delivery_id']]),'delivery repeated across pages');
        $seenDeliveries[$item['delivery_id']]=true;
    }
    $before=$page['next_before']??null;
}while($before!==null);
df_must(count($seenDeliveries)===62,
    'full signed inbox and supplier-source evidence must be accessible across pages');

echo "AURENOEVA_DEEPFETCHER_REAL_CONSUMER_CONTRACT=PASS\n";
