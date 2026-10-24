<?php
declare(strict_types=1);
/** libcurl IMAPS alternative when Hostinger's PHP IMAP extension is disabled. */
require_once __DIR__.'/supplier_inbox_store.php';
function aur_mail_curl_request(array $cfg,string $url,?string $command=null,int $maxBytes=4300000):string {
    if(!function_exists('curl_init'))throw new RuntimeException('php_imap_and_curl_unavailable');
    $handle=curl_init($url);
    if($handle===false)throw new RuntimeException('mailbox_curl_unavailable');
    $output='';
    curl_setopt_array($handle,[
        CURLOPT_USERNAME=>$cfg['user'],CURLOPT_PASSWORD=>$cfg['pass'],
        CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$output,$maxBytes):int{
            if(strlen($output)+strlen($chunk)>$maxBytes)return 0;
            $output.=$chunk;return strlen($chunk);
        }
    ]);
    if($command!==null)curl_setopt($handle,CURLOPT_CUSTOMREQUEST,$command);
    $ok=curl_exec($handle);
    $error=(int)curl_errno($handle);
    curl_close($handle);
    if($ok!==true||$error!==0) {
        if($error===23)throw new RuntimeException('imap_message_limit');
        throw new RuntimeException('hostinger_imaps_request_failed');
    }
    return $output;
}
function aur_mail_sync_curl(string $store,int $limit):array {
    $cfg=aur_vendor_rfq_smtp_config();
    $base='imaps://imap.hostinger.com/INBOX';
    $state=aur_mail_state($store);
    $status=aur_mail_curl_request($cfg,$base,'STATUS INBOX (UIDVALIDITY)',40000);
    if(!preg_match('/UIDVALIDITY\s+(\d+)/i',$status,$m))
        throw new RuntimeException('imap_uidvalidity_unavailable');
    $validity=(int)$m[1];
    $last=(int)($state['uidvalidity']??0)===$validity?(int)($state['last_uid']??0):0;
    $response=aur_mail_curl_request($cfg,$base,'UID SEARCH UID '.($last+1).':*',400000);
    preg_match('/^\*\s+SEARCH(.*)$/mi',$response,$m);
    $uids=[];
    foreach(preg_split('/\s+/',trim($m[1]??''))?:[] as $id)
        if(ctype_digit($id)&&(int)$id>$last)$uids[]=(int)$id;
    $uids=array_values(array_unique($uids));sort($uids,SORT_NUMERIC);
    $processed=0;$matched=0;$saved=0;
    foreach(array_slice($uids,0,$limit) as $uid){
        try{
            $raw=aur_mail_curl_request($cfg,$base.'/;UID='.$uid);
        }catch(RuntimeException $e){
            if($e->getMessage()!=='imap_message_limit')throw $e;
            $raw='';
        }
        $event=aur_mail_import($store,$uid,$validity,$raw);
        if($event['matched_to_sent_rfq'])$matched++;
        if($event['quote_recorded'])$saved++;
        $last=$uid;$processed++;
        aur_mail_write_json(aur_mail_dir($store).'/state.json',[
            'uidvalidity'=>$validity,'last_uid'=>$last,'checked_at'=>gmdate('c'),
            'status'=>'PASS','last_error'=>null,'transport'=>'CURL_IMAPS']);
    }
    if(!$uids)aur_mail_write_json(aur_mail_dir($store).'/state.json',[
        'uidvalidity'=>$validity,'last_uid'=>$last,'checked_at'=>gmdate('c'),
        'status'=>'PASS','last_error'=>null,'transport'=>'CURL_IMAPS']);
    return ['ok'=>true,'processed'=>$processed,'matched'=>$matched,
        'quotes_saved'=>$saved,'more_pending'=>count($uids)>$limit,
        'last_uid'=>$last,'transport'=>'CURL_IMAPS'];
}
