<?php
declare(strict_types=1);
/**
 * One-way AURENOEVA catalogue snapshot receiver.
 * Existing website HTTPS, no SFTP account, no CORE and no new database.
 * Only canonical public-safe product identities are accepted.
 */
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

function aur_catalogue_clean_snapshot(string $body): array {
    if ($body === '' || strlen($body) > 256000000) throw new RuntimeException('snapshot_size');
    $raw = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($raw) || ($raw['schema'] ?? '') !== 'aurenoeva-catalogue/1'
        || !isset($raw['items']) || !is_array($raw['items'])) {
        throw new RuntimeException('snapshot_schema');
    }
    if (count($raw['items']) > 800000) throw new RuntimeException('snapshot_record_count');
    $items = [];
    $generic = ['surgical carts','surgical cart','products','product','dental units',
        'dental equipment','equipment','medical devices','medical equipment',
        'catalogue','catalog','all products','accessories','solutions'];
    foreach ($raw['items'] as $p) {
        if (!is_array($p)) continue;
        $id = trim((string)($p['canonical_id'] ?? ''));
        $manufacturer = trim((string)($p['manufacturer'] ?? ''));
        $model = trim((string)($p['model'] ?? ''));
        $source = trim((string)($p['source_ref'] ?? ''));
        $url = parse_url($source);
        if (!preg_match('/^AUR-P-[A-F0-9]{20}$/D', $id)
            || $manufacturer === '' || strlen($manufacturer) > 100
            || $model === '' || strlen($model) > 160
            || in_array(strtolower($model), $generic, true)
            || !is_array($url) || strtolower((string)($url['scheme'] ?? '')) !== 'https'
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])) continue;
        $items[$id] = [
            'canonical_id' => $id,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'sector' => in_array((string)($p['sector'] ?? ''), ['laboratory','test','medical','industrial','energy','marine','aviation'], true) ? (string)$p['sector'] : '',
            'category' => substr(trim((string)($p['category'] ?? '')), 0, 160),
            'description' => substr(trim((string)($p['description'] ?? '')), 0, 240),
            'source_ref' => $source,
            'last_seen' => substr(trim((string)($p['last_seen'] ?? '')), 0, 40),
            'lifecycle_status' => 'UNVERIFIED'
        ];
    }
    // A broken or empty upstream catalogue must never erase the last good website snapshot.
    if (!$items) throw new RuntimeException('snapshot_no_products');
    return [json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>array_values($items),
        'availability_notice'=>'Identity evidence only; no stock or price implied.'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), count($items)];
}

