<?php
declare(strict_types=1);
/* Self-contained fixture test. No access to production catalogue or private cases. */
$root = sys_get_temp_dir() . '/aur-seo-fixture-' . bin2hex(random_bytes(5));
mkdir($root,0700,true);
mkdir($root.'/public',0700);
mkdir($root.'/private',0700);
copy(dirname(__DIR__).'/hotfix/international-seo/seo.php',$root.'/public/seo.php');
copy(dirname(__DIR__).'/hotfix/catalogue-browser/catalogue-browse.php',$root.'/public/catalogue-browse.php');
define('AUR_SEO_LIBRARY_ONLY',true);
require $root.'/public/seo.php';
$products = [
 ['canonical_id'=>'AUR-P-0123456789ABCDEF0123','manufacturer'=>'Festo','model'=>'DSBC-32-100',
  'description'=>'Pneumatic actuator with {integrated} sensing',
  'source_ref'=>'https://example.com/industrial/a','sector'=>'industrial','category'=>'Pneumatics / hydraulics',
  'classification_status'=>'EVIDENCE_MATCHED',
  'classifications'=>[['industry'=>'industrial','category'=>'Pneumatics / hydraulics',
   'method'=>'technical-facets/1','evidence_url'=>'https://example.com/industrial/a']]],
 ['canonical_id'=>'AUR-P-ABCDEF0123456789ABCD','manufacturer'=>'Festo','model'=>'VUVG 42 "special"',
  'description'=>'Valve for machine automation','source_ref'=>'https://example.com/industrial/b',
  'sector'=>'industrial','category'=>'Pneumatics / hydraulics','classification_status'=>'UNCLASSIFIED',
  'classifications'=>[]],
 ['canonical_id'=>'AUR-P-11111111111111111111','manufacturer'=>'Other','model'=>'No-index','sector'=>'industrial'],
];
$snapshot = $root.'/private/catalogue-public.json';
file_put_contents($snapshot,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$products,'availability_notice'=>'Identity only'],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$stream=iterator_to_array(aur_seo_products_from_json($snapshot));
if (count($stream)!==3 || $stream[1]['model'] !== 'VUVG 42 "special"') throw new RuntimeException('stream_json');
if (aur_seo_source_note('Find more about AR2400 Great price Official supplier Request A quote Today!') !== '') throw new RuntimeException('supplier_ad_leak');
if (aur_seo_source_note('Shop Other Spare Parts from certified global manufacturers. Zillion Parts simplifies B2B procurement with verified supplier listings, technical') !== '') throw new RuntimeException('cached_seo_marketing_copy_leak');
if (!aur_seo_indexable($products[0]) || aur_seo_indexable($products[2])) throw new RuntimeException('seo_quality_gate');
$idx=aur_seo_index($snapshot);
if ($idx['count']!==2 || ($idx['makers']['Festo']??0)!==2 ||
 ($idx['categories']['industrial|Pneumatics / hydraulics']??0)!==1) throw new RuntimeException('index_counts');
if (aur_seo_index($snapshot)!==$idx) throw new RuntimeException('index_cache');
$rows=iterator_to_array(aur_seo_read_rows($root.'/private/seo-index-products.jsonl'));
if (count($rows)!==2 || !isset($rows[0]['canonical_id'])) throw new RuntimeException('index_records');
$first=$rows[0];
$url=aur_seo_route('en','product',$first);
$_SERVER['REQUEST_URI']=$url;
$_GET=['lang'=>'en','kind'=>'product','key'=>trim(explode('/',$url)[3])];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$html=ob_get_clean();
foreach (['hreflang="de"','rel="canonical"','schema.org','"@type":"Product"',
    'AURENOEVA catalogue ID','requirement.html?q=','Stock','Festo'] as $required) {
    if (!str_contains($html,$required)) throw new RuntimeException('product_page_'.$required);
}
if (str_contains($html,'"availability"') || str_contains($html,'"offers"'))
 throw new RuntimeException('invented_inventory_or_price');

$directory=aur_seo_route('en','directory',[]);
$_SERVER['REQUEST_URI']=$directory;
$_GET=['lang'=>'en','kind'=>'directory'];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$dirHtml=ob_get_clean();
foreach (['Technical product catalogue','/en/manufacturers/','/en/categories/industrial/',
          'Oil, gas &amp; petrochemicals','not separately verified product classifications',
          'hreflang="de"','Start a sourcing enquiry'] as $text) {
    if (!str_contains($dirHtml,$text)) throw new RuntimeException('directory_'.$text);
}
$directoryDe=aur_seo_route('de','directory',[]);
$_SERVER['REQUEST_URI']=$directoryDe;
$_GET=['lang'=>'de','kind'=>'directory'];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$dirDeHtml=ob_get_clean();
if (!str_contains($dirDeHtml,'Technische Ersatzteile') || !str_contains($dirDeHtml,'/de/hersteller/') ||
    !str_contains($dirDeHtml,'Pneumatik und Hydraulik') || str_contains($dirDeHtml,'>Pneumatics / hydraulics<'))
    throw new RuntimeException('directory_de');
