<?php
declare(strict_types=1);
/** Synthetic WP09 end-to-end communication verification. NO external mail. */
require __DIR__.'/../deployment/site/procurement/public_intake.php';
require __DIR__.'/../deployment/site/procurement/embargo_screen.php';
require __DIR__.'/../deployment/site/procurement/cycle_verification.php';
function wp09check(bool $v,string $msg):void {if(!$v)throw new RuntimeException($msg);}
$store=sys_get_temp_dir().'/aur-cycle-'.bin2hex(random_bytes(7));
mkdir($store,0700,true);
try {
    $c=[
        'schema'=>'aurenoeva-requirement-contract/1.0','source'=>'PUBLIC_WEBSITE',
        'reference'=>'AUR-TEST-BUYER','contract_id'=>'TEST-ONLY',
        'idempotency_key'=>'aurenoeva:'.hash('sha256','synthetic-cycle-'.basename($store)),
        'customer'=>['organisation_name'=>'Test Buyer Ltd','contact_name'=>'Test Contact',
             'business_email'=>'buyer@example.test'],
        'requirement'=>['delivery_location'=>'DE','raw_identifier'=>'TEST-PUMP',
             'description'=>'TEST-PUMP wanted','condition'=>'new'],
        'selected_products'=>[['manufacturer'=>'Test Instruments','part_number'=>'PUMP-100',
             'model'=>'PUMP-100','quantity'=>8,'canonical_id'=>'AUR-P-'.str_repeat('C',20)]]
    ];
    $case=aur_public_case_capture($store,$c);
    $ref=$case['reference'];
    wp09check($case['payload']['sourcing_lines'][0]['quantity']===8,'quantity lost');
    $case=aur_embargo_record($store,$case,'DE');
    $first=aur_wp09_cycle_case($store,$case);
    wp09check($first['stage']==='RFQ_CAPTURED','new RFQ incorrectly verified');
    $id=bin2hex(random_bytes(12));
    $email='supplier@example.test';
    $draft=['case_reference'=>$ref,'case_source_sha256'=>$case['source_sha256'],
        'destination_country'=>'DE','draft_id'=>$id,'status'=>'DRAFT_REVIEW',
        'information_only'=>true,'template_version'=>'pricing-rfq-v3',
        'messages'=>[['to'=>$email,'vendor_name'=>'Test Supplier',
             'subject'=>'AURENOEVA Purchasing | '.$ref,
             'body'=>'Test-only quote request for buyer item 1, quantity 8',
             'status'=>'DRAFT',
             'items'=>[['source_row'=>2,'description'=>'TEST-PUMP']]]]];
    $path=aur_vendor_rfq_path($store,$ref,$id);
    aur_vendor_rfq_write($path,$draft);
    // Actual approval dispatch function executes with an injected transport:
    // proof explicitly says TEST_INJECTED, never qualifies as a real cycle.
    $sent=aur_vendor_rfq_dispatch($store,$case,$id,true,
        static fn(string $to,string $subject,string $body):bool=>$to==='supplier@example.test');
    wp09check($sent['messages'][0]['status']==='ACCEPTED_BY_MAIL_TRANSPORT','injected dispatch failed');
    wp09check(!aur_wp09_cycle_case($store,$case)['supplier_smtp_250'],
        'simulated SMTP was mistaken for production proof');
    // Synthetic persisted SMTP-250 fixture only within disposable test store.
    $sent['messages'][0]['transport_result']=[
        'transport'=>'AUTHENTICATED_SMTP','stage'=>'DATA_ACCEPTANCE',
        'code'=>250,'outcome'=>'SMTP_ACCEPTED'];
    aur_vendor_rfq_write($path,$sent);
    wp09check(aur_wp09_cycle_case($store,$case)['stage']==='SUPPLIER_SMTP_ACCEPTED',
        'supplier stage not grounded in SMTP 250');
    $reply="From: supplier@example.test\r\nTo: info@aurenoeva.com\r\n"
        ."Subject: Re: ".$ref."\r\nMessage-ID: <quote@example.test>\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        ."Quotation for buyer item 1: unit price EUR 125.50.\r\n"
        ."Delivery time: 2 weeks.\r\n";
    $event=aur_mail_import($store,101,777,$reply);
    wp09check($event['quote_recorded']===true,'actual MIME-to-quote pipeline failed');
    $quotes=aur_reply_read($store,$case);
    wp09check(count($quotes)===1 && reset($quotes)['unit_price']==='125.50','quote not persisted');
    $third=aur_wp09_cycle_case($store,$case);
    wp09check($third['stage']==='SUPPLIER_QUOTE_VERIFIED','inbox supplier quote stage failed');
    wp09check(!$third['buyer_update_smtp_250'],'unreviewed buyer draft counted as sent');
    $subject='AURENOEVA | Supplier update | '.$c['reference'];
    $buyer=['id'=>bin2hex(random_bytes(16)),'to'=>'buyer@example.test',
            'subject'=>$subject,'status'=>'IN_FLIGHT','created_at'=>gmdate('c'),
            'smtp_code'=>250,'smtp_stage'=>'DATA_ACCEPTANCE','smtp_outcome'=>'SMTP_ACCEPTED'];
    aur_mailcenter_write_local($store,[$buyer]);
    wp09check(aur_wp09_cycle_case($store,$case)['stage']==='SUPPLIER_QUOTE_VERIFIED',
       'in-flight buyer message counted as completed');
    $buyer['status']='SMTP_ACCEPTED';
    aur_mailcenter_write_local($store,[$buyer]);
    $verified=aur_wp09_cycle_case($store,$case);
    wp09check($verified['stage']==='BUYER_UPDATE_SMTP_ACCEPTED','buyer mail confirmation missing');
    wp09check(!$verified['purchase_confirmed'] && !$verified['commercial_transaction_verified'],
       'email-only flow misrepresented as sale');
    $report=aur_wp09_cycle_report($store);
    wp09check($report['summary']['buyer_updates_smtp_250']===1 &&
        $report['summary']['purchases_confirmed']===0,'real cycle report counts wrong');
    // Reject incorrect recipient, wrong case reference and simulated transport.
    foreach(['to'=>'wrong@example.test','subject'=>'Other case','smtp_code'=>421] as $key=>$bad){
        $tampered=$buyer;$tampered[$key]=$bad;
        wp09check(!aur_wp09_buyer_evidence($case,[$tampered],gmdate('c'))['confirmed'],
            'unproven buyer delivery wrongly accepted: '.$key);
    }
    $latest=aur_mail_events($store);
    foreach($latest as &$e)$e['quote_recorded']=false;
    unset($e);
    aur_mail_write_json(aur_mail_dir($store).'/events.json',['events'=>$latest]);
    wp09check(!aur_wp09_cycle_case($store,$case)['supplier_quote_verified'],
        'unverified mailbox quotation counted as genuine');
    echo "AURENOEVA_WP09_E2E_SYNTHETIC_VERIFIER=PASS\n";
} finally {
    $cleanup=static function($dir)use(&$cleanup):void{
        if(!is_dir($dir))return;
        foreach(scandir($dir)?:[] as $entry){
            if($entry==='.'||$entry==='..')continue;
            $path=$dir.'/'.$entry;
            if(is_dir($path)&&!is_link($path))$cleanup($path);else @unlink($path);
        }
        @rmdir($dir);
    };
    $cleanup($store);
}
