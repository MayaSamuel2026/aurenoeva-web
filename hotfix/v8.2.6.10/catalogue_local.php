<?php
declare(strict_types=1);
/** Public-safe Aurenoeva catalogue search from an externally published, private snapshot.
 * Snapshot must reside OUTSIDE public_html and be supplied through server environment.
 */
function aur_catalogue_snapshot_search(string $query, int $limit = 8): ?array {
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $path = trim((string)(getenv('AURENOEVA_CATALOGUE_SNAPSHOT') ?: ''));
    // Default to an app-private directory beside public_html. This allows
    // unattended Hostinger SFTP sync without changing PHP process environment.
    if ($path === '' && $root) {
        $path = dirname($root) . '/.aurenoeva-private/catalogue-public.json';
    }
    if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) return null;
    $real = realpath($path);
    if (!$real || !is_file($real) || ($root && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)))) return null;
    $size = filesize($real);
    if ($size === false || $size > 24_000_000) return null;
    $raw = @file_get_contents($real);
    if (!is_string($raw)) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['schema'] ?? '') !== 'aurenoeva-catalogue/1' || !is_array($data['items'] ?? null)) return null;
    $norm = static fn(string $x): string => preg_replace('/[^a-z0-9]+/', '', strtolower($x)) ?? '';
    $clip = static fn(string $s, int $n): string => function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
    $q = $norm($query);
    if ($q === '') return [];
    $ranked = [];
    foreach (array_slice($data['items'], 0, 80000) as $p) {
        if (!is_array($p)) continue;
        $maker = trim((string)($p['manufacturer'] ?? ''));
        $model = trim((string)($p['model'] ?? ''));
        $id = trim((string)($p['canonical_id'] ?? ''));
        // Old crawls may include broad catalogue headings, not actual models.
        $generic = ['surgical carts','surgical cart','products','dental units',
                    'medical devices','dental equipment','equipment','catalogue',
                    'catalog','accessories','solutions'];
        if ($maker === '' || $model === '' || !preg_match('/^AUR-P-[A-F0-9]{20}$/', $id)
            || in_array(strtolower($model), $generic, true)) continue;
        // Keep older known identities searchable even when manufacturer recrawls
        // are delayed. A catalogue identity is NOT verified availability.
        $m = $norm($maker); $part = $norm($model);
        $description = $norm((string)($p['description'] ?? ''));
        $all = $m . $part . $description;
        $score = $part === $q ? 1000 : (($m . $part) === $q ? 900 :
            (str_contains($part, $q) ? 700 : (str_contains($m . $part, $q) ? 550 :
            (str_contains($description, $q) ? 250 : 0))));
        if (!$score) {
            $tokens = preg_split('/\\s+/u', trim($query)) ?: [];
            $terms = array_values(array_filter(array_map($norm, $tokens)));
            if ($terms && count($terms) <= 8 && count(array_filter($terms, static fn($term) => str_contains($all, $term))) === count($terms)) $score = 150;
        }
        if (!$score) continue;
        $ranked[] = [$score, [
            'canonical_id' => $id,
            'manufacturer' => $clip($maker, 100),
            'model' => $clip($model, 160),
            'part_number' => '',
            'description' => $clip(trim((string)($p['description'] ?? '')), 240),
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
