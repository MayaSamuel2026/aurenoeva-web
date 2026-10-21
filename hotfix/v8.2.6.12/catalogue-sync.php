<?php
declare(strict_types=1);
/**
 * One-way AURENOEVA catalogue snapshot receiver.
 * Existing website HTTPS, no SFTP account, no CORE and no new database.
 * Only canonical public-safe product identities are accepted.
 */
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

function aur_catalogue_clean_snapshot(string $body): array {
    if ($body === '' || strlen($body) > 24000000) throw new RuntimeException('snapshot_size');
    $raw = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($raw) || ($raw['schema'] ?? '') !== 'aurenoeva-catalogue/1'
        || !isset($raw['items']) || !is_array($raw['items'])) {
        throw new RuntimeException('snapshot_schema');
    }
    if (count($raw['items']) > 80000) throw new RuntimeException('snapshot_record_count');
    $items = [];
    $generic = ['surgical carts','surgical cart','products','product','dental units',
        'dental equipment','equipment','medical devices','medical equipment',
        'catalogue','catalog','all products','accessories','solutions'];
    foreach ($raw['items'] as $p) {
        if (!is_array($p)) continue;
        $id = trim((string)($p['canonical_id'] ?? ''));
        $manufacturer = trim((string)($p['manufacturer'] ?? ''));
        $model = trim((string)($p['model'] ?? ''));
        $source = trim((string)($p['source_ref'] ?? ''));
        $url = parse_url($source);
        if (!preg_match('/^AUR-P-[A-F0-9]{20}$/D', $id)
            || $manufacturer === '' || strlen($manufacturer) > 100
            || $model === '' || strlen($model) > 160
            || in_array(strtolower($model), $generic, true)
            || !is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https'
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])) continue;
        $items[$id] = [
            'canonical_id' => $id,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'description' => substr(trim((string)($p['description'] ?? '')), 0, 240),
            'source_ref' => $source,
            'last_seen' => substr(trim((string)($p['last_seen'] ?? '')), 0, 40),
            'lifecycle_status' => 'UNVERIFIED'
        ];
    }
    // A broken or empty upstream catalogue must never erase the last good website snapshot.
    if (!$items) throw new RuntimeException('snapshot_no_products');
    return [json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>array_values($items),
        'availability_notice'=>'Identity evidence only; no stock or price implied.'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), count($items)];
}

function aur_catalogue_write_snapshot(string $body, string $directory): array {
    [$clean, $count] = aur_catalogue_clean_snapshot($body);
    $target = $directory . '/catalogue-public.json';
    $incoming = tempnam($directory, '.catalogue-incoming-');
    if ($incoming === false) throw new RuntimeException('snapshot_temp_unavailable');
    try {
        if (file_put_contents($incoming, $clean, LOCK_EX) !== strlen($clean))
            throw new RuntimeException('snapshot_write_failed');
        @chmod($incoming, 0600);
        if (!rename($incoming, $target)) throw new RuntimeException('snapshot_replace_failed');
        $incoming = '';
        $digest = hash('sha256', $clean);
        if (hash_file('sha256', $target) !== $digest) throw new RuntimeException('snapshot_readback');
        return ['ok'=>true,'status'=>'PUBLISHED','records'=>$count,'sha256'=>$digest,'source_sha256'=>hash('sha256', $body)];
    } finally {
        if ($incoming !== '' && is_file($incoming)) @unlink($incoming);
    }
}

// Tests exercise real private writes without an HTTP transport.
if (defined('AUR_CATALOGUE_SYNC_LIBRARY_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '{"ok":false,"error":"method"}'; exit;
}
if ((empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    http_response_code(403); echo '{"ok":false,"error":"https_required"}'; exit;
}
// One canonical private path for administrator credentials, procurement
// cases, and catalogue snapshots, regardless of Hostinger release symlinks.
require_once __DIR__ . '/procurement/private_cases.php';
try {
    $private = aur_store_root();
} catch (Throwable $e) {
    http_response_code(503); echo '{"ok":false,"error":"private_storage_unavailable"}'; exit;
}
$tokenFile = $private . '/catalogue-sync-token.sha256';
$expected = is_file($tokenFile) ? trim((string)@file_get_contents($tokenFile)) : '';
$supplied = (string)($_SERVER['HTTP_X_AURENOEVA_SYNC_TOKEN'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/D', $expected)
    || !preg_match('/^[a-f0-9]{64}$/D', $supplied)
    || !hash_equals($expected, hash('sha256', $supplied))) {
    http_response_code(401); echo '{"ok":false,"error":"unauthorized"}'; exit;
}
if (!is_dir($private) || !is_writable($private)) {
    http_response_code(503); echo '{"ok":false,"error":"private_storage_unavailable"}'; exit;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000000) {
    http_response_code(413); echo '{"ok":false,"error":"snapshot_too_large"}'; exit;
}
try {
    $body = file_get_contents('php://input', false, null, 0, 24000001);
    $result = aur_catalogue_write_snapshot((string)$body, $private);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('AURENOEVA catalogue sync failure: ' . substr($e->getMessage(), 0, 100));
    http_response_code(422);
    echo '{"ok":false,"error":"snapshot_rejected"}';
}
