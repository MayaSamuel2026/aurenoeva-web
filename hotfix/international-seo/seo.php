<?php
declare(strict_types=1);
/**
 * International, crawlable catalogue entry pages. No separate storefront,
 * order flow, buyer records, guessed stock or guessed supplier prices.
 * The established catalogue snapshot remains the only product source.
 */
const AUR_SEO_BASE = 'https://aurenoeva.com';
const AUR_SEO_CHUNK = 5000;
header('X-Content-Type-Options: nosniff');

function aur_seo_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function aur_seo_slug(string $value): string {
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    $value = strtolower(is_string($ascii) ? $ascii : $value);
    $value = trim((string)preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    return substr($value !== '' ? $value : 'part', 0, 74);
}
function aur_seo_taxon(string $value): string {
    return aur_seo_slug($value) . '-' . substr(hash('sha256', $value), 0, 10);
}
function aur_seo_route(string $language, string $type, array $row): string {
    $base = '/' . $language . '/';
    if ($type === 'product') {
        return $base . ($language === 'de' ? 'teile/' : 'parts/') .
            aur_seo_slug((string)$row['manufacturer'] . ' ' . (string)$row['model']) . '-' .
            strtolower((string)$row['canonical_id']) . '/';
    }
    if ($type === 'manufacturer') {
        return $base . ($language === 'de' ? 'hersteller/' : 'manufacturers/') .
            aur_seo_taxon((string)$row['manufacturer']) . '/';
    }
    return $base . ($language === 'de' ? 'kategorien/' : 'categories/') .
        rawurlencode((string)$row['sector']) . '/' . aur_seo_taxon((string)$row['category']) . '/';
}
function aur_seo_path(): ?string {
    $docroot = realpath(__DIR__);
    $path = trim((string)(getenv('AURENOEVA_CATALOGUE_SNAPSHOT') ?: ''));
    if ($path === '' && is_file(__DIR__ . '/procurement/private_cases.php')) {
        require_once __DIR__ . '/procurement/private_cases.php';
        try { $path = aur_store_root() . '/catalogue-public.json'; }
        catch (Throwable $e) { return null; }
    }
    if ($path === '' && $docroot) $path = dirname($docroot) . '/.aurenoeva-private/catalogue-public.json';
    $real = $path !== '' ? realpath($path) : false;
    if (!$real || !is_file($real) || !is_readable($real) || !$docroot ||
        $real === $docroot || str_starts_with($real, $docroot . DIRECTORY_SEPARATOR) ||
        (int)filesize($real) > 256000000) return null;
    return $real;
}
/** Stream only the items array, including objects with nested facet and offer data. */
function aur_seo_products_from_json(string $path): Generator {
    $fp = @fopen($path, 'rb');
    if (!$fp) throw new RuntimeException('snapshot_open');
    try {
        $buffer = '';
        $started = false; $object = ''; $depth = 0; $quoted = false; $escaped = false;
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) throw new RuntimeException('snapshot_read');
            if (!$started) {
                $buffer .= $chunk;
                if (!preg_match('/"items"\s*:\s*\[/', $buffer, $matches, PREG_OFFSET_CAPTURE)) {
                    if (strlen($buffer) > 200000) throw new RuntimeException('snapshot_schema');
                    continue;
                }
                $start = $matches[0][1] + strlen($matches[0][0]);
                $chunk = substr($buffer, $start);
                $buffer = ''; $started = true;
            }
            $n = strlen($chunk);
            for ($i = 0; $i < $n; ++$i) {
                $ch = $chunk[$i];
                if ($depth === 0) {
                    if ($ch === ']') return;
                    if ($ch !== '{') continue;
                    $depth = 1; $object = '{'; $quoted = false; $escaped = false;
                    continue;
                }
                $object .= $ch;
                if (strlen($object) > 100000) throw new RuntimeException('oversize_record');
                if ($quoted) {
                    if ($escaped) $escaped = false;
                    elseif ($ch === '\\') $escaped = true;
                    elseif ($ch === '"') $quoted = false;
                } elseif ($ch === '"') $quoted = true;
                elseif ($ch === '{') ++$depth;
                elseif ($ch === '}') {
                    --$depth;
                    if ($depth === 0) {
                        $row = json_decode($object, true);
                        if (is_array($row)) yield $row;
                        $object = '';
                    }
                }
            }
        }
        throw new RuntimeException('snapshot_incomplete');
    } finally { fclose($fp); }
}
/**
 * Maintain a small, private SEO read model beside the existing snapshot.
 * Atomic replacement, no new database, no crawler modifications.
 */
