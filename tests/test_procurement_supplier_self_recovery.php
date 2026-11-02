<?php
declare(strict_types=1);
/** Offline end-to-end self-recovery: existing provider fallback + durable retry. */
function self_check(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
}
function aur_supplier_private_file(string $store,string $reference):string {
    return $store.'/case-intelligence.json';
}
function aur_supplier_read(string $store,string $reference,string $expectedSha):?array {
    $file=aur_supplier_private_file($store,$reference);
    $data=is_file($file)?json_decode((string)file_get_contents($file),true):null;
    return is_array($data)?$data:null;
}
function aur_supplier_save(string $store,array $case):array {
    return aur_supplier_read($store,(string)$case['reference'],(string)$case['source_sha256'])??[];
}
function aur_supplier_match(string $description):?array {return null;}
require dirname(__DIR__).'/deployment/site/procurement/supplier_discovery.php';

$root=sys_get_temp_dir().'/aur-self-recovery-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
$case=['reference'=>'AUR-TEST-SELF-REPAIR','source_sha256'=>hash('sha256','fixture')];
$path=aur_supplier_private_file($root,$case['reference']);
$intel=['rows'=>[['source_row'=>1,'description'=>'MRI scanner for hospitals']],
    'global_discovery'=>['version'=>AUR_GLOBAL_DISCOVERY_VERSION,'rows'=>[]]];
file_put_contents($path,json_encode($intel,JSON_THROW_ON_ERROR));
try {
    $failure=static function(string $q):string {throw new RuntimeException('source_down');};
    $secondaryFailure=static function(string $q,string $desc,?array $m):array {
        throw new RuntimeException('secondary_down');
    };
    $a=aur_supplier_discover_batch($root,$case,1,$failure,$secondaryFailure);
    self_check($a['provider_failures']===1,'Unresolved provider outage was hidden');
    self_check($a['stats']['pending']===1,'Outage incorrectly counted as completed research');
    $stored=aur_supplier_read($root,$case['reference'],$case['source_sha256']);
    self_check(($stored['global_discovery']['rows']['1']['status']??'')==='PROVIDER_UNAVAILABLE',
        'Failed source not persisted for retry');
    // Simulate the scheduled retry becoming due. No new case or parallel worker.
    $stored['global_discovery']['rows']['1']['checked_timestamp']=0;
    file_put_contents($path,json_encode($stored,JSON_THROW_ON_ERROR));
    $secondary=static function(string $q,string $desc,?array $m):array {
        return [['page_title'=>'Hospital MRI scanner supplier',
            'website'=>'supplier.example','source_url'=>'https://supplier.example/mri',
            'role'=>'PUBLIC_SEARCH_RESULT_UNVERIFIED','availability'=>'NOT_CHECKED',
            'authorization'=>'NOT_CHECKED','quotation'=>'NOT_RECEIVED','outreach'=>'NOT_SENT']];
    };
    $b=aur_supplier_discover_batch($root,$case,1,$failure,$secondary);
    self_check($b['provider_failures']===0 && $b['fallback_recoveries']===1,
        'Alternate source did not recover failed primary');
    self_check($b['stats']['pending']===0 && $b['new_unverified_links']===1,
        'Recovered lead still classified as unresolved');
    $stored=aur_supplier_read($root,$case['reference'],$case['source_sha256']);
    $row=$stored['global_discovery']['rows']['1'];
    self_check($row['search_provider']==='DUCKDUCKGO_LITE'
        && $row['recovered_by_fallback']===true,'Recovery provenance not retained');
    self_check($row['leads'][0]['availability']==='NOT_CHECKED',
        'Unverified lead was incorrectly upgraded to available stock');
    $c=aur_supplier_discover_batch($root,$case,1,$failure,$secondary);
    self_check($c['processed']===0,'Completed research repeatedly overwritten');
    // A provider returning more than 5 results cannot be silently truncated.
    $xml='<?xml version="1.0"?><rss><channel>';
    for ($i=1;$i<=12;$i++) {
        $xml.='<item><title>Hospital MRI scanner model '.$i.'</title>'
            .'<link>https://supplier'.$i.'.example/medical-mri</link>'
            .'<description>MRI scanner medical equipment for hospitals</description></item>';
    }
    $xml.='</channel></rss>';
    self_check(count(aur_supplier_feed_parse($xml,PHP_INT_MAX))===12,
        'First-five result truncation was reintroduced');
    echo "AURENOEVA_SUPPLIER_SELF_RECOVERY=PASS\n";
} finally {
    @unlink($path);
    @unlink($root.'/.research.lock');
    @rmdir($root);
}
