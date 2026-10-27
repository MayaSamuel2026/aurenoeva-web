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
            // Per-line quantities are authoritative; the old global text
            // field remains a backward-compatible fallback for one product.
            $quantityValue=$row['quantity']??null;
            if($quantityValue===null&&count($decoded)===1&&preg_match('/^[1-9][0-9]{0,5}$/D',$quantity))
                $quantityValue=(int)$quantity;
            if($quantityValue!==null && (!is_int($quantityValue)||$quantityValue<1||$quantityValue>999999))
                aur_json(['ok'=>false,'error'=>'invalid_item_quantity'],422);
            $part = [
                'quantity'=>$quantityValue,
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

$ledgerCaptured=aur_enquiry_ledger_append([
    'event'=>'REQUIREMENT_CAPTURED',
    'reference'=>$reference,
    'requirement_contract_id'=>$contractId,
    'contract'=>$contract,
    'core'=>[
        'bound'=>(bool)$core['bound'],
        'status'=>$core['status'],
        'case_id'=>$core['case_id'],
        'compliance_state'=>$core['compliance_state'],
        'transaction_state'=>$core['transaction_state']
    ]
]);

if (!$ledgerCaptured) {
    aur_json([
        'ok'=>false,
        'error'=>'enquiry_logging',
        'reference'=>$reference,
        'requirement_contract_id'=>$contractId,
        'delivery'=>[
            'email'=>false,
            'logged'=>false,
            'core_bound'=>(bool)$core['bound'],
            'core_status'=>$core['status']
        ]
    ],503);
}


// WP09: turn every validated public RFQ into the existing private sourcing
// case, making it visible to supplier discovery, approval, SMTP and quotes.
// No automatic supplier dispatch or compliance approval is granted.
try {
    require_once __DIR__.'/procurement/public_intake.php';
    $privateStore=aur_store_root();
    $privateCase=aur_public_case_capture($privateStore,$contract);
    // Compile existing, evidence-limited manufacturer/channel matches now.
    // No network crawl, supplier message or clearance is triggered by intake.
    try{
        require_once __DIR__.'/procurement/supplier_intelligence.php';
        aur_supplier_save($privateStore,$privateCase);
    }catch(Throwable $researchError){
        error_log('AURENOEVA WP09 supplier research deferred: '.get_class($researchError));
    }
} catch(Throwable $e) {
    error_log('AURENOEVA WP09 private case intake: '.get_class($e));
    aur_json([
        'ok'=>false,'error'=>'sourcing_case_unavailable','reference'=>$reference,
        'delivery'=>['email'=>false,'logged'=>$ledgerCaptured,'private_case'=>false]
    ],503);
}

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
        $itemQty=$part['quantity']===null?'To confirm':(string)$part['quantity'];
        $lines[]=($i+1).'. '.$label.' | Quantity: '.$itemQty.($detail?' ['.implode('; ',$detail).']':'');
    }
    $selectedText=implode("\n",$lines);
}

// Keep the email notification deliberately concise. The complete structured
// Requirement Contract is already retained by NOEVA CORE; duplicating the full
// JSON contract in the email materially hurts deliverability and is unnecessary.
$subject='Aurenoeva procurement enquiry — '.$reference;
$body="New Aurenoeva procurement enquiry\n"
    ."=================================\n\n"
    ."Reference: {$reference}\n"
    ."Requirement record: {$contractId}\n"
    ."Company: {$company}\n"
    ."Contact: {$name}\n"
    ."Business email: {$email}\n\n"
    ."Starting point: {$mode}\n"
    ."Sector: {$industry}\n"
    ."Category: ".($category!==''?$category:'Not specified')."\n"
    ."Identifier / search input: ".($identifier!==''?$identifier:'Not specified')."\n"
    ."Condition: ".($condition!==''?$condition:'Not specified')."\n"
    ."Quantity: ".($quantity!==''?$quantity:'Not specified')."\n"
    ."Delivery location: ".($delivery!==''?$delivery:'Not specified')."\n"
    ."Need by: ".($needBy!==''?$needBy:'Not specified')."\n"
    ."Budget: ".($budget!==''?$budget:'Not specified')."\n"
    ."Evidence requested: ".($evidence!==''?$evidence:'None specified')."\n\n"
    ."Selected catalogue parts:\n{$selectedText}\n\n"
    ."Requirement / notes:\n".($requirement!==''?$requirement:'Not specified')."\n\n"
    ."NOEVA CORE: ".($core['bound']?'BOUND':'DEFERRED')." (".$core['status'].")\n"
    ."Operations case: ".($core['case_id']?:'Not yet bound')."\n"
    ."Submitted: ".gmdate('Y-m-d H:i:s')." UTC\n";

$mailSent=aur_send($subject,$body,$email);

$ledgerDelivery=aur_enquiry_ledger_append([
    'event'=>'NOTIFICATION_DELIVERY',
    'reference'=>$reference,
    'requirement_contract_id'=>$contractId,
    'email_delivered'=>$mailSent,
    'core_bound'=>(bool)$core['bound'],
    'core_status'=>$core['status']
]);


// A CORE record is not the same thing as a delivered mailbox notification.
// Never show the buyer a generic "Sent" state when the mail transport failed.
if (!$mailSent) {
    aur_json([
        'ok'=>false,
        'error'=>'email_delivery',
        'reference'=>$reference,
        'requirement_contract_id'=>$contractId,
        'delivery'=>[
            'email'=>false,
            'logged'=>$ledgerCaptured,
            'delivery_event_logged'=>$ledgerDelivery,
            'core_bound'=>(bool)$core['bound'],
            'core_status'=>$core['status']
        ]
    ],502);
}

aur_json([
    'ok'=>true,
    'reference'=>$reference,
    'requirement_contract_id'=>$contractId,
    'delivery'=>[
        'email'=>$mailSent,
        'logged'=>$ledgerCaptured,
        'delivery_event_logged'=>$ledgerDelivery,
        'core_bound'=>(bool)$core['bound'],
        'core_status'=>$core['status']
    ],
    'operations'=>[
        'case_bound'=>true,
        'case_id'=>$privateCase['reference'],
        'private_case_reference'=>$privateCase['reference'],
        'core_case_id'=>$core['case_id']
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
