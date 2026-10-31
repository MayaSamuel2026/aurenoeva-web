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
// Read-only version evidence for the repaired batch publisher preflight.
header('X-Aurenoeva-Catalogue-UTF8: safe-cut-v1');
header('X-Aurenoeva-Catalogue-Bulk: atomic-snapshot-v1');

/** Keep length limits in bytes without cutting through a UTF-8 code point. */
function aur_catalogue_utf8_cut(string $value, int $maxBytes): string {
    $value = trim($value);
    if (strlen($value) <= $maxBytes) return $value;
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
    // The JSON parser has already validated UTF-8. At most three trailing
    // bytes need removal to restore a complete Unicode character.
    $slice = substr($value, 0, $maxBytes);
    while ($slice !== '' && preg_match('//u', $slice) !== 1) {
        $slice = substr($slice, 0, -1);
    }
    return $slice;
}

/** Whitelist public prices; never accept stock, quotes, contact data or margins. */
function aur_catalogue_public_offers(array $item): array {
    $valid = [];
    $allowed = ['EUR','USD','GBP','CHF','CAD','AUD','JPY','CNY','SEK','NOK','DKK',
        'PLN','CZK','HUF','INR','SGD','NZD','AED','SAR','ZAR','BRL','MXN','KRW','TRY','HKD','TWD'];
    foreach (array_slice(is_array($item['public_offers'] ?? null) ? $item['public_offers'] : [], 0, 30) as $offer) {
        if (!is_array($offer)) continue;
        $url = (string)($offer['source_url'] ?? '');
        $parts = @parse_url($url);
        $seller = strtolower((string)($offer['seller_website'] ?? ''));
        $currency = strtoupper((string)($offer['currency'] ?? ''));
        $amount = $offer['amount'] ?? null;
        $when = (string)($offer['observed_at'] ?? '');
        $timestamp = strtotime($when);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || strtolower((string)$parts['host']) !== $seller
            || isset($parts['user']) || isset($parts['pass'])
            || strlen($url) > 1400 || !in_array($currency, $allowed, true)
            || !is_numeric($amount) || !is_finite((float)$amount)
            || (float)$amount <= 0 || (float)$amount > 100000000
            || $timestamp === false || $timestamp < time()-14*86400
            || $timestamp > time()+300) continue;
        $key = $seller.'|'.$url.'|'.$currency;
        if (isset($valid[$key])) continue;
        $valid[$key] = [
            'seller_website' => $seller,
            'seller_name' => aur_catalogue_utf8_cut((string)($offer['seller_name'] ?? $seller), 120),
            'source_url' => $url, 'currency' => $currency,
            'amount' => (float)$amount, 'observed_at' => $when
        ];
        if (count($valid) >= 5) break;
    }
    return array_values($valid);
}

/**
 * Preserve only independently reproducible technical facets. A crawler search
 * category is not evidence; every facet must cite text on this product record
 * and exactly the product's HTTPS source page. No client/vendor data is copied.
 */
