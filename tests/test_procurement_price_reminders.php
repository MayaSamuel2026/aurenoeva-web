<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_reminders.php';
function price_check(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException($why);
}
$root=sys_get_temp_dir().'/aur-price-reminders-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try {
    $store=$root;
    $ref='AUR-UZ-1234567890ABCDEF';
    $case=['reference'=>$ref,'source_sha256'=>hash('sha256','customer-original'),
      'country'=>'UZ','country_preflight'=>aur_embargo_screen_country('UZ'),
      'payload'=>['sourcing_lines'=>[
        ['source_row'=>2,'customer_item_number'=>12,'description_original'=>'DS.Surg 150 electrosurgical unit','quantity'=>1,'option_type'=>'REQUESTED'],
        ['source_row'=>3,'customer_item_number'=>22,'description_original'=>'DS Surg 150 - Electrosurgical Unit','quantity'=>1,'option_type'=>'REQUESTED'],
        ['source_row'=>4,'customer_item_number'=>6,'description_original'=>'MT5000S flexible endoscope washer','quantity'=>1,'option_type'=>'REQUESTED'],
        ['source_row'=>5,'customer_item_number'=>6,'description_original'=>'MT5000L flexible endoscope washer','quantity'=>null,'option_type'=>'ALTERNATIVE'],
        ['source_row'=>6,'customer_item_number'=>17,'description_original'=>'Ophthalmic examination lamp','quantity'=>1,'option_type'=>'REQUESTED'],
      ]]];
    $draftId=str_repeat('a',24);
    $original=['version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$ref,
      'case_source_sha256'=>$case['source_sha256'],'draft_id'=>$draftId,
      'created_at'=>'2026-10-23T10:00:00+00:00',
      'status'=>'TRANSPORT_ACCEPTED',
      'messages'=>[
        ['to'=>'sales@kombi-med.com','vendor_name'=>'Vendor A',
         'status'=>'ACCEPTED_BY_MAIL_TRANSPORT','sent_at'=>'2026-10-23T10:01:00+00:00',
         'items'=>[['source_row'=>2],['source_row'=>3],['source_row'=>4],['source_row'=>5]]],
        ['to'=>'sales@vendor-b.example','vendor_name'=>'Vendor B',
         'status'=>'TRANSPORT_FAILED_OR_UNCONFIRMED',
         'items'=>[['source_row'=>6]]],
        ['to'=>'sales@vendor-c.example','vendor_name'=>'Vendor C',
         'status'=>'ACCEPTED_BY_MAIL_TRANSPORT','sent_at'=>'2026-10-23T10:02:00+00:00',
         'items'=>[['source_row'=>6]]]
      ]];
    $originalPath=aur_vendor_rfq_path($store,$ref,$draftId);
    aur_vendor_rfq_write($originalPath,$original);
    $inbox=aur_private_directory($store,'supplier-inbox');
    file_put_contents($inbox.'/events.json',json_encode([
      'events'=>['quoted'=>['case_reference'=>$ref,'supplier_email'=>'sales@vendor-c.example',
        'classification'=>'CLARIFICATION_REQUIRED','processed_at'=>'2026-10-24T10:00:00Z']]
    ],JSON_THROW_ON_ERROR));
    $made=aur_price_reminder_prepare($store,$case);
    price_check(count($made['messages'])===2,'unconfirmed vendor accidentally included');
    price_check($made['excluded_unconfirmed_original_recipients']===1,'original delivery uncertainty missing');
    price_check(($made['messages'][0]['review_status']??'')==='DUPLICATE_QUANTITY_REVIEW','duplicate not flagged');
    price_check(count($made['messages'][0]['lines'])===3,'duplicate equipment not consolidated or alternative lost');
    price_check($made['messages'][0]['lines'][0]['buyer_items']===[12,22],'original buyer item identifiers lost');
    price_check($made['messages'][0]['lines'][0]['quantity']===null,'two quantities wrongly added');
    price_check(str_contains($made['messages'][0]['body'],'Customer items 12, 22'),'short email missing individualized item references');
    price_check(str_contains($made['messages'][0]['body'],'Unit price / currency'),'short price request missing');
    price_check(str_contains($made['messages'][0]['body'],'Delivery lead time'),'short lead time request missing');
    price_check(str_contains($made['messages'][0]['body'],'MT5000L'),'different alternative silently removed');
    price_check($made['messages'][1]['review_status']==='SUPPLIER_ALREADY_RESPONDED_REVIEW','supplier correspondence not flagged');
    price_check(aur_price_reminder_prepare($store,$case)===$made,'repeat draft preparation mutated saved data');
    $token=$made['messages'][0]['recipient_token'];
    $german=aur_price_reminder_kombimed_translate($store,$case,$token);
    price_check(($german['messages'][0]['language']??'')==='de','german draft language not saved');
    price_check(str_contains($german['messages'][0]['subject'],'Freundliche Erinnerung'),'german subject not prepared');
    price_check(str_contains($german['messages'][0]['body'],'Kundenpositionen 12, 22'),'duplicate buyer references lost in German translation');
    price_check(str_contains($german['messages'][0]['body'],'Die insgesamt benötigte Menge'),'duplicate quantity uncertainty not preserved');
    price_check(str_contains($german['messages'][0]['body'],'MT5000L'),'alternative omitted from German message');
    price_check(str_contains($german['messages'][0]['body'],'Einzelpreis / Währung'),'German quote headings absent');
    price_check(aur_price_reminder_kombimed_translate($store,$case,$token)===$german,'German draft conversion not idempotent');
    price_check((aur_price_reminder_read($store,$case)['messages'][1]['language']??'')==='','other recipient changed by German conversion');
    price_check(aur_vendor_rfq_read($store,$case,$draftId)['messages'][0]['status']==='ACCEPTED_BY_MAIL_TRANSPORT',
      'German conversion modified original send receipt');
    $blocked=false;
    try {aur_price_reminder_send($store,$case,[$made['messages'][0]['recipient_token']],false,true,static fn()=>true);}
    catch(InvalidArgumentException $e){$blocked=true;}
    price_check($blocked,'send without explicit owner approval succeeded');
    $count=0;
    $target=$made['messages'][0]['recipient_token'];
    $sent=aur_price_reminder_send($store,$case,[$target],true,true,
      static function($to,$subject,$body)use(&$count):bool {
          $count++;
          price_check($to==='sales@kombi-med.com','individual recipient routing wrong');
          price_check(str_contains($body,'DS.Surg 150'),'individual item text wrong');
          return true;
      });
    price_check($count===1 && $sent['processed_this_request']===1,'approved selected send did not execute once');
    $translateAfterSendDenied=false;
    try {aur_price_reminder_kombimed_translate($store,$case,$target);}
    catch(InvalidArgumentException $e){$translateAfterSendDenied=$e->getMessage()==='reminder_already_attempted_immutable';}
    price_check($translateAfterSendDenied,'already attempted reminder allowed to be altered');

    price_check(aur_price_reminder_read($store,$case)['messages'][0]['status']==='ACCEPTED_BY_MAIL_TRANSPORT',
      'sent record not persisted');
    $again=aur_price_reminder_send($store,$case,[$target],true,true,
      static function()use(&$count):bool {$count++;return true;});
    price_check($count===1 && $again['processed_this_request']===0,'duplicate send not suppressed');
    $other=$made['messages'][1]['recipient_token'];
    $uncertain=aur_price_reminder_send($store,$case,[$other],true,true,static fn()=>false);
    price_check($uncertain['messages'][1]['status']==='TRANSPORT_FAILED_OR_UNCONFIRMED',
      'uncertain result not retained');
    $retry=aur_price_reminder_send($store,$case,[$other],true,true,
      static function()use(&$count):bool{$count++;return true;});
    price_check($retry['processed_this_request']===0 && $count===1,'uncertain send retried unexpectedly');
    price_check(aur_vendor_rfq_read($store,$case,$draftId)['messages'][0]['status']==='ACCEPTED_BY_MAIL_TRANSPORT',
      'original supplier outbox was modified');
    echo "AURENOEVA_INDIVIDUAL_PRICE_REMINDERS=PASS\n";
}finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
      FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
