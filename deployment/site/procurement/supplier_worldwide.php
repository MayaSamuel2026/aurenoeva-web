<?php
declare(strict_types=1);
/**
 * Case-owned worldwide supplier research. Click-driven, incremental, free.
 * Source verification = page fetched + requested equipment mentioned.
 * Does not certify stock, legal entity, commercial terms or export clearance.
 */
const AUR_WORLDWIDE_VERSION='2026-10-21.6';

function aur_worldwide_safe_ip(string $ip): bool {
    return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
}
function aur_worldwide_fetch(string $url): string {
    // Never allow crawler search results to cause requests to local/internal IPs.
    $parts=parse_url($url);
    if (!is_array($parts) || ($parts['scheme']??'')!=='https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || strlen($url)>1200) throw new RuntimeException('invalid_evidence_url');
    $host=strtolower((string)($parts['host']??''));
    if (!preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D',$host)) throw new RuntimeException('invalid_evidence_host');
    $ips=@gethostbynamel($host);
    $ip=null;
    if (is_array($ips)) foreach ($ips as $candidate) {
        if (aur_worldwide_safe_ip($candidate)) {$ip=$candidate;break;}
    }
    if ($ip===null || !function_exists('curl_init')) throw new RuntimeException('public_dns_or_http_unavailable');
    $c=curl_init($url);$body='';
    if ($c===false) throw new RuntimeException('http_unavailable');
    curl_setopt_array($c,[
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>7,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'AURENOEVA-Procurement/1.0 (+https://aurenoeva.com)',
        CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],
        CURLOPT_RESOLVE=>[$host.':443:'.$ip],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$body):int {
            if(strlen($body)+strlen($chunk)>230000) return 0;
            $body.=$chunk;return strlen($chunk);
        }
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($c,CURLOPT_PROTOCOLS_STR,'https');
    elseif (defined('CURLPROTO_HTTPS')) curl_setopt($c,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);
    $ok=curl_exec($c);
    $code=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);
    $type=(string)curl_getinfo($c,CURLINFO_CONTENT_TYPE);
    curl_close($c);
    if($ok===false || $code!==200 || !str_contains(strtolower($type),'html') || $body==='') throw new RuntimeException('evidence_page_unavailable');
    return $body;
}
function aur_worldwide_vendor_id(string $website): string {
    return 'web-'.substr(hash('sha256',strtolower($website)),0,16);
}
function aur_worldwide_contact(string $html,string $url): array {
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    $body=html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8');
    preg_match_all('/mailto:([A-Z0-9._%+\-]+@[A-Z0-9.-]+\.[A-Z]{2,})/iu',$body,$mailtos);
    preg_match_all('/(?<![A-Z0-9._%+\-])[A-Z0-9._%+\-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?![A-Z0-9._%+\-])/iu',strip_tags($body),$inline);
    $emails=array_merge($mailtos[1]??[],$inline[0]??[]);
    $email='';
    foreach(array_unique($emails) as $candidate) {
        $candidate=trim($candidate);
        $domain=strtolower((string)substr(strrchr($candidate,'@')?:'','1'));
        if(filter_var($candidate,FILTER_VALIDATE_EMAIL)===false
           || preg_match('/(?:example|invalid|test|noreply|no-reply|support\@wordpress)/i',$candidate)) continue;
        $h=preg_replace('/^www\./','',$host);
        // Trusted website email only; Gmail is allowed when explicitly published on that website,
        // but an arbitrary email embedded in a search snippet never qualifies.
        if($domain===$h || str_ends_with($h,'.'.$domain) || in_array($domain,['gmail.com','outlook.com'],true)) {
            $email=$candidate;break;
        }
    }
    $phone='';
    if(preg_match('/(?:tel:|phone(?: number)?\s*[: ]|\btelephone\s*[: ]|contact\s*[: ])\s*(\+?[\d\s().\-]{8,22})/iu',$body,$p)) {
        $phone=trim(preg_replace('/[^\d+\s().-]/','',$p[1])??'');
    }
    return ['email'=>$email,'phone'=>$phone];
}
function aur_worldwide_source_candidate(string $description, ?array $match, array $searchResult, string $html, string $url): ?array {
    $title=trim(html_entity_decode(strip_tags((string)($searchResult['page_title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $visible=trim(preg_replace('/\s+/u',' ',strip_tags(preg_replace('~<(script|style|svg)\b[^>]*>.*?</\1>~is',' ', $html)??$html))??'');
    // Check retrieved page rather than treating search result snippets as evidence.
    $evidence=aur_supplier_relevance($description,$match,['page_title'=>$title,'snippet'=>substr($visible,0,15000)]);
    if ($evidence===null) return null;
    $domain=strtolower((string)parse_url($url,PHP_URL_HOST));
    $contacts=aur_worldwide_contact($html,$url);
    $name=$title;
    $name=preg_split('/\s+[|\-–]\s+/u',$name,2)[0]??$name;
    if(strlen($name)<3 || strlen($name)>85) $name=preg_replace('/^www\./','',$domain);
    return [
        'id'=>aur_worldwide_vendor_id($domain),
        'name'=>$name,'website'=>$domain,'country'=>'Not established',
        'email'=>$contacts['email'],'phone'=>$contacts['phone'],
        'evidence_url'=>$url,'match_basis'=>$evidence,
        'evidence_checked_at'=>gmdate('c'),
        'status'=>$contacts['email']!==''?'SOURCE_CHECKED_EMAIL_PUBLISHED':'SOURCE_CHECKED_CONTACT_PENDING',
        'authorization'=>'NOT_VERIFIED','availability'=>'NOT_VERIFIED',
        'quotation'=>'NOT_RECEIVED'
    ];
}
function aur_worldwide_seed_candidates(string $description):array {
    $catalogue=aur_supplier_route_directory();$out=[];
    foreach(aur_supplier_contact_routes($description) as $route) {
        if(!isset($catalogue[$route['id']])) continue;
        $v=$catalogue[$route['id']];
        $out[]=[
            'id'=>'seed-'.$route['id'],'name'=>$v['name'],
            'website'=>(string)parse_url($v['url'],PHP_URL_HOST),
            'country'=>$v['country'],
            'email'=>$v['email'],'phone'=>$v['phone'],
            'evidence_url'=>($route['grade']==='EXACT_PRODUCT_LISTED' && isset($v['exact_url']))?$v['exact_url']:$v['url'],
            'match_basis'=>$route['grade'],
            'status'=>'PUBLISHED_CONTACT_RESEARCH_CANDIDATE',
            'authorization'=>'NOT_VERIFIED','availability'=>'NOT_VERIFIED','quotation'=>'NOT_RECEIVED',
        ];
    }
    return $out;
}
function aur_worldwide_case_candidates(array $intel,string $rowKey,string $description):array {
    $known=aur_worldwide_seed_candidates($description);
    $latest=$intel['worldwide_research']['rows'][$rowKey]??null;
    if(is_array($latest) && ($latest['version']??'')===AUR_WORLDWIDE_VERSION) {
        foreach(($latest['vendors']??[]) as $v) $known[]=$v;
    }
    $result=[];$seen=[];
    foreach($known as $vendor) {
        $key=strtolower(trim((string)($vendor['email']??''))).'|'.strtolower((string)($vendor['website']??''));
        if(isset($seen[$key])) continue;
        $seen[$key]=true;$result[]=$vendor;
    }
    return $result;
}
function aur_worldwide_stats(array $intel):array {
    $rows=$intel['rows']??[];$scanned=0;$contactable=0;$new=0;$names=[];
    foreach($rows as $row) {
        $key=(string)($row['source_row']??'');
        $r=$intel['worldwide_research']['rows'][$key]??null;
        if(is_array($r)&&($r['version']??'')===AUR_WORLDWIDE_VERSION) $scanned++;
        foreach(aur_worldwide_case_candidates($intel,$key,(string)($row['description']??'')) as $v) {
            if(!empty($v['email'])){$contactable++;$names[$v['id']]=1;}
            if(str_starts_with((string)$v['id'],'web-'))$new++;
        }
    }
    return ['scanned'=>$scanned,'total'=>count($rows),'contactable_routes'=>$contactable,
            'unique_vendors'=>count($names),'new_source_pages'=>$new];
}
function aur_worldwide_scan_batch(string $store,array $case,int $limit=1,?callable $search=null,?callable $fetch=null):array {
    if($limit<1 || $limit>2)throw new InvalidArgumentException('scan_batch_limit');
    aur_supplier_save($store,$case);
    $target=aur_supplier_private_file($store,(string)$case['reference']);
    $handle=fopen(dirname($target).'/.research.lock','c');
    if(!$handle || !flock($handle,LOCK_EX))throw new RuntimeException('catalogue_lock_unavailable');
    try{
        $intel=aur_supplier_read($store,(string)$case['reference'],(string)$case['source_sha256']);
        if(!is_array($intel))throw new RuntimeException('catalogue_case_unavailable');
        if(!isset($intel['worldwide_research']))$intel['worldwide_research']=['rows'=>[]];
        $processed=0;$found=0;$failed=0;$now=time();
        foreach($intel['rows'] as $row) {
            $key=(string)($row['source_row']??'');$prior=$intel['worldwide_research']['rows'][$key]??null;
            if(is_array($prior)&&($prior['version']??'')===AUR_WORLDWIDE_VERSION
              && $now-(int)($prior['checked_timestamp']??0)<(($prior['status']??'')==='SEARCH_UNAVAILABLE'?900:86400))continue;
            $desc=(string)($row['description']??'');$match=aur_supplier_match($desc);
            // Research worldwide + rotate one region on recurring scans.
            $queries=aur_supplier_global_queries($desc,$match);
            $region=['Europe','Asia-Pacific','Middle East','North America'][($now / 86400 | 0)%4];
            $foundVendors=[];$providerOk=false;
            foreach(['Worldwide',$region] as $scope) {
                $query=$queries[$scope];
                try {
                    $xml=$search ? $search($query) : aur_supplier_public_rss($query);
                    $results=aur_supplier_feed_parse((string)$xml,8,$desc,$match);
                    $providerOk=true;
                } catch(Throwable $e) {
                    continue;
                }
                foreach(array_slice($results,0,3) as $candidate) {
                    $url=(string)$candidate['source_url'];
                    $domain=(string)parse_url($url,PHP_URL_HOST);
                    if(isset($foundVendors[$domain]))continue;
                    try {
                        $html=$fetch ? $fetch($url) : aur_worldwide_fetch($url);
                        $v=aur_worldwide_source_candidate($desc,$match,$candidate,(string)$html,$url);
                        if($v!==null)$foundVendors[$domain]=$v;
                    }catch(Throwable $e) {continue;}
                }
            }
            $vendors=array_values($foundVendors);
            $intel['worldwide_research']['rows'][$key]=[
                'version'=>AUR_WORLDWIDE_VERSION,'checked_at'=>gmdate('c'),
                'checked_timestamp'=>$now,'status'=>$providerOk?'SEARCHED':'SEARCH_UNAVAILABLE',
                'vendors'=>$vendors,
            ];
            if(!$providerOk)$failed++;
            $found+=count($vendors);$processed++;
            if($processed>=$limit)break;
        }
        if($processed>0) {
            $json=json_encode($intel,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $tmp=dirname($target).'/.worldwide-'.bin2hex(random_bytes(6));
            if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('worldwide_write_failed');
            @chmod($tmp,0600);
            if(!rename($tmp,$target))throw new RuntimeException('worldwide_commit_failed');
        }
        return ['processed'=>$processed,'new_vendor_pages'=>$found,'provider_failures'=>$failed,
                'stats'=>aur_worldwide_stats($intel)];
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}
