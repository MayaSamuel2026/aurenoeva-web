<?php
declare(strict_types=1);
/**
 * Hostinger cron entrypoint for incremental worldwide supplier research.
 * Runs only by CLI, writes exclusively to existing private case research files,
 * never emails suppliers or changes legal review/quotes.
 *
 * Suggested Hostinger cron (after deployment verification):
 * php /home/ACCOUNT/domains/aurenoeva.com/public_html/procurement/supplier-refresh-cli.php
 */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Forbidden');}
require __DIR__.'/private_cases.php';
require __DIR__.'/supplier_intelligence.php';
require __DIR__.'/supplier_discovery.php';
require __DIR__.'/supplier_inbox.php';
require __DIR__.'/cycle_verification.php';
try {
    $store=aur_store_root();
    // Reuse the existing scheduled Hostinger job; mailbox failures must not block supplier research.
    $mail=['status'=>'NOT_RUN'];
    try{$mail=aur_mail_sync($store,30);}catch(Throwable $e){
        $mail=['ok'=>false,'error'=>$e->getMessage()];
        error_log('AURENOEVA scheduled inbox: '.$e->getMessage());
    }
    $listing=aur_case_list($store);
    $out=[];$total=0;
    foreach (array_slice($listing['cases']??[],0,25) as $row) {
        // Bound a single cron run to at most eight live lookups in total.
        if ($total>=8) break;
        $case=aur_case_read($store,(string)$row['reference']);
        $result=aur_supplier_discover_batch($store,$case,min(2,8-$total));
        $total+=(int)$result['processed'];
        $out[]=['case'=>$case['reference'],'checked'=>$result['processed'],
            'new_unverified_links'=>$result['new_unverified_links'],
            'provider_failures'=>$result['provider_failures']];
    }
    // Existing scheduled supplier-refresh job also verifies communication evidence.
    // The verifier cannot send mail or turn an enquiry into a transaction.
    $wp09=['status'=>'UNAVAILABLE'];
    try{$wp09=aur_wp09_cycle_report($store)['summary'];}
    catch(Throwable $e){error_log('AURENOEVA WP09 cron verification: '.get_class($e));}
    echo json_encode(['ok'=>true,'checked'=>$total,'cases'=>$out,'supplier_emails_sent'=>0,
        'legal_clearances_granted'=>0,'inbox'=>$mail,'wp09_verified_cycles'=>$wp09],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR,"AURENOEVA supplier refresh failed: ".get_class($e)."\n");
    exit(1);
}
