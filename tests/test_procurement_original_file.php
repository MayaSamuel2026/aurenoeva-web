<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
function check_original(bool $ok,string $why):void {
 if(!$ok)throw new RuntimeException('Original history FAIL: '.$why);
}
$root=sys_get_temp_dir().'/aur-original-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);mkdir($root.'/cases',0700);mkdir($root.'/uploads',0700);
try {
 $bytes="No.,Equipment description,Qty.\n1,Hospital bed,3\n";
 $digest=hash('sha256',$bytes);
 $ref='AUR-UZ-'.strtoupper(substr($digest,0,16));
 $upload=$root.'/uploads/'.$ref.'.csv';
 file_put_contents($upload,$bytes);
 file_put_contents($root.'/cases/'.$ref.'.json',json_encode([
  'reference'=>$ref,'original_name'=>'customer-requirements.csv','source_sha256'=>$digest
 ],JSON_THROW_ON_ERROR));
 $file=aur_case_original_file($root,$ref);
 check_original($file['path']===$upload,'original path from exact reference');
 check_original($file['name']==='customer-requirements.csv','original filename retained');
 check_original(file_get_contents($file['path'])===$bytes,'exact original bytes');
 file_put_contents($upload,"modified\n");
 try{aur_case_original_file($root,$ref);throw new RuntimeException('tampered source accepted');}
 catch(RuntimeException $e){check_original(str_contains($e->getMessage(),'hash_mismatch'),'hash mismatch rejected');}
 unlink($upload);
 try{aur_case_original_file($root,$ref);throw new RuntimeException('missing original accepted');}
 catch(RuntimeException $e){check_original(str_contains($e->getMessage(),'unavailable'),'missing original not fabricated');}
 echo "AURENOEVA_HISTORICAL_ORIGINAL_FILE=PASS exact_bytes=PASS hash=PASS missing_not_fabricated=PASS\n";
}finally{
 @unlink($upload);@unlink($root.'/cases/'.$ref.'.json');
 @rmdir($root.'/cases');@rmdir($root.'/uploads');@rmdir($root);
}