function aur_catalogue_classifications(array $part,string $source):array {
    $valid=[];
    $allowed=[
        'laboratory'=>['Mass spectrometry','Chromatography / HPLC / GC','Spectroscopy','Sample preparation','Balances & weighing','Incubation / ovens / environmental','General laboratory equipment'],
        'test'=>['Oscilloscopes','Spectrum / signal analyzers','Signal sources / generators','Meters & power analyzers','RF / microwave','Calibration equipment','Data acquisition'],
        'medical'=>['Diagnostic equipment','Imaging & visualization','Patient monitoring','Laboratory / pathology','Surgical / procedural','Sterilization & reprocessing','Other medical equipment'],
        'industrial'=>['PLC / control modules','Drives / servo / motion','Motors / gearboxes','Sensors / instrumentation','Pneumatics / hydraulics','Robotics / automation','Machine / process equipment'],
        'energy'=>['Transformers','Switchgear & protection','Generators','Power conversion / inverters','Power quality / monitoring','Turbine / plant components','Storage / auxiliary systems'],
        'marine'=>['Engines & propulsion','Pumps / fluid systems','Electrical & switchgear','Navigation / instrumentation','Deck machinery','HVAC / refrigeration','Safety / auxiliary systems'],
        'aviation'=>['Avionics','Electrical components','Hydraulic / pneumatic','Engine / powerplant components','Landing gear / actuation','Ground support / test equipment','Traceable specialist components'],
    ];
    $legacy=(string)($part['sector']??'');
    $raw=is_array($part['classifications']??null)?$part['classifications']:[];
    foreach(array_slice($raw,0,7) as $entry){
        if(!is_array($entry))continue;
        $industry=(string)($entry['industry']??'');
        $category=(string)($entry['category']??'');
        $field=(string)($entry['evidence_field']??'');
        $term=trim((string)($entry['evidence_term']??''));
        $context=trim((string)($entry['context_term']??''));
        if(!isset($allowed[$industry]) || isset($valid[$industry]) ||
           !in_array($category,$allowed[$industry],true) ||
           ($entry['method']??'')!=='technical-facets/1' ||
           ($entry['evidence_url']??'')!==$source ||
           !in_array($field,['model','description'],true) ||
           $term==='' || strlen($term)>75 || strlen($context)>75 ||
           stripos((string)($part[$field]??''),$term)===false ||
           ($legacy!==$industry && ($context==='' ||
            stripos((string)($part['model']??'').' '.(string)($part['description']??''),$context)===false)))
            continue;
        $valid[$industry]=[
            'industry'=>$industry,'category'=>$category,
            'evidence_url'=>$source,'evidence_field'=>$field,
            'evidence_term'=>$term,'context_term'=>$context,
            'method'=>'technical-facets/1','confidence'=>'MATCHED_TEXT'
        ];
    }
    return array_values($valid);
}

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
        $publicOffers = aur_catalogue_public_offers($p);
        $facets = aur_catalogue_classifications($p,$source);
        $items[$id] = [
            'canonical_id' => $id,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'sector' => in_array((string)($p['sector'] ?? ''), ['laboratory','test','medical','industrial','energy','marine','aviation'], true) ? (string)$p['sector'] : '',
            'category' => aur_catalogue_utf8_cut((string)($p['category'] ?? ''), 160),
            'description' => aur_catalogue_utf8_cut((string)($p['description'] ?? ''), 240),
            'source_ref' => $source,
            'last_seen' => aur_catalogue_utf8_cut((string)($p['last_seen'] ?? ''), 40),
            'lifecycle_status' => 'UNVERIFIED',
            'price_status' => $publicOffers ? 'PUBLIC_OFFER' : 'ON_REQUEST',
            'classifications' => $facets,
            'industries' => array_column($facets,'industry'),
            'classification_status' => $facets ? 'EVIDENCE_MATCHED' : 'UNCLASSIFIED',
            'public_offers' => $publicOffers
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
            // Record actual publication history after verified snapshot readback.
            try { require_once __DIR__.'/procurement/commercial_intelligence.php';
                  aur_ci_snapshot_observed($directory,$count,$digest); }
            catch(Throwable $e){error_log('AURENOEVA catalogue history: '.get_class($e));}
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


/**
 * Fast snapshot upload: a few HTTPS chunks, one verified atomic replacement.
 * No full-catalogue merge on every chunk; no stock or supplier checks.
 * Kept in the existing authenticated receiver and existing private directory.
 */
function aur_bulk_path(string $directory, string $sha): string {
    if (!preg_match('/^[a-f0-9]{64}$/D', $sha))
        throw new RuntimeException('bulk_invalid_digest');
    return $directory . '/.catalogue-upload-' . $sha . '.part';
}
function aur_bulk_metadata(string $body): array {
    $m=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($m))throw new RuntimeException('bulk_invalid_metadata');
    $sha=(string)($m['sha256']??'');
    $bytes=$m['bytes']??null;
    $count=$m['count']??null;
    if(!preg_match('/^[a-f0-9]{64}$/D',$sha)
       ||!is_int($bytes)||$bytes<100||$bytes>256000000
       ||!is_int($count)||$count<1||$count>800000)
        throw new RuntimeException('bulk_invalid_metadata');
    return [$sha,$bytes,$count];
}
function aur_bulk_begin(string $directory,string $sha,int $bytes):array {
    $file=aur_bulk_path($directory,$sha);
    $fp=@fopen($file,'c+b');
    if(!$fp)throw new RuntimeException('bulk_write_failed');
    try {
        if(!flock($fp,LOCK_EX)||!ftruncate($fp,0)||!fflush($fp))
            throw new RuntimeException('bulk_write_failed');
        @chmod($file,0600);
    }finally {flock($fp,LOCK_UN);fclose($fp);}
    return ['ok'=>true,'status'=>'READY','offset'=>0,'sha256'=>$sha];
}
function aur_bulk_chunk(string $directory,string $sha,int $offset,string $body):array {
    $file=aur_bulk_path($directory,$sha);
    if($offset<0||strlen($body)<1||strlen($body)>1048576)
        throw new RuntimeException('bulk_invalid_chunk');
    $fp=@fopen($file,'r+b');
    if(!$fp)throw new RuntimeException('bulk_upload_not_started');
    try {
        if(!flock($fp,LOCK_EX))throw new RuntimeException('bulk_write_failed');
        $st=fstat($fp);
        $at=(int)($st['size']??-1);
        $length=strlen($body);
        if($at===$offset){
            if(fseek($fp,0,SEEK_END)!==0||fwrite($fp,$body)!==$length||!fflush($fp))
                throw new RuntimeException('bulk_write_failed');
            $at+=$length;
        }elseif($at===$offset+$length) {
            // A request timed out after being stored; retry it without duplication.
            if(fseek($fp,$offset)!==0||fread($fp,$length)!==$body)
                throw new RuntimeException('bulk_offset_mismatch');
        }else throw new RuntimeException('bulk_offset_mismatch');
        return ['ok'=>true,'status'=>'RECEIVED','offset'=>$at,'sha256'=>$sha];
    }finally {flock($fp,LOCK_UN);fclose($fp);}
}
function aur_bulk_count(string $file):int {
    $fp=@fopen($file,'rb');
    if(!$fp)throw new RuntimeException('bulk_snapshot_unreadable');
    $count=0;$carry='';$read=0;
    try {
        while(!feof($fp)){
            $chunk=fread($fp,65536);
            if($chunk===false)throw new RuntimeException('bulk_snapshot_unreadable');
            $buffer=$carry.$chunk;
            preg_match_all('/"canonical_id"\s*:\s*"AUR-P-[A-F0-9]{20}"/',
                $buffer,$found,PREG_OFFSET_CAPTURE);
            foreach($found[0] as $match){
                if($read-strlen($carry)+$match[1]+strlen($match[0])>$read)
                    ++$count;
            }
            $read+=strlen($chunk);
            $carry=substr($buffer,-128);
        }
    }finally{fclose($fp);}
    return $count;
}
/** Read only authenticated aggregate reconciliation from the validated atomic
 * snapshot tail. No full product-list decode or exposure of supplier data.
 */
