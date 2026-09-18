<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';
require __DIR__ . '/_core_bridge.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    aur_json(['ok'=>false,'available'=>false,'error'=>'method'],405);
}

$q = trim((string)($_GET['q'] ?? ''));
$industry = substr(trim((string)($_GET['industry'] ?? '')),0,80);
$category = substr(trim((string)($_GET['category'] ?? '')),0,160);

if ($q === '' || strlen($q) < 2) {
    aur_json(['ok'=>true,'available'=>true,'items'=>[],'source'=>'NOEVA_CORE']);
}
if (strlen($q) > 180) {
    aur_json(['ok'=>false,'available'=>true,'error'=>'query_too_long'],422);
}

$result = aur_core_search($q,$industry,$category,8);
header('Cache-Control: no-store, max-age=0');

if (!$result['configured'] || !$result['reachable']) {
    // Fail closed: the public site never invents a catalogue result.
    aur_json([
        'ok'=>true,
        'available'=>false,
        'items'=>[],
        'source'=>'NOEVA_CORE',
        'binding'=>$result['configured'] ? 'UNREACHABLE' : 'UNCONFIGURED'
    ]);
}

aur_json([
    'ok'=>true,
    'available'=>true,
    'items'=>$result['items'],
    'source'=>'NOEVA_CORE',
    'source_mode'=>$result['source_mode']
]);
