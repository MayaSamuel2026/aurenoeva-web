<?php
declare(strict_types=1);
/**
 * AURENOEVA's existing private procurement store receives source-backed
 * DeepFetcher candidates. No catalog approval, RFQ creation, outbound email,
 * customer case mutation or stock assertion occurs during intake.
 */
require_once __DIR__.'/private_cases.php';

function aur_df_validate(string $raw, string $timestamp, string $signature, string $secret):array {
    if(strlen($secret)<32)throw new RuntimeException('integration_not_configured');
    if(strlen($raw)>4194304)throw new InvalidArgumentException('oversized');
    if(!ctype_digit($timestamp) || abs(time()-(int)$timestamp)>300)
        throw new InvalidArgumentException('expired_timestamp');
    $correct='v1='.hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
    if(!hash_equals($correct,$signature))throw new InvalidArgumentException('invalid_signature');
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema_version']??'')!=='1.0')
        throw new InvalidArgumentException('unsupported_schema');
    $id=$data['delivery_id']??null;
    $e=$data['evidence']??null;
    if(!is_int($id)||$id<1||!is_array($e))
        throw new InvalidArgumentException('invalid_delivery');
    foreach(['id','source_url','source_hash','field','value','quotation','verification'] as $key)
        if(!is_string($e[$key]??null)||trim($e[$key])==='')throw new InvalidArgumentException('invalid_evidence');
    if($e['verification']!=='source_backed' ||
       !preg_match('/^[a-f0-9]{64}$/D',$e['source_hash']) ||
       strlen($e['id'])>120 || strlen($e['field'])>300)
        throw new InvalidArgumentException('invalid_provenance');
    $source=parse_url($e['source_url']);
    if(!is_array($source)||($source['scheme']??'')!=='https'||empty($source['host']) ||
       isset($source['user'])||isset($source['pass']))
        throw new InvalidArgumentException('invalid_source_url');
    if(!is_string($data['mission_id']??null)||$data['mission_id']==='')
        throw new InvalidArgumentException('missing_mission');
    return $data;
}

function aur_df_commit(string $store,array $data,string $expectedWorkspace):string {
    if($expectedWorkspace!=='' && ($data['workspace_id']??null)!==$expectedWorkspace)
        throw new InvalidArgumentException('wrong_workspace');
    $dir=aur_private_directory($store,'deepfetcher-inbox');
    $id=(int)$data['delivery_id'];
    $ref='source-inbox-'.$id;
    $path=$dir.'/delivery-'.$id.'.json';
    $lock=fopen($dir.'/intake.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('intake_lock_unavailable');
    try{
        if(is_link($path))throw new RuntimeException('unsafe_intake_path');
        if(is_file($path)){
            $existing=json_decode((string)file_get_contents($path),true);
            if(!is_array($existing) || ($existing['evidence']['id']??null)!==$data['evidence']['id']
                || ($existing['mission_id']??null)!==$data['mission_id'])
                throw new RuntimeException('delivery_id_conflict');
            return $ref; // Existing business-side record is committed only once.
        }
        $record=[
            'schema'=>'aurenoeva-deepfetcher-source-inbox/1',
            'consumer_record_id'=>$ref,
            'status'=>'SOURCE_CANDIDATE_NOT_COMMERCIALLY_VERIFIED',
            'delivery_id'=>$id,
            'mission_id'=>$data['mission_id'],
            'workspace_id'=>$data['workspace_id']??null,
            'client_reference'=>$data['client_reference']??null,
            'evidence'=>$data['evidence'],
            'committed_at'=>gmdate('c'),
        ];
        $json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $temp=$dir.'/.tmp-'.bin2hex(random_bytes(12));
        try{
            if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))
                throw new RuntimeException('intake_write_failed');
            chmod($temp,0600);
            if(!rename($temp,$path))throw new RuntimeException('intake_commit_failed');
        }finally{if(is_file($temp))@unlink($temp);}
        return $ref;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function aur_df_config(string $store):array {
    $path=aur_private_directory($store,'deepfetcher-inbox').'/receiver-config.json';
    if(!is_file($path)||is_link($path))throw new RuntimeException('receiver_not_configured');
    $data=json_decode((string)file_get_contents($path),true);
    if(!is_array($data) ||
       !is_string($data['signing_secret']??null) ||
       !is_string($data['ack_token']??null) ||
       !is_string($data['api_base']??null) ||
       strlen($data['signing_secret'])<32 || strlen($data['ack_token'])<32)
        throw new RuntimeException('invalid_receiver_config');
    return $data;
}

function aur_df_acknowledge(array $config,int $id,string $recordId):bool {
    $base=rtrim($config['api_base'],'/');
    $expected='https://app.deepfetcher.com';
    $qa=getenv('AUR_DF_QUALIFICATION_ONLY')==='1' && $base==='http://api:8787';
    if($base!==$expected && !$qa)throw new RuntimeException('unapproved_ack_destination');
    $url=$base.'/api/v1/deliveries/'.$id.'/ack';
    $payload=json_encode(['consumer_record_id'=>$recordId],JSON_THROW_ON_ERROR);
    $context=stream_context_create([
        'http'=>[
            'method'=>'POST', 'header'=>"Content-Type: application/json\r\n".
                'X-DeepFetcher-Acknowledgement-Key: '.$config['ack_token']."\r\n",
            'content'=>$payload, 'timeout'=>12, 'ignore_errors'=>true,
            'follow_location'=>0,
        ],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
    ]);
    $body=@file_get_contents($url,false,$context);
    $headers=$http_response_header??[];
    $status=(string)($headers[0]??'');
    return $body!==false && (bool)preg_match('/^HTTP\/\S+ 200 /',$status);
}
