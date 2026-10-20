<?php
declare(strict_types=1);
/** One-time, CLI-only private administrator bootstrap; credentials never enter Git. */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(__DIR__)) ?: '';
require __DIR__.'/private_cases.php';
try {
    $store=aur_store_root();
    $pass=getenv('AUR_SETUP_PASSWORD');
    if (!is_string($pass) || strlen($pass)<16 || strlen($pass)>256) {
        throw new RuntimeException('password must be 16–256 characters (use AUR_SETUP_PASSWORD environment)');
    }
    $path=$store.'/admin-password.hash';
    if (is_file($path)) {
        echo "Existing administrator password preserved. Login at https://aurenoeva.com/procurement/\n";
        exit(0);
    }
    $hash=password_hash($pass,PASSWORD_BCRYPT);
    $handle=fopen($path,'x');
    if (!$handle) throw new RuntimeException('cannot create private administrator password file');
    if (!fwrite($handle,$hash."\n")) {
        fclose($handle);@unlink($path);
        throw new RuntimeException('cannot save private administrator password');
    }
    fclose($handle);@chmod($path,0600);
    aur_private_directory($store,'cases');
    aur_private_directory($store,'uploads');
    echo "AURENOEVA_PRIVATE_ADMIN_SETUP=PASS\n";
    echo "AURENOEVA_PRIVATE_DATA_OUTSIDE_PUBLIC_HTML=PASS\n";
    echo "AURENOEVA_PROCUREMENT_LOGIN=https://aurenoeva.com/procurement/\n";
} catch (Throwable $e) {
    fwrite(STDERR,"AURENOEVA_SETUP_FAILED: ".$e->getMessage()."\n");
    exit(2);
}