function aur_bulk_public_stats(string $file):?array {
    $bytes=@filesize($file);
    if(!is_int($bytes)||$bytes<40)return null;
    $fp=@fopen($file,'rb');
    if(!$fp)return null;
    try{
        if(fseek($fp,max(0,$bytes-4096),SEEK_SET)!==0)return null;
        $tail=stream_get_contents($fp,4096);
    }finally{fclose($fp);}
    if(!is_string($tail)||!preg_match('/"public_stats"\\s*:\\s*(\\{[^{}]{1,3000}\\})\\s*\\}\\s*$/s',$tail,$match))
        return null;
    $stats=json_decode($match[1],true);
    return is_array($stats)?$stats:null;
}

/** Permit a bounded, *explained* reduction in public search inventory only
 * when the canonical DB is unchanged and source-backed nontechnical
 * exclusions have risen by exactly the same number. Otherwise fail closed.
 * Historical canonical IDs and customer RFQs remain untouched on the VPS.
 */
function aur_bulk_filter_only_reduction(
    string $current,string $incoming,int $oldCount,int $newCount
):bool {
    $before=aur_bulk_public_stats($current);
    $after=aur_bulk_public_stats($incoming);
    if($before===null||$after===null||$newCount>=$oldCount)return false;
    $base=['db_products','exported_products','eligible_products','excluded_products',
        'excluded_withdrawn','excluded_invalid_id','excluded_generic',
        'excluded_missing_maker','excluded_missing_model','excluded_source',
        'excluded_duplicates','excluded_nonrecord'];
    foreach([$before,$after] as $stats)foreach($base as $key) {
        if(!is_int($stats[$key]??null)||$stats[$key]<0)return false;
    }
    if(!is_int($after['excluded_nontechnical']??null)
        ||$after['excluded_nontechnical']<0)return false;
    // Canonical product growth may occur between two successive snapshots.
    // A filtered drop is explainable when the new excluded nontechnical
    // identities account for BOTH that growth and the visible count drop.
    if($after['db_products']<$before['db_products']
        ||$after['exported_products']<$before['exported_products']
        ||$before['db_products']!==$before['exported_products']
        ||$after['db_products']!==$after['exported_products']
        ||$before['eligible_products']!==$oldCount
        ||$after['eligible_products']!==$newCount
        ||$before['excluded_products']!==$before['exported_products']-$oldCount
        ||$after['excluded_products']!==$after['exported_products']-$newCount)
        return false;
    $other=['excluded_withdrawn','excluded_invalid_id','excluded_generic',
            'excluded_missing_maker','excluded_missing_model','excluded_source',
            'excluded_duplicates','excluded_nonrecord'];
    $totals=0;
    foreach($other as $key) {
        if($before[$key]!==$after[$key])return false;
        $totals+=$after[$key];
    }
    $oldNontechnical=$before['excluded_products']-$totals;
    $newNontechnical=$after['excluded_products']-$totals;
    $delta=$oldCount-$newCount;
    $canonicalGrowth=$after['db_products']-$before['db_products'];
    return $oldNontechnical>=0
        && $newNontechnical===$after['excluded_nontechnical']
        && $newNontechnical-$oldNontechnical===$delta+$canonicalGrowth
        && $delta<=max(500,(int)floor($oldCount*0.05));
}

