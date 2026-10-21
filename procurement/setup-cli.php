<?php
declare(strict_types=1);
/**
 * Owner-only Hostinger command-line administrator setup and reset.
 * Works on the existing private credential file; never changes procurement cases.
 * Examples: php setup-cli.php | php setup-cli.php --reset
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(__DIR__)) ?: '';
require __DIR__.'/private_cases.php';

try {
    $reset = ($argc === 2 && ($argv[1] ?? '') === '--reset');
    if ($argc > 1 && !$reset) throw new RuntimeException('use --reset to replace the existing administrator password');
    $store = aur_store_root();
    $password = getenv('AUR_SETUP_PASSWORD');
    if (!is_string($password) || strlen($password) < 16 || strlen($password) > 72) {
        throw new RuntimeException('set AUR_SETUP_PASSWORD to a new password of 16–72 characters');
    }
    $path = $store.'/admin-password.hash';
    if (is_link($path)) throw new RuntimeException('administrator password path must not be a symlink');
    if (is_file($path) && !$reset) {
        echo "Administrator password already exists; nothing changed.\n";
        echo "To intentionally reset it, run: php setup-cli.php --reset\n";
        exit(0);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if (!is_string($hash) || !password_verify($password, $hash)) {
        throw new RuntimeException('password hashing failed');
    }
    $temporary = tempnam($store, '.admin-new-');
    if ($temporary === false) throw new RuntimeException('cannot create temporary credential');
    try {
        if (file_put_contents($temporary, $hash."\n", LOCK_EX) !== strlen($hash)+1) {
            throw new RuntimeException('credential write failed');
        }
        chmod($temporary, 0600);
        if (!password_verify($password, trim((string)file_get_contents($temporary)))) {
            throw new RuntimeException('credential verification failed');
        }
        if (!rename($temporary, $path)) throw new RuntimeException('cannot replace credential atomically');
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
    aur_private_directory($store,'cases');
    aur_private_directory($store,'uploads');
    echo $reset ? "AURENOEVA_PRIVATE_ADMIN_RESET=PASS\n" : "AURENOEVA_PRIVATE_ADMIN_SETUP=PASS\n";
    echo "AURENOEVA_PRIVATE_DATA_PRESERVED=PASS\n";
    echo "AURENOEVA_PRIVATE_CREDENTIAL_FILE=".$path."\n";
    echo "AURENOEVA_PROCUREMENT_LOGIN=https://aurenoeva.com/procurement/\n";
} catch (Throwable $e) {
    fwrite(STDERR,"AURENOEVA_ADMIN_COMMAND_FAILED: ".$e->getMessage()."\n");
    exit(2);
}
