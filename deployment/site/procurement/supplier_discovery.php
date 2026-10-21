<?php
declare(strict_types=1);
/**
 * AURENOEVA public worldwide supplier discovery.
 * Reads the same existing private cases and supplier research JSON.
 * Search results are unverified leads, NEVER quotes, available stock,
 * distributor authorisation, or export/sanctions clearance.
 * No customer identity, customer document, prices or case ID are transmitted.
 */
const AUR_GLOBAL_DISCOVERY_VERSION = '2026-10-21.4-relevance';

/**
 * Search with the CUSTOMER REQUEST, not a broad model family such as
 * "DIAMOND (configuration to verify)". Never send customer data.
 */
function aur_supplier_global_queries(string $description, ?array $match=null): array {
    $term=preg_replace('/[\x00-\x1f\x7f]+/u',' ', $description) ?? $description;
    $term=trim(preg_replace('/\s+/u',' ', $term) ?? $term);
    $term=function_exists('mb_substr') ? mb_substr($term,0,130) : substr($term,0,130);
    $brand=($match!==null && !in_array(($match['quality']??''),['CATEGORY_ONLY','PROBABLE_MODEL_FAMILY'],true))
        ? strtok((string)$match['name'],' /(') : '';
    if ($brand!=='' && stripos($term,$brand)===false) $term=trim($brand.' '.$term);
    return [
        'Worldwide'=>$term.' medical equipment supplier dealer distributor',
        'Europe'=>$term.' medical equipment dealer distributor Europe Germany Italy UK',
        'Asia-Pacific'=>$term.' medical equipment supplier South Korea Japan China Singapore',
        'Middle East'=>$term.' medical equipment distributor UAE Turkey India',
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
/**
 * Page relevance is a separate step from Bing retrieval. Matching a generic
 * word such as "supplier" is NOT equipment evidence.
 * A recognised manufacturer needs its specific product/model reference.
 * For anonymous equipment, match at least two request terms (or one for
 * a single highly-specific device category) in a medical/supply context.
 */
function aur_supplier_relevance(string $description, ?array $match, array $lead): ?string {
    $raw=trim((string)($lead['page_title']??'').' '.(string)($lead['snippet']??''));
    $hay=function_exists('mb_strtolower') ? mb_strtolower($raw,'UTF-8') : strtolower($raw);
    $hay=preg_replace('/[\p{Z}\s]+/u',' ',$hay) ?? $hay;
    if (trim($hay)==='') return null;
    // Explicitly reject navigational and unrelated pages even when terms overlap.
    if (preg_match('/\b(?:career|careers|jobs|recruitment|vacancy|privacy policy|cookie policy|login|bull semen|cattle breeding|geneticshop|bullensuche|fleischrinder)\b/ui',$hay)) return null;
    $category='/\b(?:medical|hospital|clinic|clinical|radiolog|diagnostic|x[\s-]?ray|imaging|ultrasound|sonograph|dental|dentist|mri|ct scan|ventilat|anesthes|anaesthes|surgical|steriliz|autoclav|patient|healthcare|endoscop|ophthalm|cardiolog|urolog|laboratory|equipment|device|machine|scanner|transducer)\b/ui';
    if ($match!==null && !in_array(($match['quality']??''),['CATEGORY_ONLY','PROBABLE_MODEL_FAMILY'],true)) {
        $brand=strtolower((string)strtok((string)($match['name']??''),' /('));
        $brandPresent=$brand!=='' && (function_exists('mb_stripos') ? mb_stripos($hay,$brand,0,'UTF-8')!==false : stripos($hay,$brand)!==false);
        $model=(string)($match['model']??'');
        $model=preg_replace('/\([^)]*\)/u',' ', $model)??$model;
        $model=preg_replace('/\b(?:probable|configuration|confirm|verify|category|model|system|product|family|version|variant)\b/ui',' ',$model)??$model;
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}-]*/u',$model,$m);
        $modelTerms=array_values(array_filter($m[0],static fn($v):bool =>
            (function_exists('mb_strlen') ? mb_strlen($v,'UTF-8') : strlen($v))>=2 && !in_array(strtolower($v),['to','the','with','of','and'],true)));
        // Prioritise the principal model name and a recognisable secondary token.
        $modelEvidence=false;
        foreach ($modelTerms as $token) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token,'/').'(?![\p{L}\p{N}])/iu',$hay)) {
                $modelEvidence=true;break;
            }
        }
        if ($brandPresent && $modelEvidence) return 'OEM_MODEL_MENTION';
        // Distinctive model plus clinical use can identify a product without
        // the brand in the title; never accept generic "DIAMOND" alone.
        $distinctive=false;
        foreach ($modelTerms as $token) {
            if (((function_exists('mb_strlen') ? mb_strlen($token,'UTF-8') : strlen($token))>=4 || preg_match('/\d/u',$token)) &&
                preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token,'/').'(?![\p{L}\p{N}])/iu',$hay)) {
                $distinctive=true;break;
            }
        }
        if ($distinctive && preg_match($category,$hay) &&
            preg_match('/\b(?:medical|hospital|clinical|radiolog|x[\s-]?ray|imaging|ultrasound|dental|mri|ventilat|anesthes|healthcare|surgical|steriliz|patient|diagnostic|equipment|scanner)\b/ui',$hay)) {
            return 'MODEL_CATEGORY_MENTION';
        }
        // The maker's general website is not evidence of this MODEL.
        return null;
    }
    $clean=preg_replace('/(?<=\d)\.(?=\d)/u','', $description)??$description;
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}-]*/u',$clean,$words);
    $stop=['system'=>1,'equipment'=>1,'medical'=>1,'manufacturer'=>1,'supplier'=>1,
        'requested'=>1,'alternative'=>1,'machine'=>1,'device'=>1,'the'=>1,'and'=>1,
        'for'=>1,'with'=>1,'model'=>1,'unit'=>1,'new'=>1,'classic'=>1,'expert'=>1,
        'type'=>1,'pieces'=>1,'piece'=>1,'qty'=>1,'power'=>1,'high'=>1,'low'=>1,
        'standard'=>1,'complete'=>1,'set'=>1,'including'=>1];
    $tokens=[];
    foreach ($words[0] as $word) {
        $word=function_exists('mb_strtolower') ? mb_strtolower($word,'UTF-8') : strtolower($word);
        if ((function_exists('mb_strlen') ? mb_strlen($word,'UTF-8') : strlen($word))<3 || isset($stop[$word])) continue;
        $tokens[$word]=true;
    }
    $n=count($tokens);$hits=0;
    foreach (array_keys($tokens) as $token) {
        if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token,'/').'(?![\p{L}\p{N}])/iu',$hay)) $hits++;
    }
    if ($hits>=min(2,max(1,$n)) && preg_match($category,$hay)) {
        return 'EQUIPMENT_CATEGORY_MENTION';
    }
    return null;
}
function aur_supplier_feed_parse(string $body, int $limit=5, string $description='', ?array $match=null): array {
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
        $candidate=['page_title'=>function_exists('mb_substr')?mb_substr($title,0,180):substr($title,0,180),
            'source_url'=>$url,'website'=>$host,
            'snippet'=>function_exists('mb_substr')?mb_substr($snippet,0,240):substr($snippet,0,240),
            'role'=>'PUBLIC_SEARCH_RESULT_UNVERIFIED',
            'availability'=>'NOT_CHECKED','authorization'=>'NOT_CHECKED',
            'quotation'=>'NOT_RECEIVED','outreach'=>'NOT_SENT'];
        if ($description!=='') {
            $basis=aur_supplier_relevance($description,$match,$candidate);
            if ($basis===null) continue;
            $candidate['match_basis']=$basis;
        }
        $leads[]=$candidate;
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
        CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
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
        if (is_array($r) && ($r['version']??'')===AUR_GLOBAL_DISCOVERY_VERSION && !empty($r['checked_at'])) {$checked++;$links+=count($r['leads']??[]);}
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
            $cooldown=(is_array($prior) && ($prior['status']??'')==='PROVIDER_UNAVAILABLE')?900:86400;
            if (is_array($prior) && ($prior['version']??'')===AUR_GLOBAL_DISCOVERY_VERSION && $now-(int)($prior['checked_timestamp']??0)<$cooldown) continue;
            $desc=(string)($row['description']??'');
            $query=aur_supplier_global_queries($desc,aur_supplier_match($desc))['Worldwide'];
            $leads=[];$status='SEARCHED_NO_MATCH';
            try {
                $xml=$fetcher ? $fetcher($query) : aur_supplier_public_rss($query);
                $leads=aur_supplier_feed_parse((string)$xml,5,$desc,aur_supplier_match($desc));
                if ($leads) $status='PUBLIC_LEADS_UNVERIFIED';
            } catch (Throwable $e) {
                // Do not fabricate matches when the search service fails.
                $status='PROVIDER_UNAVAILABLE';$fail++;
            }
            $intel['global_discovery']['rows'][$key]=[
                'version'=>AUR_GLOBAL_DISCOVERY_VERSION,'status'=>$status,'query'=>$query,
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
