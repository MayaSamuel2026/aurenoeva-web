<?php
declare(strict_types=1);
/* AURENOEVA Command read-only export / source. Called ONLY after the native
 * procurement signed-cookie authentication. No new credential authority,
 * no public cases, no outgoing email and no competing private store. */
if(!defined('AUR_COMMAND_EXPORT_AUTHORIZED')){
 http_response_code(404);exit;
}
function aur_command_export(string $store,string $mode,array $query):never {
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: private, no-store, max-age=0');
 header('X-Content-Type-Options: nosniff');
 header('X-Frame-Options: DENY');
 header('X-Robots-Tag: noindex, nofollow');
 try {
   if(($_SERVER['REQUEST_METHOD']??'')!=='GET')
     throw new InvalidArgumentException('get_required');
   if($mode==='cases') {
     // Full original case store, cursor paged. No hidden 250-case limit.
     $after=trim((string)($query['after']??''));
     if($after!==''&&!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$after))
       throw new InvalidArgumentException('invalid_case_cursor');
     $matches=[];
     $dir=aur_private_directory($store,'cases');
     foreach(new DirectoryIterator($dir) as $entry){
       if(!$entry->isFile()||$entry->isLink())continue;
       $file=$entry->getFilename();
       if(!preg_match('/^(AUR-[A-Z]{2}-[A-F0-9]{16})\.json$/D',$file,$m))continue;
       if($after!==''&&strcmp($m[1],$after)<=0)continue;
       $matches[]=$m[1];
     }
     sort($matches,SORT_STRING);$refs=array_slice($matches,0,20);
     $items=[];
     foreach($refs as $ref){
       try{
         $case=aur_case_read($store,$ref);
         $items[]=[
           'reference'=>(string)$case['reference'],
           'created_at'=>(string)($case['created_at']??''),
           'line_count'=>(int)($case['line_count']??0),
           'source_sha256'=>(string)($case['source_sha256']??''),
           'original_name'=>(string)($case['original_name']??''),
           'buyer_company'=>(string)($case['buyer']['company']??''),
         ];
       }catch(Throwable $e){error_log('AURENOEVA Command case export: '.get_class($e));}
     }
     echo json_encode(['ok'=>true,'source'=>'aurenoeva-private-cases',
       'items'=>$items,'next_after'=>count($matches)>count($refs)?end($refs):null,
       'more'=>count($matches)>count($refs)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
     exit;
   }
   if($mode==='case'){
     $ref=trim((string)($query['reference']??''));
     $case=aur_case_read($store,$ref);
     $prices=aur_prices_read($store,$case);
     $drafts=[];
     foreach(aur_vendor_rfq_list($store,$case) as $row){
       $id=(string)($row['id']??'');
       try {
         $d=aur_vendor_rfq_read($store,$case,$id);
         $drafts[]=[
           'id'=>$id,'created_at'=>(string)($d['created_at']??''),
           'status'=>(string)($d['status']??'UNKNOWN'),
           'messages'=>array_values(array_map(
            static fn(array $m):array=>[
             'vendor_name'=>(string)($m['vendor_name']??''),
             'to'=>(string)($m['to']??''),
             'subject'=>(string)($m['subject']??''),
             'body'=>(string)($m['body']??''),
             'status'=>(string)($m['status']??'DRAFT'),
             'sent_at'=>(string)($m['sent_at']??''),
             'source_url'=>(string)($m['source_url']??''),
             'smtp_code'=>(int)($m['smtp_code']??0),
             'transport_result'=>(string)($m['transport_result']??'')
            ],array_values(array_filter((array)($d['messages']??[]),'is_array'))))
         ];
       }catch(Throwable $e){error_log('AURENOEVA Command RFQ export: '.get_class($e));}
     }
     echo json_encode(['ok'=>true,'source'=>'aurenoeva-private-case',
       'case'=>$case,'prices'=>$prices,'rfq_drafts'=>$drafts],
       JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
     exit;
   }
   if($mode==='sources') {
     $before=trim((string)($query['before']??''));
     $source=aur_df_supplier_recent($store,30,$before);
     echo json_encode(['ok'=>true,'source'=>'aurenoeva-deepfetcher-supplier-observations']+$source,
       JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
     exit;
   }
   if($mode==='crawler') {
     $status=aur_ci_report($store);
     echo json_encode(['ok'=>true,'source'=>'aurenoeva-commercial-intelligence','data'=>$status],
       JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
     exit;
   }
   throw new InvalidArgumentException('invalid_export_mode');
 }catch(Throwable $e){
   $code=$e instanceof InvalidArgumentException?422:503;
   http_response_code($code);
   error_log('AURENOEVA Command export: '.get_class($e));
   echo json_encode(['ok'=>false,'error'=>$code===422?$e->getMessage():'source_read_unavailable']);
   exit;
 }
}
