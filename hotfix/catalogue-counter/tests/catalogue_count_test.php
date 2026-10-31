<?php
declare(strict_types=1);
define('AUR_CATALOGUE_COUNT_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/catalogue-count.php';
$dir = sys_get_temp_dir() . '/aur-counter-' . bin2hex(random_bytes(7));
if (!mkdir($dir, 0700)) exit(1);
$path = $dir . '/catalogue-public.json';
try {
    if (aur_catalogue_count_file($path) !== null) throw new RuntimeException('missing snapshot');
    $rows = [];
    foreach ([1,2] as $n) $rows[] = [
        'canonical_id'=>'AUR-P-'.str_pad((string)$n,20,'0',STR_PAD_LEFT),
        'manufacturer'=>'Fixture', 'model'=>'MODEL-'.$n,
        'description'=>$n===1 ? str_repeat('x',66000) : 'ok',
        'source_ref'=>'https://example.org/'.$n
    ];
    $json = json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$rows],
        JSON_THROW_ON_ERROR);
    file_put_contents($path,$json);
    $first = aur_catalogue_count_file($path);
    if ($first !== 2) throw new RuntimeException('first count actual='.var_export($first,true).' header='.substr($json,0,120));
    $time=aur_catalogue_snapshot_updated_at($path);
    if (!is_string($time) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $time))
        throw new RuntimeException('snapshot_timestamp_missing');
    $stats=['db_products'=>5,'exported_products'=>5,'eligible_products'=>2,
        'excluded_products'=>3,'excluded_invalid_id'=>1,
        'excluded_generic'=>1,'excluded_missing_maker'=>0,
        'excluded_missing_model'=>0,'excluded_source'=>1,
        'excluded_duplicates'=>0,'excluded_nonrecord'=>0];
    $withStats=['schema'=>'aurenoeva-catalogue/1','items'=>$rows,
        'availability_notice'=>'Product identity only',
        'public_stats'=>$stats];
    file_put_contents($path,json_encode($withStats,JSON_THROW_ON_ERROR));
    clearstatcache(true,$path);
    $readStats=aur_catalogue_public_stats($path,2);
    if ($readStats===null || $readStats['db_products']!==5 ||
        $readStats['excluded_source']!==1)
        throw new RuntimeException('reconciliation_stats_missing');
    if (aur_catalogue_public_stats($path,3)!==null)
        throw new RuntimeException('stale_stats_accepted');
    if (aur_catalogue_count_file($path) !== 2) throw new RuntimeException('cached count');
    $rows[] = ['canonical_id'=>'AUR-P-'.str_repeat('3',20),'manufacturer'=>'Fixture',
        'model'=>'MODEL-3','source_ref'=>'https://example.org/3'];
    file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$rows]));
    clearstatcache(true,$path);
    if (aur_catalogue_count_file($path) !== 3) throw new RuntimeException('cache invalidation');
    file_put_contents($path,'{"schema":"invalid","items":[]}');
    clearstatcache(true,$path);
    if (aur_catalogue_count_file($path) !== null) throw new RuntimeException('bad schema');
    if (aur_catalogue_crawler_status(null)['status'] !== 'NOT_INSTRUMENTED')
        throw new RuntimeException('missing crawl data must not be considered healthy');
    $now=time();
    $ts=static fn(int $unix): string => gmdate('Y-m-d\TH:i:s\Z',$unix);
    $crawl=[
        'crawl_status_version'=>1,
        'crawl_checked_at_utc'=>$ts($now),
        'crawl_last_started_at_utc'=>$ts($now-2400),
        'crawl_last_finished_at_utc'=>$ts($now-2300),
        'crawl_24h_completed_runs'=>22,
        'crawl_24h_new_products'=>42,'crawl_24h_pages'=>1200,
        'crawl_24h_failures'=>9,'crawl_last_run_new_products'=>3,
        'crawl_last_run_pages'=>100,'crawl_last_run_failures'=>1,
        'crawl_queue_total'=>400,'crawl_queue_due'=>50,
        'crawl_queue_retrying'=>11,'crawl_queue_due_pages'=>40,
        'crawl_queue_due_searches'=>10
    ];
    $healthy=aur_catalogue_crawler_status($crawl,$now);
    if($healthy['status']!=='RECENTLY_COMPLETED' ||
       $healthy['new_products_24h']!==42 || $healthy['queue_retrying']!==11)
        throw new RuntimeException('completed crawler data not available');
    $stale=$crawl;
    $stale['crawl_checked_at_utc']=$ts($now-10000);
    if(aur_catalogue_crawler_status($stale,$now)['status']!=='STALE_TELEMETRY')
        throw new RuntimeException('stale crawl data reported healthy');
    $noDiscovery=$crawl;
    $noDiscovery['crawl_24h_new_products']=0;
    if(aur_catalogue_crawler_status($noDiscovery,$now)['status']!=='ZERO_NEW_PRODUCTS_24H')
        throw new RuntimeException('zero product yield not signaled');
    $noCompletion=$crawl;
    $noCompletion['crawl_last_finished_at_utc']=$ts($now-11000);
    if(aur_catalogue_crawler_status($noCompletion,$now)['status']!=='NO_RECENT_COMPLETED_RUN')
        throw new RuntimeException('stale completed run not signaled');
    $overdue=$crawl;
    $overdue['crawl_last_started_at_utc']=$ts($now-4000);
    $overdue['crawl_last_finished_at_utc']=$ts($now-6000);
    if(aur_catalogue_crawler_status($overdue,$now)['status']!=='RUN_OVERDUE')
        throw new RuntimeException('overdue started run not signaled');
    echo "catalogue counter PASS\n";
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
