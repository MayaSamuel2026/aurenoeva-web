<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/nov11_supplier_batch.php';
function nov11_check(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$leads=aur_nov11_supplier_leads();
nov11_check(count($leads)===29,'Expected 29 distinct new sourcing leads');
$contactable=array_values(array_filter($leads,static fn($x)=>$x['email']!==''));
$manual=array_values(array_filter($leads,static fn($x)=>$x['email']===''));
nov11_check(count($contactable)===14&&count($manual)===15,'Wrong 11 Nov email/manual source split');
$map=[];$emails=[];
foreach($leads as $lead){
    nov11_check(str_starts_with($lead['url'],'https://'),'Non-HTTPS source');
    if($lead['email']!=='') {
        nov11_check(filter_var($lead['email'],FILTER_VALIDATE_EMAIL)!==false,'Invalid recipient');
        nov11_check(!isset($emails[$lead['email']]),'Duplicate email recipient');
        $emails[$lead['email']]=true;
    }
    foreach($lead['items'] as $n)$map[$n]=true;
}
nov11_check(count($map)===34,'34 unpriced item numbers not represented');
$labels=[
    1=>'DRGEM DIAMOND Classic Expert 52 kW',
    2=>'Anthos A5 Continental unit',
    3=>'Samsung HERA Z20 ultrasound',
    4=>'Samsung V8 ultrasound 4 transducers',
    5=>'Samsung V6 basic ultrasound',
    6=>'MT5000S endoscope washer',
    7=>'EVORAY EC-5BD cabinet',
    8=>'DS.Vision CystoFiber 4.9/2.2 cystoscope',
    9=>'DS.Vision FHD 3-in-1 imaging system',
    10=>'RF-Magic II radio wave coagulator',
    11=>'Medtronic Valleylab LS10 LigaSure',
    12=>'DS.Surg 150 electrosurgical unit',
    13=>'LAKHTA-MILON proctology set',
    14=>'Pentax VNL9-CP + CP-1000 + monitor',
    16=>'Heinemann MODULA Europa 0100-30000',
    17=>'MediWorks S260S slit lamp',
    18=>'VS-2000 ophthalmology workstation',
    22=>'DS.Surg 150 surgical unit',
    23=>'POLYUS-2M magnetotherapy',
    24=>'Neurosoft Neuron-Spectrum-1 EEG / EP / EMG',
    25=>'Mindray uMED 20 monitor defibrillator',
    26=>'Mindray WATO EX-35 three gas anaesthesia',
    28=>'Meditsinoff E-8 patient transport trolley',
    29=>'Neurosoft Spiro-Spectrum Lilly-tube spirometer',
    30=>'Vatech Green X 16 16x9 with cephalostat',
    31=>'GD-0046 X-ray room set',
    32=>'Castellini Skema 6 SD-9099',
    33=>'Castellini Skema 8 6X-9056',
    35=>'1.5 T MRI system',
    36=>'CATTANI AC300 013330',
    37=>'Kler KGEM-01 examination chair',
    38=>'CSSD steam sterilizer',
    39=>'CSSD dry heat sterilizer',
    40=>'H2O2 plasma sterilizer'
];
nov11_check(array_keys($labels)===array_keys($map)||count($labels)===count($map),'Missing test mapping');
$lines=[];
foreach($labels as $n=>$label)$lines[]=[
    'source_row'=>$n,'customer_item_number'=>$n,'description_original'=>$label,
    'quantity'=>in_array($n,[26,29,33,37],true)?2:(in_array($n,[28],true)?3:1),
    'option_type'=>'REQUESTED'
];
$case=['reference'=>AUR_URGENT_RFQ_REF,'country'=>'UZ',
    'country_preflight'=>aur_embargo_screen_country('UZ'),
    'source_sha256'=>hash('sha256','aurenoeva-nov11-regression'),
    'payload'=>['sourcing_lines'=>$lines]];
$plans=aur_nov11_supplier_plans($case);
nov11_check(count($plans)===14,'Fourteen researched email plans expected');
foreach($plans as $plan) {
    $mail=aur_nov11_supplier_compose($case,$plan);
    nov11_check($mail['status']==='DRAFT','Preparation attempted dispatch');
    nov11_check($mail['items']&&count($mail['items'])===count($plan['lead']['items']),'Original item mapping lost');
    nov11_check(!preg_match('/Uzbekistan|Usbekistan|Tashkent|AUR-UZ-/ui',$mail['subject'].' '.$mail['body']),
      'Confidential destination/reference leaked to supplier');
    nov11_check(str_contains($mail['subject'],'AUR-1A04A7909185FACD'),'Missing supplier-safe reference');
    if($mail['language']==='de')
        nov11_check(str_contains($mail['body'],'wir interessieren uns für '),'German send validation mismatch');
}
nov11_check(aur_vendor_rfq_is_selected_batch('TARGETED_NEW_SOURCES_ROUND_2026_11_11'),
    'Human-selected three-supplier gate not enabled');
$root=sys_get_temp_dir().'/aur-nov11-test-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try{
    $saved=aur_nov11_supplier_prepare($root,$case);
    nov11_check($saved['supplier_count']===14&&$saved['status']==='DRAFT_REVIEW',
        'Review-only batch was not persisted');
    $again=aur_nov11_supplier_prepare($root,$case);
    nov11_check($saved['draft_id']===$again['draft_id'],'Duplicate draft created');
    nov11_check(count(aur_vendor_rfq_list($root,$case))===1,'Unexpected number of outbox drafts');
    $draft=aur_vendor_rfq_read($root,$case,$saved['draft_id']);
    nov11_check(count($draft['messages'])===14&&$draft['information_only']===true,
        'Outbox lost messages or transactional restriction');
    $calls=[];
    $fake=static function(string $to,string $subject,string $body) use(&$calls):bool{
        $calls[]=['to'=>$to,'subject'=>$subject];return true;
    };
    $sent=aur_vendor_rfq_dispatch($root,$case,$saved['draft_id'],true,$fake,3,[0,1,2]);
    nov11_check(count($calls)===3 && ($sent['messages'][3]['status']??'')==='DRAFT',
        'Selected-only dispatch sent unintended recipients');
    $blocked=false;
    try {aur_vendor_rfq_dispatch($root,$case,$saved['draft_id'],true,$fake,3,[0,1,2]);}
    catch(InvalidArgumentException $e){$blocked=$e->getMessage()==='selected_supplier_already_attempted';}
    nov11_check($blocked&&count($calls)===3,'Duplicate attempts were not rejected');
}finally{
    $it=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
echo "AURENOEVA Nov11 supplier sourcing/approval test: PASS\n";
