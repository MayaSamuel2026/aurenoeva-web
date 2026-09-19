<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';
require __DIR__ . '/_core_bridge.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { aur_json(['ok'=>false,'error'=>'method'],405); }
if (!aur_same_origin_ok()) { aur_json(['ok'=>false,'error'=>'origin'],403); }
if (aur_text('website',200) !== '') { aur_json(['ok'=>true,'reference'=>'AUR-RECEIVED']); }

$lang = aur_text('lang',2)==='de' ? 'de' : 'en';
$mode = aur_text('mode',40);
$industry = aur_text('industry',120);
$category = aur_text('category',180);
$identifier = aur_text('identifier',250);
$requirement = aur_text('requirement',5000);
$condition = aur_text('condition',120);
$quantity = aur_text('quantity',120);
$delivery = aur_text('delivery_location',180);
$needBy = aur_text('need_by',160);
$budget = aur_text('budget',160);
$evidence = aur_text('evidence',2000);
$company = aur_text('company',180);
$name = aur_text('name',120);
$email = aur_text('email',190);

$selectedPartsRaw = aur_text('selected_parts',16000);
$selectedParts = [];
if ($selectedPartsRaw !== '') {
    $decoded = json_decode($selectedPartsRaw,true);
    if (is_array($decoded)) {
        foreach (array_slice($decoded,0,20) as $row) {
            if (!is_array($row)) { continue; }
            $part = [
                'canonical_id'=>substr(trim((string)($row['canonical_id']??'')),0,220),
                'manufacturer_id'=>substr(trim((string)($row['manufacturer_id']??'')),0,220),
                'manufacturer'=>substr(trim((string)($row['manufacturer']??'')),0,220),
                'part_number'=>substr(trim((string)($row['part_number']??'')),0,220),
                'model'=>substr(trim((string)($row['model']??'')),0,220),
                'description'=>substr(trim((string)($row['description']??'')),0,500),
                'identifier_type'=>substr(trim((string)($row['identifier_type']??'')),0,80),
                'lifecycle_status'=>substr(trim((string)($row['lifecycle_status']??'')),0,120),
                'relationship_type'=>substr(trim((string)($row['relationship_type']??'')),0,120),
                'confidence'=>(isset($row['confidence'])&&is_numeric($row['confidence']))?max(0,min(1,(float)$row['confidence'])):null,
                'source_ref'=>substr(trim((string)($row['source_ref']??'')),0,500),
                'original_search_query'=>substr(trim((string)($row['original_search_query']??'')),0,220),
                'selected_at'=>substr(trim((string)($row['selected_at']??'')),0,80)
            ];
            if ($part['canonical_id']==='' && $part['part_number']==='' && $part['model']==='') { continue; }
            $selectedParts[]=$part;
        }
    }
}

if (($identifier==='' && $requirement==='' && !$selectedParts) || $company==='' || $name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
    aur_json(['ok'=>false,'error'=>'validation'],422);
}
if (!aur_rate_limit('requirement',12)) { aur_json(['ok'=>false,'error'=>'rate_limit'],429); }

try { $suffix=strtoupper(substr(bin2hex(random_bytes(3)),0,6)); }
catch (Throwable $e) { $suffix=strtoupper(substr(hash('sha256',microtime(true).$email),0,6)); }
$reference='AUR-'.gmdate('Ymd-His').'-'.$suffix;
$contractId='AUR-REQ-'.gmdate('Ymd-His').'-'.$suffix;

$evidenceList=[];
foreach (array_filter(array_map('trim',explode(',',$evidence))) as $x) { $evidenceList[]=substr($x,0,240); }

$contract=[
    'schema'=>'aurenoeva-requirement-contract/1.0',
    'contract_id'=>$contractId,
    'reference'=>$reference,
    'vertical'=>'AURENOEVA',
    'source'=>'PUBLIC_WEBSITE',
    'created_at'=>gmdate('c'),
    'language'=>$lang,
    'customer'=>[
        'organisation_name'=>$company,
        'contact_name'=>$name,
        'business_email'=>$email
    ],
    'requirement'=>[
        'starting_mode'=>$mode,
        'industry'=>$industry,
        'category'=>$category,
        'raw_identifier'=>$identifier,
        'description'=>$requirement,
        'condition'=>$condition,
        'quantity'=>$quantity,
        'delivery_location'=>$delivery,
        'need_by'=>$needBy,
        'budget'=>$budget,
        'evidence_requested'=>$evidenceList
    ],
    'selected_products'=>$selectedParts,
    'authority'=>[
        'catalogue'=>'NOEVA_CORE_CANONICAL_PRODUCTS',
        'operations'=>'NOEVA_CORE_OPERATIONS_PROJECT50',
        'compliance'=>'NOEVA_CORE_COMPLIANCE_PROJECT80',
        'transactions'=>'NOEVA_CORE_TRANSACTIONS_PROJECT90'
    ],
    'orchestration'=>[
        'operations'=>[
            'action'=>'CREATE_OR_BIND_CASE',
            'case_type'=>'PROCUREMENT_REQUIREMENT',
            'relationship_context'=>'B2B_BUYER'
        ],
        'compliance'=>[
            'action'=>'DEFER_UNTIL_TRANSACTION_CONTEXT',
            'required_before'=>['CONTRACT_COMMITMENT','FUNDING_RELEASE','DISPATCH']
        ],
        'transaction'=>[
            'action'=>'DEFER_UNTIL_ACQUISITION_ROUTE',
            'requires'=>['SELECTED_SUPPLIER','BUYER_SELLER_BROKER_ROLES','COMMERCIAL_TERMS','APPLICABILITY_CONTEXT']
        ]
    ]
];

