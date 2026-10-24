<?php
declare(strict_types=1);
/** Run with existing Hostinger PHP cron: reads mail, never sends mail. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Forbidden');}
require __DIR__.'/supplier_inbox.php';
try{
    $result=aur_mail_sync(aur_store_root(),30);
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    fwrite(STDERR,'AURENOEVA inbox reconciliation failed: '.$e->getMessage()."\n");
    exit(1);
}
