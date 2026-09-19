<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';
require __DIR__ . '/_core_bridge.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { aur_json(['ok'=>false,'error'=>'method'],405); }
$h = aur_core_health();
aur_json([
  'ok'=>true,
  'bridge_schema'=>'aurenoeva-core-binding-health/1.0',
  'core'=>[
    'configured'=>$h['configured'],
    'reachable'=>$h['reachable'],
    'status'=>$h['status'],
    'service'=>$h['service'],
    'version'=>$h['version']
  ],
  'contracts'=>[
    'catalogue_search'=>'aurenoeva-public-catalogue-search/1.0',
    'requirement'=>'aurenoeva-requirement-contract/1.0',
    'operations_case'=>'CORE Operations / Project 50',
    'compliance'=>'CORE Compliance / Project 80',
    'transaction'=>'CORE Transactions / Project 90'
  ],
  'policy'=>[
    'search_fail_closed'=>true,
    'manual_requirement_fallback'=>true,
    'transaction_creation'=>'DEFER_UNTIL_ACQUISITION_ROUTE_AND_ROLES'
  ]
]);
