<?php
declare(strict_types=1);
/**
 * AURENOEVA public worldwide supplier discovery.
 * Reads the same existing private cases and supplier research JSON.
 * Search results are unverified leads, NEVER quotes, available stock,
 * distributor authorisation, or export/sanctions clearance.
 * No customer identity, customer document, prices or case ID are transmitted.
 */
const AUR_GLOBAL_DISCOVERY_VERSION = '2026-10-21.1';

function aur_supplier_global_queries(string $description, ?array $match=null): array {
    // For recognised equipment search by the public OEM/model only. Unknown
    // descriptions are truncated; never add buyer identity, case ID or country.
    $term=$match ? trim((string)$match['name'].' '.(string)$match['model']) : $description;
    $term=preg_replace('/[\x00-\x1f\x7f]+/u',' ', $term) ?? $term;
    $term=trim(preg_replace('/\s+/u',' ', $term) ?? $term);
    $term=function_exists('mb_substr') ? mb_substr($term,0,120) : substr($term,0,120);
    return [
        'Worldwide'=>$term.' equipment supplier distributor',
        'Europe'=>$term.' authorised dealer distributor Europe Germany Italy UK',
        'Asia-Pacific'=>$term.' medical equipment supplier South Korea Japan China Singapore',
        'Middle East'=>$term.' equipment distributor UAE Turkey India',
        'North America'=>$term.' medical equipment supplier United States Canada',
    ];
}
function aur_supplier_global_search_links(string $description, ?array $match=null): array {
    $links=[];
    foreach (aur_supplier_global_queries($description,$match) as $region=>$query) {
        $links[$region]='https://www.google.com/search?'.http_build_query(['q'=>$query],'','&',PHP_QUERY_RFC3986);
    }
    return $links;
}
function aur_supplier_feed_parse(string $body, int $limit=5): array {
    if (!function_exists('simplexml_load_string') || strlen($body)>220000) {
        throw new RuntimeException('feed_parser_unavailable');
    }
    $xml=@simplexml_load_string($body,'SimpleXMLElement',LIBXML_NONET | LIBXML_COMPACT);
    if (!$xml || !isset($xml->channel)) throw new RuntimeException('search_feed_invalid');
    $leads=[];$seen=[];
    foreach ($xml->channel->item as $item) {
        $url=trim((string)$item->link);
        $parts=parse_url($url);
        $host=strtolower((string)($parts['host']??''));
        if (!is_array($parts) || ($parts['scheme']??'')!=='https' ||
            $host==='' || strlen($url)>1400 || !filter_var($url,FILTER_VALIDATE_URL) ||
            !preg_match('/^[a-z0-9.-]+$/D',$host) ||
            !str_contains($host,'.') || preg_match('/(?:^|\.)(?:bing|google|duckduckgo|localhost)\.(?:com|net|org)$/D',$host) ||
            filter_var($host,FILTER_VALIDATE_IP) || isset($seen[$url])) continue;
        $seen[$url]=true;
        $title=trim(strip_tags(html_entity_decode((string)$item->title,ENT_QUOTES|ENT_HTML5,'UTF-8')));
        $snippet=trim(strip_tags(html_entity_decode((string)$item->description,ENT_QUOTES|ENT_HTML5,'UTF-8')));
        $leads[]=['page_title'=>function_exists('mb_substr')?mb_substr($title,0,180):substr($title,0,180),
            'source_url'=>$url,'website'=>$host,
            'snippet'=>function_exists('mb_substr')?mb_substr($snippet,0,240):substr($snippet,0,240),
            'role'=>'PUBLIC_SEARCH_RESULT_UNVERIFIED',
            'availability'=>'NOT_CHECKED','authorization'=>'NOT_CHECKED',
            'quotation'=>'NOT_RECEIVED','outreach'=>'NOT_SENT'];
        if (count($leads)>=$limit) break;
    }
    return $leads;
}
function aur_supplier_public_rss(string $query): string {
    // Fixed search provider, no arbitrary remote URLs and no redirect following.
    if (!function_exists('curl_init')) throw new RuntimeException('http_client_unavailable');
    $url='https://www.bing.com/search?'.http_build_query(
        ['q'=>$query,'format'=>'rss'],'','&',PHP_QUERY_RFC3986);
    $c=curl_init($url);
    if ($c===false) throw new RuntimeException('http_client_unavailable');
    $body='';
    curl_setopt_array($c,[
        CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'AURENOEVA-Supplier-Research/1.0 (+https://aurenoeva.com)',
        CURLOPT_HTTPHEADER=>['Accept: application/rss+xml, application/xml;q=0.9'],
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
            if (strlen($body)+strlen($chunk)>200000) return 0;
            $body.=$chunk;return strlen($chunk);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($c,CURLOPT_PROTOCOLS_STR,'https');
    elseif (defined('CURLPROTO_HTTPS')) curl_setopt($c,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
    $ok=curl_exec($c);
    $status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    curl_close($c);
    if ($ok===false || $status!==200 || $body==='') throw new RuntimeException('search_provider_unavailable');
    return $body;
}
function aur_supplier_discovery_stats(array $intel): array {
    $rows=$intel['global_discovery']['rows']??[];
    $checked=0;$links=0;
    foreach ($rows as $r) {
        if (is_array($r) && !empty($r['checked_at'])) {$checked++;$links+=count($r['leads']??[]);}
    }
    return ['checked'=>$checked,'links'=>$links,'total'=>count($intel['rows']??[])];
}
function aur_supplier_discover_batch(string $store,array $case,int $limit=2,?callable $fetcher=null): array {
    if ($limit<1 || $limit>4) throw new InvalidArgumentException('invalid_discovery_batch_size');
    // Reuse the existing authoritative research file; the case is not altered.
    aur_supplier_save($store,$case);
    $reference=(string)$case['reference'];
    $target=aur_supplier_private_file($store,$reference);
    $lock=fopen(dirname($target).'/.research.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('supplier_research_lock_unavailable');
    try {
        $intel=aur_supplier_read($store,$reference,(string)$case['source_sha256']);
        if (!is_array($intel)) throw new RuntimeException('research_not_found');
        if (!isset($intel['global_discovery'])) $intel['global_discovery']=[
            'version'=>AUR_GLOBAL_DISCOVERY_VERSION,'rows'=>[]];
        $now=time();$processed=0;$new=0;$fail=0;
        foreach ($intel['rows'] as $row) {
            $key=(string)($row['source_row']??'');
            $prior=$intel['global_discovery']['rows'][$key]??null;
            // Re-enrich every 24h, not on each page reload.
            if (is_array($prior) && $now-(int)($prior['checked_timestamp']??0)<86400) continue;
            $desc=(string)($row['description']??'');
            $query=aur_supplier_global_queries($desc,aur_supplier_match($desc))['Worldwide'];
            $leads=[];$status='SEARCHED_NO_MATCH';
            try {
                $xml=$fetcher ? $fetcher($query) : aur_supplier_public_rss($query);
                $leads=aur_supplier_feed_parse((string)$xml);
                if ($leads) $status='PUBLIC_LEADS_UNVERIFIED';
            } catch (Throwable $e) {
                // Do not fabricate matches when the search service fails.
                $status='PROVIDER_UNAVAILABLE';$fail++;
            }
            $intel['global_discovery']['rows'][$key]=[
                'status'=>$status,'query'=>$query,
                'checked_at'=>gmdate('c'),'checked_timestamp'=>$now,
                'search_provider'=>'BING_PUBLIC_RSS',
                'leads'=>$leads];
            $processed++;$new+=count($leads);
            if ($processed>=$limit) break;
        }
        $intel['global_discovery']['version']=AUR_GLOBAL_DISCOVERY_VERSION;
        $intel['global_discovery']['updated_at']=gmdate('c');
        if ($processed>0) {
            $json=json_encode($intel,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $tmp=dirname($target).'/.discovery-'.bin2hex(random_bytes(8));
            if (file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)) throw new RuntimeException('discovery_write_failed');
            @chmod($tmp,0600);
            if (!rename($tmp,$target)) throw new RuntimeException('discovery_commit_failed');
            $readback=aur_supplier_read($store,$reference,(string)$case['source_sha256']);
            if (!is_array($readback)) throw new RuntimeException('discovery_readback_failed');
        }
        return ['processed'=>$processed,'new_unverified_links'=>$new,'provider_failures'=>$fail,
                'stats'=>aur_supplier_discovery_stats($intel),'outreach_sent'=>false];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
