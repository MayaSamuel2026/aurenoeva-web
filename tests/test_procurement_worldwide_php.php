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
 verify(str_contains($page,'Discover suppliers worldwide for entire case'),'worldwide scan button missing');
 verify(str_contains($page,'Prepare RFQs for selected suppliers'),'RFQ selection action missing');
 verify(str_contains($page,'Mindray WATO EX-35'),'source equipment absent from render');
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
