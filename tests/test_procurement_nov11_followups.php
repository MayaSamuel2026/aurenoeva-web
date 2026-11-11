<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/nov11_supplier_followups.php';
function follow_check(bool $v,string $why):void {if(!$v)throw new RuntimeException($why);}
$case=[
    'reference'=>AUR_URGENT_RFQ_REF,'country'=>'UZ',
    'country_preflight'=>aur_embargo_screen_country('UZ'),
    'source_sha256'=>hash('sha256','nov11-replies-source'),
    'payload'=>['sourcing_lines'=>[
        ['source_row'=>3,'customer_item_number'=>3,'description_original'=>'Samsung Z20 ultrasound system, CA1-7S LA2-14A EA2-11AR CMV1-10','quantity'=>1,'option_type'=>'REQUESTED'],
        ['source_row'=>4,'customer_item_number'=>4,'description_original'=>'Samsung V8 ultrasound, four transducers, shear wave, ECG, Heart Assist','quantity'=>1,'option_type'=>'REQUESTED'],
        ['source_row'=>32,'customer_item_number'=>32,'description_original'=>'Castellini Skema 6 dental unit SD-9099','quantity'=>1,'option_type'=>'REQUESTED'],
        ['source_row'=>33,'customer_item_number'=>33,'description_original'=>'Castellini Skema 8 dental unit 6X-9056','quantity'=>2,'option_type'=>'REQUESTED'],
    ]]
];
$plans=aur_nov11_followup_plans($case);
follow_check(count($plans)===2,'Must prepare exactly two human-approved supplier follow-ups');
$to=[];$all=[];
foreach($plans as $plan){
    $m=aur_nov11_followup_compose($case,$plan);
    follow_check($m['status']==='DRAFT','Follow-up is not draft only');
    follow_check(count($m['items'])===2,'Original item requirements lost');
    follow_check(!preg_match('/Uzbekistan|Usbekistan|Tashkent|AUR-UZ-/ui',$m['subject'].' '.$m['body']),
        'Supplier-visible destination or internal reference');
    follow_check(str_contains($m['body'],'wir interessieren uns für '),'Required reviewed German wording absent');
    follow_check(str_contains($m['body'],'AUR-1A04A7909185FACD')===false ||
        !str_contains($m['body'],'AUR-UZ-'),'Supplier-safe reference malformed');
    $to[]=$m['to'];$all[$m['to']]=$m;
}
follow_check(in_array('info@duo-med.de',$to,true),'Duo Med response recipient not preserved');
follow_check(in_array('nikita.mueller@meditron.ch',$to,true),'Official Meditron expert recipient missing');
follow_check(str_contains($all['info@duo-med.de']['body'],'Ausstattungsblätter') &&
    str_contains($all['info@duo-med.de']['body'],'Position 33') &&
    str_contains($all['info@duo-med.de']['body'],'2 × Castellini Skema 8'),
    'Duo Med option/multiquantity details incomplete');
follow_check(str_contains($all['nikita.mueller@meditron.ch']['body'],'CA1-7S') &&
    str_contains($all['nikita.mueller@meditron.ch']['body'],'Heart Assist') &&
    str_contains($all['nikita.mueller@meditron.ch']['body'],'nicht angenommen'),
    'Meditron specific probe request or email rejection missing');
follow_check(aur_vendor_rfq_is_selected_batch('TARGETED_SUPPLIER_FOLLOWUPS_2026_11_11'),
    'Selected recipient send gate missing');
$root=sys_get_temp_dir().'/aur-nov11-followup-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try {
    $saved=aur_nov11_followup_prepare($root,$case);
    follow_check($saved['supplier_count']===2&&$saved['status']==='DRAFT_REVIEW','Wrong private draft status');
    $read=aur_vendor_rfq_read($root,$case,$saved['draft_id']);
    follow_check($read['information_only']===true&&$read['template_version']==='pricing-rfq-v3',
        'Follow-up draft not compatible with existing attachment/sending workflow');
    follow_check(count($read['messages'])===2&&$read['source']==='TARGETED_SUPPLIER_FOLLOWUPS_2026_11_11',
        'Wrong outbox routing');
    follow_check(aur_nov11_followup_prepare($root,$case)['draft_id']===$saved['draft_id'],
        'Repeated preparation duplicated the follow-up batch');
    follow_check(count(aur_vendor_rfq_list($root,$case))===1,'Duplicate follow-up outbox');
    $calls=[];
    $fake=static function(string $to,string $subject,string $body)use(&$calls):bool {
        $calls[]=$to;return true;
    };
    $first=aur_vendor_rfq_dispatch($root,$case,$saved['draft_id'],true,$fake,3,[0]);
    follow_check(count($calls)===1 && $first['messages'][1]['status']==='DRAFT',
        'Unselected vendor was contacted');
    $duplicate=false;
    try{aur_vendor_rfq_dispatch($root,$case,$saved['draft_id'],true,$fake,3,[0]);}
    catch(InvalidArgumentException $e){$duplicate=$e->getMessage()==='selected_supplier_already_attempted';}
    follow_check($duplicate&&count($calls)===1,'Duplicate selected recipient was not rejected');
    aur_vendor_rfq_dispatch($root,$case,$saved['draft_id'],true,$fake,3,[1]);
    follow_check(count($calls)===2,'Other supplier was not independently selectable');
}finally {
    $it=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
echo "AURENOEVA two supplier follow-ups / manual selection: PASS\n";
