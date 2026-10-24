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


/* Branded 11pt composer, strong-only formatting and unchanged RFQ semantics. */
mailcenter_assert(str_contains(aur_mailcenter_logo_url(),'aurenoeva-logo-approved.png'),
    'Approved AURENOEVA logo URL missing');
mailcenter_assert(str_contains(aur_mailcenter_signature_plain(),'AURENOEVA Purchasing') &&
    str_contains(aur_mailcenter_signature_plain(),'NOEVA Systems e.K.'),
    'Fixed mail signature missing');
$rich=aur_mailcenter_validate_send(array_merge($valid,[
    'body'=>"Dear Shaun,\nThe item is confirmed.",
    'body_html'=>'Dear Shaun,<br>The item is <strong>confirmed</strong>.'
]));
mailcenter_assert($rich['body_html']==='Dear Shaun,<br>The item is <strong>confirmed</strong>.',
    'Emphasis was not retained');
$document=aur_mailcenter_html_document($rich['body_html']);
mailcenter_assert(str_contains($document,'font-size:11pt') &&
    str_contains($document,'Arial,Helvetica,sans-serif') &&
    str_contains($document,'aurenoeva-logo-approved.png') &&
    str_contains($document,'<strong>confirmed</strong>') &&
    str_contains($document,'Kind regards,'),
    'Brand, 11pt sans-serif, bold or signature missing from outgoing HTML');
$plainMail=aur_vendor_rfq_smtp_payload(
    'supplier@example.org','RFQ original','Plain RFQ','abcd1234@aurenoeva.com');
mailcenter_assert(str_contains($plainMail,'Content-Type: text/plain; charset=UTF-8') &&
    !str_contains($plainMail,'multipart/alternative'),
    'Existing approved RFQ text-only MIME changed');
$htmlMail=aur_vendor_rfq_smtp_payload(
    'supplier@example.org','Re: quote',"Dear Shaun,\nThe item is confirmed.",
    'abcd1234@aurenoeva.com',[],$document);
mailcenter_assert(str_contains($htmlMail,'multipart/alternative') &&
    str_contains($htmlMail,'Content-Type: text/html; charset=UTF-8') &&
    preg_match('/Content-Type: text\/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)\r\n--/',$htmlMail,$htmlMatch) &&
    base64_decode($htmlMatch[1])===$document &&
    str_contains($htmlMail,base64_encode("Dear Shaun,\nThe item is confirmed.")),
    'Outgoing rich alternative missing either MIME part');
$attachedMail=aur_vendor_rfq_smtp_payload(
    'supplier@example.org','Re: quote','Hello','abcd1234@aurenoeva.com',[[
        'name'=>'technical specification.pdf','type'=>'application/pdf',
        'size'=>strlen('%PDF-test'),'path'=>(function(){
            $file=tempnam(sys_get_temp_dir(),'aur-format-');
            file_put_contents($file,'%PDF-test');
            return $file;
        })()
    ]],$document);
mailcenter_assert(str_contains($attachedMail,'multipart/mixed') &&
    str_contains($attachedMail,'multipart/alternative') &&
    str_contains($attachedMail,'Content-Disposition: attachment'),
    'Branded email lost attachment MIME nesting');
foreach([
    '<strong onclick="test">confirmed</strong>',
    '<img src="https://example.com/bad.png">',
    '<script>alert(1)</script>',
    '<strong>wrong</strong>'
] as $unsafe){
    try{aur_mailcenter_validate_send(array_merge($valid,[
        'body'=>'confirmed','body_html'=>$unsafe]));
        throw new RuntimeException('Unsafe or mismatched rich text was accepted');
    }catch(InvalidArgumentException $e){}
}
$sourceRoot=sys_get_temp_dir().'/aur-mail-rfq-'.bin2hex(random_bytes(6));
mkdir($sourceRoot.'/public_html',0700,true);
$caseStore=aur_store_root($sourceRoot.'/public_html');
$ref='AUR-UZ-1A04A7909185FACD';
$case=['reference'=>$ref,'source_sha256'=>hash('sha256','brand-test'),'country'=>'UZ',
       'line_count'=>1,'created_at'=>gmdate('c'),'payload'=>['sourcing_lines'=>[
           ['source_row'=>2,'customer_item_number'=>1,'description_original'=>'Test model','quantity'=>1]
       ]]];
aur_mail_write_json(aur_private_directory($caseStore,'cases').'/'.$ref.'.json',$case);
$draftId=bin2hex(random_bytes(12));
$draft=['case_reference'=>$ref,'case_source_sha256'=>$case['source_sha256'],
    'draft_id'=>$draftId,'created_at'=>gmdate('c'),'status'=>'TRANSPORT_ACCEPTED',
    'messages'=>[['to'=>'info@humanova.ae','subject'=>'RFQ '.$ref,
        'body'=>'Existing quotation schedule','status'=>'ACCEPTED_BY_MAIL_TRANSPORT',
        'attempted_at'=>gmdate('c'),'sent_at'=>gmdate('c'),
        'smtp_message_id'=>str_repeat('a',32).'@aurenoeva.com']]];
aur_vendor_rfq_write(aur_vendor_rfq_path($caseStore,$ref,$draftId),$draft);
$rfqSent=aur_mailcenter_rfq_sent($caseStore);
mailcenter_assert(count($rfqSent)===1&&
    $rfqSent[0]['reference']===$ref&&$rfqSent[0]['status']==='SMTP_ACCEPTED',
    'Approved previously sent RFQ omitted from consolidated Sent history');
$rfqRead=aur_mailcenter_read($caseStore,'INBOX.Sent',$rfqSent[0]['uid']);
mailcenter_assert($rfqRead['message']['body']==='Existing quotation schedule',
    'Original sent RFQ body inaccessible');
mailcenter_assert(str_contains($ui,'id="aur-mail-rich-editor"') &&
    str_contains($ui,'id="aur-mail-normal"') &&
    str_contains($ui,'id="aur-mail-bold"') &&
    str_contains($ui,'name="body_html"') &&
    str_contains($js,"event.clipboardData?.getData('text/plain')"),
    'Branding composer controls or plain-paste normalization missing');

echo "AURENOEVA_MAIL_CENTER=PASS\n";
