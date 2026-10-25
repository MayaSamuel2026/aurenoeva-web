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
/**
 * Count precisely the identities that appear in the seven public sector browsers.
 * Cache is tied to the atomic snapshot inode/mtime/size, just like the raw counter.
 * The underlying published product set is never modified or silently truncated.
 */
function aur_catalogue_visible_count_file(string $path, int $rawCount): ?int {
    if (!is_file(__DIR__.'/catalogue-browse.php')) return $rawCount;
    if (!function_exists('aur_browse_relevant')) {
        if (!defined('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY')) define('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY', true);
        require_once __DIR__.'/catalogue-browse.php';
    }
    $stat=@stat($path);
    if (!$stat || ($stat['size'] ?? 0)>256000000) return null;
    $cache=dirname($path).'/catalogue-visible-count-cache.json';
    $cached=@file_get_contents($cache);
    if (is_string($cached) && strlen($cached)<512) {
        $v=json_decode($cached,true);
        if (is_array($v) && ($v['size']??-1)===$stat['size']
            && ($v['mtime']??-1)===$stat['mtime'] && ($v['inode']??-1)===$stat['ino']
            && is_int($v['count']??null) && $v['count']>=0 && $v['count']<=$rawCount)
            return $v['count'];
    }
    $raw=@file_get_contents($path);
    if (!is_string($raw)) return null;
    $data=json_decode($raw,true);
    unset($raw);
    if (!is_array($data) || ($data['schema']??'')!=='aurenoeva-catalogue/1'
        || !is_array($data['items']??null)) return null;
    $sectors=['laboratory','test','medical','industrial','energy','marine','aviation'];
    $count=0;
    foreach ($data['items'] as $p) {
        if (!is_array($p) || !in_array((string)($p['sector']??''),$sectors,true)
            || !preg_match('/^AUR-P-[A-F0-9]{20}$/D',(string)($p['canonical_id']??''))
            || trim((string)($p['manufacturer']??''))==='' || trim((string)($p['model']??''))===''
            || !aur_browse_relevant($p)) continue;
        ++$count;
    }
    unset($data);
    $tmp=@tempnam(dirname($path),'.visible-count-');
    if ($tmp!==false) {
        @chmod($tmp,0600);
        $value=json_encode(['size'=>$stat['size'],'mtime'=>$stat['mtime'],
            'inode'=>$stat['ino'],'count'=>$count],JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp,$value,LOCK_EX)!==false) @rename($tmp,$cache);
        if (is_file($tmp)) @unlink($tmp);
    }
    return $count;
}

/** Aggregate reconciliation is embedded at the end of the published JSON.
 * Reads only a bounded tail and never exposes inventory contents or contacts.
 */
function aur_catalogue_public_stats(string $path, int $publishedCount): ?array {
    $size = @filesize($path);
    if (!is_int($size) || $size < 40) return null;
    $fp = @fopen($path,'rb');
    if (!$fp) return null;
    try {
        if (fseek($fp, max(0,$size-4096), SEEK_SET) !== 0) return null;
        $tail = stream_get_contents($fp, 4096);
    } finally { fclose($fp); }
    if (!is_string($tail) ||
        !preg_match('/"public_stats"\\s*:\\s*(\\{[^{}]{1,3000}\\})\\s*\\}\\s*$/s',$tail,$m))
        return null;
    $v = json_decode($m[1],true);
    if (!is_array($v) || ($v['eligible_products']??null)!==$publishedCount) return null;
    foreach (['db_products','exported_products','eligible_products',
              'excluded_products','excluded_invalid_id','excluded_generic',
              'excluded_missing_maker','excluded_missing_model',
              'excluded_source','excluded_duplicates','excluded_nonrecord'] as $key) {
        if (!is_int($v[$key]??null) || $v[$key]<0) return null;
    }
    if ($v['db_products'] < $v['eligible_products']) return null;
    return $v;
}

/** Snapshot modification time is a publication freshness signal, not a crawl timestamp. */
function aur_catalogue_snapshot_updated_at(string $path): ?string {
    clearstatcache(true, $path);
    $time = @filemtime($path);
    return is_int($time) && $time > 0 ? gmdate('Y-m-d\\TH:i:s\\Z', $time) : null;
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
    $visible=aur_catalogue_visible_count_file($real,$count);
    if ($visible === null) throw new RuntimeException('catalogue_unavailable');
    $payload=['ok'=>true,'searchable_parts'=>$visible,
        'published_parts'=>$count,'excluded_from_browse'=>$count-$visible,
        'snapshot_updated_at_utc'=>aur_catalogue_snapshot_updated_at($real),
        'stock_verified'=>false,'source'=>'published_catalogue_snapshot'];
    $stats=aur_catalogue_public_stats($real,$count);
    if ($stats!==null) $payload['reconciliation']=$stats;
    echo json_encode($payload,JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"ok":false,"error":"catalogue_unavailable"}';
}
