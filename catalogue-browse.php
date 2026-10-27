<?php
declare(strict_types=1);
/**
 * Public catalogue browser for the existing AURENOEVA snapshot.
 * Lists indexed product identities only; stock, prices and seller confirmation
 * remain part of the subsequent private RFQ process.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

/**
 * Resolve catalogue facets from product evidence, not the broad search phrase
 * used by the crawler. Unknown categories stay unclassified rather than
 * appearing under a demonstrably unrelated technical sector.
 */
/**
 * Exclude unmistakable consumer/editorial noise introduced by broad web discovery.
 * This only filters public catalogue results; the crawler's evidence is retained.
 * General industrial suppliers, legacy parts and unfamiliar OEMs remain eligible.
 */
function aur_browse_relevant(array $part): bool {
    $source = (string)($part['source_ref'] ?? '');
    $host = strtolower((string)(parse_url($source, PHP_URL_HOST) ?: ''));
    if (in_array($host, [
        'de.pons.com','www.pons.com','pons.com','www.lidl.de','lidl.de',
        'www.ep.de','ep.de','www.notebookcheck.com','notebookcheck.com',
        'www.pcwelt.de','pcwelt.de','www.computerbild.de','computerbild.de',
        'www.thisoldhouse.com','thisoldhouse.com',
        'www.langenscheidt.com','langenscheidt.com',
        'tribesigns.de','www.tribesigns.de',
        'www.deichmann.com','deichmann.com','www.gabor.com','gabor.com',
        'www.breuninger.com','breuninger.com','www.zalando.de','zalando.de',
        'www.aboutyou.de','aboutyou.de'
    ], true)) return false;
    $sector = (string)($part['sector'] ?? '');
    if (strcasecmp(trim((string)($part['manufacturer'] ?? '')), 'Tribesigns') === 0) return false;
    $model = (string)($part['model'] ?? '');
    if (preg_match('/\b(?:stiefelette|sneaker|schuhe?|winterboots|handtasche|umhängetasche)\b/iu', $model)) return false;
    if (preg_match('/\bpons\b/i',(string)($part['manufacturer'] ?? '')) &&
        preg_match('/^97[89][-0-9]+$/',$model)) return false;
    $path = strtolower((string)(parse_url($source, PHP_URL_PATH) ?: ''));
    if ($sector === 'aviation' && preg_match('/geekom|mini[\s-]?pc|notebook/i', $model)
        && !preg_match('/avionic|aircraft|ads[\s-]?b|transponder|flight control/i', $model))
        return false;
    if ($sector === 'marine' && (
        preg_match('/\b(?:tacx|forerunner|smart garage)\b|^edge[®\s]/iu', $model)
        || str_contains($path, '/fleet-optimisation/'))) return false;
    if ($sector === 'test' && str_ends_with($host, 'ibm.com') &&
        preg_match('/ns1 connect|watsonx|api connect/i', $model)) return false;
    return true;
}

