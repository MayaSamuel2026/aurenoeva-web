<?php
declare(strict_types=1);
/** Offline acceptance for the one canonical AURENOEVA supplier-refresh CLI. */
function verify_refresh(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
}
$root=sys_get_temp_dir().'/aur-refresh-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
$source=dirname(__DIR__).'/deployment/site/procurement/supplier-refresh-cli.php';
verify_refresh(copy($source,$root.'/supplier-refresh-cli.php'),'Cannot stage CLI');
$stub=<<<'STUB'
<?php
function aur_store_root():string{return (string)getenv('AUR_REFRESH_TEST_ROOT');}
function aur_private_directory(string $store,string $name):string {
    $path=$store.'/'.$name;
    if (!is_dir($path)) mkdir($path,0700,true);
    return $path;
}
function aur_mail_sync(string $store,int $limit=20):array {
    if (getenv('AUR_REFRESH_TEST_MAIL_FAIL')==='1') throw new RuntimeException('simulated_mailbox_failure');
    return ['ok'=>true,'status'=>'PASS','more_pending'=>false];
}
function aur_case_list(string $store):array {
    $cases=[];
    for ($i=1;$i<=37;$i++) $cases[]=['reference'=>'AUR-TEST-'.str_pad((string)$i,4,'0',STR_PAD_LEFT)];
    return ['cases'=>$cases];
}
function aur_case_read(string $store,string $ref):array {return ['reference'=>$ref];}
function aur_supplier_discover_batch(string $store,array $case,int $limit=2):array {
    return ['processed'=>1,'new_unverified_links'=>0,
        'provider_failures'=>getenv('AUR_REFRESH_TEST_PROVIDER_FAIL')==='1'?1:0];
}
function aur_wp09_cycle_report(string $store):array {
    return ['summary'=>['supplier_smtp_250'=>0,'supplier_quotes_verified'=>0]];
}
STUB;
file_put_contents($root.'/private_cases.php',$stub);
foreach (['supplier_intelligence.php','supplier_discovery.php','supplier_inbox.php','cycle_verification.php'] as $name)
    file_put_contents($root.'/'.$name,"<?php\n");
putenv('AUR_REFRESH_TEST_ROOT='.$root);
function run_refresh(string $script):array {
    $lines=[];$code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1',$lines,$code);
    $data=json_decode((string)end($lines),true);
    verify_refresh(is_array($data),'No machine-readable run receipt: '.implode(" ",$lines));
    return [$code,$data];
}
$script=$root.'/supplier-refresh-cli.php';
try {
    [$exit,$run]=run_refresh($script);
    verify_refresh($exit===0 && $run['ok']===true,'Healthy sweep incorrectly failed');
    verify_refresh($run['total_cases']===37 && $run['cases_scanned']===37,
        'Case limit regression: the full 37-case universe was not visited');
    verify_refresh($run['status']==='COMPLETE_SWEEP','Completed sweep not acknowledged');
    $dir=$root.'/supplier-intelligence';
    $cursor=json_decode(file_get_contents($dir.'/supplier-refresh-cursor.json'),true);
    verify_refresh($cursor['last_reference']==='AUR-TEST-0037','Durable cursor missing');
    putenv('AUR_REFRESH_TEST_MAIL_FAIL=1');
    [$exit,$run]=run_refresh($script);
    verify_refresh($exit===2 && $run['ok']===false && $run['status']==='DEGRADED',
        'Mailbox failure falsely presented as healthy');
    $latest=json_decode(file_get_contents($dir.'/supplier-refresh-latest.json'),true);
    verify_refresh($latest['status']==='DEGRADED','Failure receipt not persisted');
    putenv('AUR_REFRESH_TEST_MAIL_FAIL=0');
    putenv('AUR_REFRESH_TEST_PROVIDER_FAIL=1');
    [$exit,$run]=run_refresh($script);
    verify_refresh($exit===2 && $run['provider_failures']===37,
        'Supplier provider failures were hidden');
    echo "AURENOEVA_SUPPLIER_REFRESH_REENGINEERING=PASS\n";
} finally {
    foreach (glob($root.'/supplier-intelligence/*')?:[] as $path) @unlink($path);
    foreach (glob($root.'/supplier-intelligence/.*')?:[] as $path)
        if (is_file($path)) @unlink($path);
    @rmdir($root.'/supplier-intelligence');
    foreach (glob($root.'/*.php')?:[] as $path) @unlink($path);
    @rmdir($root);
}
