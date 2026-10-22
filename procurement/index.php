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
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
require_once __DIR__ . '/supplier_intelligence.php';
require_once __DIR__ . '/supplier_discovery.php';
require_once __DIR__ . '/supplier_routes.php';
require_once __DIR__ . '/supplier_worldwide.php';
require_once __DIR__ . '/supplier_rfq.php';
require_once __DIR__ . '/supplier_case_report.php';
require_once __DIR__ . '/staff_auth.php';
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
    header('Content-Disposition: attachment; filename="'.$rfqRef.'-RFQ-DRAFT.txt"');
    header('Content-Length: '.strlen($body));
    echo $body;
    exit;
}
// Private, authenticated, case-owned customer report. Never publicly expose case files.
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['customer_report'])) {
    try {
        $ref=trim((string)$_GET['customer_report']);
        $case=aur_case_read($aurStore,$ref);
        try{$intel=aur_supplier_save($aurStore,$case);}
        catch(Throwable $e) {
            $intel=aur_supplier_read($aurStore,$ref,(string)$case['source_sha256']);
            if(!is_array($intel))$intel=aur_supplier_preview($case);
        }
        try{$intel=aur_worldwide_attach_master($aurStore,$intel);}catch(Throwable $e){}
        $saved=aur_supplier_report_save($aurStore,$case,$intel);
        $pdf=@file_get_contents($saved['path']);
        if(!is_string($pdf)||!str_starts_with($pdf,'%PDF-'))throw new RuntimeException('customer_pdf_unavailable');
    }catch(Throwable $e) {
        error_log('AURENOEVA customer report: '.get_class($e));
        aur_denied(503,'customer report temporarily unavailable; original case preserved');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$ref.'-CUSTOMER-SUPPLIER-REPORT.pdf"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf; exit;
}
// Historical customer reports are private, permanent enquiry attachments.
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['customer_report_saved'])) {
    try {
        $ref=trim((string)$_GET['customer_report_saved']);
        $version=trim((string)($_GET['version']??''));
        // A report may only be opened for an existing authorized case.
        $case=aur_case_read($aurStore,$ref);
        $file=aur_customer_report_version_path($aurStore,(string)$case['reference'],$version);
        $pdf=@file_get_contents($file);
        if(!is_string($pdf)||!str_starts_with($pdf,'%PDF-'))
            throw new RuntimeException('saved_report_unreadable');
    } catch(Throwable $e) {
        error_log('AURENOEVA archived report: '.get_class($e));
        aur_denied(404,'saved customer report unavailable');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$ref.'-CUSTOMER-SUPPLIER-REPORT-'.$version.'.pdf"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf; exit;
}

$result = null;
$supplierDiscoveryMessage = null;
$catalogueSyncToken = null;
$catalogueSnapshotCount = null;
$catalogueSnapshotSectors = [];
$catalogueSnapshotCategories = [];
$catalogueSnapshotManufacturers = [];
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
if ($isAdmin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consolidate_supplier_history'])) {
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
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_rfq'])) {
        try {
            $testStatus=aur_vendor_rfq_send_smoke_test($aurStore);
            $ref=trim((string)($_POST['case_ref']??''));
            $result=$ref!==''?aur_case_read($aurStore,$ref):aur_case_list($aurStore);
        } catch(Throwable $e) {
            error_log('AURENOEVA SMTP test: '.get_class($e));
            aur_denied(503,'Dummy RFQ transport test unavailable; no supplier contacted');
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
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference']).'&draft='.$draft['draft_id'],true,303);
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
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        (isset($_POST['send_vendor_rfqs'])||isset($_POST['continue_vendor_rfqs']))) {
        try {
            $result=aur_case_read($aurStore,trim((string)($_POST['case_ref']??'')));
            $draftId=trim((string)($_POST['draft_id']??''));
            $firstApproval=isset($_POST['send_vendor_rfqs']) && ($_POST['manual_review']??'')==='1';
            // A resumed, previously approved queue is allowed to continue
            // without repeating the legal-review checkbox.
            $dispatch=aur_vendor_rfq_dispatch($aurStore,$result,$draftId,$firstApproval,null,10);
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'status'=>$dispatch['status'],
                    'progress'=>$dispatch['dispatch_progress']??null],JSON_THROW_ON_ERROR);
                exit;
            }
            header('Location: /procurement/?case='.rawurlencode((string)$result['reference']).'&draft='.rawurlencode($draftId).'#supplier-history',true,303);
            exit;
        }catch(Throwable $e){
            error_log('AURENOEVA RFQ dispatch: '.get_class($e).' '.substr($e->getMessage(),0,120));
            if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false,'error'=>'rfq_dispatch_unavailable']);
                exit;
            }
            aur_denied(422,'RFQ dispatch not completed; check saved draft statuses before retrying');
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
            $result=$ref!==''?aur_case_read($aurStore,$ref):aur_case_list($aurStore);
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
    if (isset($_GET['draft']) && is_string($_GET['draft'])) {
        try {
            $rfqPreview=aur_vendor_rfq_read($aurStore,$result,(string)$_GET['draft']);
        } catch (Throwable $e) {
            error_log('AURENOEVA RFQ draft read: '.get_class($e));
            $supplierResearchMode='The requested draft could not be opened; the underlying case remains available.';
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
h1{font-size:clamp(32px,5vw,52px);letter-spacing:-.05em;line-height:1.1;margin:46px 0 12px}h2{font-size:22px;margin:0 0 18px;letter-spacing:-.02em}
p{color:#576560}a{color:#154f45}button,.button{background:#143f38;color:white;border:0;padding:12px 20px;border-radius:8px;font-weight:650;cursor:pointer}
button:hover{background:#255d53}.ghost{background:transparent;color:#143f38;border:1px solid #c5d2cd}
.card{background:white;border:1px solid #e0e7e4;border-radius:16px;padding:28px;margin-top:25px}
form.inline{display:flex;flex-wrap:wrap;gap:16px;align-items:end}label{display:grid;gap:7px;font-weight:600;font-size:14px}
input{border:1px solid #c5d2cd;border-radius:8px;padding:12px;font:inherit;background:white;max-width:100%}
input[type=file]{width:390px}.muted{font-size:13px;color:#6b7671}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px}
th,td{text-align:left;vertical-align:top;padding:12px;border-bottom:1px solid #e6ece9}th{color:#64736d;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.pill{display:inline-block;background:#edf4f1;border-radius:20px;padding:4px 10px;font-size:12px;color:#235c50}
@media(max-width:700px){.card{padding:18px}.top{align-items:flex-start}input[type=file]{width:100%}}
</style></head><body><div class="wrap"><header class="top"><div><div class="brand">AURENOEVA</div><div class="sub">Private sourcing administration</div></div>
<?php if ($isAdmin): ?><form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>"><button class="ghost" type="submit" name="logout" value="1">Sign out</button></form><?php endif; ?>
</header>
<?php if (!$isAdmin): ?>
<h1>Staff access</h1><p>Private procurement cases and customer source documents.</p>
<p class="muted">Sign in using the existing administrator password. Password recovery is handled by the site owner in Hostinger; no setup tokens or new login accounts are created here. Existing enquiries remain stored privately.</p>
<div class="card" style="max-width:460px"><h2>Sign in</h2>
<?php if ($error): ?><p role="alert"><?=aur_escape($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>"><label>Administrator password<input type="password" name="password" autocomplete="current-password" required></label>
<p><button type="submit">Open procurement workspace</button></p></form></div>
<?php else: ?>
<h1>Customer enquiries</h1><p>Upload once. AURENOEVA stores one private sourcing case, preserves alternatives and prepares its legal review. No supplier RFQ is sent automatically.</p>
<nav aria-label="Saved procurement cases" class="card" style="padding:14px 25px">
<b>Saved customer enquiries</b>
<?php try { $savedCaseIndex=aur_case_list($aurStore); } catch(Throwable $e) { $savedCaseIndex=['cases'=>[]]; } ?>
<?php if(!empty($savedCaseIndex['cases'])): ?>
<div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:10px">
<?php foreach($savedCaseIndex['cases'] as $savedCase): ?>
<a href="?case=<?=rawurlencode((string)$savedCase['reference'])?>"><?=aur_escape((string)$savedCase['reference'])?> · <?= (int)$savedCase['line_count']?> entries</a>
<?php endforeach; ?></div>
<?php else: ?><p class="muted">No saved enquiries available.</p><?php endif;?>
<p class="muted">Enquiries persist independently of supplier research. Nothing is deleted when you upload another case, send a test email or run worldwide discovery.</p>
</nav>
<?php
try{$aurSupplierHistory=aur_worldwide_research_inventory($aurStore,false);}
catch(Throwable $e) {
    error_log('AURENOEVA supplier historical count: '.get_class($e));
    $aurSupplierHistory=null;
}
?>
<section class="card" id="supplier-history-counts">
<h2>Accumulated supplier research — all saved RFQs</h2>
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
</section>
<?php if (is_array($result) && isset($result['reference'])): ?>
<section class="card"><span class="pill">Saved · verified database readback</span><h2 style="margin-top:16px">Case <?=aur_escape((string)$result['reference'])?></h2>
<p><b><?= (int)($result['requirements'] ?? 0) ?></b> numbered requirements ·
<b><?= (int)($result['line_count'] ?? 0) ?></b> entries ·
<b><?= (int)($result['alternatives'] ?? 0) ?></b> alternative options ·
<b><?=aur_escape((string)($result['country'] ?? ''))?></b> destination</p>
<p>Legal review: <strong><?=aur_escape((string)($result['legal_review']['compliance']['status'] ?? 'REVIEW_REQUIRED'))?></strong>.
Supplier outreach, pricing, shipment and any sanctions clearance are separate controlled actions.</p>
<p style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
<a class="button" href="?rfq=<?=rawurlencode((string)$result['reference'])?>">Download original case RFQ draft</a>
<a class="button" href="?customer_report=<?=rawurlencode((string)$result['reference'])?>">Generate / Download customer report (PDF)</a>
<a href="#original-rfq-equipment">View all original requested parts ↓</a> · <a href="#supplier-workspace">Supplier contacts and RFQ selection ↓</a>
</p>
<section id="customer-report-history" style="margin:17px 0 23px;padding:15px 17px;border:1px solid #dce6e1;border-radius:10px;background:#f7faf8">
<h3 style="font-size:17px;margin:0 0 7px">Customer PDF reports saved with this enquiry</h3>
<p class="muted" style="margin:0 0 10px">Generate / Download above saves an immutable PDF under case <?=aur_escape((string)$result['reference'])?>. New supplier research creates a new version; previous customer reports remain available here. Reload the case after a new download to update this list.</p>
<?php
try{$customerSavedReports=aur_customer_report_history($aurStore,(string)$result['reference']);}
catch(Throwable $e){error_log('AURENOEVA report history: '.get_class($e));$customerSavedReports=[];}
?>
<?php if($customerSavedReports):?>
<div class="scroll"><table style="font-size:13px">
<thead><tr><th>Saved (UTC)</th><th>Version</th><th>Customer PDF</th></tr></thead><tbody>
<?php foreach($customerSavedReports as $reportEntry):?>
<tr>
<td><?=aur_escape(gmdate('d M Y, H:i',(int)$reportEntry['timestamp']))?></td>
<td><?=aur_escape(substr((string)$reportEntry['version'],0,8))?></td>
<td><a href="?customer_report_saved=<?=rawurlencode((string)$result['reference'])?>&amp;version=<?=rawurlencode((string)$reportEntry['version'])?>">Download saved version ↗</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php else:?><p class="muted" style="margin:0">No archived customer reports yet. Use Generate / Download customer report to create the first saved version.</p><?php endif;?>
</section>
<section aria-labelledby="original-equipment-heading" id="original-rfq-equipment">
<h3 id="original-equipment-heading" style="margin-top:30px;font-size:21px">Original requested equipment — <?= (int)($result['line_count']??0) ?> saved entries</h3>
<p class="muted">This is your original uploaded customer enquiry, stored in the case record. It is always displayed, independently of worldwide supplier discovery, and is not overwritten by new research.</p>
<div class="scroll"><table><thead><tr><th>Item</th><th>Requested equipment / original specification</th><th>Quantity</th><th>Option</th></tr></thead><tbody>
<?php foreach (($result['payload']['sourcing_lines'] ?? []) as $line): ?>
<tr><td><?= (int)($line['customer_item_number']??0) ?></td><td><?=aur_escape((string)($line['description_original']??''))?></td>
<td><?=isset($line['quantity']) ? (int)$line['quantity'] : '—' ?></td>
<td><?=aur_escape((string)($line['option_type']??'REQUESTED'))?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="muted">Saved reference: <?=aur_escape((string)$result['reference'])?> · <a href="?case=<?=rawurlencode((string)$result['reference'])?>">Reload original enquiry</a></p>
</section>
<section id="supplier-search-controls" aria-label="Persistent worldwide supplier search" style="border-top:1px solid #dbe4df;margin-top:24px;padding-top:20px">
<h3>Search for suppliers worldwide</h3>
<p>This search remains available alongside the original enquiry, even if supplier research is temporarily unavailable. Previously discovered source links are preserved independently of the uploaded customer specifications.</p>
<form id="aur-worldwide-scan-form" method="post" action="/procurement/" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<button type="button" id="aur-worldwide-scan-all" data-total="<?= (int)($result['line_count']??count($result['payload']['sourcing_lines']??[]))?>">Discover suppliers worldwide for entire case</button>
<button type="submit" name="scan_worldwide" value="1" class="ghost">Scan next item worldwide</button>
</form>
<p id="aur-worldwide-progress" role="status" class="muted"></p>
<a href="#supplier-workspace">View saved supplier sources and prepare RFQs ↓</a>
</section>
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
<?php elseif (is_array($result) && isset($result['cases'])): ?>
<section class="card"><h2>Existing enquiries</h2><div class="scroll"><table><thead><tr><th>Case reference</th><th>Country</th><th>Items</th><th>Received (UTC)</th><th>RFQ</th></tr></thead><tbody>
<?php foreach ($result['cases'] as $case): ?><tr><td><a href="?case=<?=rawurlencode((string)$case['reference'])?>"><?=aur_escape((string)$case['reference'])?></a></td>
<td><?=aur_escape((string)$case['country'])?></td><td><?= (int)$case['line_count']?></td><td><?=aur_escape((string)$case['created_at'])?></td><td><a href="?rfq=<?=rawurlencode((string)$case['reference'])?>">Draft</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<section class="card">
<h2>Email automation — dummy RFQ test</h2>
<p>Send one clearly marked sample RFQ from <b>info@aurenoeva.com</b> to <b>fredericksamuel1965@gmail.com</b>, using the website's real outbound email transport. This does not contact any supplier or alter existing enquiries.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<?php if(is_array($result)&&isset($result['reference'])):?><input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>"><?php endif;?>
<button type="submit" name="send_test_rfq" value="1">Send one dummy RFQ to my Gmail</button>
</form>
<?php $smtpSmoke=aur_vendor_rfq_smoke_status($aurStore); ?>
<?php if(is_array($smtpSmoke)): ?>
<p><b>Latest test:</b> <?=aur_escape((string)($smtpSmoke['status']??'UNKNOWN'))?> · <?=aur_escape((string)($smtpSmoke['checked_at']??''))?> UTC</p>
<p class="muted">To: <?=aur_escape((string)($smtpSmoke['recipient']??''))?>. <?=($smtpSmoke['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT'?'Mail transport accepted the message. Check Gmail to confirm delivery.':'Transport delivery was not confirmed. Review the mail service configuration.'?></p>
<?php endif;?>
<p class="muted">A repeated click within 15 minutes returns the prior test result and does not send another email.</p>
</section>
<section class="card"><h2>Import Excel or CSV</h2><form method="post" enctype="multipart/form-data" class="inline">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<label>Destination country<input name="country" value="UZ" pattern="[A-Za-z]{2}" maxlength="2" required style="width:110px"></label>
<label>Customer sourcing list<input name="customer_file" type="file" accept=".xlsx,.csv" required></label>
<button type="submit">Create procurement case</button></form>
<p class="muted">Maximum 20 MB · Customer files and quotations stay private · Duplicate files reuse their existing case.</p></section>
<section class="card"><h2>Live product catalogue</h2>
<p>Searchable products in the website's private catalogue: <strong><?= $catalogueSnapshotCount === null ? 'Not synchronized' : (int)$catalogueSnapshotCount ?></strong>.</p>
<p class="muted">Snapshot updated: <?= aur_escape((string)($catalogueSnapshotUpdated ?? 'not yet synchronized')) ?>. Products: <?= (int)($catalogueSnapshotCount ?? 0) ?> · Manufacturers: <?= count($catalogueSnapshotManufacturers) ?>. Counts refer to searchable product identities, not stock.</p>
<?php if ($catalogueSnapshotCount !== null): ?>
<table style="width:100%;max-width:500px;border-collapse:collapse">
<thead><tr><th style="text-align:left">Sector</th><th style="text-align:right">Products</th></tr></thead>
<tbody>
<?php foreach (['laboratory'=>'Laboratory','test'=>'Test & Measurement','medical'=>'Medical','industrial'=>'Industrial','energy'=>'Energy','marine'=>'Marine','aviation'=>'Aviation','unclassified'=>'Unclassified / historical'] as $sectorKey=>$sectorName): ?>
<tr><td style="padding:5px 0"><?=aur_escape($sectorName)?></td><td style="text-align:right"><?= (int)($catalogueSnapshotSectors[$sectorKey] ?? 0) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
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
<?php endif; // authenticated procurement workspace ?>
<footer style="margin-top:35px"><a href="/">← AURENOEVA website</a><span class="muted"> · Private staff workspace</span></footer>
</div><?php if ($isAdmin && is_array($result) && isset($result['reference'])): ?>
<script defer src="/procurement/worldwide.js?v=5"></script>
<?php endif; ?></body></html>