function aur_seo_index(string $snapshot): array {
    $dir = dirname($snapshot);
    $metaFile = $dir . '/seo-index-meta.json';
    $productsFile = $dir . '/seo-index-products.jsonl';
    $fingerprint = (string)filesize($snapshot) . ':' . (string)filemtime($snapshot);
    $readMeta = static function () use ($metaFile, $productsFile, $fingerprint): ?array {
        if (!is_file($metaFile) || !is_file($productsFile)) return null;
        $data = json_decode((string)@file_get_contents($metaFile), true);
        if (!is_array($data) || ($data['fingerprint'] ?? '') !== $fingerprint ||
            ($data['schema'] ?? '') !== 'aurenoeva-seo/1') return null;
        return $data;
    };
    if ($index = $readMeta()) return $index;
    $lock = @fopen($dir . '/.seo-index.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('index_lock');
    try {
        if ($index = $readMeta()) return $index;
        if (!defined('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY')) define('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY', true);
        require_once __DIR__ . '/catalogue-browse.php';
        $temp = tempnam($dir, '.seo-products-');
        if ($temp === false) throw new RuntimeException('index_temp');
        $out = @fopen($temp, 'wb');
        if (!$out) throw new RuntimeException('index_write');
        $makers = []; $categories = []; $count = 0;
        try {
            foreach (aur_seo_products_from_json($snapshot) as $p) {
                $id = (string)($p['canonical_id'] ?? '');
                $maker = trim((string)($p['manufacturer'] ?? ''));
                $model = trim((string)($p['model'] ?? ''));
                if (!preg_match('/^AUR-P-[A-F0-9]{20}$/D', $id) || $maker === '' || $model === '' ||
                    !aur_browse_relevant($p)) continue;
                $facets = [];
                foreach (['laboratory','test','medical','industrial','energy','marine','aviation'] as $sector) {
                    $category = aur_browse_sector_category($p, $sector);
                    // Unknown and legacy categories are not presented as independently verified.
                    if ($category !== null && $category !== '' &&
                        ($p['classification_status'] ?? '') === 'EVIDENCE_MATCHED') {
                        $facets[] = ['sector'=>$sector, 'category'=>$category];
                        $key = $sector . '|' . $category;
                        $categories[$key] = ($categories[$key] ?? 0) + 1;
                    }
                }
                $row = [
                    'canonical_id'=>$id, 'manufacturer'=>substr($maker,0,100),
                    'model'=>substr($model,0,160),
                    'description'=>substr(trim((string)($p['description'] ?? '')),0,240),
                    'source_ref'=>(string)($p['source_ref'] ?? ''), 'facets'=>$facets,
                    'last_seen'=>(string)($p['last_seen'] ?? '')
                ];
                $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($line === false || fwrite($out, $line . "\n") === false)
                    throw new RuntimeException('index_write');
                $makers[$maker] = ($makers[$maker] ?? 0) + 1;
                ++$count;
            }
        } finally { fclose($out); }
        if ($count === 0) { @unlink($temp); throw new RuntimeException('empty_index'); }
        @chmod($temp,0600);
        if (!rename($temp,$productsFile)) throw new RuntimeException('index_rename');
        ksort($makers, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($categories, SORT_STRING);
        $index = ['schema'=>'aurenoeva-seo/1', 'fingerprint'=>$fingerprint,
            'count'=>$count, 'makers'=>$makers, 'categories'=>$categories];
        $tmpMeta = tempnam($dir, '.seo-meta-');
        if (!$tmpMeta) throw new RuntimeException('index_meta');
        file_put_contents($tmpMeta,json_encode($index,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        @chmod($tmpMeta,0600);
        if (!rename($tmpMeta,$metaFile)) throw new RuntimeException('index_meta_rename');
        return $index;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function aur_seo_read_rows(string $path): Generator {
    $handle = fopen($path,'rb');
    if (!$handle) throw new RuntimeException('index_missing');
    try {
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line,true);
            if (is_array($row)) yield $row;
        }
    } finally { fclose($handle); }
}
function aur_seo_head(string $language, string $title, string $description, string $canonical, array $schema): void {
    $alternate = str_starts_with($canonical, AUR_SEO_BASE . '/en/')
        ? str_replace(AUR_SEO_BASE . '/en/', AUR_SEO_BASE . '/de/', $canonical)
        : str_replace(AUR_SEO_BASE . '/de/', AUR_SEO_BASE . '/en/', $canonical);
    // Slugs/paths can differ between locales; hreflang is rendered by callers instead.
    echo '<!doctype html><html lang="'.aur_seo_h($language).'"><head><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<meta name="description" content="'.aur_seo_h($description).'">' .
        '<meta name="robots" content="index,follow,max-snippet:-1">' .
        '<link rel="canonical" href="'.aur_seo_h($canonical).'">' .
        '<title>'.aur_seo_h($title).'</title>' .
        '<link rel="icon" href="/assets/img/brand/favicon-32.png">' .
        '<link rel="stylesheet" href="/assets/css/site.css">' .
        '<style>body{background:#F8F7F3;color:#0E3A33;font-family:"Avenir Next","Helvetica Neue",Aptos,"Segoe UI",Arial,sans-serif}' .
        '.seo-shell{width:min(1090px,calc(100% - 38px));margin:45px auto 85px}' .
        '.seo-top{background:#F8F7F3;border-bottom:1px solid #D7E0DC;position:sticky;top:0;z-index:30}' .
        '.seo-header{width:min(1190px,calc(100% - 38px));margin:auto;min-height:78px;display:flex;align-items:center;gap:24px}' .
        '.seo-logo{display:block;width:min(218px,39vw);height:auto}' .
        '.seo-nav{margin-left:auto;display:flex;align-items:center;gap:18px;flex-wrap:wrap;font-size:13px;font-weight:700}' .
        '.seo-nav a,.seo-breadcrumb a,.seo-list a{color:#2F675A;text-decoration:underline;text-underline-offset:3px}' .
        '.seo-tag{font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#3F6F62}' .
        '.seo-card{border:1px solid #D7E0DC;background:white;border-radius:18px;padding:clamp(20px,3vw,38px);box-shadow:0 15px 45px rgba(14,58,51,.05)}' .
        '.seo-shell h1{font:600 clamp(28px,4vw,46px)/1.15 Georgia,serif;margin:12px 0 16px}' .
        '.seo-shell h2{font:600 24px/1.2 Georgia,serif;margin:25px 0 10px}' .
        '.seo-shell p{line-height:1.7;color:#52685E;max-width:850px}' .
        '.seo-breadcrumb{font-size:12px;color:#587065;margin-bottom:18px}' .
        '.seo-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:25px 0}' .
        '.seo-fact{border-radius:10px;background:#F4F7F5;padding:15px;font-size:13px;overflow-wrap:anywhere}' .
        '.seo-fact b{display:block;font-size:11px;color:#648073;margin-bottom:6px}' .
        '.seo-cta{display:inline-flex;background:#0E3A33;color:white!important;text-decoration:none;border-radius:11px;padding:14px 20px;font-weight:750;margin:15px 0}' .
        '.seo-cta:hover{background:#24584B}' .
        '.seo-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin:20px 0}' .
        '.seo-item{border:1px solid #D7E0DC;border-radius:12px;padding:15px;background:#fff;overflow-wrap:anywhere;font-size:13px}' .
        '.seo-item small{display:block;color:#668076;margin-top:5px}' .
        '.seo-footer{border-top:1px solid #D7E0DC;padding:24px 0;color:#668076;font-size:12px;margin-top:55px}' .
        '.seo-pages{display:flex;gap:15px;align-items:center;margin-top:20px;font-size:13px}' .
        '@media(max-width:600px){.seo-header{min-height:66px;gap:10px}.seo-nav{gap:10px;font-size:11px}.seo-shell{margin-top:26px}}' .
        '</style><script type="application/ld+json">' .
        json_encode($schema,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) .
        '</script></head><body>' .
        '<header class="seo-top"><div class="seo-header"><a href="/" aria-label="AURENOEVA Home">' .
        '<img class="seo-logo" src="/assets/img/brand/aurenoeva-logo-approved.png" alt="AURENOEVA"></a>' .
        '<nav class="seo-nav"><a href="/">'.($language==='de'?'Startseite':'Home').'</a>' .
        '<a href="/requirement.html">'.($language==='de'?'Anfragekorb öffnen':'Open enquiry basket').'</a>' .
        '<a href="/about.html">'.($language==='de'?'Über uns':'About').'</a></nav></div></header>';
}
function aur_seo_footer(string $language): void {
    echo '<footer class="seo-footer">'.($language==='de'
      ? 'AURENOEVA · Technische Beschaffung weltweit · Bestands- und Preisangaben nur nach Bestätigung.'
      : 'AURENOEVA · Worldwide technical sourcing · Stock and prices require supplier confirmation.') .
      ' <a href="/imprint.html">'.($language==='de'?'Impressum':'Imprint').'</a></footer></main></body></html>';
}
function aur_seo_xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function aur_seo_sitemap(array $index, string $productsFile): void {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    $feed = (string)($_GET['feed'] ?? '');
    if ($feed === 'index') {
        echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['en','de'] as $lang) {
            echo '<sitemap><loc>'.AUR_SEO_BASE.'/sitemaps/taxonomy-'.$lang.'.xml</loc></sitemap>';
            for ($n=1;$n<=max(1,(int)ceil($index['count']/AUR_SEO_CHUNK));$n++) {
                echo '<sitemap><loc>'.AUR_SEO_BASE.'/sitemaps/products-'.$lang.'-'.$n.'.xml</loc></sitemap>';
            }
        }
        echo '</sitemapindex>'; return;
    }
    $language = (string)($_GET['lang'] ?? 'en');
    if (!in_array($language,['en','de'],true)) { http_response_code(404);return; }
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if ($feed === 'taxonomy') {
        foreach ($index['makers'] as $maker=>$number) {
            echo '<url><loc>'.aur_seo_xml(AUR_SEO_BASE.aur_seo_route($language,'manufacturer',['manufacturer'=>$maker])).'</loc></url>';
        }
        foreach ($index['categories'] as $key=>$number) {
            [$sector,$category]=explode('|',$key,2);
            echo '<url><loc>'.aur_seo_xml(AUR_SEO_BASE.aur_seo_route($language,'category',['sector'=>$sector,'category'=>$category])).'</loc></url>';
        }
    } elseif ($feed === 'products') {
        $page = filter_var($_GET['page']??null,FILTER_VALIDATE_INT);
        if (!$page || $page<1 || $page>ceil($index['count']/AUR_SEO_CHUNK)) { http_response_code(404);return; }
        $start=($page-1)*AUR_SEO_CHUNK; $end=$start+AUR_SEO_CHUNK; $offset=0;
        foreach (aur_seo_read_rows($productsFile) as $row) {
            if ($offset >= $end) break;
            if ($offset++ < $start) continue;
            echo '<url><loc>'.aur_seo_xml(AUR_SEO_BASE.aur_seo_route($language,'product',$row)).'</loc></url>';
        }
    } else { http_response_code(404);return; }
    echo '</urlset>';
}
function aur_seo_not_found(): never {
    http_response_code(404); header('X-Robots-Tag: noindex');
    echo 'Catalogue page not found / Katalogseite nicht gefunden'; exit;
}
function aur_seo_page(array $index,string $productsFile): void {
    $language=(string)($_GET['lang'] ?? '');
    $kind=(string)($_GET['kind'] ?? '');
    $key=(string)($_GET['key'] ?? '');
    if (!in_array($language,['en','de'],true) || strlen($key)>220) aur_seo_not_found();
    $de=$language==='de';
    $selected=null; $items=[]; $matches=0; $page=(int)($_GET['page'] ?? 1);
    if ($page < 1 || $page > 5000) aur_seo_not_found();
    $currentPath=(string)(parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH) ?: '');
    $sector=(string)($_GET['sector']??'');
    if ($kind==='manufacturer') {
        foreach ($index['makers'] as $maker=>$n) {
            if (aur_seo_taxon((string)$maker)===$key) { $selected=['manufacturer'=>(string)$maker];break; }
        }
    } elseif ($kind==='category') {
        foreach ($index['categories'] as $identity=>$n) {
            [$s,$category]=explode('|',$identity,2);
            if ($sector===$s && aur_seo_taxon($category)===$key) {
                $selected=['sector'=>$s,'category'=>$category];break;
            }
        }
    } elseif ($kind==='product' && preg_match('/(AUR-P-[A-F0-9]{20})$/i',$key,$m)) {
        $id=strtoupper($m[1]);
        foreach (aur_seo_read_rows($productsFile) as $p) {
            if ($p['canonical_id'] === $id) { $selected=$p;break; }
        }
    } else aur_seo_not_found();
    if (!$selected) aur_seo_not_found();
    $canonical=AUR_SEO_BASE.aur_seo_route($language,$kind,$selected);
    if ($currentPath !== parse_url($canonical,PHP_URL_PATH)) {
        header('Location: '.$canonical,true,301);return;
    }
    if ($kind!=='product') {
        $first=($page-1)*24; $end=$first+24;
        foreach (aur_seo_read_rows($productsFile) as $p) {
            $include=$kind==='manufacturer' ? $p['manufacturer']===$selected['manufacturer'] : false;
            if ($kind==='category') {
                foreach ($p['facets'] as $facet) {
                    if ($facet['sector']===$selected['sector'] && $facet['category']===$selected['category']) {
                        $include=true;break;
                    }
                }
            }
            if (!$include) continue;
            if ($matches >= $first && $matches < $end) $items[]=$p;
            ++$matches;
        }
    }
    $name=$kind==='product' ? $selected['manufacturer'].' '.$selected['model'] :
        ($kind==='manufacturer' ? $selected['manufacturer'] : $selected['category']);
    $typeLabel=$kind==='product' ? ($de?'Technisches Produkt':'Technical product') :
        ($kind==='manufacturer' ? ($de?'Hersteller':'Manufacturer') : ($de?'Technische Kategorie':'Technical category'));
    $title=$name.' | AURENOEVA';
    $description=$kind==='product'
        ? ($de ? 'Technische Produktidentität für '.$name.'. Quelle prüfen und eine unverbindliche Beschaffungsanfrage bei AURENOEVA stellen.'
               : 'Explore the technical product identity for '.$name.'. Review source evidence and request sourcing through AURENOEVA.')
        : ($de ? $typeLabel.' '.$name.': Technische Produkte im AURENOEVA-Katalog finden und unverbindlich anfragen.'
               : 'Explore '.$name.' technical products listed in the AURENOEVA catalogue and request sourcing without registration.');
    $schema=$kind==='product'
        ? ['@context'=>'https://schema.org','@type'=>'Product','name'=>$name,
           'brand'=>['@type'=>'Brand','name'=>$selected['manufacturer']],
           'identifier'=>['@type'=>'PropertyValue','propertyID'=>'AURENOEVA catalogue ID','value'=>$selected['canonical_id']],
           'url'=>$canonical,'description'=>$description]
        : ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>$title,'url'=>$canonical,'description'=>$description];
    header('Cache-Control: public, max-age=300');
    aur_seo_head($language,$title,$description,$canonical,$schema);
    $home=$de?'Startseite':'Home';
    echo '<main class="seo-shell"><div class="seo-breadcrumb"><a href="/">'.$home.'</a> / '.aur_seo_h($typeLabel).'</div>' .
        '<article class="seo-card"><span class="seo-tag">'.aur_seo_h($typeLabel).'</span>' .
        '<h1>'.aur_seo_h($name).'</h1><p>'.aur_seo_h($description).'</p>';
    if ($kind==='product') {
        echo '<div class="seo-facts"><div class="seo-fact"><b>'.($de?'Hersteller':'Manufacturer').'</b>' .
            aur_seo_h($selected['manufacturer']).'</div><div class="seo-fact"><b>'.($de?'Modellbezeichnung':'Model identifier').'</b>' .
            aur_seo_h($selected['model']).'</div><div class="seo-fact"><b>'.($de?'Katalogreferenz':'Catalogue reference').'</b>' .
            aur_seo_h($selected['canonical_id']).'</div></div>';
        if (trim($selected['description'])!=='') echo '<h2>'.($de?'Quellenbeschreibung':'Source description').'</h2><p>' .
            aur_seo_h($selected['description']).'</p>';
        if (filter_var($selected['source_ref'],FILTER_VALIDATE_URL) &&
            parse_url($selected['source_ref'],PHP_URL_SCHEME)==='https')
            echo '<p><a rel="nofollow noopener noreferrer" target="_blank" href="' .
                aur_seo_h($selected['source_ref']).'">'.($de?'Originalquelle ansehen':'View original product source').'</a></p>';
        echo '<p>'.($de
            ? 'Diese Katalogaufnahme belegt keine aktuelle Lagerverfügbarkeit, Lieferzeit oder einen verbindlichen Verkaufspreis. AURENOEVA prüft die Beschaffungsmöglichkeiten nach Ihrer Anfrage.'
            : 'A catalogue record does not verify current stock, lead time or a binding selling price. AURENOEVA checks sourcing possibilities after your enquiry.').'</p>';
        $query=$selected['model'];
    } else {
        echo '<p>'.($de
           ? 'Die Einträge sind Produktreferenzen, keine Zusage zu Lagerbestand oder Lieferbarkeit. Die Anfrage erfolgt über den bestehenden AURENOEVA-Anfragekorb.'
           : 'Listings are product references, not a promise of stock or supply. Enquiries use the existing AURENOEVA basket.').'</p>';
        $query=$kind==='manufacturer'?$selected['manufacturer']:$selected['category'];
    }
    echo '<a class="seo-cta" href="/requirement.html?q='.rawurlencode($query).'">' .
        ($de?'Im bestehenden Anfragekorb anfragen':'Request sourcing in existing basket').'</a>';
    if ($kind!=='product') {
        echo '<h2>'.($de?'Produkte entdecken':'Explore listed products').'</h2><div class="seo-list">';
        foreach ($items as $p) echo '<div class="seo-item"><a href="' .
            aur_seo_h(aur_seo_route($language,'product',$p)).'">' .
            aur_seo_h($p['manufacturer'].' · '.$p['model']).'</a><small>' .
            aur_seo_h($p['canonical_id']).'</small></div>';
        echo '</div>';
        if ($matches>24) {
            echo '<p>'.($de?'Angezeigte Einträge':'Displayed entries').': ' .
                ($page*24-23).'–'.min($matches,$page*24).' / '.$matches.'</p>';
            echo '<nav class="seo-pages">';
            if ($page>1) echo '<a rel="prev" href="'.aur_seo_h(aur_seo_route($language,$kind,$selected)).
                '?page='.($page-1).'">'.($de?'Zurück':'Previous').'</a>';
            if ($page*24<$matches) echo '<a rel="next" href="'.aur_seo_h(aur_seo_route($language,$kind,$selected)).
                '?page='.($page+1).'">'.($de?'Weitere Einträge':'More products').'</a>';
            echo '</nav>';
        }
    } else {
        echo '<h2>'.($de?'Weitere Katalogwege':'Related catalogue entries').'</h2><div class="seo-list">';
        echo '<div class="seo-item"><a href="'.aur_seo_h(aur_seo_route($language,'manufacturer',$selected)).'">' .
             aur_seo_h($selected['manufacturer']).'</a></div>';
        foreach (array_slice($selected['facets'],0,3) as $facet) {
            echo '<div class="seo-item"><a href="'.aur_seo_h(aur_seo_route($language,'category',$facet)).'">' .
                aur_seo_h($facet['category']).'</a></div>';
        }
        echo '</div>';
    }
    echo '</article>'; aur_seo_footer($language);
}

try {
    $snapshot=aur_seo_path();
    if (!$snapshot) throw new RuntimeException('catalogue_unavailable');
    $index=aur_seo_index($snapshot);
    $productsFile=dirname($snapshot).'/seo-index-products.jsonl';
    if (isset($_GET['feed'])) aur_seo_sitemap($index,$productsFile);
    else aur_seo_page($index,$productsFile);
} catch (Throwable $error) {
    error_log('AURENOEVA_SEO ' . $error->getMessage());
    header('X-Robots-Tag: noindex');
    http_response_code(503);
    echo 'Catalogue temporarily unavailable / Katalog vorübergehend nicht erreichbar';
}
