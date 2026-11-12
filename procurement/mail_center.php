<?php
declare(strict_types=1);
/**
 * Staff-only mailbox reader and manual sender for the existing procurement dashboard.
 * Hostinger stays authoritative for received mail; previously approved RFQ
 * dispatch, supplier reply classification and private case storage are unchanged.
 *
 * This file is a library: index.php enforces HTTPS, signed staff login and CSRF.
 * No mailbox password, mail body or token is exposed to unauthenticated routes.
 */
require_once __DIR__.'/supplier_inbox_curl.php';
require_once __DIR__.'/mail_branding.php';
require_once __DIR__.'/supplier_attachment_pack.php';

function aur_mailcenter_folder(string $folder):string {
    if(!in_array($folder,['INBOX','INBOX.Sent','INBOX.Drafts','INBOX.Trash','INBOX.Junk'],true))
        throw new InvalidArgumentException('invalid_mail_folder');
    return $folder;
}
function aur_mailcenter_uid(string $uid):string {
    if(!preg_match('/^(?:[1-9][0-9]{0,11}|local-[a-f0-9]{32}|rfq-[a-f0-9]{32})$/D',$uid))
        throw new InvalidArgumentException('invalid_mail_uid');
    return $uid;
}
function aur_mailcenter_validate_send(array $input):array {
    $to=strtolower(trim((string)($input['to']??'')));
    $subject=trim((string)($input['subject']??''));
    $body=trim((string)($input['body']??''));
    $key=(string)($input['send_key']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$key))
        throw new InvalidArgumentException('invalid_mail_send_key');
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)||strpbrk($to,"\r\n,;")!==false||strlen($to)>254)
        throw new InvalidArgumentException('invalid_mail_recipient');
    if($subject===''||strlen($subject)>500||strpbrk($subject,"\r\n")!==false)
        throw new InvalidArgumentException('invalid_mail_subject');
    if($body===''||strlen($body)>50000||str_contains($body,"\0"))
        throw new InvalidArgumentException('invalid_mail_body');
    $language=strtolower(trim((string)($input['language']??'en')));
    if(!in_array($language,['en','de'],true))
        throw new InvalidArgumentException('invalid_mail_signature_language');
    $rich=trim((string)($input['body_html']??''));
    $safeRich=$rich!==''?aur_mailcenter_clean_rich_text($rich,$body)
        :nl2br(htmlspecialchars($body,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),false);
    return ['to'=>$to,'subject'=>$subject,'body'=>$body,'body_html'=>$safeRich,
        'language'=>$language,'send_key'=>$key];
}

