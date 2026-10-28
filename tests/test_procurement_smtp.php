<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php';
function assert_smtp(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}

$root=sys_get_temp_dir().'/aurenoeva-smtp-'.bin2hex(random_bytes(7));
mkdir($root,0700,true);
$original=[
 'AURENOEVA_SMTP_HOST'=>getenv('AURENOEVA_SMTP_HOST'),
 'AURENOEVA_SMTP_USER'=>getenv('AURENOEVA_SMTP_USER'),
 'AURENOEVA_SMTP_PASSWORD'=>getenv('AURENOEVA_SMTP_PASSWORD'),
 'AURENOEVA_SMTP_PORT'=>getenv('AURENOEVA_SMTP_PORT'),
 'AURENOEVA_SMTP_SECURITY'=>getenv('AURENOEVA_SMTP_SECURITY')
];
try {
    putenv('AURENOEVA_SMTP_HOST=smtp.hostinger.com');
    putenv('AURENOEVA_SMTP_USER=info@aurenoeva.com');
    putenv('AURENOEVA_SMTP_PASSWORD=test-only-password');
    putenv('AURENOEVA_SMTP_PORT=465');
    putenv('AURENOEVA_SMTP_SECURITY=ssl');
    $cfg=aur_vendor_rfq_smtp_config();
    assert_smtp($cfg['host']==='smtp.hostinger.com'&&$cfg['port']===465,'hostinger SMTP config not loaded');
    assert_smtp(aur_vendor_rfq_smtp_ready(),'configured SMTP not recognized');
    // Exactly one supplier mail is sent to two SMTP envelope recipients:
    // supplier + undisclosed internal archive. A self-addressed test remains
    // one recipient. Never leak the internal copy in visible mail headers.
    $rcpts=aur_vendor_rfq_smtp_envelope_recipients('purchasing@supplier.example');
    assert_smtp($rcpts===['purchasing@supplier.example','info@aurenoeva.com'],
        'supplier RFQ must have exactly one invisible internal copy');
    assert_smtp(aur_vendor_rfq_smtp_envelope_recipients('INFO@AURENOEVA.COM')===
        ['INFO@AURENOEVA.COM'],'internal self-test must not create duplicate recipients');
    try {
        aur_vendor_rfq_smtp_envelope_recipients("fraud@example.com\r\nBcc: another@example.com");
        throw new RuntimeException('SMTP address header injection allowed');
    } catch(InvalidArgumentException $e) {
        assert_smtp($e->getMessage()==='invalid_recipient','invalid recipient error changed');
    }
    $source=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php');
    $smtpPart=substr($source,strpos($source,'function aur_vendor_rfq_send_transport_detail('),
        strpos($source,'function aur_vendor_rfq_send_transport(')-
        strpos($source,'function aur_vendor_rfq_send_transport_detail('));
    assert_smtp(str_contains($smtpPart,'RCPT_ARCHIVE') &&
        str_contains($smtpPart,'archive_copy_submitted') &&
        str_contains($smtpPart,"'DATA',[354],'DATA'") &&
        !str_contains($smtpPart,"'Bcc:") &&
        !str_contains($smtpPart,"'Cc:"),
        'internal copy must share original SMTP DATA and stay undisclosed to supplier');
    $smtpExchange=substr($source,strpos($source,'function aur_vendor_rfq_smtp_exchange('),
        strpos($source,'function aur_vendor_rfq_send_transport_detail(')-
        strpos($source,'function aur_vendor_rfq_smtp_exchange('));
    assert_smtp(substr_count($smtpExchange,'@fwrite(')===1,
        'SMTP stage must write each command once (previous duplicate-write regression)');

    $invalid=aur_vendor_rfq_send_transport_detail("bad\nx",'test','test');
    assert_smtp($invalid['accepted']===false && $invalid['outcome']==='NOT_SENT','invalid recipient was not rejected before SMTP');
    putenv('AURENOEVA_SMTP_SECURITY=none');
    try {aur_vendor_rfq_smtp_config();throw new RuntimeException('plaintext SMTP accepted');}
    catch(RuntimeException $e){assert_smtp($e->getMessage()==='rfq_smtp_invalid_configuration','wrong transport validation');}
    putenv('AURENOEVA_SMTP_SECURITY=ssl');

    aur_vendor_rfq_store_smtp_password($root,'test-only-mailbox-password');
    $stored=$root.'/supplier-rfqs/smtp-credential.json';
    assert_smtp(is_file($stored)&&!(fileperms($stored)&0077),'password file must be private (0600)');
    $raw=(string)file_get_contents($stored);
    assert_smtp(str_contains($raw,'test-only-mailbox-password'),'password not stored for website transport');

    $testRecord=['timestamp'=>time(),'status'=>'ACCEPTED_BY_MAIL_TRANSPORT',
        'transport_result'=>['transport'=>'AUTHENTICATED_SMTP','accepted'=>true]];
    aur_vendor_rfq_write($root.'/supplier-rfqs/aurenoeva-mail-smoke.json',$testRecord);
    assert_smtp(aur_vendor_rfq_mail_verified($root),'authenticated SMTP acceptance should unlock supplier approval');
    $testRecord['transport_result']['transport']='PHP_MAIL';
    aur_vendor_rfq_write($root.'/supplier-rfqs/aurenoeva-mail-smoke.json',$testRecord);
    assert_smtp(!aur_vendor_rfq_mail_verified($root),'historical PHP mail test must not authorize supplier SMTP');
    $testRecord['transport_result']['transport']='AUTHENTICATED_SMTP';
    $testRecord['timestamp']=time()-90000;
    aur_vendor_rfq_write($root.'/supplier-rfqs/aurenoeva-mail-smoke.json',$testRecord);
    assert_smtp(!aur_vendor_rfq_mail_verified($root),'expired SMTP test must not authorize future supplier mail');

    // Internal reminder CTA must invoke the same authenticated self-test,
    // with CSRF and case continuity, NOT a supplier send or direct SMTP bypass.
    $portal=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_portal.php');
    $entry=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/index.php');
    assert_smtp(str_contains($portal,'id="aur-reminder-smtp-test-form"') &&
      str_contains($portal,'name="send_test_rfq"') &&
      str_contains($portal,'name="smtp_return" value="reminders"') &&
      str_contains($portal,'name="csrf"') &&
      str_contains($portal,'aur_vendor_rfq_mail_verified($aurStore)') &&
      str_contains($portal,'Sending is disabled until an authenticated AURENOEVA mailbox test succeeds'),
      'Reminder SMTP verification CTA must retain authenticated mail safety gate');
    assert_smtp(str_contains($entry,"(\$_POST['smtp_return']??'')==='reminders'") &&
      str_contains($entry,'aur_vendor_rfq_send_smoke_test($aurStore)') &&
      str_contains($entry,"'&smtp_test='.\$smtpTestOutcome.'#aur-price-reminders'") &&
      !str_contains($entry,'aur_vendor_rfq_send_smoke_test($aurStore,static'),
      'Website SMTP test must return to reminders without supplier dispatch');
    $missing=aur_vendor_rfq_status_summary(['messages'=>[
        ['status'=>'ACCEPTED_BY_MAIL_TRANSPORT'],
        ['status'=>'TRANSPORT_FAILED_OR_UNCONFIRMED'],
        ['status'=>'IN_FLIGHT']
    ]]);
    assert_smtp($missing['accepted']===1&&$missing['failed_or_unconfirmed']===1&&
      $missing['in_flight']===1&&$missing['needs_reconciliation'],'legacy uncertain recipient statuses changed');
    echo "AURENOEVA_SMTP_TRANSPORT_CONTRACT=PASS\n";
} finally {
    foreach($original as $name=>$value) {
        if($value===false)putenv($name);else putenv($name.'='.$value);
    }
    foreach(glob($root.'/supplier-rfqs/*')?:[] as $file)@unlink($file);
    @rmdir($root.'/supplier-rfqs');@rmdir($root);
}
