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
/**
 * Stateless, authenticated activation-form token: Hostinger PHP session
 * persistence is not a prerequisite for this one-time setup. Signed with
 * the owner's never-public high-entropy activation code; no code is sent
 * back to the browser except the HMAC.
 */
/**
 * Recognize both historically documented activation-code locations, but
 * never accept one inside the public webroot. No new accounts or backends.
 */
function aur_setup_private_tokens(string $store): array {
    $candidates=[
        dirname($store).'/aurenoeva-activation-token.txt',
        $store.'/activation-token.txt',
        $store.'/aurenoeva-activation-token.txt',
    ];
    $valid=[];
    foreach ($candidates as $file) {
        if (!is_file($file) || is_link($file) || !is_readable($file)) continue;
        $length=@filesize($file);
        if (!is_int($length) || $length<32 || $length>256) continue;
        $secret=trim((string)@file_get_contents($file));
        if (strlen($secret)<32 || strlen($secret)>200 || preg_match('/\\s/', $secret)) continue;
        $valid[]=['path'=>$file,'secret'=>$secret];
    }
    return $valid;
}
function aur_setup_form_token(string $store, ?int $issuedAt = null): ?string {
    $tokens=aur_setup_private_tokens($store);
    if (!$tokens) return null;
    $issuedAt=$issuedAt ?? time();
    return (string)$issuedAt.'.'.hash_hmac('sha256','AURENOEVA_ACTIVATE_FORM_V2|'.$issuedAt,$tokens[0]['secret']);
}
function aur_setup_verify_form_token(string $store, string $provided, ?int $now = null): bool {
    $now=$now ?? time();
    if (!preg_match('/^([0-9]{10})\\.([a-f0-9]{64})$/D',$provided,$m)) return false;
    $issuedAt=(int)$m[1];
    if ($issuedAt>$now+30 || $issuedAt<$now-1800) return false;
    foreach (aur_setup_private_tokens($store) as $token) {
        $expected=(string)$issuedAt.'.'.hash_hmac('sha256','AURENOEVA_ACTIVATE_FORM_V2|'.$issuedAt,$token['secret']);
        if (hash_equals($expected,$provided)) return true;
    }
    return false;
}
function aur_setup_apply(string $store, string $suppliedToken, string $password, string $confirmation): string {
    // The file is created via the owner's authenticated Hostinger File Manager,
    // outside the public webroot. No hardcoded or guessable bootstrap secrets.
    $hashFile=$store.'/admin-password.hash';
    $lockFile=$store.'/activation.lock';
    if (is_link($hashFile) || is_link($lockFile)) {
        throw new RuntimeException('invalid_private_file');
    }
    $lock=@fopen($lockFile,'c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('activation_lock_unavailable');
    try {
        if (file_exists($hashFile)) return 'already_activated';
        $tokens=aur_setup_private_tokens($store);
        if (!$tokens) return 'activation_token_missing';
        $tokenFile=null;
        foreach ($tokens as $token) {
            if (hash_equals($token['secret'],$suppliedToken)) {
                $tokenFile=$token['path'];
                break;
            }
        }
        if ($tokenFile===null) return 'invalid_activation_code';
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

/**
 * Controlled Hostinger-owner password recovery.
 * The existing private activation token proves File Manager access. Replacing
 * this one password hash never touches customer cases, uploads, or catalogue.
 * Readback is verified BEFORE atomic replacement; prior tokens then become invalid.
 */
function aur_setup_recover(string $store, string $suppliedToken, string $password, string $confirmation): string {
    $hashFile=$store.'/admin-password.hash';
    $lockFile=$store.'/activation.lock';
    if (is_link($hashFile) || is_link($lockFile)) throw new RuntimeException('invalid_private_file');
    $lock=@fopen($lockFile,'c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('activation_lock_unavailable');
    try {
        $tokens=aur_setup_private_tokens($store);
        if (!$tokens) return 'activation_token_missing';
        $usedToken=null;
        foreach ($tokens as $token) {
            if (hash_equals($token['secret'],$suppliedToken)) { $usedToken=$token['path']; break; }
        }
        if ($usedToken===null) return 'invalid_activation_code';
        if (strlen($password)<16 || strlen($password)>256 || $password!==$confirmation)
            return 'password_invalid_or_mismatched';
        if (file_exists($hashFile) && !is_file($hashFile)) throw new RuntimeException('invalid_private_file');
        $hash=password_hash($password,PASSWORD_BCRYPT);
        if (!is_string($hash) || !str_starts_with($hash,'$2y
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
$hasCredential=is_file($hashPath) && !is_link($hashPath);
$formToken=aur_setup_form_token($store);
if ($hasCredential && $formToken===null) {
    header('Location: /procurement/',true,303);
    exit;
}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $origin=(string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin!=='' && $origin!=='https://aurenoeva.com' && $origin!=='https://www.aurenoeva.com') {
        aur_setup_fail(403,'invalid form origin');
    }
    if (!aur_setup_verify_form_token($store,(string)($_POST['csrf'] ?? ''))) {
        // The token file may not be at the expected hosting path, or a
        // browser may have submitted a pre-release form; neither is a
        // reason to relax one-time setup authentication.
        aur_setup_fail(403,'activation form expired or unavailable; reopen /procurement/activate.php and try again');
    }
    $limitPath=$store.'/activation-attempts.json';
    $rateLock=@fopen($store.'/activation-rate.lock','c');
    if (!$rateLock || !flock($rateLock,LOCK_EX)) aur_setup_fail(503,'activation rate limiter unavailable');
    try {
        $state=is_file($limitPath)?json_decode((string)file_get_contents($limitPath),true):null;
        if (!is_array($state) || (time()-(int)($state['started'] ?? 0))>3600) $state=['started'=>time(),'fails'=>0];
        if ((int)$state['fails']>=12) aur_setup_fail(429,'too many attempts; contact site owner');
        // A global 12/hour limit prevents distributed guesses across many IPs.
        $outcome=$hasCredential
            ? aur_setup_recover($store,(string)($_POST['code'] ?? ''),(string)($_POST['password'] ?? ''),(string)($_POST['confirm'] ?? ''))
            : aur_setup_apply($store,(string)($_POST['code'] ?? ''),(string)($_POST['password'] ?? ''),(string)($_POST['confirm'] ?? ''));
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
    if ($outcome==='activated' || $outcome==='already_activated' || $outcome==='recovered') {
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
<h1><?= $hasCredential ? 'Recover administrator access' : 'Activate private procurement' ?></h1>
<p><?= $hasCredential ? 'The application has a saved administrator credential, but you can replace it securely if your password is no longer accepted.' : 'No administrator credential is available to this application. Complete the one-time activation to restore access.' ?></p>
<p>In Hostinger File Manager, open <code>domains/aurenoeva.com/.aurenoeva-private/</code> beside <code>public_html</code>. Create <code>activation-token.txt</code> containing your own unique, unpredictable 32+ character code. Keep the code private and enter it below together with the new password. The code is removed after successful activation or recovery; your administrator password hash remains.</p>
<?php if ($error!==''):?><p class="error"><?=aur_setup_escape($error)?></p><?php endif;?>
<?php if ($formToken!==null): ?>
<form method="post" action="/procurement/activate.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=aur_setup_escape($formToken)?>">
<label for="code">Private activation code</label>
<input id="code" name="code" type="password" required autocomplete="off" minlength="32">
<label for="password">New administrator password</label>
<input id="password" name="password" type="password" minlength="16" maxlength="256" autocomplete="new-password" required>
<label for="confirm">Confirm administrator password</label>
<input id="confirm" name="confirm" type="password" minlength="16" maxlength="256" autocomplete="new-password" required>
<button type="submit"><?= $hasCredential ? 'Securely replace administrator password' : 'Activate administrator' ?></button>
</form>
<?php else: ?>
<p class="error">The private activation file was not found or contains an invalid code. Check it is <strong>outside public_html</strong>, named <code>aurenoeva-activation-token.txt</code> beside <code>public_html</code> or <code>activation-token.txt</code> inside <code>.aurenoeva-private</code>, and has at least 32 characters on one line. Then refresh.</p>
<?php endif; ?>
<small>The one-time code is deleted only after the persistent administrator credential has been saved and checked. Customer cases, catalogue records and supplier RFQs are not changed.</small>
</main></body></html>
))
            throw new RuntimeException('secure_password_hash_unavailable');
        $tmp=@tempnam($store,'.admin-credential-');
        if ($tmp===false) throw new RuntimeException('credential_temp_unavailable');
        try {
            @chmod($tmp,0600);
            if (file_put_contents($tmp,$hash."\n",LOCK_EX)!==strlen($hash)+1 ||
                !password_verify($password,trim((string)@file_get_contents($tmp)))) {
                throw new RuntimeException('credential_readback_failed');
            }
            if (!@rename($tmp,$hashFile)) throw new RuntimeException('credential_replace_failed');
            $tmp='';
        } finally {
            if ($tmp!=='' && is_file($tmp)) @unlink($tmp);
        }
        @chmod($hashFile,0600);
        if (!password_verify($password,trim((string)@file_get_contents($hashFile))))
            throw new RuntimeException('credential_final_readback_failed');
        // This is a one-time owner proof, not a persistent login file.
        if (!@unlink($usedToken)) error_log('AURENOEVA owner recovery token cleanup required');
        return 'recovered';
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
$formToken=aur_setup_form_token($store);
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $origin=(string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin!=='' && $origin!=='https://aurenoeva.com' && $origin!=='https://www.aurenoeva.com') {
        aur_setup_fail(403,'invalid form origin');
    }
    if (!aur_setup_verify_form_token($store,(string)($_POST['csrf'] ?? ''))) {
        // The token file may not be at the expected hosting path, or a
        // browser may have submitted a pre-release form; neither is a
        // reason to relax one-time setup authentication.
        aur_setup_fail(403,'activation form expired or unavailable; reopen /procurement/activate.php and try again');
    }
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
<p>One-time administrator setup. First create <code>aurenoeva-activation-token.txt</code> beside <code>public_html</code> in Hostinger File Manager. It must contain a unique, unpredictable code of at least 32 characters.</p>
<?php if ($error!==''):?><p class="error"><?=aur_setup_escape($error)?></p><?php endif;?>
<?php if ($formToken!==null): ?>
<form method="post" action="/procurement/activate.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?=aur_setup_escape($formToken)?>">
<label for="code">Private activation code</label>
<input id="code" name="code" type="password" required autocomplete="off" minlength="32">
<label for="password">New administrator password</label>
<input id="password" name="password" type="password" minlength="16" maxlength="256" autocomplete="new-password" required>
<label for="confirm">Confirm administrator password</label>
<input id="confirm" name="confirm" type="password" minlength="16" maxlength="256" autocomplete="new-password" required>
<button type="submit">Activate administrator</button>
</form>
<?php else: ?>
<p class="error">The private activation file was not found or contains an invalid code. Check it is <strong>outside public_html</strong>, named <code>aurenoeva-activation-token.txt</code> beside <code>public_html</code> or <code>activation-token.txt</code> inside <code>.aurenoeva-private</code>, and has at least 32 characters on one line. Then refresh.</p>
<?php endif; ?>
<small>The code is checked against a file outside public_html, consumed once, and never stored in GitHub. No supplier RFQ is sent during activation.</small>
</main></body></html>