/** Mail attachments are stored privately by the immutable send key, not publicly accessible. */
function aur_mailcenter_attachments_dir(string $store,string $id):string {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('invalid_mail_send_key');
    $dir=aur_private_directory($store,'mail-center').'/attachments';
    if(is_link($dir)||(!is_dir($dir)&&!@mkdir($dir,0700)))
        throw new RuntimeException('mail_attachment_storage_unavailable');
    $destination=$dir.'/'.$id;
    if(is_link($destination)||(!is_dir($destination)&&!@mkdir($destination,0700)))
        throw new RuntimeException('mail_attachment_storage_unavailable');
    return $destination;
}
function aur_mailcenter_validate_uploads(array $files,bool $uploaded=true):array {
    if(!$files)return [];
    if(!isset($files['name'],$files['error'],$files['size'],$files['tmp_name'])
        ||!is_array($files['name'])||!is_array($files['error'])||
        !is_array($files['size'])||!is_array($files['tmp_name']))
        throw new InvalidArgumentException('invalid_mail_attachment');
    $count=count($files['name']);
    if($count>5)throw new InvalidArgumentException('too_many_mail_attachments');
    $out=[];$total=0;
    foreach($files['name'] as $index=>$original) {
        if(!is_int($index)||!array_key_exists($index,$files['error'])
           ||!array_key_exists($index,$files['size'])
           ||!array_key_exists($index,$files['tmp_name']))
            throw new InvalidArgumentException('invalid_mail_attachment');
        $error=(int)$files['error'][$index];
        if($error===UPLOAD_ERR_NO_FILE)continue;
        if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('mail_attachment_upload_failed');
        if(!is_string($original)||!is_string($files['tmp_name'][$index]))
            throw new InvalidArgumentException('invalid_mail_attachment');
        $name=trim((string)preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u','_',str_replace('\\','/',$original)));
        $size=(int)$files['size'][$index];
        $path=$files['tmp_name'][$index];
        if($name===''||$name==='.'||$name==='..'||strlen($name)>200
           ||$size<1||$size>8388608||!is_file($path)||is_link($path)
           ||filesize($path)!==$size||($uploaded&&!is_uploaded_file($path)))
            throw new InvalidArgumentException('invalid_mail_attachment');
        $total+=$size;
        if($total>10485760)throw new InvalidArgumentException('mail_attachments_too_large');
        $out[]=['name'=>$name,'size'=>$size,'tmp_name'=>$path];
    }
    return $out;
}
function aur_mailcenter_store_uploads(string $store,string $id,array $files):array {
    $uploads=aur_mailcenter_validate_uploads($files);
    if(!$uploads)return [];
    $dir=aur_mailcenter_attachments_dir($store,$id);
    $stored=[];
    foreach($uploads as $index=>$upload) {
        $path=$dir.'/'.$index.'.bin';
        if(is_file($path)||is_link($path))throw new RuntimeException('mail_attachment_conflict');
        if(!move_uploaded_file($upload['tmp_name'],$path))throw new RuntimeException('mail_attachment_store_failed');
        @chmod($path,0600);
        $type='application/octet-stream';
        if(class_exists('finfo')){
            $detected=(new finfo(FILEINFO_MIME_TYPE))->file($path);
            if(is_string($detected)&&preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$detected))
                $type=$detected;
        }
        $stored[]=['name'=>$upload['name'],'size'=>$upload['size'],'type'=>$type,'path'=>$path];
    }
    return $stored;
}
/** Save the approved brochure beside uploaded attachments only when selected. */
function aur_mailcenter_attach_brochure(string $store,string $id,array $existing,string $language='en'):array {
    $brochure=aur_supplier_brochure_asset($language);
    $source=$brochure['path'];
    if(!is_file($source)||is_link($source)||!is_readable($source))
        throw new RuntimeException('mail_brochure_missing');
    $bytes=filesize($source);
    if(!is_int($bytes)||$bytes<100||$bytes>8388608)
        throw new RuntimeException('mail_brochure_invalid');
    if(count($existing)>=5)throw new InvalidArgumentException('too_many_mail_attachments');
    if(array_sum(array_map(static fn($a)=>(int)$a['size'],$existing))+$bytes>10485760)
        throw new InvalidArgumentException('mail_attachments_too_large');
    $dir=aur_mailcenter_attachments_dir($store,$id);
    $target=$dir.'/'.count($existing).'.bin';
    if(is_file($target)||is_link($target))throw new RuntimeException('mail_attachment_conflict');
    if(!copy($source,$target))throw new RuntimeException('mail_attachment_store_failed');
    @chmod($target,0600);
    $existing[]=['name'=>$brochure['name'],
        'size'=>$bytes,'type'=>'application/pdf','path'=>$target];
    return $existing;
}
function aur_mailcenter_sent_attachment(string $store,string $uid,int $index):array {
    $uid=aur_mailcenter_uid($uid);
    if(!str_starts_with($uid,'local-')||$index<0||$index>4)
        throw new InvalidArgumentException('invalid_mail_attachment');
    $id=substr($uid,6);
    foreach(aur_mailcenter_local($store) as $entry) {
        if(($entry['id']??'')!==$id)continue;
        $attachment=$entry['attachments'][$index]??null;
        if(!is_array($attachment))throw new InvalidArgumentException('mail_attachment_not_found');
        $path=aur_mailcenter_attachments_dir($store,$id).'/'.$index.'.bin';
        if(is_link($path)||!is_file($path)||filesize($path)!==(int)($attachment['size']??-1))
            throw new RuntimeException('mail_attachment_not_available');
        return ['path'=>$path,'name'=>(string)$attachment['name'],
            'size'=>(int)$attachment['size'],'type'=>(string)$attachment['type']];
    }
    throw new InvalidArgumentException('mail_not_found');
}

