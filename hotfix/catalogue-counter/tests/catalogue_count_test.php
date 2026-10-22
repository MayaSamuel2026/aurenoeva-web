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
    if (aur_catalogue_count_file($path) !== 2) throw new RuntimeException('cached count');
    $rows[] = ['canonical_id'=>'AUR-P-'.str_repeat('3',20),'manufacturer'=>'Fixture',
        'model'=>'MODEL-3','source_ref'=>'https://example.org/3'];
    file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$rows]));
    clearstatcache(true,$path);
    if (aur_catalogue_count_file($path) !== 3) throw new RuntimeException('cache invalidation');
    file_put_contents($path,'{"schema":"invalid","items":[]}');
    clearstatcache(true,$path);
    if (aur_catalogue_count_file($path) !== null) throw new RuntimeException('bad schema');
    echo "catalogue counter PASS\n";
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
