<?php
declare(strict_types=1);
require __DIR__.'/../deployment/site/procurement/public_intake.php';
require __DIR__.'/../deployment/site/procurement/supplier_intelligence.php';
require __DIR__.'/../deployment/site/procurement/supplier_rfq.php';
require __DIR__.'/../deployment/site/procurement/supplier_replies.php';
function wp09_assert(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$store=sys_get_temp_dir().'/aur-wp09-'.bin2hex(random_bytes(6));
if(!mkdir($store,0700,true))throw new RuntimeException('test_store_create_failed');
try {
    $contract=[
      'schema'=>'aurenoeva-requirement-contract/1.0','source'=>'PUBLIC_WEBSITE',
      'reference'=>'AUR-20261027-120000-TEST','contract_id'=>'AUR-REQ-TEST',
      'idempotency_key'=>'aurenoeva:'.hash('sha256','wp09-controlled-test'),
      'customer'=>['organisation_name'=>'Example GmbH','contact_name'=>'Example Buyer',
                    'business_email'=>'buyer@example.test'],
      'requirement'=>['raw_identifier'=>'Siemens 6ES7','description'=>'Deliver to site',
                      'delivery_location'=>'','condition'=>'new'],
      'selected_products'=>[
        ['canonical_id'=>'AUR-P-'.str_repeat('A',20),'manufacturer'=>'Siemens',
          'model'=>'6ES7-123','quantity'=>3,'source_ref'=>'https://example.test/siemens'],
        ['canonical_id'=>'AUR-P-'.str_repeat('B',20),'manufacturer'=>'Festo',
          'model'=>'VUVS-001','quantity'=>17,'source_ref'=>'https://example.test/festo']
      ]
    ];
    $case=aur_public_case_capture($store,$contract);
    wp09_assert($case['country']==='ZZ','destination must stay unknown, no inferred clearance');
    wp09_assert($case['destination_review_required']===true,'unverified destination requires review');
    wp09_assert(($case['buyer']['email']??'')==='buyer@example.test','buyer contact lost');
    wp09_assert(($case['source']??'')==='PUBLIC_WEBSITE','public source missing');
    wp09_assert($case['line_count']===2,'separate product lines lost');
    $lines=$case['payload']['sourcing_lines'];
    wp09_assert($lines[0]['quantity']===3 && $lines[1]['quantity']===17,'individual quantities lost');
    wp09_assert($lines[0]['canonical_part_id']==='AUR-P-'.str_repeat('A',20),'canonical id lost');
    wp09_assert($lines[0]['rfq_status']==='NOT_SENT','unexpected supplier dispatch');
    wp09_assert(is_file($store.'/cases/'.$case['reference'].'.json'),'private case file absent');
    wp09_assert(aur_case_read($store,$case['reference'])['line_count']===2,'private case readback failed');
    $draft=aur_rfq_draft($case);
    wp09_assert(count($draft['lines'])===2 && $draft['lines'][0]['quantity']===3
        && $draft['lines'][1]['quantity']===17,'existing RFQ engine incompatible');
    $intel=aur_supplier_preview($case);
    wp09_assert(count($intel['rows'])===2 && $intel['outreach_sent']===false,
        'supplier research incompatible or external outreach implied');
    // Repeated same enquiry must use same case without creating duplicate sends.
    $repeat=$contract;$repeat['reference']='AUR-NEW-PUBLIC-REF';
    $repeat['contract_id']='AUR-NEW-CONTRACT-REF';
    wp09_assert(aur_public_case_capture($store,$repeat)['reference']===$case['reference'],
        'idempotent repeat created a second case');
    wp09_assert(count(glob($store.'/cases/*.json'))===1,'duplicate case files');
    $invalid=$contract;$invalid['idempotency_key']='aurenoeva:'.hash('sha256','invalid');
    $invalid['selected_products'][1]['quantity']=0;
    try{aur_public_case_capture($store,$invalid);throw new RuntimeException('accepted invalid quantity');}
    catch(InvalidArgumentException $expected){}
    $free=$contract;$free['idempotency_key']='aurenoeva:'.hash('sha256','free');
    $free['selected_products']=[];$free['requirement']['description']='Need unusual pump assembly';
    $freeCase=aur_public_case_capture($store,$free);
    wp09_assert($freeCase['line_count']===1 &&
        $freeCase['payload']['sourcing_lines'][0]['quantity']===null,'description-only RFQ failed');
    $de=$contract;$de['idempotency_key']='aurenoeva:'.hash('sha256','de');
    $de['requirement']['delivery_location']='DE';
    $deCase=aur_public_case_capture($store,$de);
    wp09_assert($deCase['country']==='DE' && !$deCase['destination_review_required'],
        'ISO country lost');
    wp09_assert(aur_vendor_rfq_primary($store,$case)===null,'test unexpectedly sent supplier RFQ');
    echo "AURENOEVA_WP09_PUBLIC_PRIVATE_INTAKE=PASS\n";
} finally {
    // This fixture is private test data only.
    foreach(glob($store.'/cases/*')?:[] as $file)@unlink($file);
    @rmdir($store.'/cases');@unlink($store.'/case.lock');@rmdir($store);
}
