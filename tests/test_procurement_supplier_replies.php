<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_replies.php';
function assert_reply(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/aur-reply-'.bin2hex(random_bytes(7));
mkdir($root.'/public_html',0700,true);
$store=aur_store_root($root.'/public_html');
$case=['reference'=>'AUR-UZ-1234567890ABCDEF','source_sha256'=>hash('sha256','item source'),'country'=>'UZ',
 'payload'=>['sourcing_lines'=>[['source_row'=>2,'customer_item_number'=>7,
 'description_original'=>'Example pump ABC-777','quantity'=>2,'option_type'=>'REQUESTED']]]];
$id=bin2hex(random_bytes(12));
$draft=['case_reference'=>$case['reference'],'case_source_sha256'=>$case['source_sha256'],'draft_id'=>$id,
 'status'=>'TRANSPORT_ACCEPTED','information_only'=>true,'template_version'=>'pricing-rfq-v3',
 'messages'=>[['to'=>'sales@example.org','vendor_name'=>'Supplier Ltd',
 'status'=>'ACCEPTED_BY_MAIL_TRANSPORT','items'=>[['source_row'=>2,'description'=>'Example pump ABC-777']]]]];
aur_vendor_rfq_write(aur_vendor_rfq_path($store,$case['reference'],$id),$draft);
$choices=aur_reply_choices($draft);
assert_reply(count($choices)===1,'sent supplier/item choices lost');
$key=array_key_first($choices);
$saved=aur_reply_record($store,$case,$id,$key,[
 'availability'=>'QUOTED','unit_price'=>'1234.50','currency'=>'EUR',
 'lead_time'=>'2 weeks','reply_reference'=>'Quote Q-123','notes'=>'Stock confirmed by supplier']);
assert_reply(($saved['source_row']??0)===2 && ($saved['item_number']??0)===7,
 'RFQ source row / original item number not maintained');
$read=aur_reply_read($store,$case);
assert_reply(count($read)===1 && reset($read)['unit_price']==='1234.50','response readback unavailable');
foreach(['wrongrow','wrongvendor'] as $test) {
 $denied=false;
 try{aur_reply_record($store,$case,$id,$test,['availability'=>'QUOTED','unit_price'=>'42','currency'=>'EUR']);}
 catch(InvalidArgumentException $e){$denied=true;}
 assert_reply($denied,'uncontacted vendor or mismatched original row accepted');
}
$bad=false;try{aur_reply_record($store,$case,$id,$key,
 ['availability'=>'QUOTED','unit_price'=>'1.0','currency'=>'INVALID']);}
catch(InvalidArgumentException $e){$bad=true;}
assert_reply($bad,'invalid price currency accepted');
assert_reply(count(aur_reply_read($store,$case))===1,'failed update damaged existing quote');
$case['buyer']=['company'=>'Example GmbH','contact'=>'Purchasing Team','email'=>'buyer@example.test'];
$case['public_reference']='AUR-20261027-120000-TEST';
$prepared=aur_reply_buyer_draft($case,aur_reply_read($store,$case));
assert_reply(is_array($prepared)&&$prepared['to']==='buyer@example.test','buyer draft recipient missing');
assert_reply($prepared['send_allowed']===false,'buyer email incorrectly marked approved for dispatch');
assert_reply(str_contains($prepared['body'],'1234.50 EUR') &&
    str_contains($prepared['body'],'Requested quantity: 2'),'supplier price or original quantity lost');
assert_reply(str_contains($prepared['body'],'not independently verified stock'),
    'draft wrongly implies firm availability');
assert_reply(aur_reply_buyer_draft(['buyer'=>[]],aur_reply_read($store,$case))===null,
    'anonymous buyer must not create an addressed outgoing draft');
echo "AURENOEVA_ITEM_MATCHED_SUPPLIER_REPLIES=PASS\n";
