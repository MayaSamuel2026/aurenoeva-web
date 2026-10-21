<?php
declare(strict_types=1);
/**
 * One-time Hostinger File Manager activation: no SSH, public token, PHP shell,
 * hardcoded password, or new backend. Requires a private setup secret supplied
 * by the website owner beside public_html in aurenoeva-activation-token.txt.
 */
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function aur_setup_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function aur_setup_fail(int $status, string $reason): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'AURENOEVA setup: ' . $reason;
    exit;
}
function aur_setup_apply(string $store, string $suppliedToken, string $password, string $confirmation): string {
    // The file is created via the owner's authenticated Hostinger File Manager,
    // outside the public webroot. No hardcoded or guessable bootstrap secrets.
    $hashFile=$store.'/admin-password.hash';
    $tokenFile=dirname($store).'/aurenoeva-activation-token.txt';
    $lockFile=$store.'/activation.lock';
    if (is_link($hashFile) || is_link($tokenFile) || is_link($lockFile)) {
        throw new RuntimeException('invalid_private_file');
    }
    $lock=@fopen($lockFile,'c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('activation_lock_unavailable');
    try {
        if (file_exists($hashFile)) return 'already_activated';
        if (!is_file($tokenFile) || !is_readable($tokenFile)) return 'activation_token_missing';
        $size=@filesize($tokenFile);
        if (!is_int($size) || $size < 32 || $size > 256) return 'invalid_activation_token_file';
        $expected=trim((string)@file_get_contents($tokenFile));
        if (strlen($expected) < 32 || strlen($expected) > 200
            || preg_match('/\s/', $expected)) return 'invalid_activation_token_file';
        if (!hash_equals($expected, $suppliedToken)) return 'invalid_activation_code';
        if (strlen($password) < 16 || strlen($password) > 256
            || $password !== $confirmation) return 'password_invalid_or_mismatched';
        $hash=password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($hash) || !str_starts_with($hash,'$2y$')) {
            throw new RuntimeException('secure_password_hash_unavailable');
        }
        $file=@fopen($hashFile,'x'); // Never clobber an existing administrator.
        if (!$file) return 'already_activated';
        try {
            if (@fwrite($file,$hash."\n")!==strlen($hash)+1) {
                throw new RuntimeException('credential_write_failed');
            }
        } catch (Throwable $e) {
            fclose($file);
            @unlink($hashFile);
            throw $e;
        }
        fclose($file);
        @chmod($hashFile,0600);
        if (!password_verify($password,trim((string)file_get_contents($hashFile)))) {
            @unlink($hashFile);
            throw new RuntimeException('credential_readback_failed');
        }
        // Burn the one-time secret immediately after verified password creation.
        if (!@unlink($tokenFile)) {
            // Credential exists but no second activation is possible.
            error_log('AURENOEVA activation code cleanup required');
        }
        return 'activated';
    } finally {
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
if (defined('AUR_ACTIVATION_TEST_LIBRARY')) return;
require_once __DIR__.'/private_cases.php';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https';
if (!$secure && PHP_SAPI !== 'cli-server') aur_setup_fail(403,'HTTPS required');
try {
    $store=aur_store_root();
} catch(Throwable $e) {
    aur_setup_fail(503,'private hosting directory unavailable');
}
$hashPath=$store.'/admin-password.hash';
if (is_file($hashPath) && !is_link($hashPath)) {
    header('Location: /procurement/',true,303);
    exit;
}
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('aurenoeva_admin_setup');
session_set_cookie_params(['lifetime'=>0,'path'=>'/procurement','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf'] ?? ''))) aur_setup_fail(403,'invalid request token');
    $limitPath=$store.'/activation-attempts.json';
    $rateLock=@fopen($store.'/activation-rate.lock','c');
    if (!$rateLock || !flock($rateLock,LOCK_EX)) aur_setup_fail(503,'activation rate limiter unavailable');
    try {
        $state=is_file($limitPath)?json_decode((string)file_get_contents($limitPath),true):null;
        if (!is_array($state) || (time()-(int)($state['started'] ?? 0))>3600) $state=['started'=>time(),'fails'=>0];
        if ((int)$state['fails']>=12) aur_setup_fail(429,'too many attempts; contact site owner');
        // A global 12/hour limit prevents distributed guesses across many IPs.
        $outcome=aur_setup_apply($store,(string)($_POST['code'] ?? ''),(string)($_POST['password'] ?? ''),(string)($_POST['confirm'] ?? ''));
        if ($outcome!=='activated' && $outcome!=='already_activated') {
            $state['fails']=(int)$state['fails']+1;
            @file_put_contents($limitPath,json_encode($state),LOCK_EX);
        }
    } catch(Throwable $e) {
        error_log('AURENOEVA activation error: '.get_class($e));
        $outcome='activation_unavailable';
    } finally {
        flock($rateLock,LOCK_UN);
        fclose($rateLock);
    }
    if ($outcome==='activated' || $outcome==='already_activated') {
        session_regenerate_id(true);
        header('Location: /procurement/',true,303);
        exit;
    }
    $error=$outcome==='password_invalid_or_mismatched'
        ? 'Choose matching administrator passwords of at least 16 characters.'
        : ($outcome==='activation_token_missing'
           ? 'Create aurenoeva-activation-token.txt beside public_html in File Manager first.'
           : 'Activation was not completed. Check the activation code and private setup file.');
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>AURENOEVA · Secure administrator activation</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#16231f;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:520px;margin:min(10vh,80px) auto 35px;padding:30px 34px;background:#fff;border:1px solid #dce5e1;border-radius:14px;box-shadow:0 18px 55px #182a2412}
h1{font-size:27px;letter-spacing:-.035em;line-height:1.2}p{color:#586761}label{display:block;margin:20px 0 6px;font-size:13px;font-weight:700}
input{display:block;width:100%;font:inherit;border:1px solid #b4c4bc;padding:12px 14px;border-radius:7px}
button{width:100%;margin-top:26px;padding:15px;border:0;border-radius:7px;background:#172e27;color:#fff;font-weight:700;font-size:15px;cursor:pointer}
small{display:block;margin:25px 0;color:#64736b;font-size:12px}.error{padding:12px 15px;color:#85221f;background:#fff1ef;border:1px solid #f4c4bc;border-radius:7px}
.wordmark{font-size:13px;letter-spacing:.21em;font-weight:800}
</style></head><body>
<main><div class="wordmark">AURENOEVA</div>
<h1>Activate private procurement</h1>
<p>One-time administrator setup. First create <code>aurenoeva-activation-token.txt</code> beside <code>public_html</code> in Hostinger File Manager using Hostinger File Manager. It must contain a unique, unpredictable code of at least 32 characters.</p>
<?php if ($error!==''):?><p class="error"><?=aur_setup_escape($error)?></p><?php endif;?>
<form method="post" action="/procurement/activate.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=aur_setup_escape((string)$_SESSION['csrf'])?>">
<label for="code">Private activation code</label>
<input id="code" name="code" type="password" required autocomplete="off" minlength="32">
<label for="password">New administrator password</label>
<input id="password" name="password" type="password" minlength="16" maxlength="256" autocomplete="new-password" required>
<label for="confirm">Confirm administrator password</label>
<input id="confirm" name="confirm" type="password" minlength="16" maxlength="256" autocomplete="new-password" required>
<button type="submit">Activate administrator</button>
</form>
<small>The code is checked against a file outside public_html, consumed once, and never stored in GitHub. No supplier RFQ is sent during activation.</small>
</main></body></html>
