<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/mail_center.php';
function mailcenter_assert(bool $condition,string $why):void {
    if(!$condition)throw new RuntimeException($why);
}
mailcenter_assert(aur_mailcenter_folder('INBOX.Sent')==='INBOX.Sent','Missing Sent folder');
foreach(['../../etc/passwd','INBOX/;UID=1','INBOX.Sent?token=x'] as $unsafe){
    try{aur_mailcenter_folder($unsafe);throw new RuntimeException('Folder traversal accepted');}
    catch(InvalidArgumentException $e){}
}
mailcenter_assert(aur_mailcenter_uids("* SEARCH 5 1 30 5\r\nA1 OK\r\n")===[30,5,1],
    'Mailbox UIDs not parsed, sorted or de-duplicated');
mailcenter_assert(aur_mailcenter_uids("* SEARCH\r\nA1 OK\r\n")===[],'Empty mailbox not supported');
$valid=['send_key'=>str_repeat('a',32),'to'=>'supplier@example.com',
    'subject'=>'Re: AUR-UZ-1A04A7909185FACD','body'=>'Quotation received.'];
mailcenter_assert(aur_mailcenter_validate_send($valid)['to']==='supplier@example.com',
    'Valid manual message rejected');
foreach([
 ['to'=>"supplier@example.com\r\nBcc: attacker@example.com"],
 ['to'=>'supplier@example.com,other@example.com'],
 ['subject'=>"subject\r\nBcc: attacker@example.com"],
 ['body'=>''],
 ['send_key'=>'invalid-key'],
] as $bad){
    try{aur_mailcenter_validate_send(array_merge($valid,$bad));
        throw new RuntimeException('Unsafe manual mail accepted');}
    catch(InvalidArgumentException $e){}
}
$raw="From: Supplier <supplier@example.com>\r\nTo: info@aurenoeva.com\r\n".
    "Date: Sat, 24 Oct 2026 12:00:00 +0000\r\n".
    "Subject: Re: AUR-UZ-1A04A7909185FACD\r\n".
    "Content-Type: text/plain; charset=UTF-8\r\n\r\n".
    "We can supply the requested items.\r\n";
$summary=aur_mailcenter_summary('INBOX','14',$raw,true);
mailcenter_assert($summary['reference']==='AUR-UZ-1A04A7909185FACD'&&
    $summary['unread']===true&&$summary['folder']==='INBOX',
    'Supplier message not linked safely to case');
mailcenter_assert(!array_key_exists('body',$summary)&&strlen($summary['preview'])<=180,
    'Mailbox overview must expose a short preview, not a full body field');
echo "AURENOEVA_MAIL_CENTER=PASS\n";
