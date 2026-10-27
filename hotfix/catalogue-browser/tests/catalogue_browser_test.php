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
        'description'=>($i % 3 === 0 ? 'Mass spectrometry equipment ' : 'Chromatography equipment ').$i,
        'sector'=>'laboratory',
        'category'=>($i % 3 === 0 ? 'Mass spectrometry' : 'Chromatography / HPLC / GC'),
        'source_ref'=>'https://example.com/product/'.$i,
    ];
}
// WP02: seller evidence survives browsing, while absent offers say on request.
$items[0]['public_offers'] = [[
    'seller_website'=>'example.com','seller_name'=>'Example Seller',
    'source_url'=>'https://example.com/product/0',
    'currency'=>'EUR','amount'=>42.5,'observed_at'=>gmdate('c')
]];
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
    if($first['items'][0]['price_status']!=='PUBLIC_OFFER'
       || count($first['items'][0]['public_offers'])!==1
       || $first['items'][1]['price_status']!=='ON_REQUEST')
       throw new RuntimeException('public_offer_and_on_request_fallback_wrong');
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
    // Regression: a broad HPLC crawler search must not classify every lab
    // discovery as chromatography, and unrelated sectors must not show it.
    $mixed=[
       ['canonical_id'=>'AUR-P-'.str_repeat('1',20),'manufacturer'=>'Fritsch GmbH',
        'model'=>'P-5 premium','description'=>'Planetary ball mill sample preparation',
        'sector'=>'laboratory','category'=>'Chromatography / HPLC / GC'],
       ['canonical_id'=>'AUR-P-'.str_repeat('2',20),'manufacturer'=>'YMC Europe',
        'model'=>'YMC SEC (U)HPLC Säulen','description'=>'Separation column',
        'sector'=>'laboratory','category'=>'Chromatography / HPLC / GC'],
       ['canonical_id'=>'AUR-P-'.str_repeat('3',20),'manufacturer'=>'Sartorius',
        'model'=>'Sartocheck 5 Plus','description'=>'Automated filter integrity tester',
        'sector'=>'laboratory','category'=>'Chromatography / HPLC / GC'],
       ['canonical_id'=>'AUR-P-'.str_repeat('4',20),'manufacturer'=>'Example',
        'model'=>'Fake aerospace part','description'=>'Chromatography',
        'sector'=>'aviation','category'=>'Chromatography / HPLC / GC']
    ];
    $mixed[]=['canonical_id'=>'AUR-P-'.str_repeat('5',20),'manufacturer'=>'PONS',
        'model'=>'978-3-12-562482-5','description'=>'German grammar book',
        'sector'=>'laboratory','category'=>'Sample preparation',
        'source_ref'=>'https://de.pons.com/shop/deutsch/grammatik/978-3-12-562482-5'];
    $mixed[]=['canonical_id'=>'AUR-P-'.str_repeat('6',20),'manufacturer'=>'SILVERCREST',
        'model'=>'100397202','description'=>'Kitchen cutting board',
        'sector'=>'aviation','category'=>'Hydraulic / pneumatic',
        'source_ref'=>'https://www.lidl.de/p/kitchen-board/p100397202'];
    file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$mixed],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $lab=aur_browse_snapshot($path,'laboratory');
    if($lab['sector_total']!==3 || $lab['total']!==3 ||
       $lab['items'][0]['category']!=='Sample preparation' ||
       $lab['items'][1]['category']!=='Chromatography / HPLC / GC' ||
       $lab['items'][2]['category']!=='General laboratory equipment')
       throw new RuntimeException('laboratory_evidence_mapping_wrong');
    $avi=aur_browse_snapshot($path,'aviation');
    if($avi['sector_total']!==1 || $avi['items'][0]['category']!=='' ||
       $avi['categories'][0]['name']!=='__other')
       throw new RuntimeException('cross_sector_category_leaked');
    $hplc=aur_browse_snapshot($path,'laboratory','Chromatography / HPLC / GC');
    if($hplc['total']!==1 || $hplc['items'][0]['model']!=='YMC SEC (U)HPLC Säulen')
       throw new RuntimeException('chromatography_false_positive');
    $medical=['canonical_id'=>'AUR-P-'.str_repeat('7',20),
        'manufacturer'=>'Mindray','model'=>'VS-600','description'=>'Vital signs monitor',
        'sector'=>'medical','category'=>'Surgical / procedural',
        'source_ref'=>'https://www.mindray.com/en/products/patient-monitoring/vital-signs-monitoring/vs-600'];
    $meter=['canonical_id'=>'AUR-P-'.str_repeat('8',20),
        'manufacturer'=>'Tektronix','model'=>'DMM7510','description'=>'Digital multimeter',
        'sector'=>'test','category'=>'Oscilloscopes',
        'source_ref'=>'https://www.tek.com/en/products/keithley/digital-multimeter/dmm7510'];
    file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>[$medical,$meter]],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if(aur_browse_snapshot($path,'medical')['items'][0]['category']!=='Patient monitoring')
        throw new RuntimeException('medical_monitor_category_wrong');
    if(aur_browse_snapshot($path,'test')['items'][0]['category']!=='Meters & power analyzers')
        throw new RuntimeException('multimeter_category_wrong');
    // Restore full fixture for the remaining assertions.
    file_put_contents($path,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$items],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

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
