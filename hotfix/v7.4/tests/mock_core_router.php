<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if($path==='/api/health'){
 echo json_encode(['ok'=>true,'service'=>'noeva-core-os-mock','release_id'=>'NOEVA-CORE-OS-v1.2.0']);
 exit;
}
if($path==='/api/catalogue'){
 echo json_encode([
  'ok'=>true,
  'catalogue_products'=>2,
  'products'=>[
   [
    'id'=>'aur:siemens:6ES7331-1KF02-0AB0',
    'manufacturer'=>'Siemens',
    'model'=>'SM 331',
    'part_number'=>'6ES7331-1KF02-0AB0',
    'name'=>'SIMATIC S7-300 SM 331',
    'category'=>'PLC / I/O module',
    'lifecycle'=>'legacy',
    'aliases'=>['6ES73311KF020AB0'],
    'relationship'=>'CANONICAL',
    'confidence'=>'manufacturer',
    'source_url'=>'https://example.invalid/siemens'
   ],
   [
    'id'=>'aur:other:TEST-0001',
    'manufacturer'=>'Example',
    'model'=>'TEST-0001',
    'part_number'=>'TEST-0001',
    'name'=>'Unrelated test part',
    'category'=>'Test',
    'lifecycle'=>'current',
    'aliases'=>[]
   ]
  ]
 ]);
 exit;
}
if($path==='/api/public/v1/aurenoeva/requirements' && ($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $raw=file_get_contents('php://input');$payload=json_decode($raw,true);
 if(!is_array($payload) || ($payload['schema']??'')!=='aurenoeva-requirement-contract/1.0'){
  http_response_code(422);echo json_encode(['ok'=>false]);exit;
 }
 echo json_encode([
  'status'=>'BOUND',
  'requirement_id'=>'core-req-test-001',
  'operations'=>['party_id'=>'party-test-001','case_id'=>'case-test-001'],
  'compliance'=>['state'=>'NOT_EVALUATED','manifest_ref'=>null],
  'transaction'=>['state'=>'NOT_CREATED','id'=>null]
 ]);
 exit;
}
http_response_code(404);echo json_encode(['ok'=>false,'error'=>'not_found']);
