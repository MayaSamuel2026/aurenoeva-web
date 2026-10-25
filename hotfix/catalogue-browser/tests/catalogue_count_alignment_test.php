<?php
declare(strict_types=1);
define('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY',true);
require dirname(__DIR__).'/catalogue-browse.php';
define('AUR_CATALOGUE_COUNT_LIBRARY_ONLY',true);
require dirname(__DIR__,2).'/catalogue-counter/catalogue-count.php';
$dir=sys_get_temp_dir().'/aur-count-alignment-'.bin2hex(random_bytes(5));
mkdir($dir,0700,true);$path=$dir.'/catalogue-public.json';
try {
    $fixture=[
        ['canonical_id'=>'AUR-P-'.str_repeat('1',20),'manufacturer'=>'Agilent',
        'model'=>'1260 Infinity','sector'=>'laboratory','category'=>'Chromatography / HPLC / GC',
        'description'=>'HPLC instrument','source_ref'=>'https://www.agilent.com/product/1260'],
        ['canonical_id'=>'AUR-P-'.str_repeat('2',20),'manufacturer'=>'Tribesigns',
        'model'=>'SFWYD-9PPY5ITP900','sector'=>'laboratory','category'=>'Spectroscopy',
        'description'=>'Bartisch 180 cm Küche Esszimmer',
        'source_ref'=>'https://tribesigns.de/products/bartisch'],
        ['canonical_id'=>'AUR-P-'.str_repeat('3',20),'manufacturer'=>'Mindray',
        'model'=>'WATO EX-35','sector'=>'medical','category'=>'Surgical / procedural',
        'description'=>'Anaesthesia workstation',
        'source_ref'=>'https://www.mindray.com/en/products/anesthesia/wato-ex-35'],
    ];
    $fixture[]=['canonical_id'=>'AUR-P-'.str_repeat('4',20),'manufacturer'=>'Tribesigns',
        'model'=>'207556','sector'=>'laboratory','category'=>'Spectroscopy',
        'description'=>'Bartisch 160 cm',
        'source_ref'=>'https://luxus-kollektion.de/products/tribesigns-bartisch-207556'];
    file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$fixture],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $raw=aur_catalogue_count_file($path);
    // A prior release can leave an older count for an unchanged snapshot.
    // A changed classification checksum must cause recomputation.
    $st=stat($path);
    file_put_contents($dir.'/catalogue-visible-count-cache.json',json_encode([
        'size'=>$st['size'],'mtime'=>$st['mtime'],'inode'=>$st['ino'],
        'filter_sha256'=>str_repeat('a',64),'count'=>3],JSON_THROW_ON_ERROR));
    $visible=aur_catalogue_visible_count_file($path,(int)$raw);
    $browsed=aur_browse_snapshot($path,'laboratory')['sector_total']
            +aur_browse_snapshot($path,'medical')['sector_total'];
    if ($raw!==4 || $visible!==2 || $browsed!==2)
        throw new RuntimeException("count_not_reconciled:$raw/$visible/$browsed");
    if(aur_browse_snapshot($path,'laboratory','', 'Tribesigns')['total']!==0)
        throw new RuntimeException('consumer_furniture_visible');
    echo "AURENOEVA_VISIBLE_COUNT_AND_CONSUMER_FILTER=PASS\n";
}finally{
    foreach(glob($dir.'/*')?:[] as $file) @unlink($file);
    @rmdir($dir);
}
