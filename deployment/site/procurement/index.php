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
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
try {
    $aurStore = aur_store_root();
} catch (Throwable $e) {
    aur_denied(503, 'private hosting directory unavailable');
}
$passwordHash = is_readable($aurStore.'/admin-password.hash')
    ? trim((string)file_get_contents($aurStore.'/admin-password.hash')) : '';
if ($passwordHash === '' || !str_starts_with($passwordHash, '$2y$')) {
    aur_denied(503, 'administrator credentials not configured');
}
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!$secure && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    aur_denied(403, 'HTTPS required');
}
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('aurenoeva_private_staff');
session_set_cookie_params(['lifetime'=>0,'path'=>'/procurement','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
$csrf = (string) $_SESSION['csrf'];
$isAdmin = !empty($_SESSION['admin'])
    && hash_equals((string)($_SESSION['credential_version'] ?? ''), hash('sha256', $passwordHash))
    && time() - (int)($_SESSION['seen'] ?? 0) < 3600 * 8;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    aur_denied(403, 'CSRF validation failed');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /procurement/');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !$isAdmin) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateFile = sys_get_temp_dir() . '/aur_staff_' . substr(hash('sha256', $ip), 0, 24);
    $record = is_file($rateFile) ? json_decode((string)@file_get_contents($rateFile), true) : null;
    if (!is_array($record) || time() - (int)($record['start'] ?? 0) > 900) $record = ['start'=>time(),'fails'=>0];
    if ((int)$record['fails'] >= 8) aur_denied(429, 'too many attempts, retry later');
    if (password_verify((string)$_POST['password'], $passwordHash)) {
        @unlink($rateFile);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['credential_version'] = hash('sha256', $passwordHash);
        $_SESSION['seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /procurement/');
        exit;
    }
    ++$record['fails'];
    @file_put_contents($rateFile, json_encode($record), LOCK_EX);
    $error = 'Incorrect password.';
}
if ($isAdmin) $_SESSION['seen'] = time();
if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['password'])) {
    aur_denied(401, 'authentication required');
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

$result = null;
$supplierDiscoveryMessage = null;
$catalogueSyncToken = null;
$catalogueSnapshotCount = null;
$catalogueSnapshotFile = $aurStore . '/catalogue-public.json';
if ($isAdmin && is_file($catalogueSnapshotFile)) {
    $catalogueSnapshot = json_decode((string)@file_get_contents($catalogueSnapshotFile), true);
    if (is_array($catalogueSnapshot) && isset($catalogueSnapshot['items']) && is_array($catalogueSnapshot['items'])) {
        $catalogueSnapshotCount = count($catalogueSnapshot['items']);
    }
}
if ($isAdmin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_catalogue_sync'])) {
        $catalogueSyncToken = bin2hex(random_bytes(32));
        $credentialFile = $aurStore . '/catalogue-sync-token.sha256';
        if (file_put_contents($credentialFile, hash('sha256', $catalogueSyncToken) . "\n", LOCK_EX) === false) {
            aur_denied(503, 'catalogue synchronization credential could not be stored');
        }
        @chmod($credentialFile, 0600);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discover_suppliers'])) {
        try {
            $caseRef=trim((string)($_POST['case_ref']??''));
            $result=aur_case_read($aurStore,$caseRef);
            $run=aur_supplier_discover_batch($aurStore,$result,2);
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
if ($isAdmin && is_array($result) && isset($result['reference'])) {
    try {
        $supplierResearch=aur_supplier_save($aurStore,$result);
    } catch (Throwable $e) {
        error_log('AURENOEVA private supplier research: '.get_class($e));
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
<div class="card" style="max-width:460px"><h2>Sign in</h2>
<?php if ($error): ?><p role="alert"><?=aur_escape($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>"><label>Administrator password<input type="password" name="password" autocomplete="current-password" required></label>
<p><button type="submit">Open procurement workspace</button></p></form></div>
<?php else: ?>
<h1>Customer enquiries</h1><p>Upload once. AURENOEVA stores one private sourcing case, preserves alternatives and prepares its legal review. No supplier RFQ is sent automatically.</p>
<section class="card"><h2>Import Excel or CSV</h2><form method="post" enctype="multipart/form-data" class="inline">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<label>Destination country<input name="country" value="UZ" pattern="[A-Za-z]{2}" maxlength="2" required style="width:110px"></label>
<label>Customer sourcing list<input name="customer_file" type="file" accept=".xlsx,.csv" required></label>
<button type="submit">Create procurement case</button></form>
<p class="muted">Maximum 20 MB · Customer files and quotations stay private · Duplicate files reuse their existing case.</p></section>
<section class="card"><h2>Live product catalogue</h2>
<p>Searchable products in the website's private catalogue: <strong><?= $catalogueSnapshotCount === null ? 'Not synchronized' : (int)$catalogueSnapshotCount ?></strong>.</p>
<p class="muted">Products are automatically updated by the existing Hostinger crawler after HTTPS synchronization is connected. No supplier prices or inventory claims are published.</p>
<?php if ($catalogueSyncToken !== null): ?>
<p><strong>New sync key (shown only once):</strong></p>
<p><code style="display:block;overflow-wrap:anywhere;background:#eef3f1;padding:12px;border-radius:8px"><?=aur_escape($catalogueSyncToken)?></code></p>
<p class="muted">Copy this key into the VPS setup terminal. Do not send it in chat, email or GitHub. Generating another key invalidates the previous one.</p>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<button type="submit" name="generate_catalogue_sync" value="1">Generate / rotate catalogue sync key</button></form>
<?php endif; ?>
</section>
<?php if (is_array($result) && isset($result['reference'])): ?>
<section class="card"><span class="pill">Saved · verified database readback</span><h2 style="margin-top:16px">Case <?=aur_escape((string)$result['reference'])?></h2>
<p><b><?= (int)($result['requirements'] ?? 0) ?></b> numbered requirements ·
<b><?= (int)($result['line_count'] ?? 0) ?></b> entries ·
<b><?= (int)($result['alternatives'] ?? 0) ?></b> alternative options ·
<b><?=aur_escape((string)($result['country'] ?? ''))?></b> destination</p>
<p>Legal review: <strong><?=aur_escape((string)($result['legal_review']['compliance']['status'] ?? 'REVIEW_REQUIRED'))?></strong>.
Supplier outreach, pricing, shipment and any sanctions clearance are separate controlled actions.</p>
<?php if (is_array($supplierResearch)): ?>
<div style="border-top:1px solid #e1e7e4;margin-top:24px;padding-top:23px">
<h2>Supplier intelligence — worldwide discovery</h2>
<p>Manufacturer matching plus worldwide supplier research for the <strong>existing private case</strong>. Search covers all countries; the destination country is used for delivery, servicing and legal review only. Public search results are leads, not quotations, stock or authorised distributors.</p>
<p><span class="pill"><b><?= (int)$supplierResearch['summary']['entries_with_oem_route']?></b> equipment entries with product / manufacturer routes</span>
<span class="pill"><b><?= (int)$supplierResearch['summary']['entries_needing_identity_research']?></b> unresolved</span>
<span class="pill">0 confirmed supplier quotes</span></p>
<?php $discoveryStats=aur_supplier_discovery_stats($supplierResearch); ?>
<p><span class="pill"><?= (int)$discoveryStats['checked'] ?> / <?= (int)$discoveryStats['total'] ?> entries searched online</span>
<span class="pill"><?= (int)$discoveryStats['links'] ?> unverified public seller/product links</span></p>
<form method="post" style="margin:15px 0">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<button type="submit" name="discover_suppliers" value="1">Search next 2 equipment entries worldwide</button>
</form>
<?php if ($supplierDiscoveryMessage): ?><p class="muted" role="status"><?=aur_escape($supplierDiscoveryMessage)?></p><?php endif; ?>
<p class="muted">Each click checks two unsearched or stale entries, keeping the website responsive. The result is saved with this case and refreshed after 24 hours. Automated search may be unavailable if the public search provider blocks access. Regional research links are always available.</p>
<p class="muted">Product evidence source set: <?=aur_escape((string)$supplierResearch['rule_version'])?>. Source verification and current country representation remain open. Alternatives stay distinct; sanctions, destination-use, registration and licensing checks remain mandatory before supplier outreach.</p>
<div class="scroll"><table><thead><tr><th>Item</th><th>Requested equipment</th><th>Potential supply route / evidence</th><th>Next research action</th></tr></thead><tbody>
<?php foreach (($supplierResearch['rows'] ?? []) as $candidate): ?>
<tr><td><?= (int)$candidate['customer_item_number'] ?>
<?php if (($candidate['option_type']??'')==='ALTERNATIVE'): ?><div class="muted">Alternative</div><?php endif;?></td>
<td><?=aur_escape((string)$candidate['description'])?></td><td>
<?php if (empty($candidate['sources'])): ?>
<span class="muted">Manufacturer not yet reliably identified.</span>
<?php else: foreach ($candidate['sources'] as $lead): ?>
<div style="margin-bottom:13px">
<b><?=aur_escape((string)$lead['company'])?></b> <small class="muted"><?=aur_escape((string)$lead['role'])?></small>
<div><a target="_blank" rel="noopener noreferrer nofollow" href="<?=aur_escape((string)$lead['evidence_url'])?>">Open source evidence ↗</a></div>
<div class="muted"><?=aur_escape((string)($lead['match_quality']??''))?> · availability and authorisation unverified.</div>
<?php if (!empty($lead['note'])): ?><div class="muted"><?=aur_escape((string)$lead['note'])?></div><?php endif;?>
</div>
<?php endforeach; endif;?>
</td><td>
<?php foreach (aur_supplier_global_search_links((string)$candidate['description'],aur_supplier_match((string)$candidate['description'])) as $region=>$url): ?>
<a style="display:inline-block;margin:0 9px 6px 0" target="_blank" rel="noopener noreferrer nofollow" href="<?=aur_escape($url)?>"><?=aur_escape($region)?> ↗</a>
<?php endforeach; ?>
<?php $publicRow=$supplierResearch['global_discovery']['rows'][(string)$candidate['source_row']]??null; ?>
<?php if (is_array($publicRow)): ?>
<div class="muted"><b><?=aur_escape((string)$publicRow['status'])?></b> · Checked <?=aur_escape((string)$publicRow['checked_at'])?></div>
<?php foreach (($publicRow['leads']??[]) as $webLead): ?>
<div style="margin-top:10px">
<a target="_blank" rel="noopener noreferrer nofollow" href="<?=aur_escape((string)$webLead['source_url'])?>"><?=aur_escape((string)$webLead['page_title'])?> ↗</a>
<div class="muted"><?=aur_escape((string)$webLead['website'])?> · unverified web discovery, not confirmed inventory</div>
<?php if (!empty($webLead['snippet'])): ?><div class="muted"><?=aur_escape((string)$webLead['snippet'])?></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?><div class="muted">Live discovery not yet run for this entry.</div><?php endif; ?>
<div class="muted"><?=aur_escape((string)$candidate['status'])?></div>
</td></tr>
<?php endforeach;?></tbody></table></div>
<p class="muted">Search links launch user-directed research. A listed OEM, historical dealer or web-search result is NOT evidence of confirmed stock, approved distribution in the destination, pricing, or lawful export. Every entity and shipment route requires individual screening.</p>
</div>
<?php else: ?><div class="card"><h2>Supplier identification pending</h2><p>The original procurement case is saved. Supplier matching will be available after the research source module is verified.</p></div><?php endif; ?>

<p><a class="button" href="?rfq=<?=rawurlencode((string)$result['reference'])?>">Download supplier RFQ draft</a></p>
<p class="muted">Automatically prepared from the saved enquiry · editable text · NOT SENT. Supplier identification, sanctions screening, goods classification and compliance review are required before dispatch.</p>
<p><a href="?case=<?=rawurlencode((string)$result['reference'])?>">Read this case again from the database</a></p>
<div class="scroll"><table><thead><tr><th>Item</th><th>Equipment / specification</th><th>Quantity</th><th>Type</th></tr></thead><tbody>
<?php foreach (array_slice($result['payload']['sourcing_lines'] ?? [],0,250) as $line): ?>
<tr><td><?= (int)($line['customer_item_number'] ?? 0)?></td><td><?=aur_escape((string)($line['description_original'] ?? ''))?></td>
<td><?=isset($line['quantity']) ? (int)$line['quantity'] : '—'?></td><td><?=aur_escape((string)($line['option_type'] ?? ''))?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ((int)($result['line_count'] ?? 0)>250): ?><p class="muted">Showing first 250 entries; all entries are persisted privately.</p><?php endif; ?></section>
<?php elseif (is_array($result) && isset($result['cases'])): ?>
<section class="card"><h2>Existing enquiries</h2><div class="scroll"><table><thead><tr><th>Case reference</th><th>Country</th><th>Items</th><th>Received (UTC)</th><th>RFQ</th></tr></thead><tbody>
<?php foreach ($result['cases'] as $case): ?><tr><td><a href="?case=<?=rawurlencode((string)$case['reference'])?>"><?=aur_escape((string)$case['reference'])?></a></td>
<td><?=aur_escape((string)$case['country'])?></td><td><?= (int)$case['line_count']?></td><td><?=aur_escape((string)$case['created_at'])?></td><td><a href="?rfq=<?=rawurlencode((string)$case['reference'])?>">Draft</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php endif; ?>
<footer style="margin-top:35px"><a href="/">← AURENOEVA website</a><span class="muted"> · Private staff workspace</span></footer>
</div></body></html>
