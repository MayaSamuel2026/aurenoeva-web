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

$example=aur_vendor_rfq_smtp_payload('buyer@example.com','Simple','Hello','abc@aurenoeva.com');
mailcenter_assert(!str_contains($example,'multipart/mixed')&&
    str_contains($example,'Content-Type: text/plain; charset=UTF-8')&&
    str_contains($example,base64_encode('Hello')),
    'Existing text-only RFQ email MIME format must remain unchanged');
$tmp=tempnam(sys_get_temp_dir(),'aur-attach-');
file_put_contents($tmp,"%PDF-1.4\nExample attachment\n");
$fixture=['name'=>['technical specification.pdf'],'error'=>[UPLOAD_ERR_OK],
    'size'=>[filesize($tmp)],'tmp_name'=>[$tmp]];
$parts=aur_mailcenter_validate_uploads($fixture,false);
$mailcenterBadName=aur_mailcenter_validate_uploads(array_merge($fixture,['name'=>["../unsafe\r\nfile.pdf"]]),false);
mailcenter_assert(!str_contains($mailcenterBadName[0]['name'],"\r") && !str_contains($mailcenterBadName[0]['name'],'/'),
    'Unsafe attachment filename characters must be sanitized');
mailcenter_assert(count($parts)===1&&$parts[0]['name']==='technical specification.pdf',
    'Normal outgoing attachment should validate');
$mime=aur_vendor_rfq_smtp_payload('buyer@example.com','Quotation','Please see attached',
    'abcd1234@aurenoeva.com',[[
    'name'=>'technical specification.pdf','type'=>'application/pdf',
    'size'=>filesize($tmp),'path'=>$tmp
]]);
mailcenter_assert(str_contains($mime,'multipart/mixed; boundary=') &&
    str_contains($mime,'filename*=UTF-8'.chr(39).chr(39).'technical%20specification.pdf') &&
    str_contains($mime,base64_encode("%PDF-1.4\nExample attachment\n")) &&
    !str_contains($mime,'Bcc:'),
    'MIME attachment content or encoding is incorrect');
foreach([
    array_merge($fixture,['error'=>[UPLOAD_ERR_INI_SIZE]]),
    array_merge($fixture,['size'=>[9000000]]),
    array_merge($fixture,['name'=>array_fill(0,6,'file.pdf'),
                          'error'=>array_fill(0,6,UPLOAD_ERR_OK),
                          'size'=>array_fill(0,6,1),
                          'tmp_name'=>array_fill(0,6,$tmp)]),
] as $unsafe){
    try{aur_mailcenter_validate_uploads($unsafe,false);
        throw new RuntimeException('Unsafe outgoing upload accepted');}
    catch(InvalidArgumentException $e){}
}
@unlink($tmp);
$ui=file_get_contents(dirname(__DIR__).'/deployment/site/procurement/index.php');
$js=file_get_contents(dirname(__DIR__).'/deployment/site/procurement/mail-center.js');
mailcenter_assert(str_contains($ui,'name="attachments[]"') &&
    str_contains($ui,'aur_mailcenter_send($aurStore,$_POST,(array)'),
    'Multipart uploads not wired through authenticated dashboard');
mailcenter_assert(str_contains($js,'new FormData(compose)') &&
    str_contains($js,"mail_action:'download_attachment'") &&
    !str_contains($ui,'data-mail-folder="INBOX.Drafts"'),
    'Client attachment upload / download unavailable or unwanted Drafts tab retained');

echo "AURENOEVA_MAIL_CENTER=PASS\n";
