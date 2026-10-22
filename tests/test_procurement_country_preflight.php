<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/embargo_screen.php';
function check(bool $ok, string $message): void {
    if(!$ok) throw new RuntimeException($message);
}
$store=sys_get_temp_dir().'/aur-country-preflight-'.bin2hex(random_bytes(6));
mkdir($store.'/cases',0700,true);
try {
    $ref='AUR-UZ-1234567890ABCDEF';
    $sourceHash=hash('sha256','unaltered-original-customer-file');
    $original=[
        'reference'=>$ref,'country'=>'UZ','source_sha256'=>$sourceHash,
        'line_count'=>1,'payload'=>[
            'enquiry_country'=>'UZ','delivery_country'=>'UZ',
            'sourcing_lines'=>[
                ['source_row'=>2,'customer_item_number'=>1,
                 'description_original'=>'Laboratory instrument', 'quantity'=>2]
            ]
        ]
    ];
    $file=$store.'/cases/'.$ref.'.json';
    file_put_contents($file,json_encode($original,JSON_THROW_ON_ERROR));
    $screened=aur_embargo_record($store,$original,'UZ');
    check(($screened['country_preflight']['status']??'')==='PRELIMINARY_COUNTRY_CHECK_RECORDED',
        'Uzbekistan country triage missing');
    check(($screened['country_preflight']['legal_clearance']??true)===false,
        'Preliminary result must not claim sanctions clearance');
    check(($screened['country_preflight']['source_checked_live']??true)===false,
        'Offline watchlist must not pretend an authoritative live feed was checked');
    aur_embargo_assert_dispatch_ready($screened);
    $same=aur_embargo_record($store,$screened,'UZ');
    check(count($same['country_preflight_history'])===1,
        'Repeated case open must not add redundant audit events');
    $russia=aur_embargo_record($store,$screened,'RU');
    check($russia['country_preflight']['enhanced_review']===true,
        'Enhanced country restrictions were not flagged');
    check($russia['payload']['delivery_country']==='RU',
        'Corrected actual delivery country not reflected in case payload');
    check($russia['payload']['enquiry_country']==='UZ',
        'Original upload-country provenance was overwritten');
    check($russia['reference']===$ref && $russia['source_sha256']===$sourceHash,
        'Changing destination damaged original case identity');
    $blocked=false;
    try {aur_embargo_assert_dispatch_ready($russia);}
    catch(InvalidArgumentException $e){$blocked=$e->getMessage()==='destination_requires_sanctions_review';}
    check($blocked,'Flagged country must not be silently sent without further review');
    $restored=aur_embargo_record($store,$russia,'UZ');
    check($restored['country_preflight']['enhanced_review']===false,
        'Re-screen on changing destination back did not execute');
    check(count($restored['country_preflight_history'])===3,
        'Destination review audit history missing');
    $invalid=false;
    try{aur_embargo_record($store,$restored,'ZZ');}
    catch(InvalidArgumentException $e){$invalid=true;}
    check($invalid,'Unrecognised ISO country was accepted');
    check(aur_case_read($store,$ref)['country']==='UZ',
        'An invalid update damaged the saved case');

    $index=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/index.php');
    $rfq=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php');
    check(str_contains($index,'name="update_destination_country"')
        && str_contains($index,'aur_embargo_record($aurStore')
        && str_contains($index,'id="destination-screen"'),
        'Website RFQ upload/reopen must show and persist country preflight');
    check(str_contains($rfq,'aur_embargo_assert_dispatch_ready($case)')
        && str_contains($rfq,'destination_changed_redraft_required'),
        'Supplier dispatch is not tied to the screened destination');
    echo "AURENOEVA_RFQ_COUNTRY_PREFLIGHT=PASS\n";
} finally {
    foreach(glob($store.'/cases/*')?:[] as $f) @unlink($f);
    @unlink($store.'/case.lock');
    @rmdir($store.'/cases');
    @rmdir($store);
}