function aur_mailcenter_local_path(string $store):string {
    return aur_private_directory($store,'mail-center').'/sent.json';
}
function aur_mailcenter_local(string $store):array {
    $path=aur_mailcenter_local_path($store);
    if(is_link($path))throw new RuntimeException('unsafe_mail_history');
    $data=is_file($path)?json_decode((string)file_get_contents($path),true):null;
    return is_array($data)&&is_array($data['entries']??null)?$data['entries']:[];
}
function aur_mailcenter_write_local(string $store,array $entries):void {
    $path=aur_mailcenter_local_path($store);
    if(is_link($path))throw new RuntimeException('unsafe_mail_history');
    aur_mail_write_json($path,['schema'=>'aurenoeva-mail-center-sent/1','entries'=>$entries]);
}
function aur_mailcenter_imap(string $folder,?string $command=null,int $maxBytes=4300000):string {
    $folder=aur_mailcenter_folder($folder);
    $cfg=aur_vendor_rfq_smtp_config();
    return aur_mail_curl_request($cfg,'imaps://imap.hostinger.com/'.$folder,$command,$maxBytes);
}
function aur_mailcenter_uids(string $raw):array {
    $ids=[];
    foreach(preg_split('/\r?\n/',$raw)?:[] as $line) {
        if(!preg_match('/^\*\s+SEARCH(?:\s+(.*))?$/i',$line,$m))continue;
        foreach(preg_split('/\s+/',trim((string)($m[1]??'')))?:[] as $value)
            if(preg_match('/^[1-9][0-9]{0,11}$/D',$value))$ids[]=(int)$value;
    }
    $ids=array_values(array_unique($ids));
    rsort($ids,SORT_NUMERIC);
    return $ids;
}
function aur_mailcenter_source(string $folder,string $uid):string {
    $folder=aur_mailcenter_folder($folder);
    if(!preg_match('/^[1-9][0-9]{0,11}$/D',$uid))throw new InvalidArgumentException('invalid_mail_uid');
    $cfg=aur_vendor_rfq_smtp_config();
    return aur_mail_curl_request($cfg,'imaps://imap.hostinger.com/'.$folder.'/;UID='.$uid);
}
function aur_mailcenter_summary(string $folder,string $uid,string $raw,bool $unread=false):array {
    $headers=aur_mail_headers($raw);
    $mail=aur_mail_parse($raw);
    $text=(string)($mail['text']??'');
    return [
        'uid'=>$uid,'folder'=>$folder,
        'from'=>(string)($headers['from']??$mail['from']),
        'to'=>(string)($headers['to']??''),
        'date'=>(string)($mail['received_at']??''),
        'subject'=>(string)($mail['subject']?:'(No subject)'),
        'preview'=>substr(preg_replace('/\s+/u',' ',$text)??$text,0,180),
        'unread'=>$unread,
        'message_id'=>(string)($headers['message-id']??''),
        'has_attachments'=>(bool)preg_match('/^Content-Disposition:\s*attachment/im',$raw),
        'reference'=>preg_match('/AUR-[A-Z]{2}-[A-F0-9]{16}/',(string)$mail['subject'],$m)?$m[0]:null
    ];
}
function aur_mailcenter_local_summary(array $entry):array {
    $subject=(string)($entry['subject']??'');
    return [
        'uid'=>'local-'.(string)$entry['id'],'folder'=>'INBOX.Sent',
        'from'=>'info@aurenoeva.com','to'=>(string)($entry['to']??''),
        'date'=>(string)($entry['created_at']??''),'subject'=>$subject,
        'preview'=>substr(preg_replace('/\s+/u',' ',(string)($entry['body']??''))??'',0,180),
        'unread'=>false,'has_attachments'=>!empty($entry['attachments']),
        'message_id'=>(string)($entry['smtp_message_id']??''),
        'imap_sent_archive'=>(string)($entry['imap_sent_archive']??''),
        'status'=>(string)($entry['status']??'IN_FLIGHT'),
        'reference'=>preg_match('/AUR-[A-Z]{2}-[A-F0-9]{16}/',$subject,$m)?$m[0]:null
    ];
}

