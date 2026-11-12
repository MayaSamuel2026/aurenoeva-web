<?php
declare(strict_types=1);
/** Machine-to-machine source candidate intake; not a public procurement endpoint. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/deepfetcher_source_inbox.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    http_response_code(405);
    header('Allow: POST');
    echo '{"status":"method_not_allowed"}';
    exit;
}
try{
    $store=aur_store_root();
    $config=aur_df_config($store);
    $raw=(string)file_get_contents('php://input');
    $data=aur_df_validate($raw,(string)($_SERVER['HTTP_X_DEEPFETCHER_TIMESTAMP']??''),
        (string)($_SERVER['HTTP_X_DEEPFETCHER_SIGNATURE']??''),$config['signing_secret']);
    $recordId=aur_df_commit($store,$data,(string)($config['workspace_id']??'aurenoeva'));
    // A positive HTTP acceptance is NOT an acknowledgement. The receipt is
    // explicit and only follows a durable, idempotent AURENOEVA source-inbox write.
    if(!aur_df_acknowledge($config,(int)$data['delivery_id'],$recordId)){
        http_response_code(503);
        echo json_encode(['status'=>'committed_ack_pending','consumer_record_id'=>$recordId]);
        exit;
    }
    echo json_encode(['status'=>'committed_and_acknowledged','consumer_record_id'=>$recordId]);
}catch(InvalidArgumentException|JsonException $e){
    http_response_code(422);
    echo '{"status":"rejected_invalid_source_delivery"}';
}catch(RuntimeException $e){
    if($e->getMessage()==='delivery_id_conflict'){
        http_response_code(409);
        echo '{"status":"conflicting_committed_source_delivery"}';
        exit;
    }
    error_log('AURENOEVA DeepFetcher intake: '.get_class($e));
    http_response_code(503);
    echo '{"status":"receiver_unavailable"}';
}catch(Throwable $e){
    error_log('AURENOEVA DeepFetcher intake: '.get_class($e));
    http_response_code(503);
    echo '{"status":"receiver_unavailable"}';
}