function aur_catalogue_write_snapshot(string $body, string $directory): array {
    [$clean, $acceptedInput] = aur_catalogue_clean_snapshot($body);
    $target = $directory . '/catalogue-public.json';
    $lock=fopen($directory.'/.catalogue-merge.lock','c');
    if(!$lock || !flock($lock,LOCK_EX))
        throw new RuntimeException('catalogue_snapshot_write_lock_unavailable');
    try {
        // Decode previously sanitized products only once. The earlier repair
        // decoded, sanitized, encoded, and decoded the entire accumulated
        // snapshot on EVERY small batch, exhausting PHP memory as it grew.
        $newData=json_decode($clean,true,512,JSON_THROW_ON_ERROR);
        $byId=[];
        if(is_file($target)) {
            $old=@file_get_contents($target);
            if(!is_string($old) || $old==='')
                throw new RuntimeException('existing_catalogue_snapshot_unreadable');
            $previous=json_decode($old,true,512,JSON_THROW_ON_ERROR);
            if(($previous['schema']??'')!=='aurenoeva-catalogue/1' ||
               !is_array($previous['items']??null))
                throw new RuntimeException('existing_catalogue_schema_invalid');
            foreach($previous['items'] as $item) {
                if(!is_array($item))continue;
                $id=(string)($item['canonical_id']??'');
                if(!preg_match('/^AUR-P-[A-F0-9]{20}$/D',$id))continue;
                $byId[$id]=$item;
            }
            unset($old,$previous);
        }
        foreach($newData['items'] as $item) {
            $id=$item['canonical_id'];
            if(isset($byId[$id])) {
                foreach(['sector','category'] as $field) {
                    if(($item[$field]??'')==='' &&
                       ($byId[$id][$field]??'')!=='')
                        $item[$field]=$byId[$id][$field];
                }
            }
            $byId[$id]=$item;
        }
        unset($newData);
        $count=count($byId);
        if($count<$acceptedInput)
            throw new RuntimeException('catalogue_merge_lost_input');
        $normalized=json_encode(['schema'=>'aurenoeva-catalogue/1',
             'items'=>array_values($byId),
             'availability_notice'=>'Product identity only; no supplier stock or price is implied.'],
             JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        unset($byId);
        $incoming=tempnam($directory,'.catalogue-incoming-');
        if($incoming===false)throw new RuntimeException('snapshot_temp_unavailable');
        try {
            if(file_put_contents($incoming,$normalized,LOCK_EX)!==strlen($normalized))
                throw new RuntimeException('snapshot_write_failed');
            @chmod($incoming,0600);
            if(!rename($incoming,$target))
                throw new RuntimeException('snapshot_replace_failed');
            $incoming='';
            $digest=hash('sha256',$normalized);
            if(hash_file('sha256',$target)!==$digest)
                throw new RuntimeException('snapshot_readback');
            return ['ok'=>true,'status'=>'PUBLISHED','records'=>$count,
                'accepted_input_records'=>$acceptedInput,'sha256'=>$digest,
                'source_sha256'=>hash('sha256',$body)];
        } finally {
            if($incoming!=='' && is_file($incoming))@unlink($incoming);
        }
    } finally {
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}

/**
 * Enrich the SAME private worldwide vendor master used by RFQ searches.
 * A search result is a lead; a fetched product page is product-identity
 * evidence. Neither is proof of availability or authority to distribute.
 */
function aur_catalogue_merge_vendor_evidence(string $store,string $body):array {
    if(!function_exists('aur_worldwide_master_merge'))
        throw new RuntimeException('supplier_master_missing');
    $raw=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    $aggregate=[];
    $normalHost=static function(mixed $url,mixed $host):string {
        $url=trim((string)$url);
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
           ||isset($parts['user'])||isset($parts['pass']))return '';
        $domain=strtolower((string)($parts['host']??''));
        if(!preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D',$domain)
           ||$domain!==strtolower((string)$host))return '';
        return $domain;
    };
    foreach(array_slice($raw['vendor_leads']??[],0,5000) as $r) {
        if(!is_array($r))continue;
        $url=(string)($r['evidence_url']??'');
        $host=$normalHost($url,$r['website']??'');
        if($host==='')continue;
        $checked=(string)($r['checked']??'')==='SITE_FETCHED';
        $email=trim((string)($r['email']??''));
        $domain=strtolower((string)substr(strrchr($email,'@')?:'',1));
        $rootHost=preg_replace('/^www\\./','',$host);
        if(!$checked || filter_var($email,FILTER_VALIDATE_EMAIL)===false ||
           !($domain===$rootHost || str_ends_with($rootHost,'.'.$domain) ||
             in_array($domain,['gmail.com','outlook.com'],true)))$email='';
        $phone=$checked?substr(trim((string)($r['phone']??'')),0,50):'';
        $status=$email!==''?'SOURCE_CHECKED_EMAIL_PUBLISHED':
            ($checked?'SOURCE_CHECKED_CONTACT_PENDING':'SEARCH_RESULT_NOT_SITE_VERIFIED');
        $aggregate[$host]=[
            'id'=>aur_worldwide_vendor_id($host),'name'=>$host,'website'=>$host,
            'country'=>'Not established','email'=>$email,'phone'=>$phone,'evidence_url'=>$url,
            'status'=>$status,'authorization'=>'NOT_VERIFIED',
            'availability'=>'NOT_VERIFIED','quotation'=>'NOT_RECEIVED',
            'match_basis'=>'CATALOGUE_DISCOVERY_LEAD','equipment_hints'=>[],
            'catalogue_products'=>[]
        ];
        $cat=trim((string)($r['category']??''));
        if($cat!=='')$aggregate[$host]['equipment_hints'][]=substr($cat,0,160);
    }
    foreach(array_slice($raw['vendor_products']??[],0,25000) as $r) {
        if(!is_array($r))continue;
        $url=(string)($r['source_url']??'');
        $host=$normalHost($url,$r['website']??'');
        $id=(string)($r['product_id']??'');
        if($host===''||!preg_match('/^AUR-P-[A-F0-9]{20}$/D',$id))continue;
        if(!isset($aggregate[$host])) {
            $aggregate[$host]=[
                'id'=>aur_worldwide_vendor_id($host),'name'=>$host,'website'=>$host,
                'country'=>'Not established','email'=>'','phone'=>'','evidence_url'=>$url,
                'status'=>'SOURCE_CHECKED_CONTACT_PENDING','authorization'=>'NOT_VERIFIED',
                'availability'=>'NOT_VERIFIED','quotation'=>'NOT_RECEIVED',
                'match_basis'=>'CATALOGUE_PRODUCT_PAGE','equipment_hints'=>[],
                'catalogue_products'=>[]
            ];
        }
        if(($aggregate[$host]['status']??'')!=='SOURCE_CHECKED_EMAIL_PUBLISHED')
            $aggregate[$host]['status']='SOURCE_CHECKED_CONTACT_PENDING';
        $aggregate[$host]['match_basis']='CATALOGUE_PRODUCT_PAGE';
        $aggregate[$host]['evidence_url']=$url;
        $model=substr(trim((string)($r['model']??'')),0,160);
        $maker=substr(trim((string)($r['manufacturer']??'')),0,100);
        $category=substr(trim((string)($r['category']??'')),0,160);
        if($model==='')continue;
        $aggregate[$host]['catalogue_products'][$id]=[
           'product_id'=>$id,'manufacturer'=>$maker,'model'=>$model,
           'category'=>$category,'sector'=>substr((string)($r['sector']??''),0,30),
           'source_url'=>$url,'evidence_type'=>substr((string)($r['evidence_type']??''),0,60),
           'last_seen'=>substr((string)($r['last_seen']??''),0,40)
        ];
        $aggregate[$host]['equipment_hints'][]=trim($maker.' '.$model);
        if($category!=='')$aggregate[$host]['equipment_hints'][]=$category;
    }
    $observations=[];
    foreach($aggregate as $v){
        $v['equipment_hints']=array_values(array_unique($v['equipment_hints']));
        $observations[]=['vendor'=>$v,'description'=>''];
    }
    $added=aur_worldwide_master_merge($store,$observations);
    return ['vendors_seen'=>count($observations),'vendors_added'=>$added,
       'product_links'=>array_sum(array_map(static fn($v):int=>count($v['catalogue_products']),$aggregate))];
}

// Tests exercise real private writes without an HTTP transport.
if (defined('AUR_CATALOGUE_SYNC_LIBRARY_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['POST','GET'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo '{"ok":false,"error":"method"}'; exit;
}
if ((empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    http_response_code(403); echo '{"ok":false,"error":"https_required"}'; exit;
}
// One canonical private path for administrator credentials, procurement
// cases, and catalogue snapshots, regardless of Hostinger release symlinks.
require_once __DIR__ . '/procurement/private_cases.php';
try {
    $private = aur_store_root();
} catch (Throwable $e) {
    http_response_code(503); echo '{"ok":false,"error":"private_storage_unavailable"}'; exit;
}
$tokenFile = $private . '/catalogue-sync-token.sha256';
$expected = is_file($tokenFile) ? trim((string)@file_get_contents($tokenFile)) : '';
$supplied = (string)($_SERVER['HTTP_X_AURENOEVA_SYNC_TOKEN'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/D', $expected)
    || !preg_match('/^[a-f0-9]{64}$/D', $supplied)
    || !hash_equals($expected, hash('sha256', $supplied))) {
    http_response_code(401); echo '{"ok":false,"error":"unauthorized"}'; exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    // Reuse the existing private worldwide vendor master as harvester seeds.
    // Never expose case details, customers, quotes or supplier contact emails.
    try {
        require_once __DIR__ . '/procurement/supplier_worldwide.php';
        $master=aur_worldwide_master_read($private);
        $seeds=[];
        foreach (($master['vendors']??[]) as $v) {
            if(!is_array($v))continue;
            $url=(string)($v['evidence_url']??'');
            if(preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}/~i',$url)){
                $seeds[$url]=['url'=>$url,'manufacturer'=>'','sector'=>'','category'=>'',
                   'source'=>'EXISTING_RFQ_VENDOR_MASTER'];
            }
            foreach (($v['catalogue_products']??[]) as $product) {
                if(!is_array($product))continue;
                $url=(string)($product['source_url']??'');
                if(!preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}/~i',$url))continue;
                $seeds[$url]=['url'=>$url,
                   'manufacturer'=>substr((string)($product['manufacturer']??''),0,100),
                   'sector'=>substr((string)($product['sector']??''),0,30),
                   'category'=>substr((string)($product['category']??''),0,160),
                   'source'=>'EXISTING_RFQ_PRODUCT_EVIDENCE'];
            }
            if(count($seeds)>=10000)break;
        }
        header('Cache-Control: private, no-store');
        echo json_encode(['ok'=>true,'source'=>'EXISTING_RFQ_VENDOR_MASTER',
            'seeds'=>array_slice(array_values($seeds),0,10000)],
            JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){
        error_log('AURENOEVA RFQ seeds unavailable: '.substr($e->getMessage(),0,120));
        http_response_code(503);echo '{"ok":false,"error":"vendor_seed_unavailable"}';
    }
    exit;
}
if (!is_dir($private) || !is_writable($private)) {
    http_response_code(503); echo '{"ok":false,"error":"private_storage_unavailable"}'; exit;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000000) {
    http_response_code(413); echo '{"ok":false,"error":"snapshot_too_large"}'; exit;
}
try {
    $body = file_get_contents('php://input', false, null, 0, 24000001);
    $mode=(string)($_GET['mode']??'products');
    if($mode==='vendors') {
        // Transfer vendor evidence separately, in small authenticated batches.
        // The product catalogue stays searchable even when vendor writes retry.
        $evidence=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);
        if(($evidence['schema']??'')!=='aurenoeva-vendor-evidence/1'
           || !is_array($evidence['vendor_products']??null)
           || !is_array($evidence['vendor_leads']??null)
           || count($evidence['vendor_products'])+count($evidence['vendor_leads'])>2500)
            throw new RuntimeException('invalid_vendor_evidence_batch');
        require_once __DIR__ . '/procurement/supplier_worldwide.php';
        $result=['ok'=>true,'status'=>'MERGED',
            'vendor_intelligence'=>aur_catalogue_merge_vendor_evidence($private,(string)$body),
            'source_sha256'=>hash('sha256',(string)$body)];
    }elseif($mode==='products') {
        $result = aur_catalogue_write_snapshot((string)$body, $private);
        $result['vendor_sync']='NOT_INCLUDED';
    }else throw new RuntimeException('unsupported_sync_mode');
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('AURENOEVA catalogue sync failure: ' . substr($e->getMessage(), 0, 100));
    http_response_code(422);
    echo '{"ok":false,"error":"snapshot_rejected"}';
}
