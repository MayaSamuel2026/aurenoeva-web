<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';
require __DIR__ . '/_core_bridge.php';
require __DIR__ . '/catalogue_local.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') aur_json(['ok'=>false,'available'=>false,'error'=>'method'],405);
$q = trim((string)($_GET['q'] ?? ''));
$industry = substr(trim((string)($_GET['industry'] ?? '')),0,80);
$category = substr(trim((string)($_GET['category'] ?? '')),0,160);
if ($q === '' || strlen($q) < 2) aur_json(['ok'=>true,'available'=>true,'items'=>[],'source'=>'AURENOEVA']);
if (strlen($q) > 180) aur_json(['ok'=>false,'available'=>true,'error'=>'query_too_long'],422);
header('Cache-Control: no-store, max-age=0');

// Prefer the independent, app-owned catalogue. While it is not installed,
// preserve the existing working legacy search behavior (and all form/CORE cases).
$items = aur_catalogue_snapshot_search($q, 8);
if ($items !== null) {
    aur_json(['ok'=>true,'available'=>true,'items'=>$items,'source'=>'AURENOEVA_OWNED_CATALOGUE',
              'source_mode'=>'verified_identity_candidates']);
}
$result = aur_core_search($q,$industry,$category,8);
if (!$result['configured'] || !$result['reachable']) {
    aur_json(['ok'=>true,'available'=>false,'items'=>[],'source'=>'NOEVA_CORE',
      'binding'=>$result['configured'] ? 'UNREACHABLE' : 'UNCONFIGURED']);
}
aur_json(['ok'=>true,'available'=>true,'items'=>$result['items'],'source'=>'NOEVA_CORE',
 'source_mode'=>$result['source_mode']);
