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

function aur_mailcenter_folder(string $folder):string {
    if(!in_array($folder,['INBOX','INBOX.Sent','INBOX.Drafts','INBOX.Trash','INBOX.Junk'],true))
        throw new InvalidArgumentException('invalid_mail_folder');
    return $folder;
}
function aur_mailcenter_uid(string $uid):string {
    if(!preg_match('/^(?:[1-9][0-9]{0,11}|local-[a-f0-9]{32})$/D',$uid))
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
    return ['to'=>$to,'subject'=>$subject,'body'=>$body,'send_key'=>$key];
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
        'status'=>(string)($entry['status']??'IN_FLIGHT'),
        'reference'=>preg_match('/AUR-[A-Z]{2}-[A-F0-9]{16}/',$subject,$m)?$m[0]:null
    ];
}
function aur_mailcenter_list(string $store,string $folder,int $page):array {
    $folder=aur_mailcenter_folder($folder);
    $page=max(1,min(100,$page));$pageSize=20;
    $local=[];
    if($folder==='INBOX.Sent') {
        $entries=aur_mailcenter_local($store);
        foreach(array_reverse($entries) as $entry)
            if(is_array($entry))$local[]=aur_mailcenter_local_summary($entry);
    }
    $error=null;$server=[];
    try{
        $uids=aur_mailcenter_uids(aur_mailcenter_imap($folder,'UID SEARCH ALL',200000));
        $unread=aur_mailcenter_uids(aur_mailcenter_imap($folder,'UID SEARCH UNSEEN',200000));
        $start=($page-1)*$pageSize;
        foreach(array_slice($uids,$start,$pageSize) as $uid) {
            try {$server[]=aur_mailcenter_summary($folder,(string)$uid,
                aur_mailcenter_source($folder,(string)$uid),in_array($uid,$unread,true));}
            catch(Throwable $e){error_log('AURENOEVA mail header read: '.get_class($e));}
        }
    }catch(Throwable $e){
        error_log('AURENOEVA mail list: '.get_class($e));
        $error='hostinger_mailbox_unavailable';
    }
    // Locally sent messages are visible even if IMAP Sent does not store
    // copies of messages submitted through this site's authenticated SMTP.
    $items=$folder==='INBOX.Sent' ? array_merge(array_slice($local,($page-1)*$pageSize,$pageSize),$server) : $server;
    if($folder==='INBOX.Sent') {
        usort($items,static function($a,$b):int {
            return strtotime((string)$b['date']) <=> strtotime((string)$a['date']);
        });
        $items=array_slice($items,0,$pageSize);
    }
    return ['ok'=>$error===null||count($local)>0,'folder'=>$folder,'page'=>$page,
        'items'=>$items,'more'=>count($server)===$pageSize,'warning'=>$error];
}
function aur_mailcenter_read(string $store,string $folder,string $uid):array {
    $folder=aur_mailcenter_folder($folder);$uid=aur_mailcenter_uid($uid);
    if(str_starts_with($uid,'local-')){
        if($folder!=='INBOX.Sent')throw new InvalidArgumentException('invalid_mail_folder');
        foreach(aur_mailcenter_local($store) as $entry) {
            if('local-'.($entry['id']??'')!==$uid)continue;
            return ['ok'=>true,'message'=>array_merge(aur_mailcenter_local_summary($entry),[
                'body'=>(string)($entry['body']??''),'download'=>false,
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
        $attached=aur_mailcenter_store_uploads($store,$id,$files);
        $attachmentMetadata=array_map(static fn($part)=>[
            'name'=>$part['name'],'size'=>$part['size'],'type'=>$part['type']
        ],$attached);
        $entry=['id'=>$id,'to'=>$send['to'],'subject'=>$send['subject'],
            'body'=>$send['body'],'created_at'=>gmdate('c'),'status'=>'IN_FLIGHT',
            'attachments'=>$attachmentMetadata];
        $entries[]=$entry;
        aur_mailcenter_write_local($store,$entries); // durable before SMTP
        $transport=aur_vendor_rfq_send_transport_detail(
            $send['to'],$send['subject'],$send['body'], $id.'@aurenoeva.com',$attached);
        $entry['status']=($transport['accepted']??false)===true?'SMTP_ACCEPTED':'DELIVERY_UNCONFIRMED';
        $entry['smtp_code']=(int)($transport['code']??0);
        $entry['smtp_stage']=(string)($transport['stage']??'');
        $entry['smtp_outcome']=(string)($transport['outcome']??'');
        $entries[count($entries)-1]=$entry;
        aur_mailcenter_write_local($store,$entries);
        return ['ok'=>$entry['status']==='SMTP_ACCEPTED','status'=>$entry['status'],
            'smtp_code'=>$entry['smtp_code'],'smtp_stage'=>$entry['smtp_stage']];
    }finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
}
