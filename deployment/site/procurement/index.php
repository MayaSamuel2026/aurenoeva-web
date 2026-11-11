<?php
declare(strict_types=1);
/**
 * AURENOEVA staff procurement upload. Single app-owned case authority.
 * PHP-native on the existing website host. Store and initial administrator
 * password hash remain outside public_html; no Python or VPS dependency.
 */
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

function aur_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function aur_denied(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo "AURENOEVA private procurement: " . $message;
    exit;
}
require_once __DIR__ . '/private_cases.php';
require_once __DIR__ . '/embargo_screen.php';
require_once __DIR__ . '/supplier_intelligence.php';
require_once __DIR__ . '/supplier_discovery.php';
require_once __DIR__ . '/supplier_routes.php';
require_once __DIR__ . '/supplier_worldwide.php';
require_once __DIR__ . '/supplier_rfq.php';
require_once __DIR__ . '/supplier_reminders.php';
require_once __DIR__ . '/urgent_supplier_drafts.php';
require_once __DIR__ . '/researched_supplier_round.php';
require_once __DIR__ . '/nov11_supplier_batch.php';
require_once __DIR__ . '/nov11_supplier_followups.php';
require_once __DIR__ . '/supplier_replies.php';
require_once __DIR__ . '/supplier_quality.php';
require_once __DIR__ . '/supplier_inbox.php';
require_once __DIR__ . '/cycle_verification.php';
require_once __DIR__ . '/mail_center.php';
require_once __DIR__ . '/staff_auth.php';
require_once __DIR__ . '/deepfetcher_source_inbox.php';
require_once __DIR__ . '/commercial_intelligence.php';
require_once __DIR__ . '/quote_comparison.php';
// The supplier dashboard uses one compact JSON request instead of a large
// multipart/array form. Same signed-cookie authentication and CSRF validation.
if (($_SERVER['REQUEST_METHOD']??'')==='POST' &&
    strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]))==='application/json') {
    try{
        $_POST=aur_staff_procurement_json_post((string)file_get_contents('php://input'));
    }catch(Throwable $e){
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'invalid_dashboard_request']);
        exit;
    }
}
try {
    $aurStore = aur_store_root();
} catch (Throwable $e) {
    aur_denied(503, 'private hosting directory unavailable');
}
$passwordHash = is_readable($aurStore.'/admin-password.hash')
    ? trim((string)file_get_contents($aurStore.'/admin-password.hash')) : '';
// One canonical credential file. Never silently create a new administrator on login.
if (!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/D', $passwordHash)) {
    aur_denied(503, 'administrator credentials are unavailable; verify the permanent Hostinger private directory');
}
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!$secure && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    aur_denied(403, 'HTTPS required');
}
// Signed, private-cookie authentication: independent of PHP session persistence.
// SameSite=Lax permits a link from ChatGPT to open an existing signed-in session.
// Every write remains protected by a credential-bound, one-hour HMAC CSRF token.
$authCookie=(string)($_COOKIE[AUR_STAFF_COOKIE]??'');
$isAdmin=aur_staff_cookie_valid($passwordHash,$authCookie);
$csrfContext=aur_staff_csrf_context($isAdmin,$authCookie);
$csrf=aur_staff_csrf_issue($passwordHash,$csrfContext);
if ($_SERVER['REQUEST_METHOD']==='POST' &&
    !aur_staff_csrf_valid($passwordHash,$csrfContext,(string)($_POST['csrf']??''))) {
    aur_denied(403,'Form expired or invalid. Reload the page and try again.');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['logout'])) {
    if (!$isAdmin) aur_denied(401,'authentication required');
    aur_staff_cookie_send('',time()-3600);
    header('Location: /procurement/',true,303);
    exit;
}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['password']) && !$isAdmin) {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $rateFile=$aurStore.'/login-attempts-'.substr(hash('sha256',$ip),0,24).'.json';
    $record=is_file($rateFile)?json_decode((string)@file_get_contents($rateFile),true):null;
    if (!is_array($record)||time()-(int)($record['start']??0)>900) $record=['start'=>time(),'fails'=>0];
    if ((int)$record['fails']>=8) aur_denied(429,'too many attempts, retry later');
    if (password_verify((string)$_POST['password'],$passwordHash)) {
        @unlink($rateFile);
        aur_staff_cookie_send(aur_staff_cookie_issue($passwordHash),time()+AUR_STAFF_TTL);
        header('Location: /procurement/',true,303);
        exit;
    }
    ++$record['fails'];
    @file_put_contents($rateFile,json_encode($record),LOCK_EX);
    @chmod($rateFile,0600);
    $error='Incorrect password.';
}
if (!$isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['password'])) {
    aur_denied(401,'authentication required');
}


/* Authenticated read-only source for NOEVA Command.
 * Uses this exact native staff cookie and never publishes unprotected records. */
if(isset($_GET['command_export'])){
    if(!$isAdmin){
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo '{"ok":false,"error":"authentication_required"}';
        exit;
    }
    define('AUR_COMMAND_EXPORT_AUTHORIZED',true);
    require_once __DIR__.'/command_export.php';
    aur_command_export($aurStore,(string)$_GET['command_export'],$_GET);
}


/* Original source uploads remain private. Authorised staff can retrieve the
 * exact hash-verified CSV/XLSX bytes by canonical case reference. */
if(isset($_GET['original_file'])){
    if(!$isAdmin)aur_denied(401,'administrator login required');
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')aur_denied(405,'GET required');
    try{
        $file=aur_case_original_file($aurStore,(string)$_GET['original_file']);
    }catch(Throwable $e){
        error_log('AURENOEVA original upload read: '.get_class($e));
        aur_denied(404,'Original source file unavailable');
    }
    header('Content-Type: '.$file['type']);
    header('Content-Length: '.(int)$file['size']);
    header('Content-Disposition: attachment; filename="original-enquiry.'.strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION)).'"; filename*=UTF-8'.chr(39).chr(39).rawurlencode((string)$file['name']));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile((string)$file['path']);exit;
}

/* Private brochure library: original website assets cannot be requested
 * directly (assets/.htaccess). Downloads require the signed admin cookie.
 */
if(isset($_GET['brochure'])){
    if(!$isAdmin)aur_denied(401,'administrator login required');
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')aur_denied(405,'GET required');
    $variant=(string)$_GET['brochure'];
    $name=match($variant){
        'pdf'=>'AURENOEVA_Company_Introduction_A4.pdf',
        'de'=>'AURENOEVA_Firmenbroschuere_Deutsch.pdf',
        'preview','image'=>'AURENOEVA_Company_Introduction.png',
        default=>null
    };
    if($name===null)aur_denied(404,'brochure not found');
    $path=__DIR__.'/assets/'.$name;
    if(!is_file($path)||is_link($path)||!is_readable($path))
        aur_denied(503,'brochure unavailable');
    header('Content-Type: '.(in_array($variant,['pdf','de'],true)?'application/pdf':'image/png'));
    header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.($variant==='preview'?'inline':'attachment').'; filename="'.$name.'"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* Staff Mail Center. Same authenticated signed cookie and CSRF as RFQ actions. */
if(isset($_GET['mail_action'])||isset($_POST['mail_action'])){
    header('Cache-Control: private, no-store, max-age=0');
    if(!$isAdmin){http_response_code(401);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"authentication_required"}';exit;}
    $action=(string)($_POST['mail_action']??$_GET['mail_action']??'');
    $folder=(string)($_GET['folder']??'INBOX');
    $uid=(string)($_GET['uid']??'');
    try{
        if($action==='download_attachment'&&$_SERVER['REQUEST_METHOD']==='GET'){
            $attachment=aur_mailcenter_sent_attachment($aurStore,$uid,(int)($_GET['attachment_index']??-1));
            header('Content-Type: '.$attachment['type']);
            header('Content-Length: '.$attachment['size']);
            header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8'.chr(39).chr(39).rawurlencode($attachment['name']));
            header('X-Content-Type-Options: nosniff');
            readfile($attachment['path']);exit;
        }
        if($action==='download'&&$_SERVER['REQUEST_METHOD']==='GET'){
            $source=aur_mailcenter_source($folder,aur_mailcenter_uid($uid));
            header('Content-Type: message/rfc822');
            header('Content-Disposition: attachment; filename="aurenoeva-message-'.(int)$uid.'.eml"');
            header('X-Content-Type-Options: nosniff');
            echo $source;exit;
        }
        if($action==='send'&&$_SERVER['REQUEST_METHOD']==='POST'){
            $response=aur_mailcenter_send($aurStore,$_POST,(array)($_FILES['attachments']??[]));
        }elseif($action==='list'&&$_SERVER['REQUEST_METHOD']==='GET'){
            $response=aur_mailcenter_list($aurStore,$folder,(int)($_GET['page']??1));
        }elseif($action==='read'&&$_SERVER['REQUEST_METHOD']==='GET'){
            $response=aur_mailcenter_read($aurStore,$folder,$uid);
        }else{throw new InvalidArgumentException('invalid_mail_action');}
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }catch(Throwable $e){
        error_log('AURENOEVA Mail Center: '.get_class($e));
        http_response_code($e instanceof InvalidArgumentException?422:503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'mailbox_operation_unavailable']);
        exit;
    }
}

/* Private, read-only commercial intelligence endpoint; never cached or public. */
if (isset($_GET['intelligence'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    if (!$isAdmin || ($_SERVER['REQUEST_METHOD']??'')!=='GET') {
        http_response_code(401);echo '{"ok":false,"error":"authentication_required"}';exit;
    }
    try { echo json_encode(aur_ci_report($aurStore),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }
    catch (Throwable $e) {error_log('AURENOEVA commercial intelligence: '.get_class($e));
        http_response_code(503);echo '{"ok":false,"error":"intelligence_unavailable"}';}
    exit;
}

/* Live readback: private, authenticated, read-only persisted RFQ ledger.
 * The browser must use this to verify completion; AJAX response counters do
 * not replace the original case ledger or supplier evidence.
 */
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['rfq_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    if(!$isAdmin) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'authentication_required']);
        exit;
    }
    try {
        $caseRef=trim((string)($_GET['case_ref']??''));
        $draftId=trim((string)($_GET['draft']??''));
        $case=aur_case_read($aurStore,$caseRef);
        $draft=aur_vendor_rfq_read($aurStore,$case,$draftId);
        $progress=aur_vendor_rfq_status_summary($draft);
        $progress['recovery_remaining']=aur_vendor_rfq_recoverable_count($draft);
        $progress=array_merge($progress,aur_vendor_rfq_delivery_breakdown($draft));
        $recipients=[];
        foreach(($draft['messages']??[]) as $i=>$message) {
            if(!is_array($message))continue;
            $recipients[]=[
                'index'=>(int)$i,
                'supplier'=>(string)($message['vendor_name']??$message['to']??'Supplier'),
                'status'=>(string)($message['status']??'UNKNOWN'),
                'smtp_accepted'=>(($message['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT')
            ];
        }
        echo json_encode(['ok'=>true,'case_reference'=>$case['reference'],
            'draft_id'=>$draft['draft_id'],'status'=>$draft['status'],
            'progress'=>$progress,'recipients'=>$recipients,
            'last_batch'=>$draft['last_batch']??null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        exit;
    } catch(Throwable $e) {
        error_log('AURENOEVA RFQ readback: '.get_class($e));
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'rfq_readback_unavailable']);
        exit;
    }
}

/* Signed-in CSRF-protected inbox synchronization. Never sends supplier emails. */
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['sync_supplier_inbox'])){
    if(!$isAdmin)aur_denied(401,'authentication required');
    header('Content-Type: application/json; charset=utf-8');
    try{
        $sync=aur_mail_sync($aurStore,20);
        echo json_encode($sync,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }catch(Throwable $e){
        error_log('AURENOEVA inbox sync: '.$e->getMessage());
        http_response_code(503);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);
    }
    exit;
}

/* RFQ exports are generated only after staff authentication, never public URLs. */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['rfq'])) {
    try {
        $rfqRef=trim((string)$_GET['rfq']);
        $draft=aur_rfq_draft(aur_case_read($aurStore,$rfqRef));
        $body=aur_rfq_text($draft);
    } catch (Throwable $e) {
        aur_denied(404,'RFQ draft unavailable');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$rfqRef.'-ORIGINAL-ENQUIRY.txt"');
    header('Content-Length: '.strlen($body));
    echo $body;
    exit;
}

/* Staff-only private price comparison; records stay anchored to original source rows. */
if (($_SERVER['REQUEST_METHOD']??'')==='POST' &&
    (isset($_POST['save_price_offer'])||isset($_POST['delete_price_offer']))) {
    if(!$isAdmin)aur_denied(401,'authentication required');
    try {
        $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
        if(isset($_POST['delete_price_offer'])) {
            aur_prices_delete($aurStore,$case,trim((string)($_POST['offer_id']??'')));
            $flag='price_deleted';
        } else {
            aur_prices_record($aurStore,$case,$_POST);
            $flag='price_saved';
        }
        header('Location: /procurement/?case='.rawurlencode((string)$case['reference']).'&'.$flag.'=1#aur-quote-comparison',true,303);
        exit;
    } catch(Throwable $e) {
        error_log('AURENOEVA price comparison: '.get_class($e));
        aur_denied(422,'Price offer could not be saved; check the original row, numeric price, currency and evidence reference.');
    }
}