/**
 * Read existing approved RFQ dispatch ledger into the same Sent screen.
 * No duplicate persistence, new database or modification to RFQ dispatch.
 */
function aur_mailcenter_rfq_sent(string $store):array {
    $found=[];
    foreach(aur_case_list($store)['cases']??[] as $summary){
        try{
            $case=aur_case_read($store,(string)$summary['reference']);
            foreach(aur_vendor_rfq_list($store,$case) as $draftInfo){
                $draft=aur_vendor_rfq_read($store,$case,(string)$draftInfo['id']);
                foreach(($draft['messages']??[]) as $i=>$message) {
                    if(!is_array($message))continue;
                    $status=(string)($message['status']??'DRAFT');
                    if(!in_array($status,['ACCEPTED_BY_MAIL_TRANSPORT',
                          'TRANSPORT_FAILED_OR_UNCONFIRMED','IN_FLIGHT'],true))continue;
                    if(empty($message['attempted_at'])&&empty($message['sent_at'])
                       &&empty($message['transport_result']))continue;
                    $uid='rfq-'.substr(hash('sha256',$case['reference'].'|'.$draftInfo['id'].'|'.$i),0,32);
                    $date=(string)($message['sent_at']??$message['attempted_at']??$draft['created_at']??'');
                    $confirmed=$status==='ACCEPTED_BY_MAIL_TRANSPORT';
                    $entry=['uid'=>$uid,'folder'=>'INBOX.Sent',
                        'from'=>'info@aurenoeva.com','to'=>(string)($message['to']??''),
                        'date'=>$date,'subject'=>(string)($message['subject']??''),
                        'body'=>(string)($message['body']??''),'preview'=>substr(
                            preg_replace('/\s+/u',' ',(string)($message['body']??''))??'',0,180),
                        'unread'=>false,'has_attachments'=>false,'reference'=>$case['reference'],
                        'status'=>$confirmed?'SMTP_ACCEPTED':'DELIVERY_UNCONFIRMED',
                        'message_id'=>(string)($message['smtp_message_id']??''),
                        'source'=>'APPROVED_RFQ_LEDGER'];
                    $found[$uid]=$entry;
                }
            }
        }catch(Throwable $e){
            error_log('AURENOEVA Mail Center RFQ history: '.get_class($e));
        }
    }
    return array_values($found);
}
/** Append an RFC822 copy to the same Hostinger mailbox's Sent folder.
 * Archive failure never causes a second SMTP send or overwrites local evidence.
 */
function aur_mailcenter_archive_imap(string $raw):string {
    return aur_vendor_rfq_archive_sent_imap($raw);
}

/**
 * Build stable chronological Sent pages across locally recorded SMTP/RFQ
 * dispatches and Hostinger IMAP copies. Message-ID is the only safe
 * cross-store deduplication key: equal subjects can be legitimate re-sends.
 */
function aur_mailcenter_sent_page(array $local,array $server,int $page,int $pageSize,bool $serverHasMore):array {
    $seen=[];$unique=[];
    foreach(array_merge($local,$server) as $entry) {
        $mid=strtolower(trim((string)($entry['message_id']??''),"<> \t\r\n"));
        $key=$mid!==''?'mid:'.$mid:'uid:'.(string)($entry['uid']??'');
        if(isset($seen[$key]))continue;
        $seen[$key]=true;
        $unique[]=$entry;
    }
    usort($unique,static function(array $a,array $b):int {
        $ad=strtotime((string)($a['date']??''))?:0;
        $bd=strtotime((string)($b['date']??''))?:0;
        return ($bd<=>$ad) ?: strcmp((string)($b['uid']??''),(string)($a['uid']??''));
    });
    $offset=($page-1)*$pageSize;
    return ['items'=>array_slice($unique,$offset,$pageSize),
        'more'=>$serverHasMore||count($unique)>$offset+$pageSize];
}

