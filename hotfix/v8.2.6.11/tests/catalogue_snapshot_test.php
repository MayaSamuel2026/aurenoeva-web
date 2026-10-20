<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/v8.2.6.10/catalogue_local.php';

$dir = sys_get_temp_dir() . '/aurenoeva-catalogue-qa-' . bin2hex(random_bytes(6));
$web = $dir . '/public_html';
$private = $dir . '/.aurenoeva-private';
mkdir($web, 0700, true);
mkdir($private, 0700, true);
$_SERVER['DOCUMENT_ROOT'] = $web;
putenv('AURENOEVA_CATALOGUE_SNAPSHOT=');

$record = [
    'canonical_id' => 'AUR-P-' . str_repeat('A',20),
    'manufacturer' => 'Mindray',
    'model' => 'WATO EX-35',
    'source_ref' => 'https://www.mindray.com/en/products/anesthesia/wato-ex-35',
    'description' => 'Anesthesia machine',
    'last_seen' => gmdate('c'),
    'private_cost' => 'NEVER_PUBLIC',
];
$generic = $record;
$generic['canonical_id'] = 'AUR-P-' . str_repeat('B',20);
$generic['model'] = 'Surgical carts';
$generic['private_cost'] = 'SECRET';
file_put_contents(
    $private . '/catalogue-public.json',
    json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>[$record,$generic]])
);
try {
    $found = aur_catalogue_snapshot_search('WATO EX-35');
    if (!is_array($found) || count($found) !== 1 || $found[0]['model'] !== 'WATO EX-35') {
        throw new RuntimeException('private_snapshot_model_search_failed');
    }
    if (str_contains(json_encode($found), 'NEVER_PUBLIC') ||
        str_contains(json_encode($found), 'SECRET')) {
        throw new RuntimeException('private_fields_exposed');
    }
    $genericSearch = aur_catalogue_snapshot_search('Surgical carts');
    if (!is_array($genericSearch) || count($genericSearch) !== 0) {
        throw new RuntimeException('generic_collection_promoted_as_model');
    }
    unlink($private . '/catalogue-public.json');
    if (aur_catalogue_snapshot_search('WATO EX-35') !== null) {
        throw new RuntimeException('missing_snapshot_must_preserve_existing_fallback');
    }
    echo "AURENOEVA_PRIVATE_CATALOGUE_PHP=PASS\n";
} finally {
    @unlink($private . '/catalogue-public.json');
    rmdir($web);
    rmdir($private);
    rmdir($dir);
}
