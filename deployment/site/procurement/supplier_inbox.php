<?php
declare(strict_types=1);
/** Hostinger mailbox poller, using standard PHP IMAP extension. */
require_once __DIR__.'/supplier_inbox_store.php';
function aur_mail_sync(string $store,int $limit=20):array {
    $limit=max(1,min(30,$limit));
    $mutex=fopen(aur_mail_dir($store).'/sync.lock','c');
    if(!$mutex||!flock($mutex,LOCK_EX|LOCK_NB))
        return ['ok'=>true,'busy'=>true,'processed'=>0,'matched'=>0,'quotes_saved'=>0];
    $mailbox=null;
    try {
        if(!function_exists('imap_open'))throw new RuntimeException('php_imap_extension_unavailable');
        $cfg=aur_vendor_rfq_smtp_config();
        $mailbox=@imap_open('{imap.hostinger.com:993/imap/ssl/validate-cert}INBOX',
            $cfg['user'],$cfg['pass'],OP_READONLY,1);
        if(!$mailbox)throw new RuntimeException('hostinger_imap_auth_failed');
        $status=@imap_status($mailbox,'{imap.hostinger.com:993/imap/ssl/validate-cert}INBOX',SA_UIDVALIDITY);
        if(!$status||empty($status->uidvalidity))throw new RuntimeException('imap_uidvalidity_unavailable');
        $validity=(int)$status->uidvalidity;
        $state=aur_mail_state($store);
        $last=(int)($state['uidvalidity']??0)===$validity?(int)($state['last_uid']??0):0;
        $uids=@imap_search($mailbox,'UID '.($last+1).':*',SE_UID);
        $ids=is_array($uids)?array_values(array_filter($uids,static fn($id)=>(int)$id>$last)):[];
        sort($ids,SORT_NUMERIC);
        $processed=0;$matched=0;$saved=0;
        foreach(array_slice($ids,0,$limit) as $uid){
            $uid=(int)$uid;
            $overview=imap_fetch_overview($mailbox,(string)$uid,FT_UID);
            $size=(int)($overview[0]->size??0);
            $raw='';
            if($size<=4000000){
                $raw=(string)imap_fetchheader($mailbox,$uid,FT_UID|FT_PEEK).
                    (string)imap_body($mailbox,$uid,FT_UID|FT_PEEK);
            }
            $event=aur_mail_import($store,$uid,$validity,$raw);
            if($event['matched_to_sent_rfq'])$matched++;
            if($event['quote_recorded'])$saved++;
            $last=$uid;$processed++;
            aur_mail_write_json(aur_mail_dir($store).'/state.json',
                ['uidvalidity'=>$validity,'last_uid'=>$last,'checked_at'=>gmdate('c'),
                    'status'=>'PASS','last_error'=>null]);
        }
        if(!$ids)aur_mail_write_json(aur_mail_dir($store).'/state.json',
            ['uidvalidity'=>$validity,'last_uid'=>$last,'checked_at'=>gmdate('c'),
                'status'=>'PASS','last_error'=>null]);
        return ['ok'=>true,'processed'=>$processed,'matched'=>$matched,
            'quotes_saved'=>$saved,'more_pending'=>count($ids)>$limit,'last_uid'=>$last];
    }catch(Throwable $e){
        $state=aur_mail_state($store);
        $state['status']='ERROR';$state['last_error']=$e->getMessage();
        $state['checked_at']=gmdate('c');
        aur_mail_write_json(aur_mail_dir($store).'/state.json',$state);
        throw $e;
    }finally {
        if($mailbox!==null)imap_close($mailbox);
        flock($mutex,LOCK_UN);fclose($mutex);
    }
}
