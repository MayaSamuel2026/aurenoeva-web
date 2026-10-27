<?php
declare(strict_types=1);
/** Run with existing Hostinger PHP cron: reads mail, never sends mail. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Forbidden');}
require __DIR__.'/supplier_inbox.php';
require __DIR__.'/cycle_verification.php';
try{
    $result=aur_mail_sync(aur_store_root(),30);
    // Automatically recompute real-cycle evidence after the mailbox read.
    // Read-only and contains no buyer or supplier personal data in CLI output.
    try{$result['wp09_verified_cycles']=aur_wp09_cycle_report(aur_store_root())['summary'];}
    catch(Throwable $e){error_log('AURENOEVA WP09 cycle evidence: '.get_class($e));$result['wp09_verification']='UNAVAILABLE';}
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    fwrite(STDERR,'AURENOEVA inbox reconciliation failed: '.$e->getMessage()."\n");
    exit(1);
}
