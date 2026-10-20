<?php
declare(strict_types=1);
/**
 * AURENOEVA private case upload + readback.
 * Deploy ONLY as /crm/aurenoeva-v1/import.php alongside the established
 * CRM session guard. This file is NOT part of the public aurenoeva.com site.
 */
require_once dirname(__DIR__) . '/wetraq/session-guard.php';
noeva_wetraq_require_employee(false);
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'self\'');

function aur_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function aur_fail(int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'AURENOEVA import: ' . $message;
    exit;
}
$identity = noeva_wetraq_session_identity();
$uid = (string) ($identity['user_id'] ?? '');
$csrf = (string) ($identity['csrf'] ?? '');
if ($uid === '' || $csrf === '') aur_fail(401, 'authentication required');
$usersFile = dirname(__DIR__) . '/storage/users.json';
$users = is_readable($usersFile) ? json_decode((string) file_get_contents($usersFile), true) : null;
if (!is_array($users)) aur_fail(503, 'CRM user authority unavailable');
$actor = null;
foreach ($users as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === $uid && !empty($row['active'])) {
        $actor = $row;
        break;
    }
}
if (!$actor) aur_fail(403, 'user not active');
$role = (string) ($actor['role'] ?? 'read_only');
$readScope = $actor['read_scope'] ?? $actor['business_scope'] ?? [];
$writeScope = $actor['write_scope'] ?? [];
$permitted = static function ($scope) use ($role): bool {
    if ($role === 'global_admin') return true;
    if (!is_array($scope)) return false;
    $scope = array_map('strtolower', array_map('strval', $scope));
    return in_array('aurenoeva', $scope, true) || in_array('all', $scope, true);
};
if (!$permitted($readScope)) aur_fail(403, 'AURENOEVA read scope required');
$mayWrite = in_array($role, ['global_admin','operations','sales'], true) && $permitted($writeScope);