$fingerprintPayload=$contract;
unset($fingerprintPayload['contract_id'],$fingerprintPayload['reference'],$fingerprintPayload['created_at']);
$contract['idempotency_key']='aurenoeva:'.hash('sha256',json_encode($fingerprintPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

$core=aur_core_requirement_binding($contract);

$selectedText='None selected';
if ($selectedParts) {
    $lines=[];
    foreach ($selectedParts as $i=>$part) {
        $label=trim(($part['manufacturer']!==''?$part['manufacturer'].' · ':'').($part['part_number']!==''?$part['part_number']:$part['model']));
        $detail=[];
        if ($part['model']!=='' && $part['model']!==$part['part_number']) { $detail[]='model='.$part['model']; }
        if ($part['canonical_id']!=='') { $detail[]='canonical_id='.$part['canonical_id']; }
        if ($part['relationship_type']!=='') { $detail[]='relationship='.$part['relationship_type']; }
        if ($part['confidence']!==null) { $detail[]='confidence='.number_format($part['confidence'],3,'.',''); }
        if ($part['source_ref']!=='') { $detail[]='source_ref='.$part['source_ref']; }
        $lines[]=($i+1).'. '.$label.($detail?' ['.implode('; ',$detail).']':'');
    }
    $selectedText=implode("\n",$lines);
}

$coreText=
    "CORE binding: ".($core['bound']?'BOUND':'DEFERRED')."\n".
    "CORE binding status: ".$core['status']."\n".
    "Requirement Contract: {$contractId}\n".
    "Operations case: ".($core['case_id']?:'Not yet bound')."\n".
    "Party: ".($core['party_id']?:'Not yet bound')."\n".
    "Compliance state: ".$core['compliance_state']."\n".
    "Applicability manifest: ".($core['applicability_manifest_ref']?:'Not yet issued')."\n".
    "Transaction state: ".$core['transaction_state']."\n".
    "Canonical transaction: ".($core['transaction_id']?:'Not yet created')."\n";

$subject='[Aurenoeva requirement '.$reference.'] '.($category!==''?$category:$industry);
$body="AURENOEVA PROCUREMENT REQUIREMENT\n\n"
    ."Reference: {$reference}\n"
    ."Requirement Contract: {$contractId}\n"
    ."Language: {$lang}\n"
    ."Starting mode: {$mode}\n"
    ."Sector: {$industry}\n"
    ."Category: {$category}\n"
    ."Raw identifier / search input: ".($identifier!==''?$identifier:'Not specified')."\n"
    ."Selected catalogue parts:\n{$selectedText}\n"
    ."Requirement: ".($requirement!==''?$requirement:'Not specified')."\n"
    ."Condition: {$condition}\n"
    ."Quantity: ".($quantity!==''?$quantity:'Not specified')."\n"
    ."Delivery location: ".($delivery!==''?$delivery:'Not specified')."\n"
    ."Need by: ".($needBy!==''?$needBy:'Not specified')."\n"
    ."Budget: ".($budget!==''?$budget:'Not specified')."\n"
    ."Evidence requested: ".($evidence!==''?$evidence:'None specified')."\n\n"
    ."Company: {$company}\n"
    ."Name: {$name}\n"
    ."Email: {$email}\n\n"
    .$coreText."\n"
    ."Submitted: ".gmdate('Y-m-d H:i:s')." UTC\n"
    ."Requirement Contract JSON: ".json_encode($contract,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";

$mailSent=aur_send($subject,$body,$email);
if (!$mailSent && !$core['bound']) { aur_json(['ok'=>false,'error'=>'delivery'],500); }

aur_json([
    'ok'=>true,
    'reference'=>$reference,
    'requirement_contract_id'=>$contractId,
    'delivery'=>[
        'email'=>$mailSent,
        'core_bound'=>(bool)$core['bound'],
        'core_status'=>$core['status']
    ],
    'operations'=>[
        'case_bound'=>$core['case_id']!==null,
        'case_id'=>$core['case_id']
    ],
    'compliance'=>[
        'state'=>$core['compliance_state'],
        'manifest_ref'=>$core['applicability_manifest_ref']
    ],
    'transaction'=>[
        'state'=>$core['transaction_state'],
        'transaction_id'=>$core['transaction_id']
    ]
]);
