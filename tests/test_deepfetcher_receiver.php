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
echo "AURENOEVA_DEEPFETCHER_REAL_CONSUMER_CONTRACT=PASS\n";