function aur_bulk_commit(string $directory,string $sha,int $bytes,int $count):array {
    $file=aur_bulk_path($directory,$sha);
    $target=$directory.'/catalogue-public.json';
    $lock=@fopen($directory.'/.catalogue-merge.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('bulk_write_failed');
    try {
        // A duplicate commit following a lost HTTP response is also a success.
        if(!is_file($file)&&is_file($target)
           &&hash_file('sha256',$target)===$sha
           &&aur_bulk_count($target)===$count)
            return ['ok'=>true,'status'=>'PUBLISHED','records'=>$count,'sha256'=>$sha];
        if(!is_file($file)||filesize($file)!==$bytes
           ||hash_file('sha256',$file)!==$sha)
            throw new RuntimeException('bulk_digest_mismatch');
        $fp=@fopen($file,'rb');
        if(!$fp)throw new RuntimeException('bulk_snapshot_unreadable');
        $prefix=fread($fp,110);
        fclose($fp);
        if(!is_string($prefix)||
           !str_starts_with($prefix,'{"schema":"aurenoeva-catalogue/1","items":['))
            throw new RuntimeException('bulk_snapshot_schema');
        if(aur_bulk_count($file)!==$count)
            throw new RuntimeException('bulk_count_mismatch');
        if(is_file($target)){
            $oldCount=aur_bulk_count($target);
            if($oldCount>$count){
                // Full authoritative snapshots may SHRINK only when the crawler
                // reports evidence-backed withdrawal and the drop stays bounded.
                // A partial/malformed upload cannot silently erase the catalogue.
                $fp=@fopen($file,'rb');
                if(!$fp)throw new RuntimeException('bulk_snapshot_unreadable');
                $tailLength=min($bytes,4096);
                fseek($fp,-$tailLength,SEEK_END);
                $tail=(string)fread($fp,$tailLength);
                fclose($fp);
                $confirmed=0;
                if(preg_match('/"catalogue_health":\\{"lifecycle_version":1,([^}]{0,3000})\\}/',$tail,$match)
                   &&preg_match('/"confirmed_withdrawn_products":([0-9]+)/',$match[1],$amount))
                    $confirmed=(int)$amount[1];
                $drop=$oldCount-$count;
                $bounded=$drop<=max(500,(int)floor($oldCount*0.05));
                $filtered=$bounded && aur_bulk_filter_only_reduction(
                    $target,$file,$oldCount,$count);
                if(!$bounded || ($confirmed<$drop && !$filtered))
                    throw new RuntimeException('bulk_count_regression');
            }
        }
        @chmod($file,0600);
        if(!@rename($file,$target))
            throw new RuntimeException('bulk_write_failed');
        clearstatcache(true,$target);
        try { require_once __DIR__.'/procurement/commercial_intelligence.php';
              aur_ci_snapshot_observed($directory,$count,$sha); }
        catch(Throwable $e){error_log('AURENOEVA catalogue history: '.get_class($e));}
        return ['ok'=>true,'status'=>'PUBLISHED','records'=>$count,'sha256'=>$sha];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
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
    if($mode==='bulk-begin') {
        [$sha,$bytes,$count]=aur_bulk_metadata((string)$body);
        $result=aur_bulk_begin($private,$sha,$bytes);
    }elseif($mode==='bulk-chunk') {
        $sha=(string)($_SERVER['HTTP_X_AURENOEVA_UPLOAD_SHA256']??'');
        $offsetRaw=(string)($_SERVER['HTTP_X_AURENOEVA_UPLOAD_OFFSET']??'');
        if(!preg_match('/^(0|[1-9][0-9]{0,8})$/D',$offsetRaw))
            throw new RuntimeException('bulk_invalid_chunk');
        $result=aur_bulk_chunk($private,$sha,(int)$offsetRaw,(string)$body);
    }elseif($mode==='bulk-commit') {
        [$sha,$bytes,$count]=aur_bulk_metadata((string)$body);
        $result=aur_bulk_commit($private,$sha,$bytes,$count);
    }elseif($mode==='vendors') {
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
    // This endpoint is sync-token authenticated. Report only a bounded,
    // predefined diagnostic code; never expose filesystem paths or record data.
    $knownReasons = [
        'snapshot_size', 'snapshot_schema', 'snapshot_record_count',
        'snapshot_no_products', 'catalogue_snapshot_write_lock_unavailable',
        'existing_catalogue_snapshot_unreadable', 'existing_catalogue_schema_invalid',
        'catalogue_merge_lost_input', 'snapshot_temp_unavailable',
        'snapshot_write_failed', 'snapshot_replace_failed',
        'snapshot_readback', 'invalid_vendor_evidence_batch',
        'bulk_invalid_digest','bulk_invalid_metadata','bulk_invalid_chunk',
        'bulk_upload_not_started','bulk_offset_mismatch','bulk_write_failed',
        'bulk_snapshot_unreadable','bulk_digest_mismatch',
        'bulk_snapshot_schema','bulk_count_mismatch','bulk_count_regression',
        'unsupported_sync_mode'
    ];
    $reason = $e->getMessage();
    $code = in_array($reason, $knownReasons, true)
        ? $reason : 'unexpected_'.get_class($e);
    $temporary = in_array($code, [
        'catalogue_snapshot_write_lock_unavailable',
        'snapshot_temp_unavailable', 'snapshot_write_failed',
        'snapshot_replace_failed', 'snapshot_readback'
    ], true);
    error_log('AURENOEVA catalogue sync failure: '.substr($e->getMessage(),0,120));
    http_response_code($temporary ? 503 : 422);
    echo json_encode(['ok'=>false,'error'=>'snapshot_rejected',
        'reason_code'=>substr($code,0,90)], JSON_THROW_ON_ERROR);
}