$tools = trim((string) (getenv('AURENOEVA_TOOLS_PATH') ?: '/opt/noeva-aurenoeva/app-tools'));
$db = trim((string) (getenv('AURENOEVA_PRIVATE_DB') ?: '/opt/noeva-aurenoeva/private/cases.sqlite3'));
$uploadDir = trim((string) (getenv('AURENOEVA_UPLOAD_DIR') ?: '/opt/noeva-aurenoeva/private/inbox'));
if ($tools === '' || $db === '' || $uploadDir === '' || !str_starts_with($tools, '/')
    || !str_starts_with($db, '/') || !str_starts_with($uploadDir, '/')
    || !is_file($tools . '/procurement_case_store.py')
    || !is_file($tools . '/procurement_intake.py')
    || str_contains($db, '/public_html/') || str_contains($uploadDir, '/public_html/')) {
    aur_fail(503, 'private importer not configured');
}
function aur_exec_python(array $command): array {
    if (!function_exists('proc_open')) aur_fail(503, 'private process execution disabled');
    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) aur_fail(503, 'import process unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], 3000000);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2], 2000);
    fclose($pipes[2]);
    $status = proc_close($process);
    $obj = is_string($stdout) ? json_decode($stdout, true) : null;
    if ($status !== 0 || !is_array($obj) || ($obj['ok'] ?? true) === false) {
        error_log('AURENOEVA importer error; exit=' . $status . '; detail=' . substr((string) $stderr, 0, 200));
        aur_fail(422, 'document or case operation unsuccessful');
    }
    return $obj;
}
function aur_python_command(string $tool, string $db, string $action, array $args=[]): array {
    $python = getenv('AURENOEVA_PYTHON_BIN') ?: '/usr/bin/python3';
    return aur_exec_python(array_merge([$python, $tool . '/procurement_case_store.py', $action, '--db', $db], $args));
}
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$mayWrite) aur_fail(403, 'AURENOEVA write scope required');
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) aur_fail(403, 'CSRF token mismatch');
    if (empty($_FILES['customer_file']) || !is_array($_FILES['customer_file'])) aur_fail(422, 'upload missing');
    $file = $_FILES['customer_file'];
    if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK
        || (int) ($file['size'] ?? 0) < 1 || (int) ($file['size'] ?? 0) > 20000000
        || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) aur_fail(422, 'invalid upload');
    $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx','csv'], true)) aur_fail(422, 'only XLSX or CSV accepted');
    $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/D', $country)) aur_fail(422, 'invalid destination country');
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0700, true)) aur_fail(503, 'private storage unavailable');
    $upload = rtrim($uploadDir, '/') . '/aur-' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $upload)) aur_fail(503, 'secure upload write failed');
    @chmod($upload, 0600);
    $result = aur_python_command($tools, $db, 'import', ['--source', $upload, '--country', $country]);
    if (($result['import_state'] ?? '') !== 'PERSISTED_PRIVATE_APP_CASE') {
        aur_fail(503, 'case not confirmed by database readback');
    }
    // The original file is retained as private evidence, never copied into Git.
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ref = trim((string) ($_GET['case'] ?? ''));
    if ($ref !== '' && !preg_match('/^[A-Za-z0-9_-]{4,96}$/D', $ref)) aur_fail(422, 'invalid case reference');
    $result = $ref ? aur_python_command($tools, $db, 'get', ['--case', $ref])
                   : aur_python_command($tools, $db, 'list');
} else aur_fail(405, 'method not allowed');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>AURENOEVA — private imports</title>
<style>body{margin:0;background:#f6f8f7;color:#142a26;font:16px/1.55 system-ui}main{max-width:1000px;margin:5vh auto;padding:30px;background:white;border-radius:15px;box-shadow:0 6px 34px #18362918}h1{font-size:32px}input,button,select{padding:11px;font:inherit}button{background:#134c40;color:white;border:0;border-radius:8px}a{color:#176454}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #dce6e1}code{word-break:break-all}section{margin:26px 0}</style>
<main><small>NOEVA SYSTEMS / AURENOEVA · AUTHENTICATED EMPLOYEE AREA</small><h1>Procurement cases</h1>
<p>Customer lists are private. No supplier enquiries are dispatched on upload and no customer prices are published.</p>
<?php if ($mayWrite): ?>
<section><h2>Import customer list</h2><form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=aur_html($csrf)?>">
<label>Destination country <input name="country" value="UZ" pattern="[A-Za-z]{2}" maxlength="2" required></label>
<label> Customer XLSX or CSV <input name="customer_file" type="file" accept=".xlsx,.csv" required></label>
<button type="submit">Import and verify</button></form></section>
<?php endif; ?>
<?php if (is_array($result) && isset($result['reference'])): ?>
<section><h2>Verified database readback</h2>
<p><b>Case:</b> <?=aur_html((string)$result['reference'])?>
 · <b>Entries:</b> <?= (int)($result['line_count']??0) ?>
 · <b>Alternative options:</b> <?= (int)($result['alternatives']??0) ?></p>
<p><b>Import status:</b> <?=aur_html((string)($result['import_state']??''))?>. Legal review required before RFQ dispatch.</p>
<p><a href="?case=<?=rawurlencode((string)$result['reference'])?>">Read case again from database</a></p>
</section>
<?php elseif (is_array($result) && isset($result['cases'])): ?>
<section><h2>Existing AURENOEVA imports</h2><table><thead><tr><th>Reference</th><th>Destination</th><th>Lines</th><th>Created</th></tr></thead><tbody>
<?php foreach ($result['cases'] as $case): ?>
<tr><td><a href="?case=<?=rawurlencode((string)$case['reference'])?>"><?=aur_html((string)$case['reference'])?></a></td>
<td><?=aur_html((string)$case['country'])?></td><td><?= (int)$case['line_count']?></td><td><?=aur_html((string)$case['created_at'])?></td></tr>
<?php endforeach; ?></tbody></table></section>
<?php endif; ?>
<p><a href="/aurenoeva-v1/">Back to sourcing workspace</a></p></main></html>