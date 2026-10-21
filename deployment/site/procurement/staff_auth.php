<?php
declare(strict_types=1);
/**
 * AURENOEVA staff access without PHP session storage.
 * Hostinger has previously lost PHP sessions between requests; all tokens
 * are bound to the existing private administrator bcrypt hash.
 * A password change immediately revokes every token.
 *
 * No new credential database, no password reset, no plaintext secrets.
 */
const AUR_STAFF_COOKIE = 'aurenoeva_staff_v2';
const AUR_STAFF_TTL = 28800; // 8 hours
function aur_staff_key(string $passwordHash): string {
    if (!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/D', $passwordHash)) {
        throw new InvalidArgumentException('invalid_private_password_hash');
    }
    return hash('sha256', "AURENOEVA-STAFF-TOKENS-V2\0".$passwordHash, true);
}
function aur_staff_cookie_issue(string $passwordHash, ?int $now=null, ?string $nonce=null): string {
    $now=$now??time();
    $nonce=$nonce??bin2hex(random_bytes(12));
    if (!preg_match('/^[a-f0-9]{24}$/D',$nonce)) throw new InvalidArgumentException('invalid_nonce');
    $unsigned='v2.'.$now.'.'.$nonce;
    return $unsigned.'.'.hash_hmac('sha256','COOKIE|'.$unsigned,aur_staff_key($passwordHash));
}
function aur_staff_cookie_valid(string $passwordHash, string $token, ?int $now=null): bool {
    $now=$now??time();
    if (!preg_match('/^(v2\.([0-9]{10})\.[a-f0-9]{24})\.([a-f0-9]{64})$/D',$token,$m)) return false;
    $issued=(int)$m[2];
    if ($issued>$now+60 || $issued<$now-AUR_STAFF_TTL) return false;
    $expected=hash_hmac('sha256','COOKIE|'.$m[1],aur_staff_key($passwordHash));
    return hash_equals($expected,$m[3]);
}
function aur_staff_csrf_issue(string $passwordHash,string $context,?int $now=null,?string $nonce=null): string {
    $now=$now??time();
    $nonce=$nonce??bin2hex(random_bytes(8));
    if (!preg_match('/^[a-f0-9]{16}$/D',$nonce)) throw new InvalidArgumentException('invalid_nonce');
    $unsigned=$now.'.'.$nonce;
    return $unsigned.'.'.hash_hmac('sha256','CSRF|'.$context.'|'.$unsigned,aur_staff_key($passwordHash));
}
function aur_staff_csrf_valid(string $passwordHash,string $context,string $token,?int $now=null): bool {
    $now=$now??time();
    if (!preg_match('/^([0-9]{10}\.[a-f0-9]{16})\.([a-f0-9]{64})$/D',$token,$m)) return false;
    $timestamp=(int)explode('.',$m[1],2)[0];
    if ($timestamp>$now+60 || $timestamp<$now-3600) return false;
    $expected=hash_hmac('sha256','CSRF|'.$context.'|'.$m[1],aur_staff_key($passwordHash));
    return hash_equals($expected,$m[2]);
}
function aur_staff_csrf_context(bool $isAdmin,string $cookie):string {
    return $isAdmin?'authenticated:'.hash('sha256',$cookie):'anonymous';
}
function aur_staff_cookie_send(string $token,int $expires):void {
    setcookie(AUR_STAFF_COOKIE,$token,[
        'expires'=>$expires,'path'=>'/procurement','secure'=>true,
        'httponly'=>true,'samesite'=>'Lax',
    ]);
}
