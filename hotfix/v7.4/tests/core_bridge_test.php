<?php
declare(strict_types=1);
putenv('AURENOEVA_CORE_BASE_URL=http://127.0.0.1:18741');
putenv('AURENOEVA_CORE_HEALTH_PATH=/api/health');
putenv('AURENOEVA_CORE_CATALOGUE_PATH=/api/catalogue');
putenv('AURENOEVA_CORE_REQUIREMENT_PATH=/api/public/v1/aurenoeva/requirements');
require dirname(__DIR__) . '/_core_bridge.php';

function assert_true(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}

$h=aur_core_health();
assert_true($h['configured']===true,'health configured');
assert_true($h['reachable']===true,'health reachable');
assert_true($h['version']==='NOEVA-CORE-OS-v1.2.0','health release maps');

$s=aur_core_search('6ES7331-1KF02-0AB0','industrial','PLC / I/O module',8);
assert_true($s['reachable']===true,'catalogue reachable');
assert_true(count($s['items'])===1,'exact query filters canonical catalogue');
assert_true($s['items'][0]['canonical_id']==='aur:siemens:6ES7331-1KF02-0AB0','canonical id preserved');
assert_true($s['items'][0]['part_number']==='6ES7331-1KF02-0AB0','part number preserved');
assert_true($s['items'][0]['manufacturer']==='Siemens','manufacturer preserved');

$alias=aur_core_search('6ES73311KF020AB0','','',8);
assert_true(count($alias['items'])===1,'alias query resolves canonical part');

$none=aur_core_search('DOES-NOT-EXIST','','',8);
assert_true(count($none['items'])===0,'no-match does not fabricate product');

$contract=[
 'schema'=>'aurenoeva-requirement-contract/1.0',
 'contract_id'=>'AUR-REQ-TEST',
 'reference'=>'AUR-TEST',
 'vertical'=>'AURENOEVA',
 'source'=>'TEST',
 'customer'=>['organisation_name'=>'NOEVA Systems e.K.','contact_name'=>'Test','business_email'=>'info@aurenoeva.com'],
 'requirement'=>['raw_identifier'=>'6ES7331-1KF02-0AB0'],
 'selected_products'=>$s['items'],
 'orchestration'=>[
  'operations'=>['action'=>'CREATE_OR_BIND_CASE'],
  'compliance'=>['action'=>'DEFER_UNTIL_TRANSACTION_CONTEXT'],
  'transaction'=>['action'=>'DEFER_UNTIL_ACQUISITION_ROUTE']
 ]
];
$b=aur_core_requirement_binding($contract);
assert_true($b['bound']===true,'Requirement Contract binds to CORE ingress');
assert_true($b['case_id']==='case-test-001','Project 50 Operations case id maps');
assert_true($b['party_id']==='party-test-001','canonical party id maps');
assert_true($b['compliance_state']==='NOT_EVALUATED','Project 80 correctly deferred at raw enquiry');
assert_true($b['transaction_state']==='NOT_CREATED','Project 90 correctly deferred before acquisition route');
assert_true($b['transaction_id']===null,'no premature transaction id fabricated');

echo "V7.4 CORE bridge contract qualification: PASS\n";
