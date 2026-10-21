<?php
declare(strict_types=1);
define('AUR_CATALOGUE_SYNC_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/catalogue-sync.php';
require dirname(__DIR__, 2) . '/v8.2.6.10/catalogue_local.php';

$root = sys_get_temp_dir() . '/aur-catalogue-sync-' . bin2hex(random_bytes(6));
$web = $root . '/public_html';
$private = $root . '/.aurenoeva-private';
mkdir($web, 0700, true);
mkdir($private, 0700, true);
$_SERVER['DOCUMENT_ROOT'] = $web;
putenv('AURENOEVA_CATALOGUE_SNAPSHOT=');
$example = ['schema'=>'aurenoeva-catalogue/1','items'=>[
    ['canonical_id'=>'AUR-P-'.str_repeat('A',20),'manufacturer'=>'Mindray',
        'model'=>'WATO EX-35', 'description'=>'Anaesthesia system',
        'source_ref'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35',
        'last_seen'=>gmdate('c'),'private_cost'=>'MUST_NOT_EXPOSE'],
    ['canonical_id'=>'AUR-P-'.str_repeat('B',20),'manufacturer'=>'Anthos',
        'model'=>'Surgical carts','source_ref'=>'https://www.anthos.it/en/'],
]];
try {
    $response = aur_catalogue_write_snapshot(json_encode($example), $private);
    if (($response['records'] ?? 0) !== 1 || ($response['source_sha256'] ?? '') !== hash('sha256',json_encode($example)))
        throw new RuntimeException('sync_snapshot_acknowledgement');
    $saved = file_get_contents($private . '/catalogue-public.json');
    if (str_contains($saved,'MUST_NOT_EXPOSE') || str_contains($saved,'Surgical carts'))
        throw new RuntimeException('private_data_or_generic_product_exported');
    $found = aur_catalogue_snapshot_search('WATO EX-35');
    if (!is_array($found) || count($found) !== 1 || $found[0]['model'] !== 'WATO EX-35')
        throw new RuntimeException('website_search_readback_failed');
    // A later smaller scan keeps previously published product identities.
    $next = ['schema'=>'aurenoeva-catalogue/1','items'=>[
        ['canonical_id'=>'AUR-P-'.str_repeat('C',20), 'manufacturer'=>'Garmin',
         'model'=>'GPSMAP 9217xsv', 'sector'=>'marine',
         'category'=>'Navigation / instrumentation',
         'source_ref'=>'https://www.garmin.com/en-US/p/2001334/']
    ]];
    $reconciled = aur_catalogue_write_snapshot(json_encode($next), $private);
    if (($reconciled['records'] ?? 0) !== 2)
        throw new RuntimeException('historical_products_not_preserved');
    $marine = aur_catalogue_snapshot_search('GPSMAP 9217xsv', 8, 'marine', 'Navigation / instrumentation');
    if (count($marine ?? []) !== 1 || $marine[0]['sector'] !== 'marine')
        throw new RuntimeException('marine_category_search_failed');
    if (aur_catalogue_snapshot_search('GPSMAP 9217xsv', 8, 'medical', 'Diagnostic equipment') !== [])
        throw new RuntimeException('sector_filter_not_applied');
    if (count(aur_catalogue_snapshot_search('WATO EX-35') ?? []) !== 1)
        throw new RuntimeException('original_model_lost_during_reconciliation');
    // The same case-owned worldwide vendor master must absorb new product evidence
    // without losing email/contact details already discovered by RFQ research.
    require_once dirname(__DIR__,3) . '/deployment/site/procurement/private_cases.php';
    require_once dirname(__DIR__,3) . '/deployment/site/procurement/supplier_worldwide.php';
    $store=$root . '/existing-private';
    mkdir($store,0700,true);
    $host='www.garmin.com';
    $id=aur_worldwide_vendor_id($host);
    aur_worldwide_master_merge($store,[['vendor'=>[
        'id'=>$id,'website'=>$host,'name'=>'Known Garmin Contact',
        'email'=>'published@example.net','phone'=>'+49 123',
        'status'=>'SOURCE_CHECKED_EMAIL_PUBLISHED','evidence_url'=>'https://www.garmin.com/en-US/p/2001334/'
    ],'description'=>'marine navigation']]);
    $enrichment=json_encode(['vendor_leads'=>[
        ['website'=>'www.garmin.com','evidence_url'=>'https://www.garmin.com/en-US/p/2001334/',
         'sector'=>'marine','category'=>'Navigation / instrumentation']],
      'vendor_products'=>[
        ['website'=>'www.garmin.com','product_id'=>'AUR-P-'.str_repeat('C',20),
         'source_url'=>'https://www.garmin.com/en-US/p/2001334/',
         'manufacturer'=>'Garmin','model'=>'GPSMAP 9217xsv',
         'sector'=>'marine','category'=>'Navigation / instrumentation',
         'evidence_type'=>'STRUCTURED_PRODUCT','last_seen'=>gmdate('c')]
      ]]);
    $v=aur_catalogue_merge_vendor_evidence($store,$enrichment);
    if($v['product_links']!==1 || $v['vendors_added']!==0)
        throw new RuntimeException('vendor_products_not_merged_with_prior_master');
    $master=aur_worldwide_master_read($store);
    $vendor=$master['vendors'][$id]??[];
    if (($vendor['email']??'')!=='published@example.net'
        || !isset($vendor['catalogue_products']['AUR-P-'.str_repeat('C',20)])
        || !in_array('Garmin GPSMAP 9217xsv',$vendor['equipment_hints']??[],true))
        throw new RuntimeException('contact_or_crawled_product_lost');
    $saved = file_get_contents($private . '/catalogue-public.json');
    $digest = hash('sha256', $saved);
    try { aur_catalogue_write_snapshot(json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>[]]), $private); }
    catch (RuntimeException $e) { /* Expected: preserve last good */ }
    if (hash_file('sha256', $private . '/catalogue-public.json') !== $digest)
        throw new RuntimeException('last_good_snapshot_overwritten');
    echo "AURENOEVA_CATALOGUE_HTTPS_SYNC_AND_SEARCH=PASS\n";
} finally {
    @unlink($private . '/catalogue-public.json');
    @unlink($root . '/existing-private/supplier-intelligence/worldwide-vendors.json');
    @unlink($root . '/existing-private/supplier-intelligence/.worldwide-vendors.lock');
    @rmdir($root . '/existing-private/supplier-intelligence');
    @rmdir($root . '/existing-private');
    @rmdir($web); @rmdir($private); @rmdir($root);
}