if (aur_seo_category_label('Pneumatics / hydraulics','en') !== 'Pneumatics / hydraulics' ||
    aur_seo_category_label('Balances & weighing','de') !== 'Waagen und Wägetechnik' ||
    aur_seo_category_label('Chromatography / HPLC / GC','de') !== 'Chromatographie / HPLC / GC')
    throw new RuntimeException('category_translation');

$maker=aur_seo_route('de','manufacturer',['manufacturer'=>'Festo']);
$_SERVER['REQUEST_URI']=$maker;
$_GET=['lang'=>'de','kind'=>'manufacturer','key'=>basename(trim($maker,'/'))];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$html=ob_get_clean();
if (!str_contains($html,'Technische Produkte') || !str_contains($html,'href="/de/teile/'))
 throw new RuntimeException('maker_page');
$cat=aur_seo_route('en','category',['sector'=>'industrial','category'=>'Pneumatics / hydraulics']);
$_SERVER['REQUEST_URI']=$cat;
$_GET=['lang'=>'en','kind'=>'category','sector'=>'industrial','key'=>basename(trim($cat,'/'))];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$html=ob_get_clean();
if (!str_contains($html,'Pneumatics / hydraulics') || !str_contains($html,'DSBC-32-100'))
 throw new RuntimeException('category_page');
$catDe=aur_seo_route('de','category',['sector'=>'industrial','category'=>'Pneumatics / hydraulics']);
$_SERVER['REQUEST_URI']=$catDe;
$_GET=['lang'=>'de','kind'=>'category','sector'=>'industrial','key'=>basename(trim($catDe,'/'))];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$html=ob_get_clean();
if (!str_contains($html,'<h1>Pneumatik und Hydraulik</h1>') ||
    !str_contains($html,'<title>Pneumatik und Hydraulik | AURENOEVA</title>') ||
    !str_contains($html,'DSBC-32-100') || !str_contains($html,'rel="canonical"') ||
    !str_contains($html,aur_seo_taxon('Pneumatics / hydraulics')))
 throw new RuntimeException('category_page_de');
$productDe=aur_seo_route('de','product',$first);
$_SERVER['REQUEST_URI']=$productDe;
$_GET=['lang'=>'de','kind'=>'product','key'=>basename(trim($productDe,'/'))];
ob_start();aur_seo_page($idx,$root.'/private/seo-index-products.jsonl');$html=ob_get_clean();
if (!str_contains($html,'Pneumatik und Hydraulik') || str_contains($html,'>Pneumatics / hydraulics<'))
 throw new RuntimeException('product_related_category_de');
$_GET=['feed'=>'index'];ob_start();aur_seo_sitemap($idx,$root.'/private/seo-index-products.jsonl');$xml=ob_get_clean();
if (!str_contains($xml,'products-de-1.xml') || !str_contains($xml,'taxonomy-en.xml'))
 throw new RuntimeException('sitemap_index');
$_GET=['feed'=>'products','lang'=>'en','page'=>'1'];
ob_start();aur_seo_sitemap($idx,$root.'/private/seo-index-products.jsonl');$xml=ob_get_clean();
if (substr_count($xml,'<url>')!==2 || !str_contains($xml,'aur-p-0123456789abcdef0123'))
 throw new RuntimeException('product_sitemap');
$_GET=['feed'=>'taxonomy','lang'=>'de'];
ob_start();aur_seo_sitemap($idx,$root.'/private/seo-index-products.jsonl');$xml=ob_get_clean();
if (substr_count($xml,'<url>')!==3 || !str_contains($xml,'/de/kategorien/') || !str_contains($xml,'/de/katalog/'))
 throw new RuntimeException('taxonomy_sitemap');
foreach (glob($root.'/private/*') as $f) unlink($f);
foreach (glob($root.'/private/.*') as $f) { if (is_file($f)) unlink($f); }
unlink($root.'/public/seo.php');unlink($root.'/public/catalogue-browse.php');
rmdir($root.'/public');rmdir($root.'/private');rmdir($root);
echo "AURENOEVA_INTERNATIONAL_SEO=PASS\n";
