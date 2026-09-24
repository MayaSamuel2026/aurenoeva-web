<?php
declare(strict_types=1);

/**
 * Aurenoeva -> NOEVA CORE server-side adapter.
 *
 * This file deliberately contains transport/adaptation only. It does not
 * implement shared search, Operations, Compliance or Transaction logic.
 */

function aur_core_env(string $name, string $default = ''): string {
    $v = getenv($name);
    return $v === false ? $default : trim((string)$v);
}

function aur_core_join_url(string $base, string $path): string {
    if ($base === '') { return ''; }
    if (preg_match('#^https?://#i', $path)) { return $path; }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function aur_core_headers(): array {
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    $token = aur_core_env('AURENOEVA_CORE_API_TOKEN');
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . str_replace(["\r","\n"], '', $token); }
    $key = aur_core_env('AURENOEVA_CORE_API_KEY');
    if ($key !== '') { $headers[] = 'X-NOEVA-API-Key: ' . str_replace(["\r","\n"], '', $key); }
    $headers[] = 'X-NOEVA-Vertical: aurenoeva';
    // Preserve the public website origin for CORE public-ingress allow-listing.
    $headers[] = 'Origin: https://aurenoeva.com';
    return $headers;
}

function aur_core_http_json(string $method, string $url, ?array $payload = null, float $timeout = 6.0): array {
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'unconfigured'];
    }
    $method = strtoupper($method);
    $headers = aur_core_headers();
    $raw = false; $status = 0; $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 2500,
            CURLOPT_TIMEOUT_MS => (int)round($timeout * 1000),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Aurenoeva-Core-Bridge/1.0'
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($payload !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) { $error = (string)curl_error($ch); }
        curl_close($ch);
    } else {
        $context = ['http'=>[
            'method'=>$method,
            'timeout'=>$timeout,
            'ignore_errors'=>true,
            'header'=>implode("\r\n", $headers) . "\r\n"
        ]];
        if ($payload !== null && $method !== 'GET') {
            $context['http']['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        $raw = @file_get_contents($url, false, stream_context_create($context));
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) { $status = (int)$m[1]; }
    }

    if (!is_string($raw)) { return ['ok'=>false,'status'=>$status,'data'=>null,'error'=>$error ?: 'transport']; }
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        return ['ok'=>false,'status'=>$status,'data'=>is_array($data)?$data:null,'error'=>'response'];
    }
    return ['ok'=>true,'status'=>$status,'data'=>$data,'error'=>''];
}

function aur_core_base_url(): string {
    // Existing NOEVA VPS HTTPS ingress. Public-safe CORE routes therefore do
    // not require a Hostinger secret or a new DNS record.
    return rtrim(aur_core_env('AURENOEVA_CORE_BASE_URL', 'https://noeva-core.179-198-203-247.nip.io'), '/');
}

function aur_core_search_url(): string {
    $exact = aur_core_env('AURENOEVA_CORE_SEARCH_URL');
    if ($exact !== '') { return $exact; }
    // Product identity stays fail-closed until a dedicated canonical
    // Aurenoeva catalogue authority is promoted. Do not promote crawler
    // observations into catalogue truth merely because CORE is reachable.
    $path = aur_core_env('AURENOEVA_CORE_CATALOGUE_PATH', '');
    return $path === '' ? '' : aur_core_join_url(aur_core_base_url(), $path);
}

function aur_core_requirement_url(): string {
    $exact = aur_core_env('AURENOEVA_CORE_REQUIREMENT_URL');
    if ($exact !== '') { return $exact; }
    $base = aur_core_base_url();
    $path = aur_core_env('AURENOEVA_CORE_REQUIREMENT_PATH', '/api/public/v1/aurenoeva/requirements');
    return aur_core_join_url($base, $path);
}

function aur_core_health_url(): string {
    $exact = aur_core_env('AURENOEVA_CORE_HEALTH_URL');
    if ($exact !== '') { return $exact; }
    return aur_core_join_url(aur_core_base_url(), aur_core_env('AURENOEVA_CORE_HEALTH_PATH', '/api/health'));
}

