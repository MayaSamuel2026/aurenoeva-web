<?php
declare(strict_types=1);
require dirname(__DIR__) . '/_form_common.php';

if (!aur_revenue_event([
    'event_id'=>'recommendation:qualification',
    'event_type'=>'RECOMMENDATION_SHOWN',
    'subject_key'=>'QUALIFICATION',
    'metadata'=>['surface'=>'qualification','result_count'=>1]
])) {
    fwrite(STDERR, "direct revenue event failed\n");
    exit(1);
}

if (!aur_enquiry_ledger_append([
    'event'=>'REQUIREMENT_CAPTURED',
    'reference'=>'AUR-GATE-REVENUE',
    'requirement_contract_id'=>'AUR-REQ-GATE-REVENUE',
    'core'=>['bound'=>true]
])) {
    fwrite(STDERR, "ledger append failed\n");
    exit(1);
}

echo "PASS Aurenoeva revenue-loop bridge\n";
