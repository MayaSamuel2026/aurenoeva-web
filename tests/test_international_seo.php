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
 ['canonical_id'=>'WRONG','manufacturer'=>'Unknown','model'=>'No-index','sector'=>'industrial'],
];
$snapshot = $root.'/private/catalogue-public.json';
file_put_contents($snapshot,json_encode(['schema'=>'aurenoeva-catalogue/1','items'=>$products,'availability_notice'=>'Identity only'],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$stream=iterator_to_array(aur_seo_products_from_json($snapshot));
if (count($stream)!==3 || $stream[1]['model'] !== 'VUVG 42 "special"') throw new RuntimeException('stream_json');
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
$_GET=['feed'=>'index'];ob_start();aur_seo_sitemap($idx,$root.'/private/seo-index-products.jsonl');$xml=ob_get_clean();
if (!str_contains($xml,'products-de-1.xml') || !str_contains($xml,'taxonomy-en.xml'))
 throw new RuntimeException('sitemap_index');
$_GET=['feed'=>'products','lang'=>'en','page'=>'1'];
ob_start();aur_seo_sitemap($idx,$root.'/private/seo-index-products.jsonl');$xml=ob_get_clean();
if (substr_count($xml,'<url>')!==2 || !str_contains($xml,'aur-p-0123456789abcdef0123'))
 throw new RuntimeException('product_sitemap');
$_GET=['feed'=>'taxonomy','lang'=>'de'];
ob_start();aur_seo_sitemap($idx,$root.'/private/seo-index-products.jsonl');$xml=ob_get_clean();
if (substr_count($xml,'<url>')!==2 || !str_contains($xml,'/de/kategorien/'))
 throw new RuntimeException('taxonomy_sitemap');
foreach (glob($root.'/private/*') as $f) unlink($f);
foreach (glob($root.'/private/.*') as $f) { if (is_file($f)) unlink($f); }
unlink($root.'/public/seo.php');unlink($root.'/public/catalogue-browse.php');
rmdir($root.'/public');rmdir($root.'/private');rmdir($root);
echo "AURENOEVA_INTERNATIONAL_SEO=PASS\n";