function aur_core_health(): array {
    $url = aur_core_health_url();
    if ($url === '') {
        return ['configured'=>false,'reachable'=>false,'status'=>0,'service'=>null,'version'=>null];
    }
    $r = aur_core_http_json('GET', $url, null, 3.5);
    $data = is_array($r['data'] ?? null) ? $r['data'] : [];
    return [
        'configured'=>true,
        'reachable'=>(bool)$r['ok'],
        'status'=>(int)$r['status'],
        'service'=>$data['service'] ?? ($data['name'] ?? null),
        'version'=>$data['version'] ?? ($data['release_id'] ?? ($data['engine_version'] ?? null))
    ];
}

function aur_core_norm(string $v): string {
    $v = strtolower(trim($v));
    return preg_replace('/[^a-z0-9]+/i', '', $v) ?? '';
}

function aur_core_candidate_list(array $data): array {
    foreach (['items','results','products','matches'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) { return $data[$key]; }
    }
    if (isset($data['result']) && is_array($data['result'])) {
        foreach (['items','results','products','matches'] as $key) {
            if (isset($data['result'][$key]) && is_array($data['result'][$key])) { return $data['result'][$key]; }
        }
    }
    return [];
}

function aur_core_pick(array $row, array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && !is_array($row[$key]) && $row[$key] !== null) {
            $v = trim((string)$row[$key]);
            if ($v !== '') { return $v; }
        }
    }
    return $fallback;
}

function aur_core_public_product(array $row, string $query): ?array {
    $part = aur_core_pick($row, ['part_number','manufacturer_part_number','mpn','canonical_part','sku','identifier']);
    $manufacturer = aur_core_pick($row, ['manufacturer','manufacturer_name','brand']);
    $model = aur_core_pick($row, ['model','model_number','product_name','name']);
    if ($part === '' && $model === '') { return null; }

    $confidence = null;
    foreach (['confidence','match_confidence','score'] as $ck) {
        if (isset($row[$ck]) && is_numeric($row[$ck])) { $confidence = max(0,min(1,(float)$row[$ck])); break; }
    }
    if ($confidence === null && isset($row['confidence']) && is_string($row['confidence'])) {
        $m = strtolower($row['confidence']);
        $confidence = in_array($m, ['manufacturer','verified','authoritative'], true) ? 0.98 : null;
    }
    $sourceRef = aur_core_pick($row, ['source_ref','evidence_ref','source_id','source_url']);
    $relationship = aur_core_pick($row, ['relationship_type','match_type','relationship'], 'CANDIDATE');
    return [
        'canonical_id'=>aur_core_pick($row, ['canonical_product_id','canonical_id','product_id','id']),
        'manufacturer_id'=>aur_core_pick($row, ['manufacturer_id']),
        'manufacturer'=>$manufacturer,
        'part_number'=>$part,
        'model'=>$model,
        'description'=>aur_core_pick($row, ['description','short_description','summary','name']),
        'identifier_type'=>aur_core_pick($row, ['identifier_type'], $part !== '' ? 'MPN' : 'MODEL'),
        'lifecycle_status'=>aur_core_pick($row, ['lifecycle_status','lifecycle','status']),
        'relationship_type'=>$relationship,
        'confidence'=>$confidence,
        'source_ref'=>$sourceRef,
        '_query'=>$query
    ];
}

function aur_core_search_score(array $row, string $query): int {
    $q = aur_core_norm($query);
    if ($q === '') { return 0; }
    $part = aur_core_norm(aur_core_pick($row, ['part_number','manufacturer_part_number','mpn','canonical_part','sku','identifier']));
    $model = aur_core_norm(aur_core_pick($row, ['model','model_number','product_name','name']));
    $manufacturer = aur_core_norm(aur_core_pick($row, ['manufacturer','manufacturer_name','brand']));
    $name = aur_core_norm(aur_core_pick($row, ['name','description','short_description']));
    $aliases = [];
    if (isset($row['aliases']) && is_array($row['aliases'])) {
        foreach ($row['aliases'] as $a) { if (!is_array($a)) { $aliases[] = aur_core_norm((string)$a); } }
    }
    if ($part !== '' && $part === $q) { return 1000; }
    if (in_array($q, $aliases, true)) { return 950; }
    if ($model !== '' && $model === $q) { return 900; }
    $joined = $manufacturer . $part . $model . $name . implode('', $aliases);
    if ($joined !== '' && str_contains($joined, $q)) { return 600; }
    if ($part !== '' && str_contains($q, $part)) { return 550; }
    return 0;
}

