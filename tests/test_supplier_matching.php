<?php
declare(strict_types=1);
require_once __DIR__.'/../deployment/site/procurement/supplier_matching.php';

function check_supplier(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
function candidate(string $name,string $site,string $basis,string $status='PUBLISHED_CONTACT_RESEARCH_CANDIDATE'):array {
    return [
        'name'=>$name,'website'=>$site,'country'=>'UZ',
        'email'=>'rfq@'.$site,'evidence_url'=>'https://'.$site.'/portfolio',
        'match_basis'=>$basis,'status'=>$status,
        'authorization'=>'NOT_VERIFIED','availability'=>'NOT_VERIFIED'
    ];
}
$exact=candidate('Exact Supplier','exact.example','EXACT_PRODUCT_LISTED');
$category=candidate('Category Supplier','category.example','CATEGORY_ENQUIRY');
$search=candidate('Search Only','search.example','SEARCH_RESULT_MATCH','SEARCH_RESULT_NOT_SITE_VERIFIED');
$rank=aur_supplier_rank_candidates('Mindray WATO EX-35 anesthesia',[$search,$category,$exact]);
check_supplier(count($rank)===3,'expected three documented candidates');
check_supplier($rank[0]['name']==='Exact Supplier' && $rank[0]['score']===91,'exact product must rank first');
check_supplier($rank[1]['score']===32 && $rank[2]['score']<=18,'category/search evidence must be downgraded');
check_supplier($rank[0]['availability']==='NOT_VERIFIED' && $rank[0]['authorization']==='NOT_VERIFIED','rank cannot claim stock or authorization');
check_supplier($rank[2]['email']==='' && !$rank[2]['contactable'],'search-only lead must not become RFQ recipient');
check_supplier(aur_supplier_rank_candidates('Some part',[array_merge($exact,['evidence_url'=>'http://example.invalid'])])===[],'HTTP link must not count as supplier evidence');
check_supplier(aur_supplier_rank_candidates('Some part',[array_merge($exact,['evidence_url'=>'https://user:pass@exact.example/private'])])===[],'credential-bearing evidence link must be rejected');

$profile=aur_supplier_profile(array_merge($exact,[
    'country'=>'Not established','equipment_hints'=>['Brand X exact model stocked now'],
    'catalogue_products'=>[
        ['model'=>'WATO EX-35','brand'=>'Mindray','family'=>'Anesthesia','source_url'=>'https://exact.example/products/ex35'],
        ['model'=>'Untested','brand'=>'Guess','source_url'=>'http://untrusted.invalid/guess']
    ],
    'supply_capabilities'=>[
        ['name'=>'Maintenance','evidence_state'=>'DOCUMENTED_ON_SOURCE','evidence_url'=>'https://exact.example/service'],
        ['name'=>'In stock','evidence_state'=>'UNVERIFIED','evidence_url'=>'https://exact.example/stock']
    ],
    'declared_role'=>'DISTRIBUTOR'
]));
check_supplier($profile['country']==='NOT_ESTABLISHED','cannot infer supplier country');
check_supplier($profile['supplier_role']==='NOT_ESTABLISHED','role needs explicit evidence');
check_supplier(count($profile['brands'])===1 && $profile['brands'][0]['name']==='Mindray','brand must have source evidence');
check_supplier(count($profile['documented_models'])===1,'model needs source evidence');
check_supplier(count($profile['product_families'])===1,'family needs source evidence');
check_supplier(count($profile['documented_capabilities'])===1,'capability must be source-documented');
check_supplier($profile['availability']==='NOT_VERIFIED','catalogue product must not imply stock');
$roleProfile=aur_supplier_profile(array_merge($exact,[
    'declared_role'=>'DISTRIBUTOR','role_evidence_state'=>'DOCUMENTED_ON_SOURCE',
    'role_evidence_url'=>'https://exact.example/company/distributor'
]));
check_supplier($roleProfile['supplier_role']==='DISTRIBUTOR','evidence-backed role missing');
$modelRef=candidate('Model Cataloguer','model.example','CATEGORY_ENQUIRY');
$modelRef['catalogue_products']=[['model'=>'WATO EX-35','source_url'=>'https://model.example/sku/ex35']];
$modelRank=aur_supplier_rank_one('Need Mindray WATO EX-35', $modelRef);
check_supplier($modelRank!==null && $modelRank['score']===82 && $modelRank['tier']==='MODEL_REFERENCE','documented exact model should outrank category-only');
$otherModel=aur_supplier_rank_one('Need WATO EX-36',$modelRef);
check_supplier($otherModel!==null && $otherModel['score']===32,'partial/fuzzy model may not establish exact match');
$best=aur_supplier_rank_candidates('WATO EX-35',[$category,$exact,array_merge($exact,['match_basis'=>'CATEGORY_ENQUIRY'])]);
check_supplier(count($best)===2 && $best[0]['score']===91,'same supplier multiple pages must deduplicate to best evidence');
$rfq=aur_supplier_rank_rfq([
    '1'=>aur_supplier_rank_candidates('Mindray WATO EX-35',[$exact,$category]),
    '2'=>aur_supplier_rank_candidates('Ultrasound',[$category]),
]);
check_supplier(count($rfq)===2,'RFQ aggregation lost supplier');
check_supplier($rfq[0]['name']==='Category Supplier' && $rfq[0]['matched_lines']===2,'cross-item supplier coverage must rank first');
check_supplier($rfq[0]['coverage']==100 && $rfq[0]['availability']==='NOT_VERIFIED','coverage is not delivery assurance');
check_supplier(!isset($rfq[0]['email']),'read-only RFQ suggestions must not expose new dispatch recipients');
echo "AURENOEVA_SUPPLIER_INTELLIGENCE=PASS\n";
