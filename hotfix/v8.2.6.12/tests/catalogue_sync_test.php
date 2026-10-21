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
    $digest = hash('sha256', $saved);
    try { aur_catalogue_write_snapshot(json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>[]]), $private); }
    catch (RuntimeException $e) { /* Expected: preserve last good */ }
    if (hash_file('sha256', $private . '/catalogue-public.json') !== $digest)
        throw new RuntimeException('last_good_snapshot_overwritten');
    echo "AURENOEVA_CATALOGUE_HTTPS_SYNC_AND_SEARCH=PASS\n";
} finally {
    @unlink($private . '/catalogue-public.json');
    @rmdir($web); @rmdir($private); @rmdir($root);
}
