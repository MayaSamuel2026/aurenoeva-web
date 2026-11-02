<?php
declare(strict_types=1);
/**
 * Canonical Hostinger CLI refresh: mailbox reconciliation and fair, durable
 * case-by-case supplier research. No mail dispatch or regulatory clearance.
 * Every stored case eventually gets a turn; run time is bounded, not catalogue
 * size, supplier count, or the total number of cases.
 */
if (PHP_SAPI !== 'cli') {http_response_code(403);exit('Forbidden');}
require __DIR__.'/private_cases.php';
require __DIR__.'/supplier_intelligence.php';
require __DIR__.'/supplier_discovery.php';
require __DIR__.'/supplier_inbox.php';
require __DIR__.'/cycle_verification.php';

function aur_refresh_read_state(string $file):array {
    if (!is_file($file) || is_link($file)) return [];
    $data=json_decode((string)file_get_contents($file),true);
    return is_array($data)?$data:[];
}
function aur_refresh_write_state(string $file,array $value):void {
    if (is_link($file)) throw new RuntimeException('unsafe_refresh_state');
    $tmp=dirname($file).'/.refresh-'.bin2hex(random_bytes(8));
    $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)) throw new RuntimeException('refresh_state_write_failed');
    chmod($tmp,0600);
    if (!rename($tmp,$file)) { @unlink($tmp);throw new RuntimeException('refresh_state_commit_failed'); }
}
function aur_refresh_start_index(array $refs,string $after):int {
    if ($after==='') return 0;
    foreach ($refs as $i=>$reference) if (strcmp($reference,$after)>0) return $i;
    return 0;
}

