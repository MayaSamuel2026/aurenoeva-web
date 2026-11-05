<?php
declare(strict_types=1);
/** Source-backed supplier and product observations for AURENOEVA's private procurement store.
 * Research candidates only; never offers, confirmed inventory, or verified supplier authorization.
 */
require_once __DIR__.'/private_cases.php';

function aur_df_supplier_observe(string $store, array $delivery): bool {
    $e=$delivery['evidence']??[];
    if(!is_array($e) || ($e['verification']??'')!=='source_backed')return false;
    $field=(string)($e['field']??'');
    if(!in_array($field,['supplier.name','supplier.email','product.model','product.manufacturer','product.sku'],true))return false;
    $value=trim((string)($e['value']??''));
    $quote=(string)($e['quotation']??'');
    if($value==='' || strlen($value)>500 || $quote==='' || stripos($quote,$value)===false)return false;
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($e['source_hash']??'')))return false;
    $url=(string)($e['source_url']??'');
    $parts=parse_url($url);
    if(!is_array($parts) || ($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))return false;
    $host=strtolower(rtrim((string)$parts['host'],'.'));
    if(!preg_match('/^[a-z0-9][a-z0-9.-]*\\.[a-z]{2,}$/D',$host))return false;
    if($field==='supplier.email' && filter_var($value,FILTER_VALIDATE_EMAIL)===false)return false;
    $evidenceId=(string)($e['id']??'');
    if($evidenceId==='' || strlen($evidenceId)>120)return false;
    $vendorId='web-'.substr(hash('sha256',$host),0,16);
    $dir=aur_private_directory(aur_private_directory($store,'supplier-intelligence'),'deepfetcher-vendors');
    $path=$dir.'/'.$vendorId.'.json';
    $lock=fopen($dir.'/.intelligence.lock','c');
    if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('deepfetcher_supplier_lock_unavailable');
    try{
        if(is_link($path))throw new RuntimeException('deepfetcher_supplier_unsafe_path');
        $record=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        if(!is_array($record))$record=[
            'schema'=>'aurenoeva-deepfetcher-supplier-intel/1',
            'vendor_id'=>$vendorId,'website'=>$host,
            'status'=>'SOURCE_CANDIDATE_NOT_COMMERCIALLY_VERIFIED',
            'availability'=>'NOT_VERIFIED','quotation'=>'NOT_RECEIVED',
            'supplier_authorization'=>'NOT_VERIFIED','observations'=>[],
        ];
        $obs=[
            'evidence_id'=>$evidenceId,'delivery_id'=>(int)$delivery['delivery_id'],
            'mission_id'=>(string)$delivery['mission_id'],
            'client_reference'=>(string)($delivery['client_reference']??''),
            'field'=>$field,'value'=>$value,'quotation'=>$quote,
            'source_url'=>$url,'source_hash'=>$e['source_hash'],
            'source_last_seen_at'=>(string)($e['last_seen_at']??''),
        ];
        if(isset($record['observations'][$evidenceId])){
            $old=$record['observations'][$evidenceId];
            foreach(['field','value','source_url','source_hash','mission_id'] as $key){
                if(($old[$key]??null)!==$obs[$key])throw new RuntimeException('deepfetcher_supplier_evidence_conflict');
            }
            return true;
        }
        $record['observations'][$evidenceId]=$obs;
        $record['updated_at']=gmdate('c');
        $json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $temp=$dir.'/.new-'.bin2hex(random_bytes(12));
        try{
            if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('deepfetcher_supplier_write_failed');
            if(!chmod($temp,0600) || !rename($temp,$path))throw new RuntimeException('deepfetcher_supplier_commit_failed');
        }finally{if(is_file($temp))@unlink($temp);}
        return true;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function aur_df_supplier_recent(string $store,int $pageSize=20,string $before=''):array {
    $pageSize=max(1,min(100,$pageSize)); // A page size, not a supplier-record limit.
    if(strlen($before)>110 || ($before!=='' &&
       !preg_match('/^[0-9T:+.Z-]{19,35}\\|web-[a-f0-9]{16}$/D',$before)))
        throw new InvalidArgumentException('invalid_supplier_cursor');
    $dir=aur_private_directory(aur_private_directory($store,'supplier-intelligence'),'deepfetcher-vendors');
    $total=0;$top=[];
    foreach(new DirectoryIterator($dir) as $entry){
        if(!$entry->isFile() || $entry->isLink() ||
           !preg_match('/^web-[a-f0-9]{16}\\.json$/D',$entry->getFilename()))continue;
        $data=json_decode((string)file_get_contents($entry->getPathname()),true);
        if(!is_array($data) || ($data['schema']??'')!=='aurenoeva-deepfetcher-supplier-intel/1')continue;
        $total++;
        $key=(string)($data['updated_at']??'').'|'.(string)$data['vendor_id'];
        if($before!=='' && strcmp($key,$before)>=0)continue;
        // At most one extra item is retained to prove the next page exists.
        if(count($top)>=$pageSize+1){
            $oldest=min(array_keys($top));
            if(strcmp($key,$oldest)<=0)continue;
        }
        $observations=$data['observations']??[];
        $models=[];$names=[];$sources=[];
        foreach($observations as $obs){
            if(!is_array($obs))continue;
            if(($obs['field']??'')==='product.model')$models[]=(string)$obs['value'];
            if(($obs['field']??'')==='supplier.name')$names[]=(string)$obs['value'];
            $sources[]=(string)($obs['source_url']??'');
        }
        $top[$key]=['vendor_id'=>$data['vendor_id'],'website'=>$data['website'],
            'supplier_names'=>array_values(array_unique($names)),
            'product_models'=>array_values(array_unique($models)),
            'evidence_count'=>count($observations),'source_urls'=>array_values(array_unique($sources)),
            'updated_at'=>(string)($data['updated_at']??''),
            'status'=>$data['status']];
        krsort($top,SORT_STRING);
        if(count($top)>$pageSize+1)array_pop($top);
    }
    krsort($top,SORT_STRING);
    $hasMore=count($top)>$pageSize;
    if($hasMore)array_pop($top);
    $keys=array_keys($top);
    return ['total_vendors'=>$total,'items'=>array_values($top),'page_size'=>$pageSize,
            'next_before'=>$hasMore?(string)end($keys):null];
}
