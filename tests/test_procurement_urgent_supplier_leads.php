<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/supplier_routes.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_worldwide.php';

function urgent_assert(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
}
$cases=[
    'DRGEM DIAMOND Classic Expert X-ray system 52 kW'=>['global-drgem-global-kr'],
    'Samsung Z20 ultrasound system, specification 2'=>['global-medicinos-lt','global-smdmedical-ch'],
    'V8 (4 transducers + shear wave + ECG + Heart Assist + all options)'=>['global-medicinos-lt'],
    'V6, specification: 3 transducers (basic configuration)'=>['global-medicinos-lt'],
    'Huvitz HRK-1 autorefractor/keratometer'=>['global-opptica-lv'],
    'Huvitz HNT-1 non-contact tonometer'=>['global-opptica-lv'],
    'Octopus 600 automatic computerized ophthalmic perimeter'=>[
        'global-uandi-nl','global-mandarin-sg','global-haag-uk'],
    'Drager Savina ventilator, refurbished, manufactured in 2010-2013'=>['global-bhmedical-ae'],
    'Mindray WATO EX-35: 3-gas and AGSS, 2 vaporizers'=>['global-phase-us'],
    'Covidien LigaSure Valleylab LS10 VLLS10GEN generator'=>['global-sakomed-us'],
    'Heinemann Modula-Europa 0100-30000 ENT workstation'=>['global-heinemann-de'],
    'Pentax ENT video system CP-1000 + VNL9-CP'=>['global-pentax-emea-de'],
    'Anthos A5 Continental dental unit'=>['global-eggert-de'],
    'Skema 6 SD-9099 dental unit'=>['global-duomed-de'],
    'Skema 8 6X-9056 dental unit'=>['global-duomed-de'],
    'CATTANI dental compressor 45 L, 238 L/min'=>['global-mediadental-de'],
];
$sourceBacked=[];
foreach($cases as $description=>$expectedIds) {
    $found=aur_supplier_international_contacts($description);
    $byId=[];
    foreach($found as $v)$byId[$v['id']]=$v;
    foreach($expectedIds as $id) {
        urgent_assert(isset($byId[$id]),'Expected model-specific source absent: '.$id.' in '.$description);
        $lead=$byId[$id];
        urgent_assert(filter_var($lead['email']??'',FILTER_VALIDATE_EMAIL)!==false,
            'Invalid independently published contact: '.$id);
        urgent_assert(str_starts_with((string)($lead['url']??''),'https://'),
            'Non-HTTPS evidence: '.$id);
        urgent_assert(($lead['grade']??'')==='EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'
            || ($lead['grade']??'')==='OFFICIAL_MANUFACTURER_CONTACT',
            'Contact route improperly claims available stock: '.$id);
        $sourceBacked[$id]=$lead;
    }
    $seeded=aur_worldwide_seed_candidates($description);
    foreach($expectedIds as $id)
        urgent_assert(in_array($id,array_column($seeded,'id'),true),
            'Curated lead not routed to existing case research: '.$id);
}
urgent_assert(count($sourceBacked)===15,'Urgent supplier source count mismatch');
foreach($sourceBacked as $id=>$lead) {
    urgent_assert(!in_array(strtolower($lead['email']),[
        'info@humanova.ae','sales@kombi-med.com',
        'info@deltamaxgroup.uz','info@aurenoeva.com',
        'info@meditech.uz','yes@medsyst.uz',
        'info@japanmedicalequipment.com','sales@medworld.ca'
    ],true),'Previously contacted supplier wrongly marketed as new: '.$id);
}
$unrelated=aur_supplier_international_contacts('Institutional hospital beds with mattress');
foreach($unrelated as $v)urgent_assert(!isset($sourceBacked[$v['id']]),
    'Unrelated hospital bed falsely matched as exact medical device: '.$v['id']);
$fixture=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_routes.php');
urgent_assert(!str_contains($fixture,'send_vendor_rfqs')
    && !str_contains($fixture,'send_price_reminders'),
    'Supplier research must never dispatch emails automatically');
echo 'AURENOEVA_URGENT_MODEL_MATCHED_SUPPLIER_RESEARCH=PASS '.count($sourceBacked)."\n";
