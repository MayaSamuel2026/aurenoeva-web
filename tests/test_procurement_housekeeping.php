<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php';
function hcheck(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
$root=sys_get_temp_dir().'/aur-rfq-housekeeping-'.bin2hex(random_bytes(5));
mkdir($root.'/public_html',0700,true);
try {
  $store=aur_store_root($root.'/public_html');
  $case=['reference'=>'AUR-UZ-1234567890ABCDEF','source_sha256'=>hash('sha256','housekeeping'),'country'=>'UZ'];
  $base=['version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
    'case_source_sha256'=>$case['source_sha256'],'destination_country'=>'UZ',
    'approval'=>null,'template_version'=>'pricing-rfq-v3'];
  $draftMessage=['to'=>'vendor@example.test','status'=>'DRAFT','sent_at'=>null];
  $unsent=[];
  for($i=0;$i<2;$i++){
    $id=str_repeat(dechex($i+1),24);
    $d=$base+['draft_id'=>$id,'created_at'=>'2026-10-23T20:00:0'.$i.'Z',
      'status'=>'DRAFT_REVIEW','messages'=>[$draftMessage]];
    aur_vendor_rfq_write(aur_vendor_rfq_path($store,$case['reference'],$id),$d);
    $unsent[]=$id;
  }
  $sentId=str_repeat('a',24);
  $accepted=$draftMessage;
  $accepted['status']='ACCEPTED_BY_MAIL_TRANSPORT';
  $accepted['sent_at']='2026-10-23T19:59:00Z';
  $accepted['attempted_at']='2026-10-23T19:58:59Z';
  $accepted['transport_result']=['transport'=>'AUTHENTICATED_SMTP','stage'=>'DATA_ACCEPTANCE',
     'code'=>250,'outcome'=>'SMTP_ACCEPTED'];
  $sent=$base+['draft_id'=>$sentId,'created_at'=>'2026-10-23T19:00:00Z',
    'status'=>'TRANSPORT_ACCEPTED','messages'=>[$accepted]];
  $sent['approval']=['reviewed_at'=>'2026-10-23T19:00:00Z'];
  aur_vendor_rfq_write(aur_vendor_rfq_path($store,$case['reference'],$sentId),$sent);
  $primary=aur_vendor_rfq_primary($store,$case);
  hcheck($primary!==null && $primary['id']===$sentId,'An unsent newer draft replaced the sent RFQ');
  hcheck(aur_vendor_rfq_public_status($primary)==='Sent to mail server (1/1)','Sent RFQ mislabeled');
  hcheck(aur_vendor_rfq_cleanup_unsent($store,$case,false)===2,'Unused version count incorrect');
  hcheck(aur_vendor_rfq_cleanup_unsent($store,$case,true)===2,'Unused versions were not cleaned');
  foreach($unsent as $id)hcheck(!is_file(aur_vendor_rfq_path($store,$case['reference'],$id)),'Unused draft survived');
  hcheck(is_file(aur_vendor_rfq_path($store,$case['reference'],$sentId)),'Email evidence deleted');
  hcheck(aur_vendor_rfq_cleanup_unsent($store,$case,true)===0,'Cleanup not idempotent');
  // Unconfirmed transport attempts must never be wiped, even if nobody received mail.
  $id=str_repeat('b',24);
  $uncertain=$base+['draft_id'=>$id,'created_at'=>'2026-10-24T00:00:00Z',
    'status'=>'PARTIAL_OR_FAILED','messages'=>[array_merge($draftMessage,['status'=>'IN_FLIGHT','attempted_at'=>'2026-10-24T00:00:00Z'])]];
  aur_vendor_rfq_write(aur_vendor_rfq_path($store,$case['reference'],$id),$uncertain);
  hcheck(aur_vendor_rfq_cleanup_unsent($store,$case,true)===0,'Attempted message considered unused');
  hcheck(is_file(aur_vendor_rfq_path($store,$case['reference'],$id)),'Attempted send was deleted');
  echo "AURENOEVA_RFQ_HOUSEKEEPING=PASS\n";
} finally {
  foreach(glob($root.'/.aurenoeva-private/supplier-rfqs/*')?:[] as $p)if(is_file($p))@unlink($p);
  @rmdir($root.'/.aurenoeva-private/supplier-rfqs');
  @rmdir($root.'/.aurenoeva-private');@rmdir($root.'/public_html');@rmdir($root);
}