$result = null;
$supplierDiscoveryMessage = null;
$countryScreenError = null;
$catalogueSyncToken = null;
$catalogueSnapshotCount = null;
$catalogueSnapshotSectors = [];
$catalogueSnapshotCategories = [];
$catalogueSnapshotManufacturers = [];
// The website catalogue now has 49 technical categories across seven industries.
// These facet counts are separate from the historical single-sector allocation.
// Keep this taxonomy aligned with the verified catalogue-sync.php allowlist.
$catalogueFacetTaxonomy = [
    'laboratory'=>['Mass spectrometry','Chromatography / HPLC / GC','Spectroscopy','Sample preparation','Balances & weighing','Incubation / ovens / environmental','General laboratory equipment'],
    'test'=>['Oscilloscopes','Spectrum / signal analyzers','Signal sources / generators','Meters & power analyzers','RF / microwave','Calibration equipment','Data acquisition'],
    'medical'=>['Diagnostic equipment','Imaging & visualization','Patient monitoring','Laboratory / pathology','Surgical / procedural','Sterilization & reprocessing','Other medical equipment'],
    'industrial'=>['PLC / control modules','Drives / servo / motion','Motors / gearboxes','Sensors / instrumentation','Pneumatics / hydraulics','Robotics / automation','Machine / process equipment'],
    'energy'=>['Transformers','Switchgear & protection','Generators','Power conversion / inverters','Power quality / monitoring','Turbine / plant components','Storage / auxiliary systems'],
    'marine'=>['Engines & propulsion','Pumps / fluid systems','Electrical & switchgear','Navigation / instrumentation','Deck machinery','HVAC / refrigeration','Safety / auxiliary systems'],
    'aviation'=>['Avionics','Electrical components','Hydraulic / pneumatic','Engine / powerplant components','Landing gear / actuation','Ground support / test equipment','Traceable specialist components'],
];
$catalogueSectorNames = [
    'laboratory'=>'Laboratory','test'=>'Test & Measurement','medical'=>'Medical',
    'industrial'=>'Industrial','energy'=>'Energy','marine'=>'Marine','aviation'=>'Aviation',
    'unclassified'=>'Unclassified / historical',
];
// Match the twelve additional industry choices already published on the homepage.
// These are live all-catalogue discovery searches, NOT independently verified
// technical classifications. No separate counts are claimed until the crawler
// starts emitting evidence-backed facets for these twelve industries.
$catalogueAdditionalIndustries = [
    ['name'=>'Oil, Gas & Petrochemicals','query'=>'oil gas'],
    ['name'=>'Chemicals & Process Industries','query'=>'chemical'],
    ['name'=>'Pharmaceutical & Biotechnology','query'=>'pharma'],
    ['name'=>'Automotive & Electric Mobility','query'=>'automotive'],
    ['name'=>'Rail & Transportation','query'=>'rail'],
    ['name'=>'Food & Beverage','query'=>'food'],
    ['name'=>'Agriculture & Forestry','query'=>'agriculture'],
    ['name'=>'Mining & Heavy Equipment','query'=>'mining'],
    ['name'=>'Construction & Building Technology','query'=>'construction'],
    ['name'=>'Water, Waste & Environmental','query'=>'water'],
    ['name'=>'Electronics & Semiconductors','query'=>'semiconductor'],
    ['name'=>'Logistics & Warehousing','query'=>'logistics'],
];
$catalogueFacetCategories = [];
$catalogueFacetIndustries = [];
foreach ($catalogueFacetTaxonomy as $industry => $categories) {
    $catalogueFacetCategories[$industry] = array_fill_keys($categories, 0);
    $catalogueFacetIndustries[$industry] = 0;
}
$catalogueFacetClassified = 0;
$catalogueFacetMulti = 0;
$catalogueFacetAssignments = 0;
$catalogueSnapshotUpdated = null;
$catalogueVendorTotal = 0;
$catalogueVendorWithContact = 0;
$catalogueVendorProductLinks = 0;
if ($isAdmin) {
    $supplierMaster = aur_worldwide_master_read($aurStore);
    $catalogueVendorTotal = count($supplierMaster['vendors'] ?? []);
    foreach (($supplierMaster['vendors'] ?? []) as $candidate) {
        if(!is_array($candidate))continue;
        if(filter_var((string)($candidate['email']??''),FILTER_VALIDATE_EMAIL))
            $catalogueVendorWithContact++;
        $catalogueVendorProductLinks += count($candidate['catalogue_products'] ?? []);
    }
}
$catalogueSnapshotFile = $aurStore . '/catalogue-public.json';
if ($isAdmin && is_file($catalogueSnapshotFile)) {
    $catalogueSnapshot = json_decode((string)@file_get_contents($catalogueSnapshotFile), true);
    if (is_array($catalogueSnapshot) && isset($catalogueSnapshot['items']) && is_array($catalogueSnapshot['items'])) {
        $catalogueSnapshotCount = count($catalogueSnapshot['items']);
        $catalogueSnapshotUpdated = gmdate('Y-m-d H:i', (int)filemtime($catalogueSnapshotFile)) . ' UTC';
        foreach ($catalogueSnapshot['items'] as $item) {
            $sector = trim((string)($item['sector'] ?? ''));
            if (!in_array($sector, ['laboratory','test','medical','industrial','energy','marine','aviation'], true)) {
                $sector = 'unclassified';
            }
            $catalogueSnapshotSectors[$sector] = ($catalogueSnapshotSectors[$sector] ?? 0) + 1;
            $category = trim((string)($item['category'] ?? ''));
            if ($category === '') $category = 'Category not yet classified';
            $catalogueSnapshotCategories[$sector][$category] =
                ($catalogueSnapshotCategories[$sector][$category] ?? 0) + 1;
            $maker = trim((string)($item['manufacturer'] ?? ''));
            if ($maker !== '') $catalogueSnapshotManufacturers[$maker] = true;
            // One canonical product counts once within each independently supported
            // industry, even when multiple categories or duplicate facets are present.
            $supportedIndustries = [];
            $facets = is_array($item['classifications'] ?? null) ? $item['classifications'] : [];
            foreach ($facets as $facet) {
                if (!is_array($facet)) continue;
                $industry = (string)($facet['industry'] ?? '');
                $technicalCategory = (string)($facet['category'] ?? '');
                if (isset($supportedIndustries[$industry])
                    || !isset($catalogueFacetCategories[$industry][$technicalCategory])
                    || ($facet['method'] ?? '') !== 'technical-facets/1'
                    || ($facet['confidence'] ?? '') !== 'MATCHED_TEXT'
                    || ($facet['evidence_url'] ?? '') !== ($item['source_ref'] ?? '')) continue;
                $supportedIndustries[$industry] = true;
                $catalogueFacetIndustries[$industry]++;
                $catalogueFacetCategories[$industry][$technicalCategory]++;
                $catalogueFacetAssignments++;
            }
            if ($supportedIndustries) $catalogueFacetClassified++;
            if (count($supportedIndustries) > 1) $catalogueFacetMulti++;
        }
    }
}
// Reuse the already-authenticated procurement session for a searchable
// catalogue export. This is the website snapshot, not an assertion about stock.
if (isset($_GET['export_catalogue'])) {
    if (!$isAdmin) aur_denied(403, 'administrator access required');
    if (!isset($catalogueSnapshot['items']) || !is_array($catalogueSnapshot['items']))
        aur_denied(404, 'website catalogue not synchronized');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="AURENOEVA-CATALOGUE-' . gmdate('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $csv = fopen('php://output', 'wb');
    if (!$csv) aur_denied(500, 'CSV output unavailable');
    fputcsv($csv, ['ID','Sector','Category','Manufacturer','Model','Description','Source URL','Last seen']);
    foreach ($catalogueSnapshot['items'] as $item) {
        if (!is_array($item)) continue;
        $cells = [
            (string)($item['canonical_id'] ?? ''), (string)($item['sector'] ?? ''),
            (string)($item['category'] ?? ''), (string)($item['manufacturer'] ?? ''),
            (string)($item['model'] ?? ''), (string)($item['description'] ?? ''),
            (string)($item['source_ref'] ?? ''), (string)($item['last_seen'] ?? ''),
        ];
        // Untrusted page titles must never become Excel formulas.
        foreach ($cells as &$cell) {
            if (preg_match('/^\\s*[=+@-]/u', $cell)) $cell = "'" . $cell;
        }
        unset($cell);
        fputcsv($csv, $cells);
    }
    fclose($csv);
    exit;
}
$aurSupplierAuditMessage=null;
$aurCommercialNotice=null;
$aurDfNotice=null;
if ($isAdmin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['configure_deepfetcher_receiver'])) {
        try {
            aur_df_provision($aurStore,(string)($_POST['df_signing_secret']??''),
                (string)($_POST['df_ack_token']??''));
            header('Location: /procurement/?deepfetcher_configured=1#aur-deepfetcher',true,303);
            exit;
        } catch (Throwable $e) {
            error_log('AURENOEVA DeepFetcher receiver setup: '.get_class($e));
            $aurDfNotice='Credentials were not saved. Use the exact two 64-character hexadecimal values from the destination registration response.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_commercial_outcome'])) {
        try {
            if (($_POST['staff_verified']??'')!=='1') throw new InvalidArgumentException('staff_confirmation_required');
            aur_ci_record_outcome($aurStore,trim((string)($_POST['case_ref']??'')),
                (string)($_POST['outcome']??''),(string)($_POST['evidence_reference']??''));
            header('Location: /procurement/?outcome_saved=1#tab-intelligence',true,303);exit;
        } catch (Throwable $e) {
            error_log('AURENOEVA commercial outcome: '.get_class($e));
            $aurCommercialNotice='Outcome was not saved. Select a valid existing case and enter the retained supporting evidence reference.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consolidate_supplier_history'])) {
        try {
            $historical=aur_worldwide_research_inventory($aurStore,true);
            $aurSupplierAuditMessage='Historical RFQ research reconciled: '
                .(int)$historical['newly_consolidated_supplier_websites']
                .' newly reusable websites; '
                .(int)$historical['global_supplier_master'].' global vendor records total.';
        } catch (Throwable $e) {
            error_log('AURENOEVA supplier history consolidation: '.get_class($e));
            $aurSupplierAuditMessage='Supplier history reconciliation failed. All original research is preserved.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_catalogue_sync'])) {
        $catalogueSyncToken = bin2hex(random_bytes(32));
        $credentialFile = $aurStore . '/catalogue-sync-token.sha256';
        if (file_put_contents($credentialFile, hash('sha256', $catalogueSyncToken) . "\n", LOCK_EX) === false) {
            aur_denied(503, 'catalogue synchronization credential could not be stored');
        }
        @chmod($credentialFile, 0600);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['configure_rfq_smtp'])) {
        try {
            aur_vendor_rfq_store_smtp_password($aurStore,(string)($_POST['smtp_password']??''));
            $ref=trim((string)($_POST['case_ref']??''));
            $draft=trim((string)($_POST['draft_id']??''));
            $location='/procurement/?smtp_configured=1';
            if(preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))
                $location.='&case='.rawurlencode($ref);
            if(preg_match('/^[a-f0-9]{24}$/D',$draft))
                $location.='&draft='.rawurlencode($draft);
            header('Location: '.$location.'#aur-rfq-approval',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA SMTP configuration: '.get_class($e));
            $supplierDiscoveryMessage='Mailbox settings could not be saved. Check the mailbox SMTP password and try again.';
            try {$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}
            catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_supplier_reply'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draftId=trim((string)($_POST['draft_id']??''));
            aur_reply_record($aurStore,$case,$draftId,trim((string)($_POST['supplier_item']??'')),$_POST);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference']).'&draft='.rawurlencode($draftId).'&response_saved=1#supplier-replies',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA supplier reply entry: '.get_class($e));
            $supplierDiscoveryMessage='Supplier quotation not saved. Check the selected sent RFQ, original equipment item, price/currency and source reference.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_unsent_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $count=aur_vendor_rfq_cleanup_unsent($aurStore,$case,true);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&cleaned='.(int)$count.'#supplier-history',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA unused RFQ cleanup: '.get_class($e));
            aur_denied(503,'Unused RFQ cleanup could not be completed. Sent email evidence is preserved.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_rfq'])) {
        // SMTP is tested by THIS authenticated website, using the exact
        // supplier transport, and the diagnostic recipient is always our own
        // mailbox. Testing never triggers a supplier dispatch.
        $ref=trim((string)($_POST['case_ref']??''));
        $returnToReminders=(($_POST['smtp_return']??'')==='reminders'
            && preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref));
        $smtpTestOutcome='error';
        try {
            $testStatus=aur_vendor_rfq_send_smoke_test($aurStore);
            $smtpTestOutcome=aur_vendor_rfq_mail_verified($aurStore)?'passed':'failed';
            if(!$returnToReminders)
                $result=$ref!==''?aur_case_read($aurStore,$ref):aur_case_list($aurStore);
        } catch(Throwable $e) {
            error_log('AURENOEVA SMTP test: '.get_class($e).' '.substr($e->getMessage(),0,100));
            if(!$returnToReminders)
                aur_denied(503,'Internal website SMTP test could not run; no supplier contacted');
        }
        if($returnToReminders) {
            header('Location: /procurement/?case='.rawurlencode($ref)
                .'&smtp_test='.$smtpTestOutcome.'#aur-price-reminders',true,303);
            exit;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan_worldwide'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $run=aur_worldwide_scan_batch($aurStore,$result,1);
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'processed'=>$run['processed'],'new_vendor_pages'=>$run['new_vendor_pages'],
                    'provider_failures'=>$run['provider_failures'],'stats'=>$run['stats']],JSON_THROW_ON_ERROR);
                exit;
            }
            $supplierDiscoveryMessage='Worldwide research updated: '.(int)$run['processed'].' equipment item.';
        }catch(Throwable $e) {
            error_log('AURENOEVA worldwide discovery: '.get_class($e).' '.substr($e->getMessage(),0,120));
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                // Error identifiers contain no secrets, pathnames or customer data.
                echo json_encode(['ok'=>false,'error'=>'scan_unavailable',
                    'reason'=>preg_match('/^(?:catalogue_lock_unavailable|catalogue_case_unavailable|worldwide_write_failed|worldwide_commit_failed|supplier_research_lock_unavailable|supplier_research_save_failed|supplier_research_commit_failed)$/D',$e->getMessage())?$e->getMessage():'hosting_or_research_error']);
                exit;
            }
            aur_denied(503,'worldwide research temporarily unavailable; original enquiry preserved');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_price_reminders'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $prepared=aur_price_reminder_prepare($aurStore,$result);
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference'])
                .'&reminders_prepared=1#aur-price-reminders',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA price reminder preparation: '.get_class($e).' '.substr($e->getMessage(),0,100));
            $supplierDiscoveryMessage='Price reminders were not prepared: '
                .(in_array($e->getMessage(),['no_original_rfq_ledger','no_confirmed_supplier_recipients'],true)
                    ?'No original SMTP-accepted supplier recipients with saved item assignments were found.'
                    :'Review the original saved RFQ and retry. No supplier was contacted.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['translate_price_reminder'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            aur_price_reminder_kombimed_translate($aurStore,$result,
                trim((string)($_POST['translate_price_reminder']??'')));
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference'])
                .'&reminders_language=de#aur-price-reminders',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA KombiMED reminder language: '.get_class($e));
            $supplierDiscoveryMessage='Die deutsche Fassung konnte nicht gespeichert werden. '
                .'Bitte kontrollieren Sie den Entwurf. Es wurde keine E-Mail gesendet.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_price_reminders'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $result=aur_embargo_record($aurStore,$result,(string)($result['country']??''));
            $targets=$_POST['reminder_targets']??[];
            if(!is_array($targets))throw new InvalidArgumentException('invalid_reminder_selection');
            $sent=aur_price_reminder_send($aurStore,$result,$targets,
                ($_POST['manual_review']??'')==='1',
                ($_POST['reply_review_ack']??'')==='1');
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference'])
                .'&reminders_sent='.(int)($sent['processed_this_request']??0).'#aur-price-reminders',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA price reminder dispatch: '.get_class($e).' '.substr($e->getMessage(),0,100));
            $supplierDiscoveryMessage='No price reminder batch was released: '
                .(in_array($e->getMessage(),['rfq_smtp_test_required','rfq_smtp_not_configured'],true)
                    ?'Recheck the authenticated mailbox and perform a new internal SMTP test.'
                    :'Review selected suppliers, prior replies, duplicate quantities and legal release conditions.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_urgent_supplier_signatures'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $revision=aur_urgent_supplier_refresh_signatures($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$revision['draft_id'])
                .'&signatures_updated='.(int)$revision['updated_unsent']
                .'#aur-urgent-supplier-rfqs',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA urgent signature revision: '.get_class($e).' '.substr($e->getMessage(),0,70));
            $supplierDiscoveryMessage='The unsent RFQ signatures could not be refreshed. '
                .'No supplier email was sent; existing sent records remain unchanged.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}
            catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_nov_short_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $revised=aur_nov_short_refresh_unsent($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$revised['draft_id'])
                .'&short_refreshed='.(int)$revised['updated_unsent']
                .'#aur-nov-short-supplier-rfqs',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA supplier tone refresh: '.get_class($e));
            $supplierDiscoveryMessage='Unsent short emails could not be refreshed. No messages were sent or changed.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}
            catch(Throwable $ignored){}
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_stock_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $prepared=aur_stock_listing_prepare($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$prepared['draft_id'])
                .'#aur-rfq-approval',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA stockholder preparation: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='Stockholder drafts could not be saved. No supplier contacted. Check original RFQ item mappings.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}
            catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_nov11_supplier_followups'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draft=aur_nov11_followup_prepare($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$draft['draft_id'])
                .'#aur-rfq-approval',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA 11 November two supplier follow-ups: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='Duo Med and Meditron drafts could not be saved. Nothing was sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_nov11_source_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draft=aur_nov11_supplier_prepare($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$draft['draft_id'])
                .'#aur-rfq-approval',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA 11 November supplier batch: '.get_class($e).' '.substr($e->getMessage(),0,110));
            $supplierDiscoveryMessage='The 11 November supplier round could not be saved. No emails were sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_next_sourcing_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draft=aur_researched_next_20261106_prepare($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$draft['draft_id'])
                .'#aur-rfq-approval',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA 6 November sourcing drafts: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='The new round could not be saved; no enquiries have been sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_followup_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draft=aur_researched_20261105_followup_prepare($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$draft['draft_id'])
                .'#aur-rfq-approval',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA next researched supplier draft: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='The new supplier drafts could not be saved. No enquiries have been sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_researched_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draft=aur_researched_20261105_prepare($aurStore,$case);
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$draft['draft_id'])
                .'#aur-rfq-approval',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA researched supplier draft: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='Research-backed supplier drafts could not be saved; no enquiries were sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_next_stock_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draft=aur_next_stock_prepare($aurStore,$case);
            // Save drafts to the existing RFQ outbox; selection and sending remain separate.
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$draft['draft_id']).'#aur-rfq-approval',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA next supplier draft: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='Next supplier round could not be prepared. No messages were sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_nov_short_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $prepared=aur_nov_short_prepare($aurStore,$case);
            // Private draft import only; sending requires a separate staff approval.
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$prepared['draft_id'])
                .'&short_prepared=1#aur-nov-short-supplier-rfqs',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA short supplier preparation: '.get_class($e).' '.substr($e->getMessage(),0,120));
            $supplierDiscoveryMessage='Short supplier emails could not be saved. Existing records were not changed and no emails were sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}
            catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_urgent_supplier_rfqs'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $prepared=aur_urgent_supplier_prepare($aurStore,$case);
            // Preparation changes the case-owned outbox only. Never send from this action.
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference'])
                .'&draft='.rawurlencode((string)$prepared['draft_id'])
                .'&urgent_prepared=1#aur-urgent-supplier-rfqs',true,303);
            exit;
        }catch(Throwable $e) {
            error_log('AURENOEVA urgent supplier draft preparation: '.get_class($e).' '.substr($e->getMessage(),0,100));
            $supplierDiscoveryMessage='New supplier RFQ drafts could not be saved. '
                .'Check the original buyer item mappings and existing recipient records. '
                .'No emails were sent.';
            try{$result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));}
            catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prepare_vendor_rfqs'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            // The dashboard tolerates a temporarily unavailable shared master.
            // Preparing its selected vendors must follow the identical rule.
            try{$intel=aur_supplier_save($aurStore,$result);}
            catch(Throwable $e) {
                error_log('AURENOEVA draft research read fallback: '.get_class($e));
                $intel=aur_supplier_read($aurStore,(string)$result['reference'],(string)$result['source_sha256']);
                if(!is_array($intel))$intel=aur_supplier_preview($result);
            }
            try{$intel=aur_worldwide_attach_master($aurStore,$intel);}
            catch(Throwable $e){
                error_log('AURENOEVA draft vendor master fallback: '.get_class($e));
            }
            $selected=$_POST['selected_routes']??[];
            $selectedVendors=$_POST['selected_vendors']??[];
            // Compact browser POST avoids Hostinger filtering repeated fields.
            $compact=$_POST['vendor_tokens']??'';
            if(!is_array($selected)||!is_array($selectedVendors)||!is_string($compact))
                throw new InvalidArgumentException('invalid_dashboard_vendor_selection');
            if($compact!=='') {
                if(strlen($compact)>19500 ||
                   !preg_match('/^[a-f0-9]{64}(?:,[a-f0-9]{64}){0,299}$/D',$compact))
                    throw new InvalidArgumentException('invalid_dashboard_vendor_selection');
                $selectedVendors=array_values(array_unique(array_merge(
                    $selectedVendors,explode(',',$compact))));
            }
            $selected=array_values(array_unique(array_merge($selected,
                aur_vendor_rfq_expand_vendors($result,$intel,$selectedVendors))));
            $draft=aur_vendor_rfq_prepare($aurStore,$result,$intel,$selected);
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'draft_id'=>$draft['draft_id'],
                    'vendor_count'=>count($draft['messages']??[]),'status'=>'DRAFT_REVIEW'],
                    JSON_THROW_ON_ERROR);
                exit;
            }
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference']).'&draft='.$draft['draft_id'].'#aur-rfq-approval',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA RFQ draft: '.get_class($e).' '.substr($e->getMessage(),0,120));
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'error'=>'rfq_draft_unavailable',
                    'reason'=>aur_vendor_rfq_safe_error($e)]);
                exit;
            }
            // HTML fallback must retain the case and supplier dashboard,
            // never replace the whole workspace with a plaintext 422 page.
            $supplierDiscoveryMessage='RFQ draft not created ('.aur_vendor_rfq_safe_error($e).'). Your original equipment and all supplier leads remain saved. Re-select the intended suppliers or retry after correcting the error.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_destination_country'])) {
        try {
            $case=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            aur_embargo_record($aurStore,$case,(string)($_POST['destination_country']??''));
            header('Location: /procurement/?case='.rawurlencode((string)$case['reference']).'#destination-screen',true,303);
            exit;
        } catch (Throwable $e) {
            error_log('AURENOEVA country screen update: '.get_class($e));
            $supplierDiscoveryMessage='The selected destination could not be saved; original case data remain unchanged.';
            try { $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??''))); } catch(Throwable $ignored){}
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_vendor_rfqs'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $result=aur_embargo_record($aurStore,$result,(string)($result['country']??''));
            aur_embargo_assert_dispatch_ready($result);
            $draftId=trim((string)($_POST['draft_id']??''));
            $selected=($_POST['selected_indices']??null);
            if($selected!==null&&!is_array($selected))
                throw new InvalidArgumentException('invalid_checked_supplier_list');
            $dispatch=aur_vendor_rfq_dispatch($aurStore,$result,$draftId,
                ($_POST['manual_review']??'')==='1',null,3,$selected);
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'status'=>$dispatch['status'],
                    'progress'=>$dispatch['dispatch_progress']??null],JSON_THROW_ON_ERROR);
                exit;
            }
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference']).'&draft='.rawurlencode($draftId).'&send_review=1#aur-send-progress',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA RFQ dispatch: '.get_class($e).' '.substr($e->getMessage(),0,120));
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'error'=>aur_vendor_rfq_safe_error($e)]);
                exit;
            }
            aur_denied(422,'RFQ dispatch not completed; check saved draft statuses before retrying');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_vendor_rfqs'])) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $result=aur_embargo_record($aurStore,$result,(string)($result['country']??''));
            aur_embargo_assert_dispatch_ready($result);
            $draftId=trim((string)($_POST['draft_id']??''));
            $dispatch=aur_vendor_rfq_recover(
                $aurStore,$result,$draftId,
                ($_POST['manual_review']??'')==='1',
                ($_POST['duplicate_risk_ack']??'')==='1'
            );
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'status'=>$dispatch['status'],
                    'progress'=>$dispatch['dispatch_progress']??null],JSON_THROW_ON_ERROR);
                exit;
            }
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference']).
                '&draft='.rawurlencode($draftId).'#aur-rfq-approval',true,303);
            exit;
        } catch(Throwable $e) {
            error_log('AURENOEVA RFQ recovery: '.get_class($e).' '.
                substr($e->getMessage(),0,120));
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'error'=>aur_vendor_rfq_safe_error($e)]);
                exit;
            }
            aur_denied(422,'RFQ recovery stopped; reload saved draft to review recipient history');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discover_suppliers'])) {
        try {
            $caseRef=trim((string)($_POST['case_ref']??''));
            $result=aur_case_read($aurStore,$caseRef);
            $run=aur_supplier_discover_batch($aurStore,$result,2);
            if (str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'processed'=>(int)$run['processed'],
                    'new_unverified_links'=>(int)$run['new_unverified_links'],
                    'provider_failures'=>(int)$run['provider_failures']],
                    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                exit;
            }
            $supplierDiscoveryMessage='Searched '.(int)$run['processed'].' equipment entries; found '
                .(int)$run['new_unverified_links'].' public links. Provider failures: '
                .(int)$run['provider_failures'].'. No supplier contacted or commercially verified.';
        } catch (Throwable $e) {
            error_log('AURENOEVA supplier discovery: '.get_class($e));
            aur_denied(503,'supplier discovery temporarily unavailable; saved case remains intact');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['customer_file'])) {
        $file = $_FILES['customer_file'];
        $size = (int)($file['size'] ?? 0);
        $country = strtoupper(trim((string)($_POST['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/D', $country)) aur_denied(422, 'country must be two-letter ISO code');
        if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK || $size < 1 || $size > 20000000
            || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) aur_denied(422, 'invalid upload (maximum 20 MB)');
        $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext,['csv','xlsx'],true)) aur_denied(422, 'only CSV/XLSX supported');
        try {
            $result = aur_case_import($aurStore,(string)$file['tmp_name'],$country,(string)$file['name']);
        } catch (Throwable $e) {
            error_log('AURENOEVA case importer failed: '.get_class($e).' '.substr($e->getMessage(),0,100));
            aur_denied(422, 'import unsuccessful: '.(str_contains($e->getMessage(),'xlsx_support')?'XLSX ZIP/XML support not enabled':'verify spreadsheet columns and data'));
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ref=trim((string)($_GET['case'] ?? ''));
        try {
            if($ref!=='')$result=aur_case_read($aurStore,$ref);
            else {
                $list=aur_case_list($aurStore);
                $ref=(string)($list['cases'][0]['reference']??'');
                $result=$ref!==''?aur_case_read($aurStore,$ref):$list;
            }
        } catch (Throwable $e) {
            aur_denied(404,'case not found');
        }
    }
}
// Supplier intelligence is a derived, private view of the same authoritative
// case, automatically refreshed on import and on authenticated case opening.
$supplierResearch=null;
$rfqPreview=null;
$supplierResearchMode='';
if ($isAdmin && is_array($result) && isset($result['reference'])) {
    try {
        // Website guests may leave the destination unspecified. Do not
        // invent a country or mislabel this as a screening-system failure.
        if(($result['country']??'')==='ZZ'){
            $countryScreenError='Destination country not yet supplied. Select the actual country below before approving any supplier RFQs.';
        } else {
            $result=aur_embargo_record($aurStore,$result,(string)($result['country']??''));
        }
    } catch (Throwable $e) {
        error_log('AURENOEVA country screening: '.get_class($e));
        $countryScreenError='Country screening could not be recorded. Supplier requests must not be dispatched until the case can be screened.';
    }
    try {
        $supplierResearch=aur_supplier_save($aurStore,$result);
    } catch (Throwable $e) {
        error_log('AURENOEVA supplier research save: '.get_class($e).' '.substr($e->getMessage(),0,110));
        $supplierResearchMode='Supplier records are temporarily read-only. Existing equipment and known supplier routes remain available.';
        try {
            $supplierResearch=aur_supplier_read($aurStore,(string)$result['reference'],(string)$result['source_sha256']);
        } catch (Throwable $readError) {
            error_log('AURENOEVA supplier research read: '.get_class($readError));
        }
        if (!is_array($supplierResearch)) {
            $supplierResearch=aur_supplier_preview($result);
        }
    }
    if (is_array($supplierResearch)) {
        try {
            $supplierResearch=aur_worldwide_attach_master($aurStore,$supplierResearch);
        } catch (Throwable $e) {
            error_log('AURENOEVA worldwide master read: '.get_class($e));
            $supplierResearchMode='Shared supplier catalogue temporarily unavailable; this case and its local supplier contacts remain accessible.';
        }
    }
    // A customer case is the ONE RFQ. Never generate supplier email versions
    // merely by opening the page. Draft preparation requires an explicit action.
    $aurDraftToOpen=isset($_GET['draft']) && is_string($_GET['draft'])
        ? (string)$_GET['draft'] : '';
    if($aurDraftToOpen==='') {
        try {
            $primary=aur_vendor_rfq_primary($aurStore,$result);
            $aurDraftToOpen=(string)($primary['id']??'');
        } catch(Throwable $e) {
            error_log('AURENOEVA primary RFQ read: '.get_class($e));
        }
    }
    if($aurDraftToOpen!=='') {
        try {
            $rfqPreview=aur_vendor_rfq_read($aurStore,$result,$aurDraftToOpen);
        } catch(Throwable $e) {
            error_log('AURENOEVA RFQ draft read: '.get_class($e));
            $supplierResearchMode='The requested RFQ cannot be opened; the original case is preserved.';
        }
    }
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>AURENOEVA · Procurement administration</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f3f5f4;color:#17221f;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif}
.wrap{max-width:1120px;margin:auto;padding:24px 24px 80px}.top{display:flex;justify-content:space-between;align-items:center;padding:22px 0;border-bottom:1px solid #dce4e0;gap:20px}
.brand{font-weight:800;letter-spacing:.13em;font-size:19px}.sub{color:#5b6863;font-size:12px;letter-spacing:.15em;text-transform:uppercase}
.aur-header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.aur-home-link{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border:1px solid #c5d2cd;border-radius:8px;background:#fff;color:#143f38;font-weight:650;text-decoration:none;white-space:nowrap}
.aur-home-link:hover{background:#e9f2ed;color:#143f38}.aur-home-link:focus-visible{outline:3px solid #95bcb0;outline-offset:2px}
h1{font-size:clamp(32px,5vw,52px);letter-spacing:-.05em;line-height:1.1;margin:46px 0 12px}h2{font-size:22px;margin:0 0 18px;letter-spacing:-.02em}
p{color:#576560}a{color:#154f45}button,.button{background:#143f38;color:white;border:0;padding:12px 20px;border-radius:8px;font-weight:650;cursor:pointer}
button:hover{background:#255d53}.ghost{background:transparent;color:#143f38;border:1px solid #c5d2cd}
.card{background:white;border:1px solid #e0e7e4;border-radius:16px;padding:28px;margin-top:25px}
form.inline{display:flex;flex-wrap:wrap;gap:16px;align-items:end}label{display:grid;gap:7px;font-weight:600;font-size:14px}
input{border:1px solid #c5d2cd;border-radius:8px;padding:12px;font:inherit;background:white;max-width:100%}
input[type=file]{width:390px}.muted{font-size:13px;color:#6b7671}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px}
th,td{text-align:left;vertical-align:top;padding:12px;border-bottom:1px solid #e6ece9}th{color:#64736d;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.pill{display:inline-block;background:#edf4f1;border-radius:20px;padding:4px 10px;font-size:12px;color:#235c50}
.aux-disclosure{border:1px solid #e0e8e3;border-radius:10px;padding:14px 16px;margin:16px 0;background:#fafcfb}
.aux-disclosure>summary{font-weight:700;cursor:pointer;color:#1c4037;padding:2px;list-style-position:inside}
.aux-disclosure[open]>summary{padding-bottom:12px;border-bottom:1px solid #e1e8e4;margin-bottom:14px}
.aux-disclosure .scroll{max-height:480px;overflow:auto}
.aur-workflow-note{background:#eff5f2;border-left:3px solid #256556;padding:12px 15px;border-radius:5px;line-height:1.5}
.aur-mail-preview{padding:12px 0;border-bottom:1px solid #dae4e0}
.aur-mail-preview>summary{font-weight:600;cursor:pointer}
#aur-prepare-rfqs,#aur-send-all-rfqs{font-size:16px;padding:15px 24px}
#supplier-workspace .pill{margin:2px}
.aur-guide{margin:22px 0 14px;padding:22px 24px;border:1px solid #d8e2dd;border-radius:18px;background:#fff}
.aur-guide-head{display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap}
.aur-guide-head h2{margin:0 0 5px}.aur-guide-head p{margin:0 0 14px}
.aur-steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin:12px 0 18px}
.aur-step{display:flex;gap:10px;align-items:center;text-decoration:none;color:#24423a;padding:12px 10px;border:1px solid #dce5e0;border-radius:10px;background:#fafcfb;min-width:0}
.aur-step b{display:grid;place-items:center;flex:none;width:29px;height:29px;border-radius:50%;background:#e4eee8;font-size:13px}
.aur-step span{font-size:13px;font-weight:700;line-height:1.25;overflow-wrap:anywhere}
.aur-step.current{border-color:#174d40;background:#eaf3ed;box-shadow:inset 0 0 0 1px #174d40}
.aur-step.current b{background:#174d40;color:white}.aur-step:hover{border-color:#174d40}
.aur-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding-top:14px;border-top:1px solid #e6ece8}
.aur-metric{display:flex;flex-direction:column;gap:2px}.aur-metric strong{font-size:25px;line-height:1.3;font-variant-numeric:tabular-nums}
.aur-metric small{font-size:12px;color:#5c6a64}.aur-next{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:16px;padding:12px 15px;border-radius:9px;background:#f0f4f1}
.aur-next p{margin:0;font-size:14px}.aur-next a{font-weight:700;text-decoration:underline}
.aur-catalogue-facet-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:16px 0}
.aur-catalogue-facet-metrics>div{padding:14px;background:#f2f7f4;border:1px solid #dbe7df;border-radius:11px}
.aur-catalogue-facet-metrics strong{display:block;font-size:24px;line-height:1.35;font-variant-numeric:tabular-nums;color:#143f38}
.aur-catalogue-facet-metrics span{font-size:12px;color:#536d62}
.aur-catalogue-facet-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:15px 0}
.aur-catalogue-facet-sector{border:1px solid #d8e4de;border-radius:11px;background:#fcfdfc;min-width:0}
.aur-catalogue-facet-sector[open]{background:white;border-color:#a8c1b4}
.aur-catalogue-facet-sector summary{cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:15px 16px;color:#133e35}
.aur-catalogue-facet-sector summary:focus-visible,.aur-catalogue-primary-breakdown summary:focus-visible{outline:3px solid #95bcb0;outline-offset:2px}
.aur-catalogue-facet-sector-name{font-weight:750}.aur-catalogue-facet-sector-name small,.aur-catalogue-facet-sector-count small{display:block;color:#668077;font-weight:450;font-size:11px;margin-top:3px}
.aur-catalogue-facet-sector-count{text-align:right;font-size:19px;font-weight:750;font-variant-numeric:tabular-nums;white-space:nowrap}
.aur-catalogue-facet-rows{padding:2px 16px 12px}
.aur-catalogue-facet-rows>div{display:flex;justify-content:space-between;gap:16px;border-top:1px solid #edf0ed;padding:9px 0;font-size:12px;align-items:center}
.aur-catalogue-facet-rows strong{white-space:nowrap;font-variant-numeric:tabular-nums;color:#143f38}
.aur-catalogue-section-title{font-size:17px;color:#143f38;margin:23px 0 5px;font-weight:750}
.aur-catalogue-evidence-count{margin:0 0 7px;padding:7px 0;font-size:12px;color:#567368}
.aur-catalogue-additional-card{display:flex;flex-direction:column;justify-content:space-between;gap:10px;background:#fbfdfb;border:1px solid #d8e4de;border-radius:11px;padding:14px 16px;min-width:0}
.aur-catalogue-additional-head{display:flex;justify-content:space-between;gap:12px;color:#143f38;align-items:flex-start}
.aur-catalogue-additional-head strong{font-size:14px;line-height:1.45}
.aur-catalogue-additional-head span{font-variant-numeric:tabular-nums;color:#81928b;font-size:19px}
.aur-catalogue-additional-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.aur-catalogue-additional-foot small{font-size:11px;color:#668077}
.aur-catalogue-additional-foot a{font-size:12px;color:#155a48;text-decoration:underline;font-weight:700}
.aur-catalogue-additional-foot a:hover{color:#0d372f}
.aur-catalogue-primary-breakdown{margin-top:17px;border-top:1px solid #e3eae5;padding-top:13px}
.aur-catalogue-primary-breakdown summary{cursor:pointer;font-weight:650;color:#164a3d}
@media(max-width:680px){.aur-catalogue-facet-grid{grid-template-columns:1fr}.aur-catalogue-facet-metrics{grid-template-columns:1fr 1fr}.aur-catalogue-facet-metrics strong{font-size:21px}}
.aur-step-label{display:inline-block;color:#466055;font-size:12px;font-weight:750;letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px}
#aur-upload{border-color:#cadbd1}#aur-upload form{margin-top:16px}
#destination-screen,#supplier-search-controls,#supplier-dashboard,#aur-rfq-approval,#aur-send-status{scroll-margin-top:18px}
@media(max-width:760px){.aur-steps{grid-template-columns:repeat(5,minmax(0,1fr));gap:4px}.aur-step{display:block;text-align:center;padding:10px 2px}.aur-step b{margin:0 auto 5px}.aur-step span{font-size:10px}.aur-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.aur-guide{padding:18px 14px}}
@media(max-width:700px){.card{padding:18px}.top{align-items:flex-start}input[type=file]{width:100%}}
.aur-switcher{display:none;gap:6px;overflow-x:auto;position:sticky;top:0;z-index:12;padding:9px 0;background:#f3f5f4;border-bottom:1px solid #dce4e0;scrollbar-width:thin}
.aur-tabs-enabled .aur-switcher{display:flex}
.aur-switcher button{flex:1;min-width:max-content;padding:12px 14px;background:transparent;color:#22473d;border:1px solid transparent;font-size:14px}
.aur-switcher button[aria-selected="true"]{background:#143f38;color:white}
.aur-switcher button:focus-visible,.aur-quick-actions a:focus-visible{outline:3px solid #95bcb0;outline-offset:2px}
.aur-tabs-enabled .aur-panel:not(.is-active){display:none}
.aur-tabs-enabled .aur-guide{margin:14px 0;padding:22px}
.aur-tabs-enabled .aur-guide .aur-steps{display:none}
.aur-tabs-enabled .aur-panel .card{margin-top:14px}
.aur-tabs-enabled h1{margin:22px 0 6px;font-size:clamp(29px,4.5vw,41px)}
.aur-tabs-enabled .wrap{padding-top:12px}
.aur-tabs-enabled .aur-panel .card{padding:24px}
.aur-quick-actions{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 22px}
.aur-quick-actions a{display:inline-block;background:#fff;border:1px solid #d4dfd9;border-radius:9px;padding:10px 15px;text-decoration:none;font-size:14px;font-weight:650}
.aur-quick-actions a:hover{border-color:#154f45}
.aur-tabs-enabled #supplier-workspace,.aur-tabs-enabled #aur-rfq-approval,.aur-tabs-enabled #supplier-replies{margin-top:14px!important;border:1px solid #e0e7e4!important;padding:24px!important}
.aur-panel{scroll-margin-top:80px}
@media(max-width:760px){.aur-switcher button{flex:0 0 auto;padding:10px 12px;font-size:13px}.aur-tabs-enabled .aur-guide{padding:16px}.aur-tabs-enabled .aur-panel .card{padding:16px}.aur-quick-actions a{flex:1 1 42%;text-align:center}.aur-tabs-enabled .aur-guide .aur-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}

/* Native Mail Center: compact, accessible, responsive and isolated from RFQ UI. */
.aur-mail-shell{display:grid;grid-template-columns:132px minmax(0,1fr);gap:20px;margin-top:18px;min-height:420px}
.aur-mail-folders{display:flex;flex-direction:column;gap:7px;align-items:stretch}
.aur-mail-folders button{width:100%;text-align:left;background:#f1f5f3;color:#23463d;border:1px solid #e1e9e4;padding:10px 12px}
.aur-mail-folders button[aria-pressed="true"]{background:#173f37;color:#fff}
.aur-mail-main{min-width:0}
.aur-mail-toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.aur-mail-toolbar h3{margin:0 auto 0 0}
.aur-mail-toolbar input{max-width:240px;width:100%}
.aur-mail-row{display:block;width:100%;padding:14px 12px;border:0;border-bottom:1px solid #e1e9e4;border-radius:0;background:#fff;color:#172f29;text-align:left}
.aur-mail-row:hover,.aur-mail-row:focus-visible{background:#f1f6f3;color:#172f29}
.aur-mail-row.unread{border-left:3px solid #1f6151}
.aur-mail-row-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.aur-mail-row-top strong{font-size:13px;overflow-wrap:anywhere}
.aur-mail-row-top time{font-size:11px;color:#64716b;white-space:nowrap}
.aur-mail-row b{display:block;font-size:14px;padding:5px 0;overflow-wrap:anywhere}
.aur-mail-row small{display:block;font-size:12px;color:#64716b;overflow:hidden;max-height:34px}
.aur-mail-reference{display:inline-block;margin-top:6px;padding:3px 7px;border-radius:5px;background:#eaf2ee;font-size:11px}
.aur-mail-warning{display:inline-block;color:#a0442d;font-size:12px;margin-top:5px}
.aur-mail-detail-bar{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}
#aur-mail-detail h3{font-size:19px;margin:12px 0;overflow-wrap:anywhere}
.aur-mail-meta{border-bottom:1px solid #e1e9e4;padding-bottom:10px;font-size:12px;color:#586b62;overflow-wrap:anywhere}
.aur-mail-meta p{margin:6px 0}
#aur-mail-detail pre{font:14px/1.65 inherit;white-space:pre-wrap;overflow-wrap:anywhere;font-family:inherit;padding:12px 0;max-height:680px;overflow:auto}
.aur-mail-download{display:inline-block;margin-top:12px}
#aur-mail-compose{display:grid;gap:14px}
#aur-mail-compose[hidden],#aur-mail-detail[hidden],#aur-mail-list[hidden]{display:none}
#aur-mail-compose label{display:grid;gap:6px}
#aur-mail-compose input,#aur-mail-compose textarea{width:100%;max-width:100%;box-sizing:border-box}
#aur-mail-selected-files{margin:0;overflow-wrap:anywhere}
#aur-mail-compose input[type=file]{padding:9px;background:#fbfcfb}
#aur-mail-compose .aur-mail-paper{border:1px solid #cbd9d3;border-radius:10px;background:#fff;padding:22px 25px;box-shadow:0 4px 17px rgba(20,40,34,.045);font:normal 11pt/1.55 Arial,Helvetica,sans-serif;color:#1f2b2a}
#aur-mail-compose .aur-mail-brand{display:block;width:215px;max-width:100%;height:auto;object-fit:contain;margin:0 0 22px}
#aur-mail-compose .aur-mail-formatbar{display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin:6px 0 0}
#aur-mail-compose .aur-mail-formatbar button{background:#fff;color:#243b35;border:1px solid #cbd9d3;border-radius:7px;padding:7px 13px;font:normal 12px Arial,Helvetica,sans-serif}
#aur-mail-compose .aur-mail-formatbar button:focus-visible,#aur-mail-compose .aur-mail-formatbar button:hover{border-color:#245e50;background:#eef4f0}
#aur-mail-compose .aur-mail-formatbar button[aria-pressed="true"]{background:#1e483f;color:#fff}
#aur-mail-compose .aur-mail-formatbar .aur-mail-font{margin-left:auto;font:normal 12px Arial,Helvetica,sans-serif;color:#65776f}
#aur-mail-compose .aur-mail-rich{min-height:215px;outline:none;white-space:pre-wrap;overflow-wrap:anywhere;font:normal 11pt/1.55 Arial,Helvetica,sans-serif;color:#1f2b2a}
#aur-mail-compose .aur-mail-rich:empty:before{content:attr(data-placeholder);color:#98a6a0;pointer-events:none}
#aur-mail-compose .aur-mail-rich:focus{box-shadow:inset 0 -2px #2b6b59}
#aur-mail-compose .aur-mail-signature{font:normal 11pt/1.55 Arial,Helvetica,sans-serif;padding-top:22px;color:#263a33}
#aur-mail-compose .aur-mail-signature strong{font-weight:700}
#aur-mail-compose .aur-mail-editor-note{font:normal 12px Arial,Helvetica,sans-serif;color:#66776f;margin:5px 0}
@media(max-width:700px){#aur-mail-compose .aur-mail-paper{padding:16px}#aur-mail-compose .aur-mail-formatbar .aur-mail-font{margin-left:0}}
#aur-mail-feedback{margin:12px 0;font-size:12px;color:#426357;min-height:18px}
#aur-mail-feedback.is-error{color:#a0442d}
.aur-mail-pagination{display:flex;align-items:center;gap:9px;margin-top:14px}
@media(max-width:700px){.aur-mail-shell{grid-template-columns:1fr;gap:12px}.aur-mail-folders{flex-direction:row;overflow-x:auto}.aur-mail-folders button{width:auto;white-space:nowrap}.aur-mail-toolbar input{max-width:none;order:5}}

/* NOEVA_AURENOEVA_WORKSPACE_FOCUS_20261111: present one task at a time, never hide saved records. */
:root{--noeva-graphite:#233746;--noeva-blue:#375a70;--noeva-stroke:#dce5ea;--noeva-surface:#f2f5f7}
body{background:var(--noeva-surface);color:var(--noeva-graphite)}
.wrap{max-width:1360px;padding-top:15px}
.top{padding-bottom:15px;margin-bottom:19px;border-bottom:1px solid var(--noeva-stroke)}
.aur-page-intro{font-size:14px;line-height:1.6;color:#667989;margin:5px 0 14px;max-width:780px}
.aur-command-breadcrumb{display:flex;gap:12px;color:#667989;font-size:10px;font-weight:750;letter-spacing:.11em;text-transform:uppercase;padding:10px 0;border-top:1px solid var(--noeva-stroke)}
.aur-switcher{display:none;gap:5px;flex-wrap:wrap;overflow-x:auto;position:sticky;top:0;z-index:30;padding:11px 0;background:var(--noeva-surface);border-bottom:1px solid var(--noeva-stroke)}
.aur-switcher button{flex:0 0 auto;min-width:auto;background:#fff;color:#375a70;border:1px solid #cbd9e0;padding:11px 15px;border-radius:10px;font-size:13px}
.aur-switcher button[aria-selected="true"]{background:#375a70;color:#fff;border-color:#375a70}
.aur-switcher button:focus-visible{outline:3px solid #86a7bd;outline-offset:2px}
.aur-panel{scroll-margin-top:85px;min-width:0}
.aur-tabs-enabled .aur-panel:not(.is-active){display:none}
.aur-tabs-enabled .aur-panel .card{border:1px solid var(--noeva-stroke);border-radius:15px;background:#fff;padding:20px 23px;margin:13px 0;box-shadow:0 4px 18px #23374608}
.aur-tabs-enabled .aur-guide{border:1px solid var(--noeva-stroke);border-radius:15px;background:#fff;margin:14px 0;padding:19px}
.aur-tabs-enabled .aur-guide .aur-steps{display:none}
.aur-tabs-enabled .aur-guide h2{font:400 25px/1.2 Georgia,serif;color:var(--noeva-graphite)}
.aur-tabs-enabled .aur-guide .aur-next{border-radius:10px}
.aur-tabs-enabled .aur-guide .aur-metrics{grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.aur-tabs-enabled .aur-quick-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:12px 0 20px}
.aur-tabs-enabled .aur-quick-actions a{background:#fff;color:#375a70;border:1px solid var(--noeva-stroke);border-radius:12px;padding:17px;text-decoration:none;font-size:13px;font-weight:700}
.aur-tabs-enabled .aur-quick-actions a:hover{background:#eaf0f3}
.aur-panel > .aur-area-heading{padding:21px 1px 7px}
.aur-area-heading h2{font:400 clamp(24px,3vw,32px)/1.2 Georgia,serif;margin:0 0 7px;color:var(--noeva-graphite)}
.aur-area-heading p{max-width:760px;color:#667989;font-size:13px;line-height:1.6;margin:0}
.aur-panel details.aur-workgroup{border:1px solid var(--noeva-stroke);border-radius:13px;background:#fff;margin-top:14px;padding:14px 20px}
.aur-panel details.aur-workgroup>summary{font-size:13px;font-weight:750;color:#375a70;cursor:pointer;padding:6px 0}
.aur-panel details.aur-workgroup[open]>summary{border-bottom:1px solid var(--noeva-stroke);padding-bottom:13px;margin-bottom:14px}
.aur-panel .aur-rfq-priority{border-left:3px solid #375a70}
.aur-fallback-group{border:1px solid var(--noeva-stroke);border-radius:10px;background:#fff;margin:13px 0;padding:13px 17px}
.aur-fallback-group>summary{font-size:12px;color:#375a70;font-weight:750;cursor:pointer}
.aur-tabs-enabled .aur-fallback-group{display:none}
button,select,input,textarea{max-width:100%}
.aur-panel table{width:100%}.aur-panel .table-wrap{overflow-x:auto}
@media(max-width:860px){.aur-tabs-enabled .aur-guide .aur-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.aur-tabs-enabled .aur-quick-actions{grid-template-columns:1fr 1fr}}
@media(max-width:590px){.wrap{padding:12px}.aur-switcher{flex-wrap:nowrap}.aur-switcher button{font-size:12px;padding:9px 12px}.aur-tabs-enabled .aur-quick-actions{grid-template-columns:1fr}.aur-tabs-enabled .aur-guide .aur-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.aur-tabs-enabled .aur-panel .card{padding:14px}}

/* AURENOEVA_ONE_RFQ_20261111 — task-first interface, existing evidence unchanged. */
#aur-one-rfq{padding:23px;border:1px solid #dce5ea;border-radius:15px;background:#fff;margin:13px 0}
.aur-one-rfq-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px}
.aur-one-rfq-head h2{font:400 29px Georgia,serif;color:#233746;margin:5px 0}
.aur-one-rfq-head p{margin:0 0 12px;font-size:12px}
.aur-primary-link{display:inline-flex;background:#375a70;color:#fff!important;text-decoration:none;padding:10px 14px;border-radius:9px;font-size:12px;font-weight:700}
.aur-panel .aur-area-heading{padding:9px 0 14px}
.aur-area-heading h2{font:400 28px Georgia,serif;margin:0 0 6px}
.aur-area-heading p{font-size:13px;max-width:780px;color:#667989;margin:0}
.aur-panel details.aur-tool-drawer{border:1px solid #dce5ea;border-radius:12px;background:#fff;padding:14px 18px;margin:12px 0}
.aur-panel details.aur-tool-drawer>summary{cursor:pointer;color:#375a70;font-size:13px;font-weight:750}
.aur-panel details.aur-tool-drawer[open]>summary{border-bottom:1px solid #dce5ea;padding:0 0 12px;margin-bottom:13px}
#aur-panel-sources #item-sources{display:block;background:#fff;border:1px solid #dce5ea;border-radius:14px;padding:15px 20px}
#aur-panel-sources #item-sources>summary{font-weight:700;color:#375a70}
#aur-panel-sources #item-sources>details{padding:9px;border-radius:8px}
#aur-panel-sources #item-sources>details[open]{background:#f8fafb}
.aur-tabs-enabled .aur-guide,.aur-tabs-enabled .aur-quick-actions{display:none}
.aur-switcher button{font-size:13px;padding:11px 15px}
.aur-switcher button[aria-selected="true"]{background:#375a70;color:#fff}

/* Original-case correspondence and review queue — no separate mail authority. */
.aur-workbench-mail-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.aur-workbench-mail-columns>div{min-width:0}
.aur-mail-evidence-item{border:1px solid #dce5ea;background:#f9fbfc;border-radius:10px;padding:11px 14px;margin:9px 0;font-size:12px}
.aur-mail-evidence-item summary{cursor:pointer;font-weight:750;color:#233746}
.aur-mail-evidence-item small{display:block;color:#667989;font-weight:400;margin-top:3px}
.aur-mail-evidence-item pre{white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.65 system-ui;max-height:360px;overflow-y:auto;padding:13px;background:#fff;border:1px solid #dce5ea}
.aur-workbench-totals{display:flex;gap:10px;flex-wrap:wrap}
#aur-mail-trail,#aur-email-review-queue{border:1px solid #dce5ea;border-radius:13px;padding:22px;background:#fff;margin:13px 0}
#aur-mail-trail h2,#aur-email-review-queue h2{font:400 25px Georgia,serif;margin:0 0 7px}
#aur-mail-trail h3{font-size:15px}
@media(max-width:700px){.aur-workbench-mail-columns{grid-template-columns:1fr}}

/* AURENOEVA_COMPACT_ITEM_MAIL_20261111 — 9 at a time, no data truncation. */
#aur-panel-sources #item-sources>summary{font-size:13px;margin-bottom:4px}
#aur-panel-sources #item-sources>p.muted{font-size:12px;max-width:800px;margin:4px 0 12px}
.aur-source-explorer{display:grid;grid-template-columns:minmax(260px,37%) minmax(0,1fr);gap:14px;align-items:start;margin-top:12px}
.aur-source-side,.aur-source-detail{background:#fff;border:1px solid #dce5ea;border-radius:12px;padding:14px;min-width:0}
.aur-source-side{position:sticky;top:75px}
.aur-source-filter{width:100%;font:inherit;font-size:13px;padding:11px 12px;border:1px solid #cbd9e0;border-radius:9px;background:#fff;color:#233746}
.aur-source-count{font-size:11px;color:#667989;margin:10px 0}
.aur-source-items{display:grid;gap:5px}
.aur-source-choice{display:block;width:100%;text-align:left!important;white-space:normal;overflow-wrap:anywhere;font:600 12px/1.4 system-ui,-apple-system,Segoe UI,sans-serif;padding:10px 11px;border:1px solid #dde6ea;border-radius:8px;background:#f8fafb;color:#233746;cursor:pointer}
.aur-source-choice:hover{background:#edf3f7}
.aur-source-choice[aria-pressed=true]{background:#e5eef4;border-color:#62829a;color:#1e465d;box-shadow:inset 3px 0 0 #375a70}
.aur-source-pagination{display:flex;gap:7px;align-items:center;justify-content:space-between;margin-top:12px;flex-wrap:wrap}
.aur-source-pagination button,.aur-mail-local-pages button{font-size:11px;padding:8px 10px;border:1px solid #cddbe2;background:#fff;color:#375a70;border-radius:8px}
.aur-source-pagination button:disabled,.aur-mail-local-pages button:disabled{opacity:.4;cursor:default}
.aur-source-page-label{font-size:11px;color:#667989}
.aur-source-detail-label{color:#667989;font-size:10px;letter-spacing:.12em;font-weight:800;margin:0 0 11px}
#aur-panel-sources .aur-source-detail>details{display:block;margin:0;padding:0;border:0;background:none}
#aur-panel-sources .aur-source-detail>details>summary{display:none}
.aur-source-detail a{overflow-wrap:anywhere}
.aur-source-hidden{display:none!important}
.aur-mail-local-pages{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 1px;border-top:1px solid #dce5ea}
.aur-mail-local-pages span{font-size:11px;color:#667989}
#aur-mail-list .aur-mail-row{min-height:0;padding:9px 12px;gap:3px}
#aur-mail-list .aur-mail-row b{font-size:12px}
#aur-mail-list .aur-mail-row time{font-size:10px}
#aur-panel-mail .aur-mail-shell{max-width:100%}
@media(max-width:850px){.aur-source-explorer{grid-template-columns:1fr}.aur-source-side{position:static}.aur-source-items{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.aur-source-items{grid-template-columns:1fr}.aur-mail-local-pages{align-items:stretch}.aur-mail-local-pages span{width:100%;order:-1}}
</style><link rel="stylesheet" href="/procurement/commercial-intelligence.css?v=20261027"></head><body><div class="wrap"><header class="top"><div><div class="brand">AURENOEVA</div><div class="sub">Private sourcing administration</div></div>
<div class="aur-header-actions"><a class="aur-home-link" href="/" aria-label="Back to Aurenoeva homepage">← Back to homepage</a>
<?php if ($isAdmin): ?><form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>"><button class="ghost" type="submit" name="logout" value="1">Sign out</button></form><?php endif; ?></div>
</header>
<?php if (!$isAdmin): ?>
<h1>Staff access</h1><p>Private procurement cases and customer source documents.</p>
<p class="muted">Sign in using the existing administrator password. Password recovery is handled by the site owner in Hostinger; no setup tokens or new login accounts are created here. Existing enquiries remain stored privately.</p>
<div class="card" style="max-width:460px"><h2>Sign in</h2>
<?php if ($error): ?><p role="alert"><?=aur_escape($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>"><label>Administrator password<input type="password" name="password" autocomplete="current-password" required></label>
<p><button type="submit">Open procurement workspace</button></p></form></div>
<?php else: ?>
<h1>Procurement workspace</h1>
<p class="aur-page-intro">One clear action at a time. Review enquiries, source suppliers, prepare RFQs and handle replies without losing the original records.</p>
<div class="aur-command-breadcrumb" aria-label="Procurement workspace">
<span>NOEVA / AURENOEVA</span><span>Procurement operations</span>
</div>
<details class="aur-fallback-group" id="aur-diagnostics-fallback">
<summary>System diagnostics and source intelligence (open if the compact workspace is unavailable)</summary>
<?php
$aurDfConfigured=false;
$aurDfInbox=['total'=>0,'items'=>[]];
try{
    aur_df_config($aurStore);
    $aurDfConfigured=true;
}catch(Throwable $ignored){}
try{
    $aurDfInbox=aur_df_recent($aurStore,12);
}catch(Throwable $ignored){}
$aurDfSuppliers=['total_vendors'=>0,'items'=>[]];
try{
    $aurDfSuppliers=aur_df_supplier_recent($aurStore,20,(string)($_GET['df_before']??''),(string)($_GET['df_query']??''));
}catch(Throwable $e){error_log('AURENOEVA supplier projection view: '.get_class($e));}
?>
<section class="card" id="aur-deepfetcher">
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
<div><span class="pill">DeepFetcher · Source intelligence</span><h2 style="margin:9px 0 0">Supplier &amp; product intelligence</h2></div>
<strong><?= $aurDfConfigured?'Receiver configured':'Receiver setup required' ?></strong>
</div>
<p class="muted">The same DeepFetcher engine feeds evidence-linked supplier and product observations into AURENOEVA's private procurement research. These are candidates, not confirmed stock, supplier quotations or supplier authorizations. No RFQs are sent automatically.</p>
<p><b>Candidate supplier websites:</b> <?=(int)$aurDfSuppliers['total_vendors']?> · <b>Source records preserved:</b> <?=(int)$aurDfInbox['total']?>.</p>
<form method="get" action="/procurement/" style="display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:12px 0">
<label style="flex:1;min-width:190px">Find product model or supplier
<input name="df_query" value="<?=aur_escape((string)($_GET['df_query']??''))?>" maxlength="120" placeholder="e.g. HRK-1, S260S, supplier website"></label>
<?php if(is_array($result)&&isset($result['reference'])):?><input type="hidden" name="case" value="<?=aur_escape((string)$result['reference'])?>"><?php endif;?>
<button type="submit">Find evidence</button>
<?php if(isset($_GET['df_query'])||isset($_GET['df_before'])):?><a href="/procurement/#aur-deepfetcher">Clear filter</a><?php endif;?>
</form>
<p class="muted">Showing <?=(int)($aurDfSuppliers['matching_vendors']??$aurDfSuppliers['total_vendors'])?> matching websites. Product and RFQ matches are suggestions from original item descriptions; review them before any supplier contact.</p>
<?php if(isset($_GET['deepfetcher_configured'])):?><p role="status">Receiver credentials saved privately. Commercial sourcing is not qualified until matched real products and supplier replies are validated.</p><?php endif;?>
<?php if($aurDfNotice):?><p role="alert"><?=aur_escape($aurDfNotice)?></p><?php endif;?>
<?php if($aurDfSuppliers['items']):?>
<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
<thead><tr><th>Observed supplier website</th><th>Product models observed</th><th>Source evidence</th></tr></thead>
<tbody>
<?php foreach($aurDfSuppliers['items'] as $supplier):?>
<tr>
<td><b><?=aur_escape(implode(', ',$supplier['supplier_names'])?:$supplier['website'])?></b><div class="muted"><?=aur_escape($supplier['website'])?></div><small class="muted">Not commercially verified</small></td>
<td><?=aur_escape(implode(', ',$supplier['product_models'])?:'No specific model extracted')?></td>
<td><?=(int)$supplier['evidence_count']?> source observations
<?php $aurLinkedCases=aur_df_supplier_case_matches($aurStore,$supplier['product_models']??[]); ?>
<?php if($aurLinkedCases):?>
<details><summary><?=count($aurLinkedCases)?> possible enquiry item match<?=count($aurLinkedCases)===1?'':'es'?></summary>
<?php foreach($aurLinkedCases as $match):?>
<div style="padding:7px 0;border-bottom:1px solid #e4e9e7">
<b>Item <?=aur_escape($match['customer_item_number']!==''?$match['customer_item_number']:(string)$match['source_row'])?></b>
· <?=aur_escape($match['matched_model'])?>
<div class="muted"><?=aur_escape($match['description'])?></div>
<a href="/procurement/?case=<?=rawurlencode($match['case_reference'])?>#original-rfq-equipment">Review saved enquiry <?=aur_escape($match['case_reference'])?></a>
· <a href="/procurement/?case=<?=rawurlencode($match['case_reference'])?>#tab-rfq">RFQs &amp; replies</a>
</div>
<?php endforeach;?></details>
<?php endif;?>
<details><summary>Review <?=count($supplier['evidence_quotes']??[])?> original source quotations</summary>
<?php foreach(($supplier['evidence_quotes']??[]) as $evidence):?>
<div style="padding:9px 0;border-bottom:1px solid #e4e9e7;overflow-wrap:anywhere">
<b><?=aur_escape($evidence['field'])?></b> · <?=aur_escape($evidence['value'])?>
<blockquote style="margin:5px 0;padding-left:10px;border-left:2px solid #d2e0d9"><?=aur_escape($evidence['quotation'])?></blockquote>
<a href="<?=aur_escape($evidence['source_url'])?>" target="_blank" rel="noopener noreferrer">Original website</a>
<div class="muted">Evidence SHA-256: <?=aur_escape(substr($evidence['source_hash'],0,20))?>… · <?=aur_escape($evidence['client_reference'])?></div>
</div>
<?php endforeach;?></details>
<small class="muted">Website listing only · Stock not verified · Supplier quote not received</small>
</td>
</tr>
<?php endforeach;?></tbody></table></div>
<?php else:?>
<p class="muted">No supplier-specific DeepFetcher observations yet. General evidence remains available in the source inbox below.</p>
<?php endif;?>
<?php if(!empty($aurDfSuppliers['next_before'])):?>
<p><a href="/procurement/?df_before=<?=rawurlencode($aurDfSuppliers['next_before'])?>&amp;df_query=<?=rawurlencode((string)($_GET['df_query']??''))?>#aur-deepfetcher">Older supplier intelligence</a></p>
<?php endif;?>
<?php if(isset($_GET['df_before'])):?>
<p><a href="?#aur-deepfetcher">Return to latest supplier intelligence</a></p>
<?php endif;?>
<details><summary><?= $aurDfConfigured?'Replace private receiver credentials':'Configure signed source delivery' ?></summary>
<p class="muted">The authorized DeepFetcher destination is <code>https://aurenoeva.com/procurement/deepfetcher_receiver.php</code>, scoped to workspace <code>aurenoeva</code>. Credentials are never displayed again.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<label>Destination signing secret<input type="password" name="df_signing_secret" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="off" required></label>
<label>Acknowledgement token<input type="password" name="df_ack_token" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="off" required></label>
<p><button type="submit" name="configure_deepfetcher_receiver" value="1">Save receiver credentials privately</button></p>
</form>
</details>
</section>
<?php
$aurHasCase=is_array($result)&&isset($result['reference']);
$aurHasDraft=is_array($rfqPreview);
$aurEnhancedReview=$aurHasCase&&!empty($result['country_preflight']['enhanced_review']);
$aurLedger=$aurHasDraft?aur_vendor_rfq_status_summary($rfqPreview):null;
$aurProof=$aurHasDraft?aur_vendor_rfq_delivery_breakdown($rfqPreview):null;
$aurDraftStatus=$aurHasDraft?(string)($rfqPreview['status']??''):'';
$aurFocusStep=!$aurHasCase?1:($aurEnhancedReview?2:(!$aurHasDraft?3:($aurDraftStatus==='DRAFT_REVIEW'?4:5)));
$aurStepLinks=[
    ['Upload','#aur-upload'],
    ['Screen',$aurHasCase?'#destination-screen':'#aur-upload'],
    ['Find',$aurHasCase?'#supplier-search-controls':'#aur-upload'],
    ['Approve',$aurHasDraft?'#aur-rfq-approval':($aurHasCase?'#supplier-dashboard':'#aur-upload')],
    ['Send / status',$aurHasDraft?'#aur-send-status':($aurHasCase?'#supplier-dashboard':'#aur-upload')]
];
if(!$aurHasCase){$aurNext='Upload an enquiry';$aurNextLink='#aur-upload';}
elseif($aurEnhancedReview){$aurNext='Review destination restrictions';$aurNextLink='#destination-screen';}
elseif(!$aurHasDraft){$aurNext='Find suppliers and prepare enquiries';$aurNextLink='#supplier-dashboard';}
elseif($aurDraftStatus==='DRAFT_REVIEW'){
    $aurNext=aur_vendor_rfq_mail_verified($aurStore)?'Review and approve RFQs':'Complete internal mailbox test';
    $aurNextLink=aur_vendor_rfq_mail_verified($aurStore)?'#aur-rfq-approval':'#aur-mail-settings';
}elseif(($aurProof['smtp_250_confirmed']??0)>0){
    $aurNext='Prepare individual supplier reminders';$aurNextLink='#aur-price-reminders';
}else{$aurNext='Inspect saved sending status';$aurNextLink='#aur-send-status';}
?>
<?php
// Private, read-only self-repair receipt from the EXISTING scheduled worker.
// A missing or stale receipt is visible as such; no website request triggers
// crawling, supplier emails, destructive recovery or another scheduler.
$aurAutoHealth=['status'=>'NO_RUN_RECEIPT'];
try {
    $healthFile=aur_private_directory($aurStore,'supplier-intelligence').'/supplier-refresh-latest.json';
    if(is_file($healthFile)&&!is_link($healthFile)){
        $raw=file_get_contents($healthFile);
        $receipt=is_string($raw)?json_decode($raw,true):null;
        if(is_array($receipt)){
            $aurAutoHealth=$receipt;
            $at=(string)($receipt['finished_at']??$receipt['started_at']??'');
            $observed=$at!==''?strtotime($at):false;
            if($observed===false || time()-$observed>108000){
                $aurAutoHealth['display_status']='STALE_OR_INTERRUPTED';
            }else{
                $aurAutoHealth['display_status']=(string)($receipt['status']??'UNKNOWN');
            }
        }else{$aurAutoHealth=['status'=>'INVALID_RECEIPT','display_status'=>'UNAVAILABLE'];}
    }
}catch(Throwable $e){
    error_log('AURENOEVA refresh health: '.get_class($e));
    $aurAutoHealth=['status'=>'HEALTH_READ_FAILED','display_status'=>'UNAVAILABLE'];
}
$aurAutoStatus=(string)($aurAutoHealth['display_status']??$aurAutoHealth['status']??'NO_RUN_RECEIPT');
// WP09 verification is read-only and drawn exclusively from private records.
// A customer enquiry, server accepted email and a confirmed sale are distinct.
try{
    $aurWp09Report=aur_wp09_cycle_report($aurStore,250);
    $aurWp09Current=$aurHasCase && ($result['source']??'')==='PUBLIC_WEBSITE'
        ?aur_wp09_cycle_case($aurStore,$result):null;
}catch(Throwable $e){
    error_log('AURENOEVA WP09 verification: '.get_class($e));
    $aurWp09Report=null;$aurWp09Current=null;
}
?>
<section class="card" id="aur-wp09-verification" style="padding:18px 21px;margin:14px 0;border:1px solid #c9d9d0;background:#f6faf6">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
<div><span class="pill">WP09 · Evidence-based verification</span><h2 style="margin:9px 0 0;font-size:20px">Buyer → supplier → buyer</h2></div>
<a href="/procurement/?<?= $aurHasCase?'case='.rawurlencode((string)$result['reference']).'&amp;':'' ?>#aur-wp09-verification">Refresh verified status ↻</a></div>
<?php if($aurWp09Report===null): ?>
<p role="alert">Verification data unavailable. Existing cases, quotations and sent messages remain unchanged.</p>
<?php else: ?>
<div class="aur-metrics" style="margin:14px 0">
<div class="aur-metric"><strong><?=(int)$aurWp09Report['summary']['website_rfqs']?></strong><small>Website RFQs saved</small></div>
<div class="aur-metric"><strong><?=(int)$aurWp09Report['summary']['supplier_smtp_250']?></strong><small>Supplier SMTP confirmed</small></div>
<div class="aur-metric"><strong><?=(int)$aurWp09Report['summary']['supplier_quotes_verified']?></strong><small>Mailbox quotes verified</small></div>
<div class="aur-metric"><strong><?=(int)$aurWp09Report['summary']['buyer_updates_smtp_250']?></strong><small>Buyer update SMTP confirmed</small></div>
</div>
<?php if($aurWp09Current!==null): ?>
<p><b>Current case:</b> <?=aur_escape((string)$aurWp09Current['reference'])?> ·
<strong><?=aur_escape(str_replace('_',' ',(string)$aurWp09Current['stage']))?></strong></p>
<p class="muted"><b>Next:</b> <?=aur_escape((string)$aurWp09Current['next_action'])?></p>
<?php endif; ?>
<p class="muted" style="margin-bottom:0">Based only on recorded buyer cases, authenticated SMTP 250 acceptances and source-matched supplier mailbox evidence. SMTP acceptance is not proof of inbox delivery. Purchases are <b>not inferred or counted as confirmed</b> from the RFQ and email trail; separate, voluntary transaction confirmation is required. No test fixtures are included. <?=$aurWp09Report['limited']?'Showing most recent 250 cases.':''?></p>
<?php endif; ?>
</section>
<section class="card" id="aur-automation-health" style="padding:18px 21px;margin:14px 0;border:1px solid #d5e1db">
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
<div><span class="pill">Supplier discovery · Automatic recovery</span><h2 style="margin:9px 0 0;font-size:20px">Crawler and mailbox health</h2></div>
<strong><?=aur_escape(str_replace('_',' ',$aurAutoStatus))?></strong>
</div>
<p class="muted" style="margin:10px 0">Last observed run: <b><?=aur_escape((string)($aurAutoHealth['finished_at']??$aurAutoHealth['started_at']??'Never verified'))?></b> UTC ·
Last successful run: <b><?=aur_escape((string)($aurAutoHealth['last_successful_run_at']??'None verified'))?></b> UTC ·
Last completed sweep: <b><?=aur_escape((string)($aurAutoHealth['last_complete_sweep_at']??'None verified'))?></b> UTC</p>
<div class="aur-metrics" style="margin:12px 0">
<div class="aur-metric"><strong><?=(int)($aurAutoHealth['cases_scanned']??0)?></strong><small>Cases visited in last run</small></div>
<div class="aur-metric"><strong><?=(int)($aurAutoHealth['unresolved_rows_in_visited_cases']??0)?></strong><small>Unresolved checks in visited cases</small></div>
<div class="aur-metric"><strong><?=(int)($aurAutoHealth['recovered_by_fallback']??0)?></strong><small>Searches recovered by alternate source</small></div>
<div class="aur-metric"><strong><?=(int)($aurAutoHealth['provider_failures']??0)?></strong><small>Unresolved provider errors</small></div>
<div class="aur-metric"><strong><?=aur_escape((string)($aurAutoHealth['inbox']['status']??'NOT_VERIFIED'))?></strong><small>Mailbox sync state</small></div>
</div>
<p class="muted" style="margin:5px 0 0">The report is generated by the existing scheduled supplier-refresh process. A missing, interrupted or more than 30-hour-old receipt is not treated as success. Search leads remain unverified until independently confirmed; recovery never authorizes an RFQ, stock claim or shipment.</p>
</section>
<?php
try {
    require_once __DIR__.'/deepfetcher_source_inbox.php';
    $aurDfInbox=aur_df_recent($aurStore,12);
}catch(Throwable $e){
    error_log('AURENOEVA DeepFetcher staff view: '.get_class($e));
    $aurDfInbox=null;
}
?>
<section class="card" id="aur-deepfetcher-inbox" style="margin:14px 0;padding:18px 21px">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
<div><span class="pill">DeepFetcher · Source intelligence</span>
<h2 style="margin:9px 0 0;font-size:20px">Research evidence received</h2></div>
<strong><?= $aurDfInbox===null?'Unavailable':number_format((int)$aurDfInbox['total'],0,'.',',').' candidates' ?></strong></div>
<p class="muted">Records received by the signed DeepFetcher connection, preserved in AURENOEVA's private procurement store. Evidence is not a supplier quotation, current stock confirmation or approved RFQ. No automatic supplier outreach is triggered.</p>
<?php if($aurDfInbox===null): ?>
<p role="status">The evidence store could not be read. No existing procurement records were changed.</p>
<?php elseif(!$aurDfInbox['items']): ?>
<p class="muted">No source-backed candidates have been received yet. This does not imply the crawler has finished researching.</p>
<?php else: ?>
<div style="overflow-x:auto">
<table style="width:100%;border-collapse:collapse;font-size:14px">
<thead><tr><th style="text-align:left">Research finding</th><th style="text-align:left">Origin and receipt</th></tr></thead>
<tbody>
<?php foreach($aurDfInbox['items'] as $candidate): ?>
<tr style="border-top:1px solid #e2eae6">
<td style="padding:12px 12px 12px 0;vertical-align:top;max-width:400px">
<b><?=aur_escape($candidate['field'])?></b>
<div style="overflow-wrap:anywhere"><?=aur_escape($candidate['value'])?></div>
<small class="muted">Candidate only · not commercially confirmed</small>
</td>
<td style="padding:12px 0;vertical-align:top">
<a href="<?=aur_escape($candidate['source_url'])?>" target="_blank" rel="noopener noreferrer">Open original source</a>
<div class="muted">Receipt #<?=(int)$candidate['delivery_id']?> · <?=aur_escape($candidate['committed_at'])?></div>
<div class="muted">Evidence SHA-256: <?=aur_escape(substr($candidate['source_hash'],0,16))?>…</div>
<?php if($candidate['client_reference']!==''): ?><div class="muted">Reference: <?=aur_escape($candidate['client_reference'])?></div><?php endif;?>
</td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if((int)$aurDfInbox['total']>count($aurDfInbox['items'])): ?>
<p class="muted">Showing the latest <?=count($aurDfInbox['items'])?> records. Earlier signed records remain durably stored and accessible to the procurement integration.</p>
<?php endif; ?>
<?php endif; ?>
</section>
</details>
<nav id="aur-tabs" class="aur-switcher" role="tablist" data-default-panel="rfq" aria-label="Single customer RFQ workspace">
<button type="button" role="tab" data-aur-tab="rfq" id="aur-tab-rfq" aria-controls="aur-panel-rfq">The RFQ</button>
<button type="button" role="tab" data-aur-tab="sources" id="aur-tab-sources" aria-controls="aur-panel-sources">Sources by item</button>
<button type="button" role="tab" data-aur-tab="mail" id="aur-tab-mail" aria-controls="aur-panel-mail">Sent &amp; received</button>
<button type="button" role="tab" data-aur-tab="drafts" id="aur-tab-drafts" aria-controls="aur-panel-drafts">Prepared emails</button>
<button type="button" role="tab" data-aur-tab="more" id="aur-tab-more" aria-controls="aur-panel-more">More</button>
</nav>
<?php if($aurHasCase): ?>
<section class="card" id="aur-one-rfq"><div class="aur-one-rfq-head">
<div><span class="aur-step-label">ONE ORIGINAL CUSTOMER RFQ</span>
<h2><?=aur_escape((string)$result['reference'])?></h2>
<p class="muted"><?=(int)($result['line_count']??0)?> original item lines · <?=aur_escape((string)($result['created_at']??''))?></p></div>
<a class="aur-primary-link" href="?original_file=<?=rawurlencode((string)$result['reference'])?>">Download original RFQ</a></div>
<p class="muted">Supplier sources, drafted emails, sent messages and replies belong to this one case. Earlier email batches remain historical correspondence—not additional RFQs.</p></section>
<?php else: ?>
<section class="card" id="aur-one-rfq"><h2>No original RFQ selected</h2><p>Upload a customer sourcing file below.</p></section>
<?php endif; ?>

<nav id="aur-workflow" class="aur-guide" aria-label="RFQ status overview">
<div class="aur-guide-head"><div><h2>Enquiry workflow</h2><p class="muted"><?= $aurHasCase?'Current case: '.aur_escape((string)$result['reference']):'Start with a customer file' ?> · Select any step to jump to its controls</p></div>
<?php if($aurHasDraft): ?><a href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode((string)$rfqPreview['draft_id'])?>#aur-send-status">Refresh actual mail status ↻</a><?php endif; ?>
</div>
<div class="aur-steps">
<?php foreach($aurStepLinks as $index=>$aurStep): ?>
<a class="aur-step <?=($index+1)===$aurFocusStep?'current':''?>" href="<?=aur_escape($aurStep[1])?>" <?=($index+1)===$aurFocusStep?'aria-current="step"':''?>><b><?=$index+1?></b><span><?=aur_escape($aurStep[0])?></span></a>
<?php endforeach; ?>
</div>
<div class="aur-metrics" aria-label="Current case summary">
<div class="aur-metric"><strong><?= $aurHasCase?(int)($result['line_count']??0):'—' ?></strong><small>Requested items</small></div>
<div class="aur-metric"><strong id="aur-summary-contactable">—</strong><small>Suppliers with email</small></div>
<div class="aur-metric"><strong><?= $aurHasDraft?(int)($aurLedger['total']??0):'—' ?></strong><small>RFQ recipients</small></div>
<div class="aur-metric"><strong><?= $aurHasDraft?(int)($aurProof['smtp_250_confirmed']??0):'—' ?></strong><small>SMTP 250 confirmed</small></div>
<div class="aur-metric"><strong><?= $aurHasDraft?max(0,(int)($aurLedger['total']??0)-(int)($aurProof['smtp_250_confirmed']??0)):'—' ?></strong><small>Not yet confirmed</small></div>
</div>
<div class="aur-next"><p><b>Next action:</b> <?=aur_escape($aurNext)?><?=($aurEnhancedReview?' · Supplier sending remains on hold.':'')?></p><a href="<?=aur_escape($aurNextLink)?>">Go to step →</a></div>
<p class="muted" style="margin:10px 0 0">Mail figures come from the saved recipient ledger. SMTP acceptance confirms handoff to the mail server, not inbox delivery or a reply. The copy to info@aurenoeva.com is archived privately.</p>
</nav>
<details class="aur-fallback-group aur-legacy-functions" id="aur-legacy-functions">
<summary>Original procurement functions (open if the compact workspace is unavailable)</summary>
<section class="card" id="aur-upload"><span class="aur-step-label">Step 1 of 5 · Capture</span><h2>Customer enquiries and file uploads</h2>
<p class="muted">Customer RFQs from the AURENOEVA website now enter this same private procurement workspace automatically, including individually requested quantities. For external customer lists, you can also import Excel or CSV below.</p><form method="post" enctype="multipart/form-data" class="inline">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<label>Initial destination country
<select name="country" required style="padding:12px;border:1px solid #c5d2cd;border-radius:8px;max-width:250px">
<?php foreach(aur_embargo_countries() as $code=>$name):?>
<option value="<?=aur_escape($code)?>" <?=$code==='UZ'?'selected':''?>><?=aur_escape($name)?> (<?=aur_escape($code)?>)</option>
<?php endforeach;?></select></label>
<label>Customer sourcing list<input name="customer_file" type="file" accept=".xlsx,.csv" required></label>
<button type="submit">Create procurement case</button></form>
<p class="muted">Maximum 20 MB · Customer files and quotations stay private · Duplicate files reuse their existing case.</p></section>

<details class="card aux-disclosure" id="saved-cases" <?=!$aurHasCase?'open':''?>><summary>Saved customer enquiries · open an earlier case</summary>
<?php try { $savedCaseIndex=aur_case_list($aurStore); } catch(Throwable $e) { $savedCaseIndex=['cases'=>[]]; } ?>
<?php if(!empty($savedCaseIndex['cases'])): ?>
<div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:10px">
<?php foreach($savedCaseIndex['cases'] as $savedCase): ?>
<a href="?case=<?=rawurlencode((string)$savedCase['reference'])?>"><?=aur_escape((string)$savedCase['reference'])?> · <?= (int)$savedCase['line_count']?> entries<?php if(($savedCase['source']??'')==='PUBLIC_WEBSITE'):?> · Website<?=($savedCase['buyer_company']??'')!==''?' · '.aur_escape((string)$savedCase['buyer_company']):''?><?php endif;?></a>
<?php endforeach; ?></div>
<?php else: ?><p class="muted">No saved enquiries available.</p><?php endif;?>
<p class="muted">Enquiries persist independently of supplier research. Nothing is deleted when you upload another case, send a test email or run worldwide discovery.</p>
</details>
<?php
try{$aurSupplierHistory=aur_worldwide_research_inventory($aurStore,false);}
catch(Throwable $e) {
    error_log('AURENOEVA supplier historical count: '.get_class($e));
    $aurSupplierHistory=null;
}
?>
<details class="card aux-disclosure" id="supplier-history-counts"><summary>Global supplier research and catalogue history</summary><div>
<p class="muted">This counts the actual saved evidence in the private procurement workspace, including previously saved research and search-only leads. It is a different dataset from the VPS product-identity catalogue. Unverified search leads are not confirmed inventory or supplier quotations.</p>
<?php if($aurSupplierAuditMessage!==null):?><p role="status"><b><?=aur_escape($aurSupplierAuditMessage)?></b></p><?php endif;?>
<?php if(is_array($aurSupplierHistory)):?>
<p>
<span class="pill"><b><?=(int)$aurSupplierHistory['global_supplier_master']?></b> global supplier master websites</span>
<span class="pill"><b><?=(int)$aurSupplierHistory['distinct_supplier_websites']?></b> distinct discovered websites</span>
<span class="pill"><b><?=(int)$aurSupplierHistory['distinct_evidence_urls']?></b> saved evidence links</span>
<span class="pill"><b><?=(int)$aurSupplierHistory['site_checked_websites']?></b> websites checked</span>
<span class="pill"><b><?=(int)$aurSupplierHistory['published_email_addresses']?></b> published contact emails</span>
<span class="pill"><b><?=(int)$aurSupplierHistory['saved_research_cases']?></b> saved RFQ research cases</span>
</p>
<p class="muted">Individual source observations, including repeated mentions: <?=(int)$aurSupplierHistory['observed_records_including_repeat_mentions']?>. Previously researched equipment entries: <?=(int)$aurSupplierHistory['saved_equipment_rows']?>.
<?php if((int)$aurSupplierHistory['unreadable_case_files']>0):?> Unreadable historical research files: <?=(int)$aurSupplierHistory['unreadable_case_files']?> (not counted).<?php endif;?></p>
<?php else:?>
<p>Historical supplier statistics are temporarily unavailable. Original cases remain accessible.</p>
<?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<button type="submit" name="consolidate_supplier_history" value="1">Consolidate existing RFQ research into worldwide supplier master</button>
</form>
<p class="muted">Imports saved source leads with their original evidence and verification status. No crawl, vendor email, case deletion or customer data export is performed. Older external CORE/VPS research is not counted unless it exists in this Hostinger procurement store.</p>
</div></details>
<?php if (is_array($result) && isset($result['reference'])): ?>
<section class="card"><span class="pill">Saved · verified database readback</span><h2 style="margin-top:16px">Case <?=aur_escape((string)$result['reference'])?></h2>
<?php if(($result['source']??'')==='PUBLIC_WEBSITE'):?>
<div style="margin:14px 0;padding:14px 17px;border:1px solid #b8d3c7;border-radius:10px;background:#edf5ef">
<strong>Website enquiry · automatically captured</strong> · <?=aur_escape((string)($result['public_reference']??''))?>
<p style="margin:8px 0"><b>Buyer:</b> <?=aur_escape((string)($result['buyer']['company']??''))?> · <?=aur_escape((string)($result['buyer']['contact']??''))?> ·
<a href="mailto:<?=aur_escape((string)($result['buyer']['email']??''))?>"><?=aur_escape((string)($result['buyer']['email']??''))?></a></p>
<?php if(($result['country']??'')==='ZZ'):?><p style="margin:8px 0"><strong>Action required:</strong> Confirm the destination country below before any supplier outreach. This enquiry has not passed legal or supplier checks.</p><?php endif;?>
<p class="muted" style="margin:4px 0 0">Original catalogue parts and quantities are preserved below. Supplier matching and quotations use this case; supplier messages still require explicit staff approval.</p>
</div>
<?php endif;?>
<p><b><?= (int)($result['requirements'] ?? 0) ?></b> numbered requirements ·
<b><?= (int)($result['line_count'] ?? 0) ?></b> entries ·
<b><?= (int)($result['alternatives'] ?? 0) ?></b> alternative options ·
<b><?=aur_escape((string)($result['country'] ?? ''))?></b> destination</p>
<?php $aurQuality=aur_rfq_quality_report($result); $aurQualityCounts=$aurQuality['counts']; ?>
<details id="aur-rfq-quality" class="aux-disclosure" <?=!empty($aurQuality['issues'])?'open':''?>>
<summary>Automatic RFQ quality check · <?=count($aurQuality['issues'])?> observations</summary>
<p>
<span class="pill"><b><?=(int)$aurQualityCounts['possible_duplicates']?></b> possible duplicate specifications</span>
<span class="pill"><b><?=(int)$aurQualityCounts['open_specifications']?></b> open specifications</span>
<span class="pill"><b><?=(int)$aurQualityCounts['numbering_gaps']?></b> numbering gaps</span>
<span class="pill"><b><?=(int)$aurQualityCounts['missing_quantities']?></b> missing requested quantities</span>
</p>
<?php if(empty($aurQuality['issues'])): ?>
<p>No rule-based quality warnings found. This is not certification of technical accuracy.</p>
<?php else: ?>
<div class="scroll"><table><thead><tr><th>Finding</th><th>Interpretation</th></tr></thead><tbody>
<?php foreach($aurQuality['issues'] as $issue):?>
<tr><td><b><?=aur_escape((string)$issue['label'])?></b></td>
<td><?=aur_escape((string)$issue['detail'])?></td></tr>
<?php endforeach;?></tbody></table></div>
<?php endif;?>
<p class="muted">Read-only checks of the original buyer requirements, not an assertion of error. Alternatives are kept as options under their buyer item. Existing sent RFQs and supplier correspondence are never rewritten.</p>
</details>
<section id="destination-screen" style="border:1px solid #cad7d1;background:#f1f5f2;border-radius:10px;padding:18px;margin:18px 0">
<span class="aur-step-label">Step 2 of 5 · Destination review</span><h3 style="margin:0 0 8px">Automatic destination / embargo preflight</h3>
<form method="post" action="/procurement/" class="inline">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<label>Actual destination country
<select name="destination_country" required style="padding:12px;border:1px solid #c5d2cd;border-radius:8px;max-width:360px">
<?php foreach(aur_embargo_countries() as $code=>$name):?>
<option value="<?=aur_escape($code)?>" <?=$code===($result['country']??'')?'selected':''?>><?=aur_escape($name)?> (<?=aur_escape($code)?>)</option>
<?php endforeach;?>
</select></label>
<button class="ghost" type="submit" name="update_destination_country" value="1">Save and recheck destination</button>
</form>
<?php $countryScreen=$result['country_preflight']??null; ?>
<?php if($countryScreenError!==null || !is_array($countryScreen)):?>
<p role="alert"><strong>Screening unavailable — supplier dispatch blocked.</strong> <?=aur_escape((string)$countryScreenError)?></p>
<?php else:?>
<p role="status"><strong><?=!empty($countryScreen['enhanced_review'])?'Enhanced review required':'Preliminary country check recorded'?></strong> · <?=aur_escape((string)$countryScreen['country_name'])?> (<?=aur_escape((string)$countryScreen['destination_country'])?>) · <?=aur_escape((string)$countryScreen['checked_at'])?></p>
<p><?=aur_escape((string)$countryScreen['note'])?></p>
<?php if(!empty($countryScreen['enhanced_review'])):?><p><strong>Supplier enquiry sending is on hold for this destination pending a documented sanctions assessment.</strong> Worldwide product and supplier research remains available.</p><?php endif;?>
<?php endif;?>
<p class="muted">This is a country-level warning, not a live official-list check or legal clearance. Seller/buyer identities, ownership, goods classification, final end user and end use still require review. Official references:
<a href="https://sanctionsmap.eu/" target="_blank" rel="noopener noreferrer">EU Sanctions Map</a> ·
<a href="https://finance.ec.europa.eu/eu-and-world/sanctions-restrictive-measures/overview-sanctions-and-related-resources_en" target="_blank" rel="noopener noreferrer">European Commission</a>.
Original case reference and equipment remain unchanged if the destination is corrected.</p>
</section>
<p>Legal review: <strong><?=aur_escape((string)($result['legal_review']['compliance']['status'] ?? 'REVIEW_REQUIRED'))?></strong>.
Supplier outreach, pricing, shipment and any sanctions clearance are separate controlled actions.</p>
<p style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
<a class="button" href="?rfq=<?=rawurlencode((string)$result['reference'])?>">Download original enquiry</a>
<a class="button ghost" href="?original_file=<?=rawurlencode((string)$result['reference'])?>">Download original uploaded file (CSV/XLSX)</a>
<a href="#original-rfq-equipment">View all original requested parts ↓</a> · <a href="#supplier-workspace">Supplier contacts and RFQ selection ↓</a>
</p>
<?php
$aurLineReview=aur_rfq_line_review($result['payload']['sourcing_lines']??[]);
if($aurLineReview['requires_review']):
?>
<section class="card" id="rfq-duplicate-review" role="alert" style="border:1px solid #d7a24d">
<h3>RFQ equipment duplication review required</h3>
<p>The original customer file is preserved unchanged. These potential duplicate entries must be checked before including both in the same supplier quotation. Alternative models within one customer item remain quotation options, not additional purchases.</p>
<ul>
<?php foreach($aurLineReview['duplicate_pairs'] as $flag): ?>
<li><strong><?=aur_escape(str_replace('_',' ',(string)$flag['kind']))?></strong>:
customer items <?= (int)$flag['customer_items'][0] ?> and <?= (int)$flag['customer_items'][1] ?>,
source rows <?= (int)$flag['source_rows'][0] ?> and <?= (int)$flag['source_rows'][1] ?>.
</li>
<?php endforeach; ?>
</ul>
<p>Verify whether the buyer really requires separate quantities. If an entry is redundant, correct the source spreadsheet and upload it as a new case before including both entries in a fresh supplier RFQ. No existing sent email is modified.</p>
</section>
<?php endif; ?>
<details aria-labelledby="original-equipment-heading" id="original-rfq-equipment" class="aux-disclosure"><summary id="original-equipment-heading">Original requested equipment — <?= (int)($result['line_count']??0) ?> saved entries</summary>
<p class="muted">This is your original uploaded customer enquiry, stored in the case record. It is always displayed, independently of worldwide supplier discovery, and is not overwritten by new research.</p>
<div class="scroll"><table><thead><tr><th>Item</th><th>Requested equipment / original specification</th><th>Quantity</th><th>Option</th></tr></thead><tbody>
<?php foreach (($result['payload']['sourcing_lines'] ?? []) as $line): ?>
<tr><td><?= (int)($line['customer_item_number']??0) ?></td><td><?=aur_escape((string)($line['description_original']??''))?></td>
<td><?=isset($line['quantity']) ? (int)$line['quantity'] : '—' ?></td>
<td><?=aur_escape((string)($line['option_type']??'REQUESTED'))?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="muted">Saved reference: <?=aur_escape((string)$result['reference'])?> · <a href="?case=<?=rawurlencode((string)$result['reference'])?>">Reload original enquiry</a></p>
</details>
<?php aur_prices_panel($aurStore,$result,$csrf); ?>
<details id="supplier-search-controls" class="aux-disclosure" aria-label="Persistent worldwide supplier search"><summary>Step 3 of 5 · Find additional suppliers worldwide</summary>
<p>This search remains available alongside the original enquiry, even if supplier research is temporarily unavailable. Previously discovered source links are preserved independently of the uploaded customer specifications.</p>
<form id="aur-worldwide-scan-form" method="post" action="/procurement/" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<button type="button" id="aur-worldwide-scan-all" data-total="<?= (int)($result['line_count']??count($result['payload']['sourcing_lines']??[]))?>">Discover suppliers worldwide for entire case</button>
<button type="submit" name="scan_worldwide" value="1" class="ghost">Scan next item worldwide</button>
</form>
<p id="aur-worldwide-progress" role="status" class="muted"></p>
<a href="#supplier-workspace">View saved supplier sources and prepare RFQs ↓</a>
</details>
</section>
<?php if (is_array($supplierResearch)): ?>
<section class="card">
<?php
// Supplier discovery must never prevent saved customer equipment/RFQ from rendering.
try {
    define('AUR_PROCUREMENT_VIEW',true);
    ob_start();
    require __DIR__.'/supplier_portal.php';
    echo ob_get_clean();
} catch (Throwable $e) {
    if(ob_get_level()>0)ob_end_clean();
    error_log('AURENOEVA supplier portal rendering: '.get_class($e).' '.substr($e->getMessage(),0,140));
    echo '<h2>Supplier intelligence temporarily unavailable</h2><p>The original equipment and downloadable RFQ above are preserved. The saved supplier research remains in private storage.</p>';
}
?>
</section>
<?php else: ?><section class="card"><h2>Supplier research</h2><p>The original equipment and RFQ remain available above. Supplier research will appear here when available.</p></section><?php endif; ?>
<?php if($aurHasCase):require __DIR__.'/case_mail_workbench.php';endif; ?>
<?php elseif (is_array($result) && isset($result['cases'])): ?>
<section class="card"><h2>Existing enquiries</h2><div class="scroll"><table><thead><tr><th>Case reference</th><th>Country</th><th>Items</th><th>Received (UTC)</th><th>RFQ</th></tr></thead><tbody>
<?php foreach ($result['cases'] as $case): ?><tr><td><a href="?case=<?=rawurlencode((string)$case['reference'])?>"><?=aur_escape((string)$case['reference'])?></a></td>
<td><?=aur_escape((string)$case['country'])?></td><td><?= (int)$case['line_count']?></td><td><?=aur_escape((string)$case['created_at'])?></td>
<?php
$caseRfq=null;
try{$caseRfq=aur_vendor_rfq_primary($aurStore,aur_case_read($aurStore,(string)$case['reference']));}
catch(Throwable $e){error_log('AURENOEVA case mail summary: '.get_class($e));}
$caseRfqUrl='?case='.rawurlencode((string)$case['reference']);
if($caseRfq!==null)$caseRfqUrl.='&draft='.rawurlencode((string)$caseRfq['id']).'#aur-send-status';
?>
<td><a href="<?=aur_escape($caseRfqUrl)?>"><?=aur_escape(aur_vendor_rfq_public_status($caseRfq))?></a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>



<section class="card" id="aur-commercial-intelligence" aria-label="Commercial intelligence">
<div class="ci-heading"><div>
<h2>Commercial intelligence</h2>
<p class="ci-muted">Evidence-backed decisions from the live catalogue, buyer activity and the existing WP09 procurement ledgers.</p>
<p class="ci-muted" id="ci-asof">Open this area to load private metrics.</p>
</div><div class="ci-toolbar"><button id="ci-refresh" type="button" class="ghost">Refresh data ↻</button></div></div>
<p id="ci-status" role="status" aria-live="polite">Select Intelligence to read current records.</p>
<p id="ci-catalogue-warning" class="ci-insight" hidden>The current catalogue snapshot is unavailable. Product counts and coverage cannot be calculated.</p>
<div class="ci-kpis">
<div class="ci-kpi"><strong id="ci-products">—</strong><b>Searchable products</b><small>Unique catalogue product identities</small></div>
<div class="ci-kpi"><strong id="ci-price">—</strong><b>Verified public pricing</b><small id="ci-price-pct">Current seller offers, not our selling prices</small></div>
<div class="ci-kpi"><strong id="ci-fresh">—</strong><b>Recently observed products</b><small id="ci-fresh-pct">Product evidence within 30 days</small></div>
<div class="ci-kpi"><strong id="ci-enquiries">—</strong><b>Customer enquiries</b><small>Saved original cases · not purchases</small></div>
<div class="ci-kpi"><strong id="ci-contactable">—</strong><b>Contactable suppliers</b><small>Supplier master records with email</small></div>
<div class="ci-kpi"><strong id="ci-transactions">—</strong><b>Verified purchases</b><small id="ci-no-sale">Evidence-required outcome verification</small></div>
</div>
<p class="ci-muted" id="ci-metadata"></p>
<section class="ci-section" aria-label="Live catalogue harvesting">
<h3>Crawler progress</h3>
<p class="ci-muted">Results from the live catalogue used by website search. A discovered product is not confirmed supplier stock.</p>
<div class="ci-kpis">
<div class="ci-kpi"><strong id="ci-crawl-new">—</strong><b>New products · last 24 hours</b><small>New catalogue identities</small></div>
<div class="ci-kpi"><strong id="ci-crawl-last">—</strong><b>Last completed run</b><small id="ci-crawl-last-note">Last confirmed harvest</small></div>
<div class="ci-kpi"><strong id="ci-crawl-processed">—</strong><b>Pages processed · last 24 hours</b><small id="ci-crawl-processed-note">Including unsuccessful requests</small></div>
<div class="ci-kpi"><strong id="ci-crawl-queue">—</strong><b>URLs awaiting processing</b><small id="ci-crawl-queue-note">Remaining due work, not product count</small></div>
</div>
<p class="ci-muted" id="ci-crawler-progress" role="status" aria-live="polite">Live crawler information loads when this dashboard is refreshed.</p>
</section>
<section class="ci-section" aria-label="Catalogue freshness and withdrawals">
<h3>Catalogue health &amp; source verification</h3>
<p class="ci-muted">Seller-page evidence is distinct from guaranteed stock. Withdrawal requires two source checks at least 24 hours apart. Historical product records and RFQs remain preserved.</p>
<div class="ci-kpis">
<div class="ci-kpi"><strong id="ci-source-links">—</strong><b>Supplier-product links tracked</b><small>Separate from unique products</small></div>
<div class="ci-kpi"><strong id="ci-source-overdue">—</strong><b>Source links overdue 14 days</b><small>Successful product observations</small></div>
<div class="ci-kpi"><strong id="ci-source-review">—</strong><b>Listings requiring recheck</b><small>First negative observation</small></div>
<div class="ci-kpi"><strong id="ci-source-withdrawn">—</strong><b>Supplier listings withdrawn</b><small>Confirmed source-specific removals</small></div>
<div class="ci-kpi"><strong id="ci-products-hidden">—</strong><b>Products hidden from search</b><small>No remaining verified seller listing</small></div>
<div class="ci-kpi"><strong id="ci-products-overdue">—</strong><b>Product identities over 30 days</b><small>No recent positive source observation</small></div>
</div>
<p class="ci-muted" id="ci-source-check">Catalogue lifecycle observations require the current VPS crawler and a published canonical database snapshot.</p>
</section>
<div class="ci-two">
<section class="ci-section"><h3>Catalogue growth</h3><div id="ci-growth"></div><p class="ci-muted" id="ci-history-note"></p></section>
<section class="ci-section"><div class="ci-heading"><h3>Buyer interest</h3><div class="ci-toolbar">
<label for="ci-period">Period</label><select id="ci-period"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option></select></div></div>
<p><strong id="ci-searches">—</strong> searches · <strong id="ci-baskets">—</strong> basket additions</p>
<div id="ci-activity-chart"></div><p class="ci-muted" id="ci-activity-scope"></p></section>
</div>
<section class="ci-section">
<div class="ci-heading"><div><h3>All 19 industry categories</h3><p class="ci-muted">The established seven show primary-sector counts and independently evidenced technical classifications. The twelve newer industries remain searchable discovery topics until separate classification evidence is available. Missing counts are shown as a dash, never as zero.</p></div>
<div class="ci-toolbar"><label for="ci-industry-filter">Show</label><select id="ci-industry-filter"><option value="all">All 19</option><option value="established">7 established</option><option value="discovery">12 additional</option></select></div></div>
<div class="ci-table-scroll"><table class="ci-table"><thead><tr><th>Industry</th><th>Primary products</th><th>Technical matches</th><th>Evidence status</th></tr></thead><tbody id="ci-industries"></tbody></table></div>
</section>
<div class="ci-two">
<section class="ci-section"><h3>Enquiry-to-purchase funnel</h3><div id="ci-funnel"></div>
<p class="ci-muted">The first five stages are unique original cases, not item counts. Purchases require independent evidence.</p>
<p class="ci-muted" id="ci-case-cap" hidden>More than 1,000 cases exist; this view covers the first 1,000 only. Funnel figures are incomplete.</p></section>
<section class="ci-section"><h3>Supplier participation & quotations</h3>
<p class="ci-insight" id="ci-supplier-summary">Loading saved supplier data…</p>
<p><strong id="ci-response-rate">—</strong> supplier response rate</p>
<p><strong id="ci-quote-rate">—</strong> priced quotation rate</p><p class="ci-muted" id="ci-quote-note"></p>
<p class="ci-muted" id="ci-response-note"></p>
<p class="ci-muted">Only stored structured supplier responses are counted. SMTP acceptance is not delivery confirmation or a response.</p></section>
</div>
<section class="ci-section"><h3>Verify transaction outcome</h3>
<p class="ci-muted">Record a purchase or a confirmed non-purchase only when supporting customer or supplier correspondence is retained. This is an internal staff attestation, not an automatically verified payment. Save an evidence reference, not confidential message content.</p>
<?php if(isset($_GET['outcome_saved'])):?><p role="status" class="ci-insight">Confirmed outcome saved in the private evidence ledger.</p><?php endif;?>
<?php if($aurCommercialNotice!==null):?><p role="alert"><?=aur_escape($aurCommercialNotice)?></p><?php endif;?>
<form class="ci-outcome-form" method="post" action="/procurement/">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="record_commercial_outcome" value="1">
<label>Original enquiry<select id="ci-case-ref" name="case_ref" required><option value="">Select an enquiry</option></select></label>
<label>Verified outcome<select name="outcome" required>
<option value="PURCHASE_CONFIRMED">Purchase independently confirmed</option>
<option value="NO_PURCHASE_CONFIRMED">No purchase independently confirmed</option></select></label>
<label class="ci-wide">Evidence reference<input name="evidence_reference" minlength="8" maxlength="250" required placeholder="e.g. archived buyer confirmation or verified supplier order reference"></label>
<label class="ci-wide ci-affirm"><input type="checkbox" name="staff_verified" value="1" required> I have checked retained evidence supporting this outcome.</label>
<div class="ci-wide"><button type="submit">Save confirmed outcome</button></div>
</form>
</section>
<p class="ci-muted">Measurements reflect the current private snapshot and saved ledgers. Search and basket tracking starts only with the new instrumentation. Seller prices are not stock commitments; supplier responses are not completed transactions.</p>
</section>

<section class="card" id="aur-mail-center" aria-label="AURENOEVA Mail Center">
<h2>Mail Center <small style="font-size:13px;font-weight:500;color:#67766e">info@aurenoeva.com · Hostinger</small></h2>
<p class="muted">Read supplier emails, reply and send messages without leaving procurement. Existing RFQ automation continues independently.</p>
<div class="aur-mail-shell">
<nav class="aur-mail-folders" aria-label="Mail folders">
<button type="button" data-mail-folder="INBOX">Inbox</button>
<button type="button" data-mail-folder="INBOX.Sent">Sent</button>
<button type="button" data-mail-folder="INBOX.Junk">Junk</button>
<button type="button" data-mail-folder="INBOX.Trash">Trash</button>
</nav>
<div class="aur-mail-main">
<div class="aur-mail-toolbar">
<h3 id="aur-mail-folder-name">Inbox</h3>
<button type="button" id="aur-mail-new">+ Compose</button>
<button type="button" class="ghost" id="aur-mail-reload">Refresh ↻</button>
<input type="search" id="aur-mail-search" aria-label="Filter loaded messages" placeholder="Search this page">
</div>
<p id="aur-mail-feedback" role="status" aria-live="polite">Choose Inbox to load the live mailbox.</p>
<div id="aur-mail-list"><p class="muted">Select a mail folder.</p></div>
<div id="aur-mail-detail" hidden></div>
<form id="aur-mail-compose" hidden>
<h3 id="aur-mail-compose-heading">New message</h3>
<input type="hidden" name="send_key" value="">
<label>To <input name="to" type="email" required maxlength="254" autocomplete="email"></label>
<label>Subject <input name="subject" required maxlength="250"></label>
<label>Signature language <select name="language" id="aur-mail-language" aria-label="Outgoing legal signature language">
<option value="en" selected>English</option>
<option value="de">Deutsch</option>
</select></label>
<label>Attachments <input id="aur-mail-attachments" name="attachments[]" type="file" multiple aria-describedby="aur-mail-attachment-hint"></label>
<label style="display:flex;align-items:center;gap:10px;font-weight:600"><input type="checkbox" name="include_brochure" id="aur-mail-include-brochure" value="1" style="width:auto;flex:none"> Attach AURENOEVA company brochure (PDF)</label>
<p id="aur-mail-attachment-hint" class="muted">Up to 5 attachments including this brochure · 8 MB per file · 10 MB combined. <a href="/procurement/?brochure=pdf">English</a> · <a href="/procurement/?brochure=de">Deutsch</a>.</p>
<p id="aur-mail-selected-files" class="muted" role="status" aria-live="polite"></p>
<div class="aur-mail-formatbar" role="toolbar" aria-label="Message formatting">
<button type="button" id="aur-mail-normal" aria-label="Remove bold formatting">Normal</button>
<button type="button" id="aur-mail-bold" aria-label="Bold selected text" aria-pressed="false"><strong>Bold</strong></button>
<span class="aur-mail-font">Arial · 11 pt · black</span>
</div>
<div class="aur-mail-paper">
<img class="aur-mail-brand" src="/procurement/aurenoeva-logo.png?v=2" alt="AURENOEVA — Procurement · Components · Intelligence" width="215" height="54">
<label for="aur-mail-rich-editor" style="font-size:12px;font-weight:600;margin-bottom:7px">Message</label>
<div id="aur-mail-rich-editor" class="aur-mail-rich" contenteditable="true"
  role="textbox" aria-label="Email message, 11 point Arial, normal by default"
  aria-multiline="true" spellcheck="true" data-placeholder="Write your message…"></div>
<div class="aur-mail-signature" aria-label="Fixed signature">
<strong>AURENOEVA Purchasing</strong><br>
NOEVA Systems e.K.<br>
Marienburger Straße 16<br>
56112 Lahnstein<br>
Germany<br><br>
Commercial register<br>
Registered with Amtsgericht Koblenz<br>
HRA 23554<br><br>
Phone: +49 176 471 77751<br>
info@aurenoeva.com | https://aurenoeva.com
</div>
</div>
<input type="hidden" name="body" value="">
<input type="hidden" name="body_html" value="">
<p class="aur-mail-editor-note">Pasted text is automatically cleaned. Select text and choose <strong>Bold</strong> when needed. The logo and signature are added to the outgoing email automatically.</p>
<div class="aur-mail-detail-bar">
<button type="submit">Send email</button>
<button type="button" class="ghost" id="aur-mail-cancel">Cancel</button>
</div>
<p class="muted">The Mail Center stores each outgoing message, attachments and SMTP result in its private Sent history. A second copy is saved in the Hostinger Sent folder when supported; its archive status is recorded separately. SMTP acceptance does not guarantee inbox delivery.</p>
</form>
<div class="aur-mail-pagination">
<button type="button" class="ghost" id="aur-mail-prev" disabled>← Previous</button>
<button type="button" class="ghost" id="aur-mail-next" disabled>Next →</button>
</div>
</div></div>
</section>

<section class="card" id="aur-brand-brochure" aria-label="AURENOEVA branded company brochure">
<div style="display:flex;gap:24px;align-items:start;flex-wrap:wrap">
<div style="flex:1;min-width:250px">
<h2>AURENOEVA · Company brochure</h2>
<p>Your reusable one-page introduction for suppliers, manufacturers and sourcing partners. It explains the searchable parts catalogue, direct customer RFQs, sourcing in Germany, supplier introductions, direct customer purchasing and commission-based relationships.</p>
<p style="display:flex;flex-wrap:wrap;gap:10px">
<a class="button" href="/procurement/?brochure=pdf">English brochure · PDF</a>
<a class="button" href="/procurement/?brochure=de">Deutsche Firmenbroschüre · PDF</a>
<a class="button ghost" href="/procurement/?brochure=image">Download original image</a>
</p>
<p class="muted">Private administrator resource, ready to print or attach to emails. The master artwork and PDF are stored on this website.</p>
<p><a href="#aur-mail-center">Go to Mail Centre →</a> — when composing, tick <b>Attach AURENOEVA company brochure (PDF)</b>. No emails are sent by downloading or selecting a brochure.</p>
</div>
<img src="/procurement/?brochure=preview" loading="lazy" alt="AURENOEVA one-page corporate brochure preview" style="width:205px;max-width:100%;height:auto;border:1px solid #d7dfd8;border-radius:9px;box-shadow:0 10px 24px rgba(15,55,45,.12)">
</div>
</section>
<section class="card">
<h2 id="aur-mail-settings">AURENOEVA supplier mailbox</h2>
<p>Supplier enquiries must use authenticated Hostinger SMTP from <b>info@aurenoeva.com</b>. Mailbox credentials stay in the website's private hosting directory, never in the public catalogue or GitHub.</p>
<p><b>Website SMTP configuration:</b> <?=aur_vendor_rfq_smtp_ready()?'Credentials present':'Not configured'?> · <b>Verified website test:</b> <?=aur_vendor_rfq_mail_verified($aurStore)?'PASS':'Required before supplier dispatch'?></p>
<details><summary><?=aur_vendor_rfq_smtp_ready()?'Update mailbox SMTP password':'Connect mailbox SMTP password'?></summary>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<?php if(is_array($result)&&isset($result['reference'])):?><input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>"><?php endif;?>
<?php if(isset($_GET['draft'])):?><input type="hidden" name="draft_id" value="<?=aur_escape((string)$_GET['draft'])?>"><?php endif;?>
<label>SMTP password for info@aurenoeva.com
<input type="password" name="smtp_password" autocomplete="new-password" required maxlength="512" style="display:block;max-width:370px;margin:8px 0"></label>
<p class="muted">Enter the mailbox SMTP password directly here; do not send it in chat. The current password is never displayed.</p>
<button type="submit" name="configure_rfq_smtp" value="1">Save mailbox connection</button>
</form>
</details>
<details class="aux-disclosure"><summary>Mail diagnostics and internal test (administration only)</summary>
<h3>Email automation — internal RFQ test</h3>
<p>Send one clearly marked sample RFQ from <b>info@aurenoeva.com</b> to the <b>same AURENOEVA inbox</b> via authenticated SMTP. This does not contact suppliers or change enquiries. Supplier sending remains locked until the website test succeeds.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<?php if(is_array($result)&&isset($result['reference'])):?><input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>"><?php endif;?>
<button type="submit" name="send_test_rfq" value="1" <?=!aur_vendor_rfq_smtp_ready()?'disabled':''?>>Test AURENOEVA SMTP → AURENOEVA inbox</button>
</form>
<?php $smtpSmoke=aur_vendor_rfq_smoke_status($aurStore); ?>
<?php if(isset($_GET['smtp_configured'])):?><p role="status"><b>Mailbox password saved privately.</b> Run the internal test below; it will not contact suppliers.</p><?php endif;?>
<?php if(aur_vendor_rfq_mail_verified($aurStore)):?><p role="status"><b>Website SMTP test passed.</b> Supplier dispatch can be approved after the existing legal review.</p><?php endif;?>
<?php if(is_array($smtpSmoke)): ?>
<p><b>Latest test:</b> <?=aur_escape((string)($smtpSmoke['status']??'UNKNOWN'))?> · <?=aur_escape((string)($smtpSmoke['checked_at']??''))?> UTC</p>
<p class="muted">To: <?=aur_escape((string)($smtpSmoke['recipient']??''))?>. <?=($smtpSmoke['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT'?'SMTP server accepted the internal test. Check the AURENOEVA inbox to confirm arrival.':'Transport delivery was not confirmed. Review the mail service configuration.'?></p>
<?php endif;?>
<?php if(is_array($smtpSmoke['transport_result']??null)): $tr=$smtpSmoke['transport_result']; ?>
<p class="muted">SMTP stage: <?=aur_escape((string)($tr['stage']??''))?> · Code: <?=(int)($tr['code']??0)?> · <?=aur_escape((string)($tr['response']??''))?></p>
<?php endif;?>
<p class="muted">A successful test is reused for 15 minutes; a failed test can be retried after correcting the configuration.</p>
</details>
</section>
<section class="card"><h2>Live product catalogue</h2>
<p>Searchable products in the website's private catalogue: <strong><?= $catalogueSnapshotCount === null ? 'Not synchronized' : (int)$catalogueSnapshotCount ?></strong>.</p>
<p class="muted">Snapshot updated: <?= aur_escape((string)($catalogueSnapshotUpdated ?? 'not yet synchronized')) ?>. Products: <?= (int)($catalogueSnapshotCount ?? 0) ?> · Manufacturers: <?= count($catalogueSnapshotManufacturers) ?>. Counts refer to searchable product identities, not stock.</p>
<?php if ($catalogueSnapshotCount !== null): ?>
<p class="muted">Technical classification uses product evidence from the synchronized catalogue. The 49 categories below include zero-count categories; a product can belong to several industries without becoming a duplicate product.</p>
<div class="aur-catalogue-facet-metrics">
<div><strong><?=number_format($catalogueFacetClassified, 0, '.', ',')?></strong><span>Evidence-classified products</span></div>
<div><strong><?=number_format($catalogueFacetMulti, 0, '.', ',')?></strong><span>Products in multiple industries</span></div>
<div><strong><?=number_format($catalogueFacetAssignments, 0, '.', ',')?></strong><span>Technical industry assignments</span></div>
</div>
<h3 class="aur-catalogue-section-title">7 established industries</h3>
<p class="muted">Primary-sector totals are shown prominently; evidence-backed technical matches appear inside each industry.</p>
<div class="aur-catalogue-facet-grid">
<?php foreach ($catalogueFacetTaxonomy as $industryKey => $technicalCategories): ?>
<details class="aur-catalogue-facet-sector">
<summary><span class="aur-catalogue-facet-sector-name"><?=aur_escape($catalogueSectorNames[$industryKey])?><small><?=count($technicalCategories)?> technical categories</small></span>
<span class="aur-catalogue-facet-sector-count"><?=number_format($catalogueSnapshotSectors[$industryKey] ?? 0, 0, '.', ',')?><small>primary-sector products</small></span></summary>
<div class="aur-catalogue-facet-rows">
<p class="aur-catalogue-evidence-count"><?=number_format($catalogueFacetIndustries[$industryKey], 0, '.', ',')?> products with verified technical categories</p>
<?php foreach ($technicalCategories as $technicalCategory): ?>
<div><span><?=aur_escape($technicalCategory)?></span><strong><?=number_format($catalogueFacetCategories[$industryKey][$technicalCategory], 0, '.', ',')?></strong></div>
<?php endforeach; ?>
</div>
</details>
<?php endforeach; ?>
</div>
<h3 class="aur-catalogue-section-title">12 additional industries</h3>
<p class="muted">These industry discovery topics are already searchable on the website. They are not yet assigned separate evidence-verified industry counts in the synchronized catalogue. A dash therefore means <em>not separately classified</em>, not zero available products.</p>
<div class="aur-catalogue-facet-grid aur-catalogue-additional-industries">
<?php foreach ($catalogueAdditionalIndustries as $industry): ?>
<div class="aur-catalogue-additional-card">
<div class="aur-catalogue-additional-head"><strong><?=aur_escape($industry['name'])?></strong><span title="No separately verified industry count">—</span></div>
<div class="aur-catalogue-additional-foot"><small>Discovery enabled · count not yet classified</small><a href="<?=aur_escape('/requirement.html?mode=exact&q='.rawurlencode($industry['query']))?>">Search catalogue ↗</a></div>
</div>
<?php endforeach; ?>
</div>
<details class="aur-catalogue-primary-breakdown"><summary>Historical primary-sector allocation (<?=number_format($catalogueSnapshotCount, 0, '.', ',')?> unique products)</summary>
<table style="width:100%;max-width:550px;border-collapse:collapse">
<thead><tr><th style="text-align:left">Sector</th><th style="text-align:right">Products</th></tr></thead><tbody>
<?php foreach ($catalogueSectorNames as $sectorKey=>$sectorName): ?>
<tr><td style="padding:5px 0"><?=aur_escape($sectorName)?></td><td style="text-align:right"><?= (int)($catalogueSnapshotSectors[$sectorKey] ?? 0) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p class="muted">These original sector assignments partition the catalogue and sum to the unique product total. The evidence-based industry counts above can overlap.</p>
</details>
<p class="muted">Worldwide supplier master: <strong><?= (int)$catalogueVendorTotal ?></strong> vendor websites · <strong><?= (int)$catalogueVendorWithContact ?></strong> contactable records · <strong><?= (int)$catalogueVendorProductLinks ?></strong> retained vendor–product relationships. Contact status and product evidence remain separate from available stock.</p>
<p><a href="?export_catalogue=1" style="font-weight:600;text-decoration:underline">Download catalogue CSV — published products, models and source links</a></p>
<details style="margin-top:12px"><summary style="cursor:pointer;font-weight:600">Products by sector and subcategory</summary>
<table style="width:100%;border-collapse:collapse;margin-top:10px">
<thead><tr><th style="text-align:left">Sector</th><th style="text-align:left">Category</th><th style="text-align:right">Products</th></tr></thead><tbody>
<?php foreach ($catalogueSnapshotCategories as $sectorKey=>$categories): ?>
<?php ksort($categories); foreach ($categories as $categoryName=>$categoryCount): ?>
<tr><td style="padding:4px 8px 4px 0"><?=aur_escape(ucfirst((string)$sectorKey))?></td>
<td><?=aur_escape((string)$categoryName)?></td><td style="text-align:right"><?= (int)$categoryCount ?></td></tr>
<?php endforeach; endforeach; ?>
</tbody></table>
<p class="muted">Categories absent from this table have no classified products in the current website snapshot.</p>
</details>
<?php endif; ?>
<p class="muted">Records are preserved across scans. The existing Hostinger crawler updates this catalogue after synchronization; supplier prices and stock remain private.</p>
<?php if ($catalogueSyncToken !== null): ?>
<p><strong>New sync key (shown only once):</strong></p>
<p><code style="display:block;overflow-wrap:anywhere;background:#eef3f1;padding:12px;border-radius:8px"><?=aur_escape($catalogueSyncToken)?></code></p>
<p class="muted">Copy this key into the VPS setup terminal. Do not send it in chat, email or GitHub. Generating another key invalidates the previous one.</p>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<button type="submit" name="generate_catalogue_sync" value="1">Generate / rotate catalogue sync key</button></form>
<?php endif; ?>
</section>
</details>
<?php endif; // authenticated procurement workspace ?>
<footer style="margin-top:35px"><a href="/">← AURENOEVA website</a><span class="muted"> · Private staff workspace</span></footer>
</div><?php if ($isAdmin && is_array($result) && isset($result['reference'])): ?>
<script defer src="/procurement/worldwide.js?v=12"></script>
<?php endif; ?><?php if($isAdmin): ?><script defer src="/procurement/workspace-tabs.js?v=7"></script><script defer src="/procurement/commercial-intelligence.js?v=20261103-1"></script><script defer src="/procurement/inbox-sync.js?v=1"></script><script defer src="/procurement/mail-center.js?v=5"></script><?php endif; ?></body></html>
