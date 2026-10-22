<?php
declare(strict_types=1);
/**
 * Existing Hostinger-native, CLI-only authorized RFQ queue worker.
 * A human must first approve a saved draft through /procurement/.
 * Re-running the job does NOT create/approve drafts or re-send accepted mail.
 *
 * Hostinger hPanel -> Websites -> Dashboard -> Cron Jobs -> PHP
 * Point the PHP job at this file, e.g. every minute as the hosting plan allows.
 * Run ONLY after sender transport has been tested and the site release verified.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Forbidden');}
require_once __DIR__.'/private_cases.php';
require_once __DIR__.'/supplier_intelligence.php';
require_once __DIR__.'/supplier_discovery.php';
require_once __DIR__.'/supplier_routes.php';
require_once __DIR__.'/supplier_worldwide.php';
require_once __DIR__.'/supplier_rfq.php';

try {
    $store=aur_store_root();
    $dir=aur_private_directory($store,'supplier-rfqs');
    $files=glob($dir.'/AUR-*.json')?:[];
    sort($files,SORT_STRING);
    $processed=0;$accepted=0;$pending=0;$reviewNeeded=0;$drafts=0;
    // Short, bounded runs. Hostinger can call again after this job finishes.
    foreach($files as $path) {
        if($processed>=20)break;
        if(is_link($path)||!is_file($path))continue;
        $draft=json_decode((string)@file_get_contents($path),true);
        if(!is_array($draft)||($draft['version']??'')!=='aurenoeva-rfq-outbox/1')continue;
        if(($draft['status']??'')!=='DISPATCHING')continue;
        if(!is_array($draft['approval']??null)||
           ($draft['approval']['acknowledged_by']??'')!=='AUTHENTICATED_ADMIN')continue;
        $ref=(string)($draft['case_reference']??'');
        $id=(string)($draft['draft_id']??'');
        if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref)
            ||!preg_match('/^[a-f0-9]{24}$/D',$id))continue;
        if(basename($path)!==$ref.'-'.$id.'.json')continue;
        try {
            $case=aur_case_read($store,$ref);
            $before=count(array_filter($draft['messages']??[],
                static fn($m)=>($m['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT'));
            $result=aur_vendor_rfq_dispatch($store,$case,$id,false,null,min(10,20-$processed));
            $now=(int)($result['dispatch_progress']['processed_this_request']??0);
            $processed+=$now;
            $accepted+=max(0,(int)($result['dispatch_progress']['accepted']??0)-$before);
            $pending+=(int)($result['dispatch_progress']['remaining']??0);
            $drafts++;
        }catch(Throwable $e) {
            $reviewNeeded++;
            error_log('AURENOEVA RFQ worker: '.get_class($e).' for '.$ref);
            // Other approved drafts should not be blocked by one error.
        }
    }
    echo json_encode(['ok'=>true,'authorized_drafts_checked'=>$drafts,
        'messages_attempted'=>$processed,'accepted_by_transport'=>$accepted,
        'still_queued'=>$pending,'review_required'=>$reviewNeeded],
        JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {
    error_log('AURENOEVA RFQ worker fatal: '.get_class($e));
    fwrite(STDERR,"AURENOEVA RFQ worker failed\n");
    exit(1);
}
