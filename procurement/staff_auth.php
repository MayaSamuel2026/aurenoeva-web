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

/**
 * Translate a small authenticated dashboard request into the EXISTING form
 * workflow. The signed staff cookie and credential-bound CSRF are validated
 * by index.php exactly as for an ordinary POST; this is not a new API.
 */
function aur_staff_procurement_json_post(string $body):array {
    if(strlen($body)<2||strlen($body)>26000)throw new InvalidArgumentException('invalid_dashboard_request_size');
    $request=json_decode($body,true,8,JSON_THROW_ON_ERROR);
    if(!is_array($request))throw new InvalidArgumentException('invalid_dashboard_request');
    foreach(['csrf','case_ref'] as $field) {
        if(!is_string($request[$field]??null))throw new InvalidArgumentException('invalid_dashboard_form');
    }
    $post=['csrf'=>$request['csrf'],'case_ref'=>$request['case_ref']];
    if(($request['task']??'')==='prepare') {
        $vendors=$request['vendors']??null;
        if(!is_array($vendors)||!array_is_list($vendors)||count($vendors)<1||count($vendors)>300)
            throw new InvalidArgumentException('invalid_dashboard_vendor_selection');
        foreach($vendors as $token) {
            if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))
                throw new InvalidArgumentException('invalid_dashboard_vendor_token');
        }
        $post['selected_vendors']=array_values(array_unique($vendors));
        $post['prepare_vendor_rfqs']='1';
        return $post;
    }
    if(($request['task']??'')==='send') {
        if(!is_string($request['draft_id']??null)
            ||!preg_match('/^[a-f0-9]{24}$/D',$request['draft_id']))
            throw new InvalidArgumentException('invalid_dashboard_draft');
        $post['draft_id']=$request['draft_id'];
        $post['manual_review']=($request['manual_review']??false)===true?'1':'0';
        $post['send_vendor_rfqs']='1';
        return $post;
    }
    if(($request['task']??'')==='continue') {
        if(!is_string($request['draft_id']??null)
            ||!preg_match('/^[a-f0-9]{24}$/D',$request['draft_id']))
            throw new InvalidArgumentException('invalid_dashboard_draft');
        $post['draft_id']=$request['draft_id'];
        // Continuation is allowed only if the stored draft records a prior
        // authenticated administrator approval.
        $post['continue_vendor_rfqs']='1';
        return $post;
    }
    throw new InvalidArgumentException('unknown_dashboard_request');
}