function aur_mailcenter_list(string $store,string $folder,int $page):array {
    $folder=aur_mailcenter_folder($folder);
    $page=max(1,$page);$pageSize=20;
    $offset=($page-1)*$pageSize;
    $sent=$folder==='INBOX.Sent';
    $local=[];
    if($sent) {
        foreach(aur_mailcenter_local($store) as $entry)
            if(is_array($entry))$local[]=aur_mailcenter_local_summary($entry);
        foreach(aur_mailcenter_rfq_sent($store) as $entry)$local[]=$entry;
    }
    $error=null;$server=[];$uids=[];
    try{
        $uids=aur_mailcenter_uids(aur_mailcenter_imap($folder,'UID SEARCH ALL',200000));
        $unread=aur_mailcenter_uids(aur_mailcenter_imap($folder,'UID SEARCH UNSEEN',200000));
        // Sent has TWO sources. Read the remote prefix rather than a remote page
        // so locally and remotely stored messages share one global page boundary.
        $remoteUids=$sent
            ?array_slice($uids,0,$page*$pageSize)
            :array_slice($uids,$offset,$pageSize);
        foreach($remoteUids as $uid) {
            try {$server[]=aur_mailcenter_summary($folder,(string)$uid,
                aur_mailcenter_source($folder,(string)$uid),in_array($uid,$unread,true));}
            catch(Throwable $e){error_log('AURENOEVA mail header read: '.get_class($e));}
        }
    }catch(Throwable $e){
        error_log('AURENOEVA mail list: '.get_class($e));
        $error='hostinger_mailbox_unavailable';
    }
    if($sent) {
        $merged=aur_mailcenter_sent_page($local,$server,$page,$pageSize,
            count($uids)>$page*$pageSize);
        return ['ok'=>$error===null||count($local)>0,'folder'=>$folder,'page'=>$page,
            'items'=>$merged['items'],'more'=>$merged['more'],'warning'=>$error];
    }
    usort($server,static function(array $a,array $b):int {
        $ad=strtotime((string)($a['date']??''))?:0;
        $bd=strtotime((string)($b['date']??''))?:0;
        return ($bd<=>$ad) ?: ((int)$b['uid']<=>(int)$a['uid']);
    });
    return ['ok'=>$error===null,'folder'=>$folder,'page'=>$page,
        'items'=>$server,'more'=>count($uids)>$offset+$pageSize,'warning'=>$error];
}
function aur_mailcenter_read(string $store,string $folder,string $uid):array {
    $folder=aur_mailcenter_folder($folder);$uid=aur_mailcenter_uid($uid);
    if(str_starts_with($uid,'rfq-')){
        if($folder!=='INBOX.Sent')throw new InvalidArgumentException('invalid_mail_folder');
        foreach(aur_mailcenter_rfq_sent($store) as $entry){
            if($entry['uid']!==$uid)continue;
            return ['ok'=>true,'message'=>array_merge($entry,['download'=>false,'attachments'=>[]])];
        }
        throw new InvalidArgumentException('mail_not_found');
    }
    if(str_starts_with($uid,'local-')){
        if($folder!=='INBOX.Sent')throw new InvalidArgumentException('invalid_mail_folder');
        foreach(aur_mailcenter_local($store) as $entry) {
            if('local-'.($entry['id']??'')!==$uid)continue;
            return ['ok'=>true,'message'=>array_merge(aur_mailcenter_local_summary($entry),[
                'body'=>(string)($entry['body']??''),
                'body_html'=>(string)($entry['body_html']??''),'download'=>false,
                'attachments'=>is_array($entry['attachments']??null)?$entry['attachments']:[]
            ])];
        }
        throw new InvalidArgumentException('mail_not_found');
    }
    $raw=aur_mailcenter_source($folder,$uid);
    $item=aur_mailcenter_summary($folder,$uid,$raw);
    $parsed=aur_mail_parse($raw);
    $item['body']=(string)$parsed['text'];
    $item['download']=true;
    return ['ok'=>true,'message'=>$item];
}
function aur_mailcenter_send(string $store,array $input,array $files=[]):array {
    $send=aur_mailcenter_validate_send($input);
    $id=$send['send_key'];
    $dir=aur_private_directory($store,'mail-center');
    $lock=fopen($dir.'/.send.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('mail_send_lock_unavailable');
    try{
        $entries=aur_mailcenter_local($store);
        foreach($entries as $entry)if(($entry['id']??'')===$id)
            return ['ok'=>($entry['status']??'')==='SMTP_ACCEPTED',
                'status'=>$entry['status'],'duplicate_request'=>true];
        $selection=(string)($input['include_brochure']??'');
        if($selection!==''&&$selection!=='1')
            throw new InvalidArgumentException('invalid_brochure_selection');
        $attached=aur_mailcenter_store_uploads($store,$id,$files);
        if($selection==='1')$attached=aur_mailcenter_attach_brochure($store,$id,$attached,$send['language']);
        $attachmentMetadata=array_map(static fn($part)=>[
            'name'=>$part['name'],'size'=>$part['size'],'type'=>$part['type']
        ],$attached);
        $logoFile=__DIR__.'/aurenoeva-logo.png';
        if(!is_file($logoFile)||is_link($logoFile))
            throw new RuntimeException('mail_logo_asset_unavailable');
        $plain=$send['body']."\n\n".aur_mailcenter_signature_plain($send['language']);
        $formatted=aur_mailcenter_html_document($send['body_html'],$send['language']);
        $smtpId=$id.'@aurenoeva.com';
        $entry=['id'=>$id,'to'=>$send['to'],'subject'=>$send['subject'],
            'body'=>$plain,'body_html'=>$formatted,'language'=>$send['language'],
            'created_at'=>gmdate('c'),'status'=>'IN_FLIGHT',
            'smtp_message_id'=>$smtpId,'logo'=>aur_mailcenter_logo_url(),
            'font'=>'Arial, Helvetica, sans-serif','font_size'=>'11pt',
            'attachments'=>$attachmentMetadata];
        $entries[]=$entry;
        aur_mailcenter_write_local($store,$entries); // durable before SMTP
        $transport=aur_vendor_rfq_send_transport_detail(
            $send['to'],$send['subject'],$plain,$smtpId,$attached,$formatted,$logoFile);
        $entry['status']=($transport['accepted']??false)===true?'SMTP_ACCEPTED':'DELIVERY_UNCONFIRMED';
        $entry['smtp_code']=(int)($transport['code']??0);
        $entry['smtp_stage']=(string)($transport['stage']??'');
        $entry['smtp_outcome']=(string)($transport['outcome']??'');
        // The shared authenticated SMTP transport archives the exact MIME
        // once to IMAP Sent. Never append a second copy from Mail Center.
        $entry['imap_sent_archive']=(string)($transport['imap_sent_archive']??'NOT_ARCHIVED');
        $entries[count($entries)-1]=$entry;
        aur_mailcenter_write_local($store,$entries);
        return ['ok'=>$entry['status']==='SMTP_ACCEPTED','status'=>$entry['status'],
            'imap_sent_archive'=>$entry['imap_sent_archive'],
            'smtp_code'=>$entry['smtp_code'],'smtp_stage'=>$entry['smtp_stage']];
    }finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
}


/**
 * Display-only mailbox reconstruction for one correspondent. The original
 * Hostinger Sent/Inbox entries take precedence over the original approved RFQ
 * and Mail Centre SMTP ledgers. Never delete, mutate, or infer delivery.
 */
function aur_mailcenter_conversation_merge(array $server,array $recorded):array {
    $seen=[];$all=[];
    foreach(array_merge($server,$recorded) as $entry){
        if(!is_array($entry))continue;
        $mid=strtolower(trim((string)($entry['message_id']??''),"<> \t\r\n"));
        $key=$mid!==''?'mid:'.$mid:'uid:'.(string)($entry['folder']??'').':'.(string)($entry['uid']??'');
        if(isset($seen[$key]))continue;
        $seen[$key]=true;
        $all[]=$entry;
    }
    usort($all,static function(array $a,array $b):int {
        $at=strtotime((string)($a['date']??''))?:0;
        $bt=strtotime((string)($b['date']??''))?:0;
        return ($at<=>$bt) ?: strcmp((string)($a['uid']??''),(string)($b['uid']??''));
    });
    return $all;
}
function aur_mailcenter_conversation(string $store,string $email):array {
    $email=strtolower(trim($email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)
        throw new InvalidArgumentException('invalid_supplier_email');
    $server=[];$warnings=[];
    foreach(['INBOX','INBOX.Sent'] as $folder){
        $uids=[];
        // Provider-side mailbox searches: no arbitrary first-page cutoff.
        foreach(['UID SEARCH OR FROM "'.$email.'" TO "'.$email.'"',
                 'UID SEARCH CC "'.$email.'"'] as $command){
            try{
                $matches=aur_mailcenter_uids(aur_mailcenter_imap($folder,$command,200000));
                foreach($matches as $uid)$uids[$uid]=true;
            }catch(Throwable $e){
                error_log('AURENOEVA conversation IMAP search '. $folder.': '.get_class($e));
                $warnings[$folder]='Some '.$folder.' messages could not be checked.';
            }
        }
        ksort($uids,SORT_NUMERIC);
        foreach(array_keys($uids) as $uid){
            try{
                $message=aur_mailcenter_read($store,$folder,(string)$uid)['message'];
                // Search is substring-based; do not display another correspondent.
                $participants=(string)($message['from']??'').' '.(string)($message['to']??'');
                if(stripos($participants,$email)===false)continue;
                $message['kind']=$folder==='INBOX.Sent'?'sent':'received';
                $message['origin']='HOSTINGER';
                $server[]=$message;
            }catch(Throwable $e){
                error_log('AURENOEVA conversation IMAP read '.$folder.': '.get_class($e));
                $warnings[$folder]='Some '.$folder.' messages could not be opened.';
                $server[]=['uid'=>(string)$uid,'folder'=>$folder,'kind'=>$folder==='INBOX.Sent'?'sent':'received',
                    'date'=>'','subject'=>'Original message could not be opened','body'=>'',
                    'origin'=>'HOSTINGER_UNAVAILABLE','message_id'=>'',
                    'from'=>'','to'=>'','download'=>false];
            }
        }
    }
    $recorded=[];
    foreach(aur_mailcenter_local($store) as $entry){
        if(!is_array($entry)||($entry['status']??'')!=='SMTP_ACCEPTED')continue;
        if(strtolower(trim((string)($entry['to']??'')))!==$email)continue;
        $message=aur_mailcenter_local_summary($entry);
        $message['body']=(string)($entry['body']??'');
        $message['kind']='sent';$message['origin']='MAIL_CENTER';
        $message['download']=false;
        $message['attachments']=is_array($entry['attachments']??null)?$entry['attachments']:[];
        $recorded[]=$message;
    }
    // Original approved RFQ emails can precede Mail Centre installation.
    foreach(aur_mailcenter_rfq_sent($store) as $message){
        if(($message['status']??'')!=='SMTP_ACCEPTED')continue;
        if(strtolower(trim((string)($message['to']??'')))!==$email)continue;
        $message['kind']='sent';$message['origin']='APPROVED_RFQ_LEDGER';
        $message['download']=false;
        $recorded[]=$message;
    }
    return ['ok'=>true,'email'=>$email,
        'messages'=>aur_mailcenter_conversation_merge($server,$recorded),
        'warnings'=>array_values($warnings)];
}
