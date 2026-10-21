<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_intelligence.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_discovery.php';
$root=sys_get_temp_dir().'/aurenoeva-qa-'.bin2hex(random_bytes(6));
mkdir($root.'/public_html',0700,true);
// Simulate a web server which supplies an unrelated document root.
// The procurement script's own public_html location must remain authoritative.
$_SERVER['DOCUMENT_ROOT']=$root.'/other_document_root';
mkdir($_SERVER['DOCUMENT_ROOT'],0700,true);
function check(bool $condition,string $why):void {if(!$condition)throw new RuntimeException($why);}
function csvFile(string $dir,string $name):string {
  $file="$dir/$name";
  file_put_contents($file,"No.,Equipment description,Qty.,Selection\n1,Mindray WATO EX-35,2,Requested\n,Alternative ventilator,,Alternative\n2,1.5T MRI scanner,1,Requested\n");
  return $file;
}
define('AUR_ACTIVATION_TEST_LIBRARY',true);
require dirname(__DIR__).'/deployment/site/procurement/activate.php';
try {
    $store=aur_store_root($root.'/public_html');
    // Prove default resolution uses the deployed script path, not DOCUMENT_ROOT.
    $isolated=$root.'/public_html/procurement';
    mkdir($isolated,0700,true);
    copy(dirname(__DIR__).'/deployment/site/procurement/private_cases.php',$isolated.'/private_cases.php');
    $probe=$root.'/credential_root_probe.php';
    file_put_contents($probe,'<?php $_SERVER["DOCUMENT_ROOT"]='.var_export($_SERVER['DOCUMENT_ROOT'],true).'; require '.var_export($isolated.'/private_cases.php',true).'; echo aur_store_root();');
    $probeOutput=[];$probeExit=1;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe).' 2>&1',$probeOutput,$probeExit);
    check($probeExit===0 && trim(implode("\\n",$probeOutput))===$store,'CLI/web private root drift');
    $activationToken='AURENOEVA-TEST-CODE-ONLY-'.str_repeat('x',40);
    file_put_contents(dirname($store).'/aurenoeva-activation-token.txt',$activationToken);
    $signed=aur_setup_form_token($store,1761039000);
    check(is_string($signed) && aur_setup_verify_form_token($store,$signed,1761039050), 'stateless signed activation token rejected');
    check(!aur_setup_verify_form_token($store,$signed,1761040901), 'expired activation form accepted');
    check(!aur_setup_verify_form_token($store,$signed.'modified',1761039050), 'tampered activation form accepted');
    check(!aur_setup_verify_form_token($store,'',1761039050), 'missing activation token accepted');
    // Simulate Hostinger PHP requests without a persistent session:
    // the signed form survives independent requests, but never exposes the owner's secret.
    check(!str_contains($signed,$activationToken),'private owner code exposed in form');
    check(aur_setup_apply($store,'invalid-token','VeryStrongTestPassword123!','VeryStrongTestPassword123!')==='invalid_activation_code','activation accepted incorrect token');
    check(!is_file($store.'/admin-password.hash'),'credential unexpectedly written');
    check(aur_setup_apply($store,$activationToken,'VeryStrongTestPassword123!','different')==='password_invalid_or_mismatched','mismatch accepted');
    check(aur_setup_apply($store,$activationToken,'VeryStrongTestPassword123!','VeryStrongTestPassword123!')==='activated','valid activation denied');
    check(password_verify('VeryStrongTestPassword123!',trim((string)file_get_contents($store.'/admin-password.hash'))),'hash verification failed');
    check(!file_exists(dirname($store).'/aurenoeva-activation-token.txt'),'one-time secret not consumed');
    check(aur_setup_apply($store,$activationToken,'AnotherStrongPassword123!','AnotherStrongPassword123!')==='already_activated','re-activation not blocked');
    // The user's earlier setup instructions also placed the code inside
    // .aurenoeva-private/activation-token.txt; preserve that secure route.
    $oldRoot=$root.'/legacy/public_html';
    mkdir($oldRoot,0700,true);
    $oldStore=aur_store_root($oldRoot);
    $oldCode='LEGACY-HOSTINGER-PRIVATE-'.str_repeat('Z',44);
    file_put_contents($oldStore.'/activation-token.txt',$oldCode);
    $oldForm=aur_setup_form_token($oldStore,1761039000);
    check(is_string($oldForm) && aur_setup_verify_form_token($oldStore,$oldForm,1761039060),'legacy private activation file not accepted');
    check(aur_setup_apply($oldStore,$oldCode,'AnotherStrongPassword123!','AnotherStrongPassword123!')==='activated','legacy private activation cannot finish');
    check(!is_file($oldStore.'/activation-token.txt'),'legacy private token was not consumed');
    echo "AURENOEVA_BROWSER_ACTIVATION_PRIVATE=PASS\n";
    $src=csvFile($root,'test.csv');
    $one=aur_case_import($store,$src,'UZ','original.csv');
    check($one['ok']===true && $one['line_count']===3,'CSV item count');
    check($one['requirements']===2 && $one['alternatives']===1,'alternative detection');
    $intel=aur_supplier_save($store,$one);
    check($intel['summary']['equipment_entries']===3,'supplier research omitted case alternatives');
    check($intel['summary']['entries_with_oem_route']===1,'supplier maker matching incorrect');
    check($intel['summary']['supplier_quotes_confirmed']===0 && $intel['outreach_sent']===false,'fake supplier offer or email send');
    check(count($intel['rows'][0]['sources'])===2, 'local WATO lead not included');
    check($intel['rows'][0]['sources'][1]['role']==='LOCAL_MARKET_PRODUCT_LISTING_UNVERIFIED_STOCK','unverified supplier lead incorrectly qualified');
    check(str_contains($intel['rows'][0]['sources'][0]['note'],'Vet'),'WATO veterinary mismatch safety warning missing');
    check($intel['rows'][1]['status']==='IDENTITY_RESEARCH_REQUIRED','alternative incorrectly converted to model');
    check(str_contains($intel['rows'][2]['search_url'],'worldwide') && !str_contains($intel['rows'][2]['search_url'],'Uzbekistan'),'global fallback discovery query missing');
    $intelAgain=aur_supplier_save($store,$one);
    check($intelAgain['compiled_at']===$intel['compiled_at'],'supplier evidence write was not idempotent');
    check(aur_supplier_read($store,$one['reference'],$one['source_sha256'])!==null,'supplier readback failed');
    $rejected=false;
    try { aur_supplier_read($store,$one['reference'],'wrong-sha'); } catch (RuntimeException $e) { $rejected=true; }
    check($rejected,'supplier research mismatched case sha was accepted');
    // Global discovery consumes public search-result evidence, not RFQ approval.
    $queries=aur_supplier_global_queries('Mindray WATO EX-35',aur_supplier_match('Mindray WATO EX-35'));
    check(count($queries)===5 && !str_contains($queries['Worldwide'],'Uzbekistan'),'geographical restriction was retained');
    $regional=aur_supplier_global_search_links('Mindray WATO EX-35');
    check(isset($regional['Europe'],$regional['Asia-Pacific'],$regional['North America']),'worldwide regions absent');
    $rss='<?xml version="1.0"?><rss version="2.0"><channel><title>Search</title>'
        .'<item><title>Worldwide WATO medical equipment seller</title>'
        .'<link>https://example-medical.com/equipment/wato</link>'
        .'<description>Public product listing; availability not verified</description></item>'
        .'</channel></rss>';
    $batch=aur_supplier_discover_batch($store,$one,2,static fn(string $q): string=>$rss);
    check($batch['processed']===2 && $batch['new_unverified_links']===2,'global search did not persist individual leads');
    check($batch['outreach_sent']===false,'global discovery falsely sent supplier outreach');
    $enriched=aur_supplier_read($store,$one['reference'],$one['source_sha256']);
    $public=$enriched['global_discovery']['rows'][(string)$intel['rows'][0]['source_row']]['leads'][0]??[];
    check(($public['role']??'')==='PUBLIC_SEARCH_RESULT_UNVERIFIED','public search result wrongly qualified');
    check(($public['quotation']??'')==='NOT_RECEIVED','discovery incorrectly registered quotation');
    $repeat=aur_supplier_discover_batch($store,$one,2,static fn(string $q): string=>$rss);
    check($repeat['processed']===1,'same-day discovery should skip already checked entries');
    check(aur_supplier_discovery_stats($enriched)['links']===2,'discovery result not present in case readback');
    check(count(aur_supplier_feed_parse('<?xml version="1.0"?><rss><channel>'
        .'<item><title>Blocked search</title><link>https://www.bing.com/result</link></item>'
        .'<item><title>Legitimate result</title><link>https://suppliers.example.org/a</link></item>'
        .'</channel></rss>'))===1,'search-engine self-links were not removed');
    check(aur_supplier_match('Anthos A5 Continental dental unit')['name']==='Anthos / Cefla','Anthos OEM match missing');
    check(aur_supplier_match('Samsung Z20 ultrasound system')['name']==='Samsung Medison','Samsung exact model match missing');
    check(aur_supplier_match('Skema 8 dental unit')['name']==='Castellini / Cefla','Castellini OEM match missing');
    check(aur_supplier_match('High pressure generic steam sterilizer')===null,'generic device incorrectly exact-matched');
    echo "AURENOEVA_SUPPLIER_INTELLIGENCE_PRIVATE=PASS\\n";

    $twice=aur_case_import($store,$src,'UZ','original.csv');
    check($twice['reference']===$one['reference'],'duplicate not detected');
    $de=aur_case_import($store,$src,'DE','second.csv');
    check($de['country']==='DE' && $de['reference']!==$one['reference'],'country scoped identity');
    check(count(aur_case_list($store)['cases'])===2,'case listing did not deduplicate');
    check($one['payload']['supplier_contact_conducted']===false,'incorrect supplier send');
    check($one['payload']['legal_status']==='REVIEW_REQUIRED','incorrect legal clearance');
    $draft=aur_rfq_draft($one);
    check($draft['send_state']==='DRAFT_NOT_SENT' && $draft['send_allowed']===false,'RFQ must not be sent');
    check($draft['compliance_status']==='REVIEW_REQUIRED' && count($draft['lines'])===3,'RFQ draft must preserve source lines');
    check($draft['lines'][1]['option']==='ALTERNATIVE','RFQ alternative grouping lost');
    $rfqText=aur_rfq_text($draft);
    check(str_contains($rfqText,'AUR-UZ-') && str_contains($rfqText,'Mindray WATO EX-35'),'RFQ source evidence lost');
    check(str_contains($rfqText,'disclosed sourcing intermediary') && str_contains($rfqText,'NOT SENT'),'RFQ legal role/hold absent');
    check(!str_contains($rfqText,'price to customer'),'RFQ must not disclose markup');

    check(is_file($store.'/uploads/'.$one['reference'].'.csv'),'original evidence absent');
    check(!str_starts_with(realpath($store),realpath($root.'/public_html')),'unsafe private directory');

    if (class_exists('ZipArchive') && function_exists('simplexml_load_string')) {
        $file=$root.'/sample.xlsx';$z=new ZipArchive();
        check($z->open($file, ZipArchive::CREATE)===true,'cannot create workbook fixture');
        $z->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml','<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>No.</t></si><si><t>Equipment description</t></si><si><t>Qty.</t></si><si><t>MRI scanner</t></si><si><t>Alternative MRI</t></si></sst>');
        $z->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row><row r="2"><c r="A2"><v>1</v></c><c r="B2" t="s"><v>3</v></c><c r="C2"><v>1</v></c></row><row r="3"><c r="B3" t="s"><v>4</v></c></row></sheetData></worksheet>');
        $z->close();
        $xl=aur_case_import($store,$file,'UZ','customer.xlsx');
        check($xl['line_count']===2 && $xl['alternatives']===1,'XLSX alternative parsing failed');
    } else throw new RuntimeException('Required ZIP and SimpleXML extensions unavailable');
    echo "AURENOEVA_NATIVE_PHP_IMPORT=PASS\nAURENOEVA_PRIVATE_STORAGE=PASS\nAURENOEVA_COUNTRY_DUPLICATE=PASS\n";
} finally {
    // Test fixtures only, never production files.
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item){$item->isDir()?@rmdir((string)$item):@unlink((string)$item);}
    @rmdir($root);
}
