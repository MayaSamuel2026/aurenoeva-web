<?php
declare(strict_types=1);
/** Public-safe Aurenoeva catalogue search from an externally published, private snapshot.
 * Snapshot must reside OUTSIDE public_html and be supplied through server environment.
 */
function aur_catalogue_snapshot_search(string $query, int $limit = 8): ?array {
    $path = trim((string)(getenv('AURENOEVA_CATALOGUE_SNAPSHOT') ?: ''));
    if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) return null;
    $real = realpath($path);
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if (!$real || !is_file($real) || ($root && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)))) return null;
    $size = filesize($real);
    if ($size === false || $size > 8_000_000) return null;
    $raw = @file_get_contents($real);
    if (!is_string($raw)) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['schema'] ?? '') !== 'aurenoeva-catalogue/1' || !is_array($data['items'] ?? null)) return null;
    $norm = static fn(string $x): string => preg_replace('/[^a-z0-9]+/', '', strtolower($x)) ?? '';
    $q = $norm($query);
    if ($q === '') return [];
    $ranked = [];
    foreach (array_slice($data['items'], 0, 40000) as $p) {
        if (!is_array($p)) continue;
        $maker = trim((string)($p['manufacturer'] ?? ''));
        $model = trim((string)($p['model'] ?? ''));
        $id = trim((string)($p['canonical_id'] ?? ''));
        if ($maker === '' || $model === '' || !preg_match('/^AUR-P-[A-F0-9]{20}$/', $id)) continue;
        $m = $norm($maker); $part = $norm($model);
        $score = $part === $q ? 1000 : (($m . $part) === $q ? 900 : ((str_contains($m . $part, $q) || str_contains($q, $part)) ? 600 : 0));
        if (!$score) continue;
        $ranked[] = [$score, [
            'canonical_id' => $id,
            'manufacturer' => mb_substr($maker, 0, 100),
            'model' => mb_substr($model, 0, 160),
            'part_number' => '',
            'description' => mb_substr(trim((string)($p['description'] ?? '')), 0, 240),
            'identifier_type' => 'MODEL',
            'lifecycle_status' => 'UNVERIFIED',
            'relationship_type' => 'CANDIDATE',
            'confidence' => null,
            'source_ref' => filter_var((string)($p['source_ref'] ?? ''), FILTER_VALIDATE_URL) ?: ''
        ]];
    }
    usort($ranked, static fn(array $a,array $b): int => $b[0] <=> $a[0]);
    return array_map(static fn(array $r): array => $r[1], array_slice($ranked,0,max(1,min(8,$limit))));
}