$locked=null;
try {
    $store=aur_store_root();
    $dir=aur_private_directory($store,'supplier-intelligence');
    $locked=fopen($dir.'/.supplier-refresh.lock','c');
    if (!$locked || !flock($locked,LOCK_EX|LOCK_NB)) throw new RuntimeException('supplier_refresh_already_running');
    $started=gmdate('c');
    $clock=microtime(true);
    // A crash leaves RUNNING behind. The next scheduled execution resumes
    // from the durable per-case cursor; it never re-sends supplier emails.
    $receiptPath=$dir.'/supplier-refresh-latest.json';
    $priorReceipt=aur_refresh_read_state($receiptPath);
    $recoveredInterrupted=($priorReceipt['status']??'')==='RUNNING';
    aur_refresh_write_state($receiptPath,['status'=>'RUNNING',
        'started_at'=>$started,'last_successful_run_at'=>$priorReceipt['last_successful_run_at']??null,
        'last_complete_sweep_at'=>$priorReceipt['last_complete_sweep_at']??null]);
    $mail=['ok'=>false,'status'=>'NOT_RUN'];
    $mailBatches=0;$mailProcessed=0;
    try {
        do {
            $part=aur_mail_sync($store,30);
            $mailBatches++;
            $mailProcessed+=(int)($part['processed']??0);
            $mail=$part;
            if (($part['busy']??false)===true) {
                $mail['ok']=false;
                $mail['status']='MAILBOX_BUSY_RETRY_SCHEDULED';
                break;
            }
            if (($part['ok']??false)!==true || empty($part['more_pending'])) break;
            // A time budget is an execution checkpoint, never an inbox-size limit.
        } while (microtime(true)-$clock<25);
    } catch (Throwable $e) {
        $mail=['ok'=>false,'status'=>'ERROR','error'=>get_class($e),
            'more_pending'=>true];
        error_log('AURENOEVA supplier inbox failure: '.get_class($e));
    }
    $mail['batches_this_run']=$mailBatches;
    $mail['processed_this_run']=$mailProcessed;
    $listing=aur_case_list($store);
    $refs=[];
    foreach ($listing['cases']??[] as $case) {
        $ref=(string)($case['reference']??'');
        if ($ref!=='') $refs[$ref]=$ref;
    }
    $refs=array_values($refs);
    sort($refs,SORT_STRING);
    $cursorPath=$dir.'/supplier-refresh-cursor.json';
    $previous=aur_refresh_read_state($cursorPath);
    $start=aur_refresh_start_index($refs,(string)($previous['last_reference']??''));
    $out=[];$checked=0;$visited=0;$caseErrors=0;$providerFailures=0;
    $recoveries=0;$pendingRows=0;$assessedRows=0;$knownRows=0;$unavailableRows=0;
    $last=(string)($previous['last_reference']??'');
    $count=count($refs);
    for ($offset=0;$offset<$count;$offset++) {
        // Bound one cron execution, not the total discovery universe.
        if ($offset>0 && microtime(true)-$clock>=75) break;
        $ref=$refs[($start+$offset)%$count];
        try {
            $case=aur_case_read($store,$ref);
            $result=aur_supplier_discover_batch($store,$case,2);
            $checked+=(int)($result['processed']??0);
            $providerFailures+=(int)($result['provider_failures']??0);
            $recoveries+=(int)($result['fallback_recoveries']??0);
            $stats=$result['stats']??[];
            $pendingRows+=(int)($stats['pending']??0);
            $assessedRows+=(int)($stats['checked']??0);
            $knownRows+=(int)($stats['total']??0);
            $unavailableRows+=(int)($stats['provider_unavailable']??0);
            $out[]=['case'=>$ref,'checked'=>(int)($result['processed']??0),
                'new_unverified_links'=>(int)($result['new_unverified_links']??0),
                'provider_failures'=>(int)($result['provider_failures']??0),
                'fallback_recoveries'=>(int)($result['fallback_recoveries']??0),
                'outstanding_checks'=>(int)($stats['pending']??0)];
        } catch (Throwable $e) {
            $caseErrors++;
            error_log('AURENOEVA supplier case refresh failed '.hash('sha256',$ref).' '.get_class($e));
            $out[]=['case'=>$ref,'status'=>'ERROR','error_type'=>get_class($e)];
        }
        $visited++;
        $last=$ref;
        aur_refresh_write_state($cursorPath,['last_reference'=>$last,'updated_at'=>gmdate('c')]);
    }
    $wp09=null;
    try { $wp09=aur_wp09_cycle_report($store)['summary']; }
    catch (Throwable $e) {error_log('AURENOEVA verification failed: '.get_class($e));}
    $mailOk=($mail['ok']??false)===true;
    $ok=$mailOk && is_array($wp09) && $caseErrors===0 && $providerFailures===0;
    $status=!$ok?'DEGRADED':($visited<$count || $pendingRows>0 || !empty($mail['more_pending'])?'PARTIAL_RUN':'COMPLETE_SWEEP');
    $finished=gmdate('c');
    $report=['ok'=>$ok,'status'=>$status,'started_at'=>$started,'finished_at'=>$finished,
        'recovered_interrupted_previous_run'=>$recoveredInterrupted,
        'last_successful_run_at'=>$ok?$finished:($priorReceipt['last_successful_run_at']??null),
        'last_complete_sweep_at'=>$status==='COMPLETE_SWEEP'
            ?$finished:($priorReceipt['last_complete_sweep_at']??null),
        'duration_seconds'=>round(microtime(true)-$clock,2),
        'total_cases'=>$count,'cases_scanned'=>$visited,'remaining_this_sweep'=>max(0,$count-$visited),
        'last_reference'=>$last,'checked'=>$checked,'provider_failures'=>$providerFailures,
        'recovered_by_fallback'=>$recoveries,'source_rows_in_visited_cases'=>$knownRows,
        'assessed_rows_in_visited_cases'=>$assessedRows,'unresolved_rows_in_visited_cases'=>$pendingRows,
        'provider_unavailable_rows_in_visited_cases'=>$unavailableRows,
        'case_errors'=>$caseErrors,'cases'=>$out,'supplier_emails_sent'=>0,
        'legal_clearances_granted'=>0,'inbox'=>$mail,
        'wp09_verified_cycles'=>$wp09??['status'=>'UNAVAILABLE']];
    aur_refresh_write_state($receiptPath,$report);
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($ok?0:2);
} catch (Throwable $e) {
    fwrite(STDERR,'AURENOEVA supplier refresh failed: '.get_class($e)."\n");
    exit(1);
} finally {
    if (is_resource($locked)) {flock($locked,LOCK_UN);fclose($locked);}
}
