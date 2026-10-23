<?php
declare(strict_types=1);
define('AUR_CATALOGUE_BROWSE_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/catalogue-browse.php';

$root = sys_get_temp_dir().'/aur-browse-'.bin2hex(random_bytes(5));
mkdir($root,0700,true);
$path=$root.'/catalogue-public.json';
$items=[];
foreach(range(0,29) as $i) {
    $items[]=[
        'canonical_id'=>'AUR-P-'.strtoupper(str_pad(dechex($i),20,'0',STR_PAD_LEFT)),
        'manufacturer'=>'Agilent',
        'model'=>'1260-'.$i,
        'description'=>'Chromatography equipment '.$i,
        'sector'=>'laboratory',
        'category'=>($i % 3 === 0 ? 'Mass spectrometry' : 'Chromatography / HPLC / GC'),
        'source_ref'=>'https://example.com/product/'.$i,
    ];
}
$items[]=[
    'canonical_id'=>'AUR-P-'.str_repeat('F',20),
    'manufacturer'=>'Siemens','model'=>'6ES7132','sector'=>'industrial',
    'category'=>'PLC / control modules',
];
file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$items],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
try {
    $first=aur_browse_snapshot($path,'laboratory','', '',0,12);
    if($first['sector_total']!==30||$first['total']!==30||
       count($first['items'])!==12||$first['has_more']!==true||
       count($first['categories'])!==2)
       throw new RuntimeException('category_summary_wrong');
    $second=aur_browse_snapshot($path,'laboratory','', '',1,12);
    if(count($second['items'])!==12||
       $second['items'][0]['canonical_id']===$first['items'][0]['canonical_id'])
       throw new RuntimeException('pagination_wrong');
    $third=aur_browse_snapshot($path,'laboratory','', '',2,12);
    if(count($third['items'])!==6||$third['has_more']!==false)
       throw new RuntimeException('last_page_wrong');
    $filtered=aur_browse_snapshot($path,'laboratory','Mass spectrometry','1260-9');
    if($filtered['sector_total']!==30||$filtered['total']!==1||
       $filtered['items'][0]['model']!=='1260-9')
       throw new RuntimeException('category_filter_wrong');
    $industrial=aur_browse_snapshot($path,'industrial');
    if($industrial['sector_total']!==1||$industrial['items'][0]['model']!=='6ES7132')
       throw new RuntimeException('sector_isolation_wrong');
    foreach($filtered['items'] as $part)if(isset($part['stock'])||isset($part['price']))
       throw new RuntimeException('unverified_stock_leaked');
    try{
      aur_browse_snapshot($path,'invalid');
      throw new RuntimeException('invalid_sector_accepted');
    }catch(InvalidArgumentException $e){}
    echo "AURENOEVA_CATEGORY_BROWSE_AND_ENQUIRY_SELECTION=PASS\n";
}finally {
    @unlink($path);
    @rmdir($root);
}