function aur_core_search(string $query, string $industry = '', string $category = '', int $limit = 8): array {
    $url = aur_core_search_url();
    if ($url === '') { return ['configured'=>false,'reachable'=>false,'items'=>[],'source_mode'=>'none']; }

    $separator = str_contains($url, '?') ? '&' : '?';
    $requestUrl = $url . $separator . http_build_query([
        'q'=>$query,
        'application'=>'aurenoeva',
        'vertical'=>'aurenoeva',
        'industry'=>$industry,
        'category'=>$category,
        'limit'=>$limit
    ]);
    $r = aur_core_http_json('GET', $requestUrl, null, 6.0);
    if (!$r['ok']) { return ['configured'=>true,'reachable'=>false,'items'=>[],'source_mode'=>'remote']; }

    $data = $r['data'];
    $rows = aur_core_candidate_list($data);
    $scored = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $score = aur_core_search_score($row, $query);
        // Search endpoints may already return ranked candidates. A full catalogue
        // endpoint requires a local public-adapter filter.
        if ($score <= 0 && isset($data['q'])) { $score = 100; }
        if ($score <= 0) { continue; }
        $pub = aur_core_public_product($row, $query);
        if ($pub !== null) { $scored[] = ['score'=>$score,'item'=>$pub]; }
    }
    usort($scored, fn($a,$b)=>$b['score']<=>$a['score']);
    return [
        'configured'=>true,
        'reachable'=>true,
        'items'=>array_values(array_map(fn($x)=>$x['item'], array_slice($scored,0,$limit))),
        'source_mode'=>isset($data['catalogue_products']) ? 'catalogue_projection' : 'search_projection'
    ];
}

function aur_core_requirement_binding(array $contract): array {
    $url = aur_core_requirement_url();
    if ($url === '') {
        return [
            'configured'=>false,'bound'=>false,'status'=>'DEFERRED_CORE_UNCONFIGURED',
            'requirement_id'=>null,'party_id'=>null,'case_id'=>null,
            'applicability_manifest_ref'=>null,'transaction_id'=>null,
            'compliance_state'=>'NOT_EVALUATED','transaction_state'=>'NOT_CREATED'
        ];
    }

    $r = aur_core_http_json('POST', $url, $contract, 8.0);
    if (!$r['ok']) {
        return [
            'configured'=>true,'bound'=>false,'status'=>'DEFERRED_CORE_UNAVAILABLE',
            'requirement_id'=>null,'party_id'=>null,'case_id'=>null,
            'applicability_manifest_ref'=>null,'transaction_id'=>null,
            'compliance_state'=>'NOT_EVALUATED','transaction_state'=>'NOT_CREATED'
        ];
    }
    $d = $r['data'];
    $case = isset($d['case']) && is_array($d['case']) ? $d['case'] : [];
    $ops = isset($d['operations']) && is_array($d['operations']) ? $d['operations'] : [];
    $comp = isset($d['compliance']) && is_array($d['compliance']) ? $d['compliance'] : [];
    $tx = isset($d['transaction']) && is_array($d['transaction']) ? $d['transaction'] : [];
    return [
        'configured'=>true,
        'bound'=>true,
        'status'=>(string)($d['status'] ?? 'BOUND'),
        'requirement_id'=>$d['requirement_id'] ?? ($d['id'] ?? null),
        'party_id'=>$d['party_id'] ?? ($ops['party_id'] ?? null),
        'case_id'=>$d['case_id'] ?? ($case['id'] ?? ($ops['case_id'] ?? null)),
        'applicability_manifest_ref'=>$d['applicability_manifest_ref'] ?? ($comp['manifest_ref'] ?? null),
        'transaction_id'=>$d['transaction_id'] ?? ($tx['id'] ?? null),
        'compliance_state'=>(string)($d['compliance_state'] ?? ($comp['state'] ?? 'NOT_EVALUATED')),
        'transaction_state'=>(string)($d['transaction_state'] ?? ($tx['state'] ?? 'NOT_CREATED'))
    ];
}
