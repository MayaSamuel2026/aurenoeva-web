<?php
declare(strict_types=1);
/**
 * AURENOEVA staff procurement upload. Single app-owned case authority.
 * Hostinger must configure AURENOEVA_ADMIN_PASSWORD_HASH outside public Git
 * and install the existing Python case tools/private SQLite store on the same host.
 * NEVER expose uploaded files or customer data under public_html.
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
function aur_private_path(string $value, string $docroot): bool {
    if ($value === '' || $value[0] !== '/') return false;
    $parent = realpath(is_dir($value) ? $value : dirname($value));
    $root = realpath($docroot);
    return $parent !== false && $root !== false
        && $parent !== $root && !str_starts_with($parent . '/', rtrim($root, '/') . '/');
}
function aur_python(array $args): array {
    if (!function_exists('proc_open')) aur_denied(503, 'private importer unavailable');
    $pipes = [];
    $process = @proc_open($args, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) aur_denied(503, 'private importer unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 4000000);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2], 1000);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $result = is_string($stdout) ? json_decode($stdout, true) : null;
    if ($exit !== 0 || !is_array($result) || empty($result['ok'])) {
        error_log('AURENOEVA case process exit ' . $exit . ' ' . substr((string)$stderr, 0, 180));
        aur_denied(422, 'file format or case operation failed; check XLSX/CSV headers');
    }
    return $result;
}

$hashFile = (string)(getenv('AURENOEVA_ADMIN_HASH_FILE') ?: '/opt/noeva-aurenoeva/private/admin-password.hash');
$passwordHash = trim((string)(getenv('AURENOEVA_ADMIN_PASSWORD_HASH') ?: ''));
if ($passwordHash === '' && is_file($hashFile) && aur_private_path($hashFile, (string)($_SERVER['DOCUMENT_ROOT'] ?? ''))) {
    $passwordHash = trim((string)file_get_contents($hashFile));
}
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

$result = null;
if ($isAdmin) {
    $tools = (string)(getenv('AURENOEVA_TOOLS_PATH') ?: '/opt/noeva-aurenoeva/app-tools');
    $db = (string)(getenv('AURENOEVA_PRIVATE_DB') ?: '/opt/noeva-aurenoeva/private/cases.sqlite3');
    $inbox = (string)(getenv('AURENOEVA_UPLOAD_DIR') ?: '/opt/noeva-aurenoeva/private/inbox');
    $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if (!aur_private_path($db, $docroot) || !aur_private_path($inbox, $docroot)
        || !aur_private_path($tools, $docroot) || !is_file($tools.'/procurement_case_store.py')
        || !is_dir($inbox) || !is_writable($inbox)) {
        aur_denied(503, 'private case backend not installed on this host');
    }
    $cmd = static function (string $action, array $extra=[]) use ($tools,$db): array {
        $python = (string)(getenv('AURENOEVA_PYTHON_BIN') ?: '/usr/bin/python3');
        return aur_python(array_merge([$python, $tools.'/procurement_case_store.py', $action, '--db', $db], $extra));
    };
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['customer_file'])) {
        $file = $_FILES['customer_file'];
        $size = (int)($file['size'] ?? 0);
        $country = strtoupper(trim((string)($_POST['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/D', $country)) aur_denied(422, 'destination country must be ISO 2-letter code');
        if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK || $size < 1 || $size > 20000000
            || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) aur_denied(422, 'upload missing or over 20 MB');
        $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv'], true)) aur_denied(422, 'only XLSX or CSV accepted');
        $countryDir = rtrim($inbox,'/') . '/' . $country;
        if (is_link($countryDir) || (!is_dir($countryDir) && !@mkdir($countryDir, 0700, true))
            || !is_writable($countryDir)) aur_denied(503, 'private country intake directory unavailable');
        $target = $countryDir . '/aur-' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) aur_denied(503, 'private upload failed');
        @chmod($target, 0600);
        $result = $cmd('import', ['--source', $target, '--country', $country]);
        if (($result['import_state'] ?? '') !== 'PERSISTED_PRIVATE_APP_CASE') aur_denied(503, 'case persistence not verified');
        // Intentionally retain original evidence privately. The scheduled
        // worker adds the legal screening report to this SAME case database.
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ref = trim((string)($_GET['case'] ?? ''));
        if ($ref !== '' && !preg_match('/^[A-Za-z0-9_-]{4,96}$/D', $ref)) aur_denied(422, 'invalid case reference');
        $result = $ref !== '' ? $cmd('get', ['--case', $ref]) : $cmd('list');
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
<?php if (is_array($result) && isset($result['reference'])): ?>
<section class="card"><span class="pill">Saved · verified database readback</span><h2 style="margin-top:16px">Case <?=aur_escape((string)$result['reference'])?></h2>
<p><b><?= (int)($result['requirements'] ?? 0) ?></b> numbered requirements ·
<b><?= (int)($result['line_count'] ?? 0) ?></b> entries ·
<b><?= (int)($result['alternatives'] ?? 0) ?></b> alternative options ·
<b><?=aur_escape((string)($result['country'] ?? ''))?></b> destination</p>
<p>Legal review: <strong><?=aur_escape((string)($result['legal_review']['compliance']['status'] ?? 'REVIEW_REQUIRED'))?></strong>.
Supplier outreach, pricing, shipment and any sanctions clearance are separate controlled actions.</p>
<p><a href="?case=<?=rawurlencode((string)$result['reference'])?>">Read this case again from the database</a></p>
<div class="scroll"><table><thead><tr><th>Item</th><th>Equipment / specification</th><th>Quantity</th><th>Type</th></tr></thead><tbody>
<?php foreach (array_slice($result['payload']['sourcing_lines'] ?? [],0,250) as $line): ?>
<tr><td><?= (int)($line['customer_item_number'] ?? 0)?></td><td><?=aur_escape((string)($line['description_original'] ?? ''))?></td>
<td><?=isset($line['quantity']) ? (int)$line['quantity'] : '—'?></td><td><?=aur_escape((string)($line['option_type'] ?? ''))?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ((int)($result['line_count'] ?? 0)>250): ?><p class="muted">Showing first 250 entries; all entries are persisted privately.</p><?php endif; ?></section>
<?php elseif (is_array($result) && isset($result['cases'])): ?>
<section class="card"><h2>Existing enquiries</h2><div class="scroll"><table><thead><tr><th>Case reference</th><th>Country</th><th>Items</th><th>Received (UTC)</th></tr></thead><tbody>
<?php foreach ($result['cases'] as $case): ?><tr><td><a href="?case=<?=rawurlencode((string)$case['reference'])?>"><?=aur_escape((string)$case['reference'])?></a></td>
<td><?=aur_escape((string)$case['country'])?></td><td><?= (int)$case['line_count']?></td><td><?=aur_escape((string)$case['created_at'])?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php endif; ?>
<footer style="margin-top:35px"><a href="/">← AURENOEVA website</a><span class="muted"> · Private staff workspace</span></footer>
</div></body></html>