function aur_browse_category(array $part): string {
    $sector = (string)($part['sector'] ?? '');
    $cat = trim((string)($part['category'] ?? ''));
    static $allowed = [
        'laboratory' => ['Chromatography / HPLC / GC','Mass spectrometry','Spectroscopy',
            'Sample preparation','Balances & weighing','Incubation / ovens / environmental',
            'General laboratory equipment'],
        'test' => ['Oscilloscopes','Spectrum / signal analyzers','Signal sources / generators',
            'Meters & power analyzers','RF / microwave','Calibration equipment','Data acquisition'],
        'medical' => ['Diagnostic equipment','Imaging & visualization','Patient monitoring',
            'Laboratory / pathology','Surgical / procedural','Sterilization & reprocessing',
            'Other medical equipment'],
        'industrial' => ['PLC / control modules','Drives / servo / motion','Motors / gearboxes',
            'Sensors / instrumentation','Pneumatics / hydraulics','Robotics / automation',
            'Machine / process equipment'],
        'energy' => ['Transformers','Switchgear & protection','Generators',
            'Power conversion / inverters','Power quality / monitoring','Turbine / plant components',
            'Storage / auxiliary systems'],
        'marine' => ['Engines & propulsion','Pumps / fluid systems','Electrical & switchgear',
            'Navigation / instrumentation','Deck machinery','HVAC / refrigeration',
            'Safety / auxiliary systems'],
        'aviation' => ['Avionics','Electrical components','Hydraulic / pneumatic',
            'Engine / powerplant components','Landing gear / actuation',
            'Ground support / test equipment','Traceable specialist components']
    ];
    if (!isset($allowed[$sector])) return '';
    if ($sector === 'medical') {
        $evidence = (string)($part['model'] ?? '').' '.(string)($part['description'] ?? '').
            ' '.(string)($part['source_ref'] ?? '');
        if (preg_match('/intellivue|benevision|patient.monitor|vital.signs|pulse.oxim/i', $evidence))
            return 'Patient monitoring';
        if (preg_match('/anesth|anaesth|ventilat|\bwato\b/i', $evidence))
            return 'Surgical / procedural';
    }
    if ($sector === 'test') {
        $evidence = (string)($part['model'] ?? '').' '.(string)($part['source_ref'] ?? '');
        if (preg_match('/multimeter|dmm7510|power.analy[sz]er/i', $evidence))
            return 'Meters & power analyzers';
        if (preg_match('/oscilloscop|mso[0-9]|mdo[0-9]/i', $evidence))
            return 'Oscilloscopes';
    }
    if ($sector !== 'laboratory') return in_array($cat, $allowed[$sector], true) ? $cat : '';
    $text = (string)($part['manufacturer'] ?? '').' '.(string)($part['model'] ?? '').
        ' '.(string)($part['description'] ?? '');
    // Specific item evidence precedes legacy crawler category labels.
    $rules = [
        'Mass spectrometry' => '/mass spectrom|massenspektrom|quadrupole mass|\bmaldi\b|\btof[\s-]?ms\b/i',
        'Chromatography / HPLC / GC' => '/chromatograph|\b(?:u?hplc|gpc|lc-ms)\b|separation column|chromatographiesäule/i',
        'Spectroscopy' => '/spectroscop|spectromet(?!ry)|\bftir\b|\braman\b|spektroskop/i',
        'Balances & weighing' => '/weigh|balance|wäage|waage|smartcheck|precision scale/i',
        'Incubation / ovens / environmental' => '/incubat|\binc\s*125\b|\boven\b|climate chamber|environmental chamber|brutschrank/i',
        'Sample preparation' => '/sample prepar|centrifug|grind|grinder|granulat|mill\b|milling|comminut|homogeni|pipett|p-[0-9]{1,2}\s+premium|fritsch.{0,80}\bp-[0-9]{1,2}\b/i',
    ];
    foreach ($rules as $name => $pattern) {
        if (preg_match($pattern, $text)) return $name;
    }
    // A generic lab product found in an HPLC search is not itself HPLC.
    if ($cat === 'Chromatography / HPLC / GC') return 'General laboratory equipment';
    return in_array($cat, $allowed[$sector], true) ? $cat : 'General laboratory equipment';
}

/**
 * For newly classified items, return the category supported by evidence for
 * the requested industry, not the original crawler search sector. On records
 * without technical evidence, leave legacy discovery available but unclassified.
 */
function aur_browse_sector_category(array $part,string $requested): ?string {
    if (isset($part['classifications']) && is_array($part['classifications'])) {
        foreach ($part['classifications'] as $facet) {
            if (is_array($facet) && ($facet['industry']??'')===$requested
                && ($facet['method']??'')==='technical-facets/1')
                return (string)($facet['category']??'');
        }
        if (($part['classification_status']??'')==='EVIDENCE_MATCHED')
            return null; // Positive evidence links this part to other industries.
        if (($part['sector']??'')===$requested)
            return ''; // Legacy unclassified record; do not claim a category.
        return null;
    }
    return ($part['sector']??'')===$requested ? aur_browse_category($part) : null;
}

/** Expire source offers at read time, including between 30-minute snapshots. */
function aur_browse_public_offers(array $part): array {
    $raw = $part['public_offers'] ?? [];
    if (!is_array($raw)) return [];
    $now = time();
    $offers = [];
    foreach (array_slice($raw, 0, 5) as $offer) {
        if (!is_array($offer)) continue;
        $date = strtotime((string)($offer['observed_at'] ?? ''));
        if ($date === false || $date < $now-14*86400 || $date > $now+300) continue;
        $url = (string)($offer['source_url'] ?? '');
        $parts = @parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== (string)($offer['seller_website'] ?? '')
            || !is_numeric($offer['amount'] ?? null) || (float)$offer['amount'] <= 0) continue;
        $offers[] = $offer;
    }
    return $offers;
}

