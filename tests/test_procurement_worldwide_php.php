<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_intelligence.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_routes.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_discovery.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_worldwide.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php';
function verify(bool $ok,string $reason):void { if(!$ok)throw new RuntimeException($reason); }
$root=sys_get_temp_dir().'/aurenoeva-rfq-'.bin2hex(random_bytes(5));
mkdir($root.'/public_html',0700,true);
try{
 $store=aur_store_root($root.'/public_html');
 $case=[
   'reference'=>'AUR-UZ-1234567890ABCDEF',
   'source_sha256'=>hash('sha256','sample-source'),
   'country'=>'UZ',
   'payload'=>['sourcing_lines'=>[
     ['source_row'=>2,'description_original'=>'Mindray WATO EX-35 anesthesia workstation',
       'customer_item_number'=>1,'quantity'=>2,'option_type'=>'REQUESTED']
   ]]
 ];
 $research=aur_supplier_save($store,$case);
 $routes=aur_vendor_rfq_allowed($case,$research);
 verify(isset($routes['2~seed-intermed']),'published WATO contact not selectable');
 $draft=aur_vendor_rfq_prepare($store,$case,$research,['2~seed-intermed']);
 verify($draft['status']==='DRAFT_REVIEW','draft state invalid');
 verify(count($draft['messages'])===1,'one RFQ per supplier not enforced');
 verify(str_contains($draft['messages'][0]['body'],'Quantity: 2'),'source item quantity lost');
 verify($draft['messages'][0]['status']==='DRAFT','draft sent unexpectedly');
 $denied=false;
 try{aur_vendor_rfq_dispatch($store,$case,$draft['draft_id'],false,static fn()=>true);}
 catch(InvalidArgumentException $e){$denied=true;}
 verify($denied,'approval must be required');
 $mockCalls=0;
 $outcome=aur_vendor_rfq_dispatch($store,$case,$draft['draft_id'],true,
   static function($recipient,$subject,$body)use(&$mockCalls):bool{
     $mockCalls++;
     return filter_var($recipient,FILTER_VALIDATE_EMAIL)!==false && str_contains($body,'WATO');
   });
 verify($outcome['status']==='TRANSPORT_ACCEPTED' && $mockCalls===1,'approved mock send failed');
 $duplicateBlocked=false;
 try{aur_vendor_rfq_dispatch($store,$case,$draft['draft_id'],true,static fn()=>true);}
 catch(RuntimeException $e){$duplicateBlocked=true;}
 verify($duplicateBlocked,'duplicate send was not blocked');
 verify(!aur_worldwide_safe_ip('127.0.0.1') && !aur_worldwide_safe_ip('10.0.0.1'),'unsafe crawl IP admitted');
 $html='<html><title>Mindray WATO EX-35</title><p>Hospital medical anesthesia workstation distributor</p>'
  .'<a href="mailto:research@vendor-health.fr">Info</a></html>';
 $lead=aur_worldwide_source_candidate('Mindray WATO EX-35',aur_supplier_match('Mindray WATO EX-35'),
   ['page_title'=>'Mindray WATO EX-35 anesthesia'], $html,'https://vendor-health.fr/product');
 verify(is_array($lead)&&$lead['email']==='research@vendor-health.fr','website contact extraction broken');
 verify(aur_worldwide_master_merge($store,[['vendor'=>$lead,
     'description'=>'Mindray WATO EX-35 anesthesia workstation']])===1,'new supplier not saved globally');
 $shared=aur_worldwide_attach_master($store,$research);
 verify(count($shared['worldwide_vendor_master'])===1,'global vendor catalogue not readable');
 $reused=aur_worldwide_case_candidates($shared,'2','Mindray WATO EX-35 anesthesia workstation');
 verify(count($reused)>=2,'global manufacturer/supplier research not reusable across cases');
 verify(aur_worldwide_master_merge($store,[['vendor'=>$lead,
     'description'=>'Mindray WATO EX-35 anesthesia workstation']])===0,'global duplicate vendor inserted');

 verify(aur_worldwide_source_candidate('Mindray WATO EX-35',aur_supplier_match('Mindray WATO EX-35'),
   ['page_title'=>'Cattle jobs'],'<html><p>Breeding cattle jobs and career</p></html>',
   'https://masterrind.com/jobs')===null,'irrelevant supplier admitted');
 define('AUR_PROCUREMENT_VIEW',true);
 $result=$case;
 $supplierResearch=$research;
 $rfqPreview=$draft;
 $csrf='fixture-csrf';
 $supplierDiscoveryMessage=null;
 if (!function_exists('aur_escape')) {
    function aur_escape(string $value):string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 }
 ob_start();
 require dirname(__DIR__).'/deployment/site/procurement/supplier_portal.php';
 $page=(string)ob_get_clean();
 verify(str_contains($page,'Worldwide search controls are always available'),'supplier workspace must link to permanent search');
 verify(str_contains($page,'Prepare RFQs for selected suppliers'),'RFQ selection action missing');
 verify(str_contains($page,'Mindray WATO EX-35'),'source equipment absent from render');

 // Regression: original 44-line case always takes priority over utility cards.
 $entry=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/index.php');
 $caseAt=strpos($entry,'<section class="card"><span class="pill">Saved');
 $testAt=strpos($entry,'<h2>Email automation — dummy RFQ test</h2>');
 verify($caseAt!==false && $testAt!==false && $caseAt<$testAt,
   'procurement case/RFQ has regressed below the dummy email utility');
 $adminGuardStart=strpos($entry,'<?php if (!$isAdmin): ?>');
 $adminGuardEnd=strpos($entry,'<?php endif; // authenticated procurement workspace ?>');
 $catalogueAt=strpos($entry,'<h2>Live product catalogue</h2>');
 verify($adminGuardStart!==false && $adminGuardEnd!==false && $adminGuardStart<$caseAt
   && $adminGuardEnd>$catalogueAt && $testAt<$adminGuardEnd,
   'private procurement panels appear outside the authenticated admin boundary');

 verify(str_contains($entry,'$supplierResearch=aur_supplier_preview($result);'),
   'supplier portal can disappear when saved research is temporarily unavailable');
 verify(str_contains($entry,'id="aur-worldwide-scan-all"') &&
   strpos($entry,'id="original-rfq-equipment"')<strpos($entry,'id="aur-worldwide-scan-all"'),
   'worldwide scan controls missing from original case workspace');
 verify(str_contains($entry,'id="aur-worldwide-scan-form"'),
   'permanent search form missing');
 verify(str_contains($page,'supplier-workspace'),'direct supplier workspace anchor missing');
 $many=$case;
 $many['payload']['sourcing_lines']=[];
 for($i=1;$i<=44;$i++){
   $many['payload']['sourcing_lines'][]=[
     'source_row'=>$i+1,'customer_item_number'=>$i,
     'description_original'=>$i%2 ? 'Mindray WATO EX-35 anesthesia workstation' : 'DRGEM DIAMOND X-ray system',
     'quantity'=>1,'option_type'=>'REQUESTED'
   ];
 }
 $fortyFour=aur_supplier_preview($many);
 verify(count($fortyFour['rows'])===44,'44-equipment case could not be reconstructed');
 verify(aur_worldwide_stats($fortyFour)['total']===44,'44-equipment case failed supplier statistics');
 // Bad historical vendor data may not make the panel crash or return HTTP 503.
 $malformed=$research;
 $malformed['worldwide_vendor_master']=[
   'legacy'=>['id'=>['old-id'],'name'=>['old name'],'website'=>'old.example',
       'email'=>'info@old.example','equipment_hints'=>[['bad-hint'],'Mindray WATO EX-35']]
 ];
 $malformed['worldwide_research']=['rows'=>['2'=>[
     'checked_at'=>'2026-10-20T00:00:00Z','version'=>'legacy',
     'vendors'=>[['id'=>['legacy-id'],'name'=>'Old Supplier',
         'website'=>'old.example','evidence_url'=>'https://old.example/lead',
         'email'=>'info@old.example','country'=>'Old country']]
 ]]];
 $badStats=aur_worldwide_stats($malformed);
 verify($badStats['total']===1 && $badStats['scanned']===1,
   'historical catalogue data broke scan statistics');
 $badVendors=aur_worldwide_case_candidates($malformed,'2','Mindray WATO EX-35 anesthesia workstation');
 verify(count($badVendors)>0 && is_string($badVendors[0]['id']),
   'malformed source record breaks supplier display');
 $supplierResearch=$malformed;
 ob_start();
 require dirname(__DIR__).'/deployment/site/procurement/supplier_portal.php';
 $renderedAfterUpgrade=(string)ob_get_clean();
 verify(str_contains($renderedAfterUpgrade,'Old Supplier') &&
   str_contains($renderedAfterUpgrade,'https://old.example/lead'),
   'legacy source must remain visible even with malformed master data');
 echo "AURENOEVA_LIVE_DATA_COMPATIBILITY=PASS\n";
 // An OEM rule revision and a crawler version revision must not erase prior sources.
 $record=aur_supplier_read($store,$case['reference'],$case['source_sha256']);
 $record['rule_version']='legacy-rule-version';
 $record['worldwide_research']=['rows'=>['2'=>[
   'version'=>'legacy-crawler','checked_at'=>'2026-10-20T00:00:00Z',
   'vendors'=>[['id'=>'web-historical','name'=>'Historical vendor',
      'website'=>'old-supplier.example','evidence_url'=>'https://old-supplier.example/model',
      'status'=>'SEARCH_RESULT_NOT_SITE_VERIFIED','email'=>'','phone'=>'',
      'country'=>'Not established','match_basis'=>'HISTORICAL_EVIDENCE']]
 ]]];
 $record['global_discovery']=['rows'=>['2'=>['leads'=>[[
   'source_url'=>'https://another-supplier.example/item',
   'website'=>'another-supplier.example','page_title'=>'Historical public listing'
 ]]]]];
 file_put_contents(aur_supplier_private_file($store,$case['reference']),json_encode($record,JSON_THROW_ON_ERROR));
 $regenerated=aur_supplier_save($store,$case);
 verify(isset($regenerated['worldwide_research']['rows']['2']) &&
   isset($regenerated['global_discovery']['rows']['2']),
   'rule upgrade erased saved supplier sources');
 $historical=aur_worldwide_case_candidates($regenerated,'2','Mindray WATO EX-35 anesthesia workstation');
 verify(count(array_filter($historical,static fn($v):bool =>
   str_contains((string)($v['evidence_url']??''),'old-supplier.example')))>0,
   'crawler version upgrade hid an old source');
 verify(count(array_filter($historical,static fn($v):bool =>
   str_contains((string)($v['evidence_url']??''),'another-supplier.example')))>0,
   'saved global discovery source was hidden');
 echo "AURENOEVA_RESEARCH_PERSISTENCE=PASS\n";

 // Dummy email automation must address only the owner's fixed mailbox,
 // exercise the real RFQ format and never send a duplicate on repeated clicks.
 $testCalls=0;
 $smoke=aur_vendor_rfq_send_smoke_test($store,
     static function($to,$subject,$body)use(&$testCalls):bool{
         $testCalls++;
         verify($to==='fredericksamuel1965@gmail.com','dummy RFQ went to an unexpected recipient');
         verify(str_contains($subject,'TEST') && str_contains($body,'Quantity: 2 units')
             && str_contains($body,'Uzbekistan'),'dummy RFQ misses specification');
         return true;
     });
 verify($smoke['status']==='ACCEPTED_BY_MAIL_TRANSPORT' && $testCalls===1,
   'dummy mail transport not accepted');
 $repeat=aur_vendor_rfq_send_smoke_test($store,static function()use(&$testCalls):bool{$testCalls++;return true;});
 verify($testCalls===1 && !empty($repeat['recent_test_preserved']),'dummy mail sent twice');
 echo "AURENOEVA_DUMMY_EMAIL_TEST=PASS\n";
 echo "AURENOEVA_WORLDWIDE_UI_RENDER=PASS\n";
 echo "AURENOEVA_WORLDWIDE_RFQ_MOCK=PASS\n";
} finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $item) $item->isDir()?@rmdir((string)$item):@unlink((string)$item);
 @rmdir($root);
}
