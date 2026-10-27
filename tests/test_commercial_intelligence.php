<?php
declare(strict_types=1);
require_once __DIR__.'/../deployment/site/procurement/commercial_intelligence.php';
function ci_assert(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$store=sys_get_temp_dir().'/aur-ci-'.bin2hex(random_bytes(7));
mkdir($store,0700,true);
try {
    $now=gmdate('c');$old=gmdate('c',time()-45*86400);
    $products=[
       ['canonical_id'=>'AUR-P-'.str_repeat('A',20),'sector'=>'industrial',
        'source_ref'=>'https://example.org/parts/a','last_seen'=>$now,
        'classifications'=>[['industry'=>'industrial','category'=>'PLC / control modules',
          'confidence'=>'MATCHED_TEXT','method'=>'technical-facets/1','evidence_url'=>'https://example.org/parts/a']],
        'public_offers'=>[['amount'=>125,'currency'=>'EUR','observed_at'=>$now,
          'source_url'=>'https://example.org/parts/a']]],
       ['canonical_id'=>'AUR-P-'.str_repeat('B',20),'sector'=>'marine',
        'source_ref'=>'https://example.org/parts/b','last_seen'=>$old,
        'classifications'=>[], 'public_offers'=>[['amount'=>95,'currency'=>'EUR',
          'observed_at'=>$old,'source_url'=>'https://example.org/parts/b']]]
    ];
    file_put_contents($store.'/catalogue-public.json',json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$products],JSON_THROW_ON_ERROR));
    $ref='AUR-DE-'.str_repeat('A',16);
    mkdir($store.'/cases',0700);
    file_put_contents($store.'/cases/'.$ref.'.json',json_encode(['reference'=>$ref,
       'country'=>'DE','source_sha256'=>str_repeat('b',64),'created_at'=>$now,
       'line_count'=>1,'payload'=>['sourcing_lines'=>[]]],JSON_THROW_ON_ERROR));
    $r=aur_ci_report($store);
    ci_assert(count($r['industries'])===19,'19 industries required');
    ci_assert($r['catalogue']['products']===2,'unique product count');
    ci_assert($r['catalogue']['priced_products']===1,'recent observed price only');
    ci_assert($r['catalogue']['fresh_30d']===1,'recently seen product only');
    ci_assert($r['catalogue']['classified_products']===1,'evidence-backed classification');
    ci_assert($r['industries'][7]['classified_count']===null,'discovery not misrepresented');
    ci_assert($r['funnel']['enquiries']===1&&$r['funnel']['verified_purchases']===0,'case is not sale');
    aur_ci_event($store,'search');aur_ci_event($store,'basket_add');aur_ci_event($store,'search');
    aur_ci_snapshot_observed($store,2,str_repeat('c',64));
    $r=aur_ci_report($store);
    ci_assert(count($r['activity']['days'])===1,'buyer activity saved per day');
    ci_assert($r['activity']['days'][0]['search']===2&&$r['activity']['days'][0]['basket_add']===1,'activity categories');
    ci_assert(count($r['catalogue']['growth_history'])===1,'growth checkpoint saved');
    try {aur_ci_record_outcome($store,$ref,'PURCHASE_CONFIRMED','short');throw new RuntimeException('unsupported short evidence');}
    catch(InvalidArgumentException $e){}
    ci_assert(aur_ci_report($store)['funnel']['verified_purchases']===0,'no unproven transactions');
    aur_ci_record_outcome($store,$ref,'PURCHASE_CONFIRMED','Archived buyer order email 2026-10-27');
    $r=aur_ci_report($store);
    ci_assert($r['funnel']['verified_purchases']===1,'verified evidence counted');
    aur_ci_record_outcome($store,$ref,'NO_PURCHASE_CONFIRMED','Archived cancellation email 2026-10-27');
    $r=aur_ci_report($store);
    ci_assert($r['funnel']['verified_purchases']===0&&$r['funnel']['verified_no_purchase']===1,
      'outcome update replaces previous status without double-counting');
    echo "AURENOEVA_COMMERCIAL_INTELLIGENCE=PASS\n";
}finally{
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($store);
}