function aur_browse_snapshot(string $path, string $sector, string $category = '', string $query = '', int $page = 0, int $limit = 12): array {
    $allowed = ['laboratory','test','medical','industrial','energy','marine','aviation'];
    if (!in_array($sector, $allowed, true)) throw new InvalidArgumentException('invalid_sector');
    if (strlen($category) > 160 || strlen($query) > 120 || $page < 0 || $page > 100000)
        throw new InvalidArgumentException('invalid_filter');
    $limit = max(1, min(24, $limit));
    $size = @filesize($path);
    if ($size === false || $size < 40 || $size > 256000000 || !is_readable($path))
        throw new RuntimeException('catalogue_unavailable');
    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException('catalogue_unavailable');
    $catalogue = json_decode($raw, true);
    unset($raw);
    if (!is_array($catalogue) || ($catalogue['schema'] ?? '') !== 'aurenoeva-catalogue/1'
        || !is_array($catalogue['items'] ?? null)) throw new RuntimeException('catalogue_unavailable');
    $counts = [];
    $sectorTotal = 0; $matching = 0; $items = [];
    $start = $page * $limit;
    $lower = static fn(string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $clip = static fn(string $s, int $n): string => function_exists('mb_substr') ? mb_substr($s,0,$n,'UTF-8') : substr($s,0,$n);
    $q = $lower(trim($query));
    foreach ($catalogue['items'] as $part) {
        if (!is_array($part) || !aur_browse_relevant($part)) continue;
        $cat = aur_browse_sector_category($part,$sector);
        if ($cat === null) continue;
        $id = (string)($part['canonical_id'] ?? '');
        $maker = trim((string)($part['manufacturer'] ?? ''));
        $model = trim((string)($part['model'] ?? ''));
        if (!preg_match('/^AUR-P-[A-F0-9]{20}$/D', $id) || $maker === '' || $model === '') continue;
        $key = $cat === '' ? '__other' : $cat;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        ++$sectorTotal;
        if ($category !== '' && $category !== '__all' &&
            ($category === '__other' ? $cat !== '' : strcasecmp($cat, $category) !== 0)) continue;
        if ($q !== '' && !str_contains($lower($maker.' '.$model.' '.(string)($part['description'] ?? '')), $q)) continue;
        if ($matching >= $start && count($items) < $limit) {
            $source = (string)($part['source_ref'] ?? '');
            $items[] = [
                'canonical_id' => $id,
                'manufacturer' => $clip($maker,100),
                'model' => $clip($model,160),
                'description' => $clip((string)($part['description'] ?? ''),220),
                'sector' => $sector,
                'category' => $cat,
                'classifications' => is_array($part['classifications']??null) ? $part['classifications'] : [],
                'classification_status' => (string)($part['classification_status']??'LEGACY'),
                'part_number' => '',
                'identifier_type' => 'MODEL',
                'relationship_type' => 'CANDIDATE',
                'lifecycle_status' => 'UNVERIFIED',
                'confidence' => null,
                'source_ref' => filter_var($source, FILTER_VALIDATE_URL) ? $source : '',
                'price_status' => aur_browse_public_offers($part) ? 'PUBLIC_OFFER' : 'ON_REQUEST',
                'public_offers' => aur_browse_public_offers($part),
            ];
        }
        ++$matching;
    }
    arsort($counts, SORT_NUMERIC);
    $categories = [];
    foreach ($counts as $name => $count) $categories[] = ['name' => (string)$name, 'count' => $count];
    return [
        'ok' => true, 'sector' => $sector, 'sector_total' => $sectorTotal,
        'categories' => $categories, 'category' => $category, 'query' => $query,
        'total' => $matching, 'page' => $page, 'page_size' => $limit,
        'has_more' => $matching > $start + count($items),
        'items' => $items, 'availability' => 'CONFIRM_ON_ENQUIRY',
    ];
}

if (defined('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY')) return;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo '{"ok":false,"error":"method_not_allowed"}'; exit;
}
try {
    $sector = strtolower(trim((string)($_GET['sector'] ?? 'laboratory')));
    $category = trim((string)($_GET['category'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $page = filter_var($_GET['page'] ?? 0, FILTER_VALIDATE_INT);
    if ($page === false || $page < 0 || $page > 100000) throw new InvalidArgumentException('invalid_page');
    $root = realpath(__DIR__);
    $privatePath = trim((string)(getenv('AURENOEVA_CATALOGUE_SNAPSHOT') ?: ''));
    if ($privatePath === '' && is_file(__DIR__ . '/procurement/private_cases.php')) {
        require_once __DIR__ . '/procurement/private_cases.php';
        $privatePath = aur_store_root() . '/catalogue-public.json';
    }
    if ($privatePath === '' && $root) $privatePath = dirname($root) . '/.aurenoeva-private/catalogue-public.json';
    $real = realpath($privatePath);
    if (!$real || !$root || $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR))
        throw new RuntimeException('catalogue_unavailable');
    echo json_encode(aur_browse_snapshot($real,$sector,$category,$q,$page),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo '{"ok":false,"error":"invalid_filter"}';
} catch (Throwable $e) {
    http_response_code(503); echo '{"ok":false,"error":"catalogue_unavailable"}';
}
