<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    aur_json(['ok' => false, 'available' => false, 'error' => 'method'], 405);
}

$q = trim((string)($_GET['q'] ?? ''));
$industry = trim((string)($_GET['industry'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));

if ($q === '' || strlen($q) < 2) {
    aur_json(['ok' => true, 'available' => true, 'items' => []]);
}
if (strlen($q) > 180) {
    aur_json(['ok' => false, 'available' => true, 'error' => 'query_too_long'], 422);
}

$base = trim((string)(getenv('AURENOEVA_CORE_SEARCH_URL') ?: ''));
if ($base === '') {
    // The public website must never invent catalogue matches. Until the
    // public-safe CORE search adapter is configured, manual enquiry remains
    // available and the frontend explains that no live catalogue match was
    // returned.
    aur_json(['ok' => true, 'available' => false, 'items' => []]);
}

$scheme = strtolower((string)(parse_url($base, PHP_URL_SCHEME) ?? ''));
if (!in_array($scheme, ['https', 'http'], true)) {
    aur_json(['ok' => false, 'available' => false, 'error' => 'gateway_config'], 500);
}

$params = ['q' => $q, 'limit' => 8];
if ($industry !== '') { $params['industry'] = substr($industry, 0, 80); }
if ($category !== '') { $params['category'] = substr($category, 0, 160); }
$url = rtrim($base, '?&') . (str_contains($base, '?') ? '&' : '?') . http_build_query($params);

$raw = false;
$status = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Aurenoeva-Public-Search/1.0'
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
} else {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 6,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: Aurenoeva-Public-Search/1.0\r\n"
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
}

if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
    error_log('Aurenoeva catalogue gateway unavailable; status=' . $status);
    aur_json(['ok' => true, 'available' => false, 'items' => []]);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    aur_json(['ok' => true, 'available' => false, 'items' => []]);
}

$candidates = [];
foreach (['items', 'results', 'products', 'matches'] as $key) {
    if (isset($data[$key]) && is_array($data[$key])) { $candidates = $data[$key]; break; }
}
if (!$candidates && isset($data['result']) && is_array($data['result'])) {
    foreach (['items', 'results', 'products', 'matches'] as $key) {
        if (isset($data['result'][$key]) && is_array($data['result'][$key])) { $candidates = $data['result'][$key]; break; }
    }
}

function aur_pick(array $row, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && !is_array($row[$key]) && $row[$key] !== null) {
            $v = trim((string)$row[$key]);
            if ($v !== '') { return $v; }
        }
    }
    return $fallback;
}

$items = [];
foreach (array_slice($candidates, 0, 8) as $row) {
    if (!is_array($row)) { continue; }
    $part = aur_pick($row, ['part_number','manufacturer_part_number','mpn','canonical_part','sku','identifier']);
    $manufacturer = aur_pick($row, ['manufacturer','manufacturer_name','brand']);
    $model = aur_pick($row, ['model','model_number','product_name','name']);
    if ($part === '' && $model === '') { continue; }

    $confidence = null;
    foreach (['confidence','match_confidence','score'] as $ck) {
        if (isset($row[$ck]) && is_numeric($row[$ck])) {
            $confidence = max(0, min(1, (float)$row[$ck]));
            break;
        }
    }

    $items[] = [
        'canonical_id' => aur_pick($row, ['canonical_product_id','canonical_id','product_id','id']),
        'manufacturer_id' => aur_pick($row, ['manufacturer_id']),
        'manufacturer' => $manufacturer,
        'part_number' => $part,
        'model' => $model,
        'description' => aur_pick($row, ['description','short_description','summary']),
        'identifier_type' => aur_pick($row, ['identifier_type'], $part !== '' ? 'MPN' : 'MODEL'),
        'lifecycle_status' => aur_pick($row, ['lifecycle_status','lifecycle','status']),
        'relationship_type' => aur_pick($row, ['relationship_type','match_type'], 'CANDIDATE'),
        'confidence' => $confidence,
        'source_ref' => aur_pick($row, ['source_ref','evidence_ref','source_id'])
    ];
}

header('Cache-Control: no-store, max-age=0');
aur_json(['ok' => true, 'available' => true, 'items' => $items]);
