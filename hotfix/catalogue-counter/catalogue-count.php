<?php
declare(strict_types=1);
/**
 * Public aggregate counter for the SAME private catalogue used by catalogue-search.php.
 * Does not disclose products, vendors, source URLs, customer cases or stock claims.
 */
function aur_catalogue_count_file(string $path): ?int {
    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real)) return null;
    $stat = @stat($real);
    if (!$stat || $stat['size'] < 45 || $stat['size'] > 256_000_000) return null;
    $fp = @fopen($real, 'rb');
    if (!$fp) return null;
    try {
        $head = fread($fp, 256);
        if (!is_string($head) ||
            !preg_match('/^\s*\{\s*"schema"\s*:\s*"aurenoeva-catalogue(?:\\\\)?\/1"\s*,\s*"items"\s*:\s*\[/', $head)) return null;
        $cacheFile = dirname($real) . '/catalogue-count-cache.json';
        $cached = @file_get_contents($cacheFile);
        if (is_string($cached) && strlen($cached) < 1000) {
            $data = json_decode($cached, true);
            if (is_array($data) && ($data['size'] ?? -1) === $stat['size']
                && ($data['mtime'] ?? -1) === $stat['mtime']
                && ($data['inode'] ?? -1) === $stat['ino']
                && is_int($data['count'] ?? null) && $data['count'] > 0) return $data['count'];
        }
        rewind($fp);
        $count = 0;
        $carry = '';
        $bytesRead = 0;
        $lastMatchEnd = -1;
        // The receiver stores unique sanitized canonical IDs. Keep bounded
        // overlap, with absolute offsets to prevent omissions or double counts
        // when a field straddles a 64 KiB read boundary.
        $pattern = '/"canonical_id"\s*:\s*"AUR-P-[A-F0-9]{20}"/';
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) return null;
            if ($chunk === '' && !feof($fp)) return null;
            $buffer = $carry . $chunk;
            $base = $bytesRead - strlen($carry);
            preg_match_all($pattern, $buffer, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$matched, $offset]) {
                $end = $base + $offset + strlen($matched);
                if ($end > $lastMatchEnd) { ++$count; $lastMatchEnd = $end; }
            }
            $bytesRead += strlen($chunk);
            $carry = substr($buffer, -128);
        }
        if ($count < 1) return null;
        $payload = json_encode(['size'=>$stat['size'],'mtime'=>$stat['mtime'],
            'inode'=>$stat['ino'],'count'=>$count], JSON_THROW_ON_ERROR);
        $tmp = @tempnam(dirname($real), '.count-');
        if ($tmp !== false) {
            @chmod($tmp, 0600);
            if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
                @rename($tmp, $cacheFile);
            }
            if (is_file($tmp)) @unlink($tmp);
        }
        return $count;
    } finally {
        fclose($fp);
    }
}
if (defined('AUR_CATALOGUE_COUNT_LIBRARY_ONLY')) return;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo '{"ok":false,"error":"method_not_allowed"}'; exit;
}
try {
    $root = realpath(__DIR__);
    $path = trim((string)(getenv('AURENOEVA_CATALOGUE_SNAPSHOT') ?: ''));
    if ($path === '' && is_file(__DIR__ . '/procurement/private_cases.php')) {
        require_once __DIR__ . '/procurement/private_cases.php';
        $path = aur_store_root() . '/catalogue-public.json';
    }
    if ($path === '' && $root) $path = dirname($root) . '/.aurenoeva-private/catalogue-public.json';
    $real = realpath($path);
    if (!$real || !$root || $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('missing_private_snapshot');
    }
    $count = aur_catalogue_count_file($real);
    if ($count === null) throw new RuntimeException('catalogue_unavailable');
    echo json_encode(['ok'=>true,'searchable_parts'=>$count,
        'stock_verified'=>false,'source'=>'published_catalogue_snapshot'],
        JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"ok":false,"error":"catalogue_unavailable"}';
}
