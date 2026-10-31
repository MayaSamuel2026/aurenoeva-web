<?php
declare(strict_types=1);
define('AUR_CATALOGUE_SYNC_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/catalogue-sync.php';
require dirname(__DIR__, 2) . '/v8.2.6.10/catalogue_local.php';

$root = sys_get_temp_dir() . '/aur-catalogue-sync-' . bin2hex(random_bytes(6));
$web = $root . '/public_html';
$private = $root . '/.aurenoeva-private';
mkdir($web, 0700, true);
mkdir($private, 0700, true);
$_SERVER['DOCUMENT_ROOT'] = $web;
putenv('AURENOEVA_CATALOGUE_SNAPSHOT=');
$example = ['schema'=>'aurenoeva-catalogue/1','items'=>[
    ['canonical_id'=>'AUR-P-'.str_repeat('A',20),'manufacturer'=>'Mindray',
        'model'=>'WATO EX-35', 'description'=>'Anaesthesia system',
        'source_ref'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35',
        'last_seen'=>gmdate('c'),'private_cost'=>'MUST_NOT_EXPOSE'],
    ['canonical_id'=>'AUR-P-'.str_repeat('B',20),'manufacturer'=>'Anthos',
        'model'=>'Surgical carts','source_ref'=>'https://www.anthos.it/en/'],
]];
try {
    $response = aur_catalogue_write_snapshot(json_encode($example), $private);
    if (($response['records'] ?? 0) !== 1
        || ($response['accepted_input_records'] ?? 0) !== 1
        || ($response['source_sha256'] ?? '') !== hash('sha256',json_encode($example)))
        throw new RuntimeException('sync_snapshot_acknowledgement');
    $saved = file_get_contents($private . '/catalogue-public.json');
    if (str_contains($saved,'MUST_NOT_EXPOSE') || str_contains($saved,'Surgical carts'))
        throw new RuntimeException('private_data_or_generic_product_exported');
    $found = aur_catalogue_snapshot_search('WATO EX-35');
    if (!is_array($found) || count($found) !== 1 || $found[0]['model'] !== 'WATO EX-35')
        throw new RuntimeException('website_search_readback_failed');
    // WP02: permit only fresh, source-attributed seller prices.
    $offerExample = $example;
    $offerExample['items'] = [$example['items'][0]];
    $offerExample['items'][0]['public_offers'] = [
        ['seller_website'=>'www.mindray.com', 'seller_name'=>'Mindray Official',
         'source_url'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35',
         'currency'=>'EUR','amount'=>1200.50,'observed_at'=>gmdate('c'),
         'private_margin'=>777],
        ['seller_website'=>'fraud.example.net','seller_name'=>'Spoof',
         'source_url'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35',
         'currency'=>'EUR','amount'=>1,'observed_at'=>gmdate('c')],
    ];
    $offerExample['items'][0]['sector']='medical';
    $offerExample['items'][0]['classifications']=[[
        'industry'=>'medical','category'=>'Surgical / procedural',
        'method'=>'technical-facets/1',
        'evidence_url'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35',
        'evidence_field'=>'description','evidence_term'=>'Anaesthesia',
        'context_term'=>'','confidence'=>'MATCHED_TEXT']];
    $offerExample['items'][0]['classifications'][]=[
        'industry'=>'aviation','category'=>'Avionics',
        'method'=>'technical-facets/1',
        'evidence_url'=>'https://evil.example.com/not-the-product',
        'evidence_field'=>'description','evidence_term'=>'Anaesthesia',
        'context_term'=>'aviation','confidence'=>'MATCHED_TEXT'];
    [$priceClean,$priceCount] = aur_catalogue_clean_snapshot(json_encode($offerExample, JSON_THROW_ON_ERROR));
    $publicItem = json_decode($priceClean,true,512,JSON_THROW_ON_ERROR)['items'][0];
    if (count($publicItem['classifications'])!==1 ||
        $publicItem['industries']!==['medical'] ||
        $publicItem['classifications'][0]['category']!=='Surgical / procedural')
        throw new RuntimeException('classification_source_sanitization_failed');
    if ($priceCount!==1 || $publicItem['price_status']!=='PUBLIC_OFFER'
        || count($publicItem['public_offers'])!==1
        || $publicItem['public_offers'][0]['amount']!==1200.5
        || str_contains($priceClean,'private_margin') || str_contains($priceClean,'fraud.example.net'))
        throw new RuntimeException('public_price_source_sanitization_failed');
    // Regression: byte-limited fields containing international names must
    // never split UTF-8 sequences into malformed JSON (live HTTP 422).
    $utf8Input = ['schema'=>'aurenoeva-catalogue/1','items'=>[[
        'canonical_id'=>'AUR-P-'.str_repeat('E',20),
        'manufacturer'=>'Testgeräte GmbH','model'=>'PÜ-493',
        'category'=>str_repeat('y',159).'ü Kategorie',
        'description'=>str_repeat('x',239).'ä Gerät mit Zubehör',
        'source_ref'=>'https://example.com/products/testgeraet-493',
        'last_seen'=>gmdate('c')
    ]]];
    [$utf8Clean,$utf8Count]=aur_catalogue_clean_snapshot(
        json_encode($utf8Input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $utf8Output=json_decode($utf8Clean,true,512,JSON_THROW_ON_ERROR);
    if($utf8Count!==1
        || $utf8Output['items'][0]['description']!==str_repeat('x',239)
        || $utf8Output['items'][0]['category']!==str_repeat('y',159)
        || $utf8Output['items'][0]['manufacturer']!=='Testgeräte GmbH'
        || strlen($utf8Output['items'][0]['description'])>240)
        throw new RuntimeException('catalogue_utf8_byte_boundary_regression');
    // A later smaller scan keeps previously published product identities.
    $next = ['schema'=>'aurenoeva-catalogue/1','items'=>[
        ['canonical_id'=>'AUR-P-'.str_repeat('C',20), 'manufacturer'=>'Garmin',
         'model'=>'GPSMAP 9217xsv', 'sector'=>'marine',
         'category'=>'Navigation / instrumentation',
         'description'=>'Marine navigation radar receiver',
         'classification_status'=>'EVIDENCE_MATCHED',
         'classifications'=>[[
             'industry'=>'marine','category'=>'Navigation / instrumentation',
             'method'=>'technical-facets/1',
             'evidence_url'=>'https://www.garmin.com/en-US/p/2001334/',
             'evidence_field'=>'description','evidence_term'=>'navigation',
             'context_term'=>'Marine','confidence'=>'MATCHED_TEXT']],
         'source_ref'=>'https://www.garmin.com/en-US/p/2001334/']
    ]];
    $reconciled = aur_catalogue_write_snapshot(json_encode($next), $private);
    if (($reconciled['records'] ?? 0) !== 2
        || ($reconciled['accepted_input_records'] ?? 0) !== 1)
        throw new RuntimeException('historical_products_not_preserved');
    $marine = aur_catalogue_snapshot_search('GPSMAP 9217xsv', 8, 'marine', 'Navigation / instrumentation');
    if (count($marine ?? []) !== 1 || $marine[0]['sector'] !== 'marine')
        throw new RuntimeException('marine_category_search_failed');
    if (aur_catalogue_snapshot_search('GPSMAP 9217xsv', 8, 'medical', 'Diagnostic equipment') !== [])
        throw new RuntimeException('sector_filter_not_applied');
    if (count(aur_catalogue_snapshot_search('WATO EX-35') ?? []) !== 1)
        throw new RuntimeException('original_model_lost_during_reconciliation');
    if (!is_file($private . '/.catalogue-merge.lock'))
        throw new RuntimeException('catalogue_lock_not_created');
    // A corrupted existing site file must not be silently replaced and
    // cause the historical catalogue to disappear.
    // Later batches must append instead of replacing the first two products.
    $third=['schema'=>'aurenoeva-catalogue/1','items'=>[[
        'canonical_id'=>'AUR-P-'.str_repeat('D',20), 'manufacturer'=>'Agilent',
        'model'=>'1260 Infinity II', 'sector'=>'laboratory',
        'category'=>'Chromatography',
        'source_ref'=>'https://www.agilent.com/en/product/liquid-chromatography'
    ]]];
    $thirdAck=aur_catalogue_write_snapshot(json_encode($third),$private);
    if(($thirdAck['records']??0)!==3||($thirdAck['accepted_input_records']??0)!==1)
        throw new RuntimeException('catalogue_batches_replace_history');
    if(count(aur_catalogue_snapshot_search('WATO EX-35')??[])!==1
       ||count(aur_catalogue_snapshot_search('1260 Infinity II')??[])!==1)
        throw new RuntimeException('catalogue_batches_not_queryable');
    // The same case-owned worldwide vendor master must absorb new product evidence
    // without losing email/contact details already discovered by RFQ research.
    require_once dirname(__DIR__,3) . '/deployment/site/procurement/private_cases.php';
    require_once dirname(__DIR__,3) . '/deployment/site/procurement/supplier_worldwide.php';
    $store=$root . '/existing-private';
    mkdir($store,0700,true);
    $host='www.garmin.com';
    $id=aur_worldwide_vendor_id($host);
    aur_worldwide_master_merge($store,[['vendor'=>[
        'id'=>$id,'website'=>$host,'name'=>'Known Garmin Contact',
        'email'=>'published@example.net','phone'=>'+49 123',
        'status'=>'SOURCE_CHECKED_EMAIL_PUBLISHED','evidence_url'=>'https://www.garmin.com/en-US/p/2001334/'
    ],'description'=>'marine navigation']]);
    $enrichment=json_encode(['vendor_leads'=>[
        ['website'=>'www.garmin.com','evidence_url'=>'https://www.garmin.com/en-US/p/2001334/',
         'sector'=>'marine','category'=>'Navigation / instrumentation']],
      'vendor_products'=>[
        ['website'=>'www.garmin.com','product_id'=>'AUR-P-'.str_repeat('C',20),
         'source_url'=>'https://www.garmin.com/en-US/p/2001334/',
         'manufacturer'=>'Garmin','model'=>'GPSMAP 9217xsv',
         'sector'=>'marine','category'=>'Navigation / instrumentation',
         'evidence_type'=>'STRUCTURED_PRODUCT','last_seen'=>gmdate('c')]
      ]]);
    $v=aur_catalogue_merge_vendor_evidence($store,$enrichment);
    if($v['product_links']!==1 || $v['vendors_added']!==0)
        throw new RuntimeException('vendor_products_not_merged_with_prior_master');
    $master=aur_worldwide_master_read($store);
    $vendor=$master['vendors'][$id]??[];
    if (($vendor['email']??'')!=='published@example.net'
        || !isset($vendor['catalogue_products']['AUR-P-'.str_repeat('C',20)])
        || !in_array('Garmin GPSMAP 9217xsv',$vendor['equipment_hints']??[],true))
        throw new RuntimeException('contact_or_crawled_product_lost');
    $saved = file_get_contents($private . '/catalogue-public.json');
    $digest = hash('sha256', $saved);
    try { aur_catalogue_write_snapshot(json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>[]]), $private); }
    catch (RuntimeException $e) { /* Expected: preserve last good */ }
    if (hash_file('sha256', $private . '/catalogue-public.json') !== $digest)
        throw new RuntimeException('last_good_snapshot_overwritten');

    // Fast whole-catalogue upload: append only the current chunk, then publish
    // once with checksum/record count; no repeated merge of all prior products.
    $whole=['schema'=>'aurenoeva-catalogue/1','items'=>[
        ['canonical_id'=>'AUR-P-'.str_repeat('A',20),
         'manufacturer'=>'Mindray','model'=>'WATO EX-35',
         'source_ref'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35'],
        ['canonical_id'=>'AUR-P-'.str_repeat('C',20),
         'manufacturer'=>'Garmin','model'=>'GPSMAP 9217xsv',
         'source_ref'=>'https://www.garmin.com/en-US/p/2001334/'],
        ['canonical_id'=>'AUR-P-'.str_repeat('D',20),
         'manufacturer'=>'Agilent','model'=>'1260 Infinity II',
         'source_ref'=>'https://www.agilent.com/en/product/liquid-chromatography'],
        ['canonical_id'=>'AUR-P-'.str_repeat('F',20),
         'manufacturer'=>'Siemens','model'=>'MAGNETOM Lumina',
         'source_ref'=>'https://www.siemens-healthineers.com/en-us/']
    ]];
    $packed=json_encode($whole,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $hash=hash('sha256',$packed);
    if(aur_bulk_begin($private,$hash,strlen($packed))['status']!=='READY')
        throw new RuntimeException('bulk_not_ready');
    $cut=round(strlen($packed)/2);
    $first=substr($packed,0,(int)$cut);$second=substr($packed,(int)$cut);
    if(aur_bulk_chunk($private,$hash,0,$first)['offset']!==strlen($first)
       ||aur_bulk_chunk($private,$hash,strlen($first),$second)['offset']!==strlen($packed)
       ||aur_bulk_chunk($private,$hash,strlen($first),$second)['offset']!==strlen($packed))
        throw new RuntimeException('bulk_chunk_retry_not_idempotent');
    $ack=aur_bulk_commit($private,$hash,strlen($packed),4);
    if($ack['records']!==4
       ||aur_bulk_commit($private,$hash,strlen($packed),4)['records']!==4
       ||count(aur_catalogue_snapshot_search('MAGNETOM Lumina')??[])!==1)
        throw new RuntimeException('bulk_catalogue_not_searchable');
    if(hash_file('sha256',$private.'/catalogue-public.json')!==$hash)
        throw new RuntimeException('bulk_catalogue_checksum_mismatch');
    $smaller=['schema'=>'aurenoeva-catalogue/1','items'=>[$whole['items'][0]]];
    $short=json_encode($smaller,JSON_UNESCAPED_SLASHES);
    $shortHash=hash('sha256',$short);
    aur_bulk_begin($private,$shortHash,strlen($short));
    aur_bulk_chunk($private,$shortHash,0,$short);
    try {
        aur_bulk_commit($private,$shortHash,strlen($short),1);
        throw new RuntimeException('bulk_catalogue_regression_accepted');
    } catch(RuntimeException $e) {
        if($e->getMessage()==='bulk_catalogue_regression_accepted') throw $e;
    }
    if(hash_file('sha256',$private.'/catalogue-public.json')!==$hash)
        throw new RuntimeException('bulk_failure_overwrote_last_good');
    @unlink(aur_bulk_path($private,$shortHash));

    // An authoritative full snapshot may shrink only when a documented source
    // withdrawal accompanies it. The historical record is preserved upstream.
    $verified=['schema'=>'aurenoeva-catalogue/1',
        'items'=>array_slice($whole['items'],0,3),
        'catalogue_health'=>['lifecycle_version'=>1,'source_links'=>4,
            'confirmed_withdrawn_links'=>1,'confirmed_withdrawn_products'=>1]];
    $verifiedBytes=json_encode($verified,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $verifiedSha=hash('sha256',$verifiedBytes);
    aur_bulk_begin($private,$verifiedSha,strlen($verifiedBytes));
    aur_bulk_chunk($private,$verifiedSha,0,$verifiedBytes);
    if(aur_bulk_commit($private,$verifiedSha,strlen($verifiedBytes),3)['records']!==3
       || count(aur_catalogue_snapshot_search('MAGNETOM Lumina')??[])!==0
       || count(aur_catalogue_snapshot_search('WATO EX-35')??[])!==1)
        throw new RuntimeException('evidenced_withdrawal_not_reflected_publicly');

    // A change in a confirmed nontechnical source filter may reduce public
    // browse counts without changing even one canonical VPS database identity.
    // Accept only an exactly reconciled and bounded exclusion delta.
    $otherReasons=['excluded_withdrawn'=>0,'excluded_invalid_id'=>0,
        'excluded_generic'=>1,'excluded_missing_maker'=>0,
        'excluded_missing_model'=>0,'excluded_source'=>0,
        'excluded_duplicates'=>0,'excluded_nonrecord'=>0];
    $priorStats=['db_products'=>5,'exported_products'=>5,
        'eligible_products'=>4,'excluded_products'=>1]+$otherReasons;
    $afterStats=['db_products'=>5,'exported_products'=>5,
        'eligible_products'=>3,'excluded_products'=>2,
        'excluded_nontechnical'=>1]+$otherReasons;
    $priorPayload=json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>$whole['items'],'public_stats'=>$priorStats],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $filteredPayload=json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>array_slice($whole['items'],0,3),'public_stats'=>$afterStats],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $priorPath=$private.'/catalogue-public.json';
    $incomingPath=$root.'/filtered-candidate.json';
    file_put_contents($priorPath,$priorPayload);
    file_put_contents($incomingPath,$filteredPayload);
    if(!aur_bulk_filter_only_reduction($priorPath,$incomingPath,4,3))
        throw new RuntimeException('verified_nontechnical_filter_delta_rejected');
    $wrongDatabase=$afterStats;
    $wrongDatabase['db_products']=6;
    $wrongPayload=json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>array_slice($whole['items'],0,3),'public_stats'=>$wrongDatabase],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    file_put_contents($incomingPath,$wrongPayload);
    if(aur_bulk_filter_only_reduction($priorPath,$incomingPath,4,3))
        throw new RuntimeException('changed_canonical_database_accepted_as_filter_delta');
    $wrongExclusion=$afterStats;
    $wrongExclusion['excluded_nontechnical']=0;
    file_put_contents($incomingPath,json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>array_slice($whole['items'],0,3),'public_stats'=>$wrongExclusion],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    if(aur_bulk_filter_only_reduction($priorPath,$incomingPath,4,3))
        throw new RuntimeException('unexplained_shrink_accepted');
    // A crawler can add identities while unrelated nontechnical source rows
    // are newly excluded; the exact growth + reduction must reconcile.
    $grown=$afterStats;
    $grown['db_products']=6;
    $grown['exported_products']=6;
    $grown['excluded_products']=3;
    $grown['excluded_nontechnical']=2;
    $grownPayload=json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>array_slice($whole['items'],0,3),'public_stats'=>$grown],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    file_put_contents($incomingPath,$grownPayload);
    if(!aur_bulk_filter_only_reduction($priorPath,$incomingPath,4,3))
        throw new RuntimeException('canonical_growth_plus_reconciled_exclusions_rejected');
    $grown['excluded_nontechnical']=1;
    file_put_contents($incomingPath,json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>array_slice($whole['items'],0,3),'public_stats'=>$grown],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    if(aur_bulk_filter_only_reduction($priorPath,$incomingPath,4,3))
        throw new RuntimeException('unexplained_growth_and_removal_accepted');
    // Historical published snapshots had no excluded_withdrawn field.
    // Preserve safe migration while still requiring a full new attestation.
    $legacyPrior=$priorStats;
    unset($legacyPrior['excluded_withdrawn']);
    file_put_contents($priorPath,json_encode(['schema'=>'aurenoeva-catalogue/1',
        'items'=>$whole['items'],'public_stats'=>$legacyPrior],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    file_put_contents($incomingPath,$grownPayload);
    if(!aur_bulk_filter_only_reduction($priorPath,$incomingPath,4,3))
        throw new RuntimeException('legacy_snapshot_growth_and_filter_rejected');
    file_put_contents($priorPath,$priorPayload);
    file_put_contents($incomingPath,$filteredPayload);
    $filterHash=hash('sha256',$filteredPayload);
    aur_bulk_begin($private,$filterHash,strlen($filteredPayload));
    aur_bulk_chunk($private,$filterHash,0,$filteredPayload);
    if(aur_bulk_commit($private,$filterHash,strlen($filteredPayload),3)['records']!==3
       ||hash_file('sha256',$priorPath)!==$filterHash
       ||count(aur_catalogue_snapshot_search('MAGNETOM Lumina')??[])!==0)
        throw new RuntimeException('reconciled_filter_delta_not_published');
    @unlink($incomingPath);

    echo "AURENOEVA_CATALOGUE_HTTPS_SYNC_AND_SEARCH=PASS\n";
} finally {
    @unlink($private . '/catalogue-public.json');
    @unlink($private . '/.catalogue-merge.lock');
    @unlink($root . '/existing-private/supplier-intelligence/worldwide-vendors.json');
    @unlink($root . '/existing-private/supplier-intelligence/.worldwide-vendors.lock');
    @rmdir($root . '/existing-private/supplier-intelligence');
    @rmdir($root . '/existing-private');
    @rmdir($web); @rmdir($private); @rmdir($root);
}
