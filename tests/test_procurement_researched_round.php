<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/researched_supplier_round.php';
function research_check(bool $value,string $label):void{
    if(!$value)throw new RuntimeException($label);
}
function research_line(int $number,string $description,?int $qty=1):array {
    return ['source_row'=>$number,'customer_item_number'=>$number,
        'description_original'=>$description,'quantity'=>$qty,'option_type'=>'REQUESTED'];
}
$case=[
    'reference'=>AUR_URGENT_RFQ_REF,'country'=>'UZ',
    'source_sha256'=>hash('sha256','research-20261105-original'),
    'payload'=>['sourcing_lines'=>[
      research_line(2,'Anthos A5 Continental dental unit'),
      research_line(4,'Samsung V8 four transducers shear wave ECG Heart Assist'),
      research_line(5,'Samsung V6 basic three transducers'),
      research_line(11,'Covidien LigaSure Valleylab LS10 VLLS10GEN'),
      research_line(24,'Neuron-Spectrum-1 EEG EP EMG system'),
      research_line(30,'New Vatech 16 x 9 CBCT with cephalostat'),
      research_line(32,'Castellini Skema 6 unit SD-9099'),
      research_line(33,'Castellini Skema 8 unit 6X-9056',2),
      research_line(36,'CATTANI compressor three-cylinder 45 L'),
    ]]
];
$plans=aur_researched_20261105_plans($case);
research_check(count($plans)===9,'Expected nine new research suppliers');
$emails=[];$covered=[];$locales=[];
foreach($plans as $plan){
    $message=aur_researched_20261105_compose($case,$plan);
    $email=strtolower($message['to']);
    research_check(!isset($emails[$email]),'Duplicate research supplier');
    $emails[$email]=true;
    $locales[$message['language']]=true;
    research_check(($message['status']??'')==='DRAFT','Research preparation cannot send');
    research_check(!preg_match('/Uzbekistan|Usbekistan|AUR-UZ-|Tashkent/ui',
        $message['subject'].' '.$message['body']),'Country leaked into supplier-facing message');
    research_check(str_contains($message['body'],'NOEVA Systems e.K.')
        && str_contains($message['body'],'HRA 23554'),'Legal sender details missing');
    research_check($message['listing_evidence_url']===$plan['lead']['evidence_url'],
        'Supplier evidence not retained');
    research_check(count($message['items'])===count($plan['lead']['items']),
        'Incorrect item count');
    foreach($message['items'] as $item){
        $n=(int)$item['customer_item_number'];
        $covered[$n]=true;
        if($n===33)research_check($item['quantity']===2,'Skema 8 quantity lost');
        research_check(($item['option_type']??'')==='REQUESTED','Alternative silently included');
    }
}
research_check(count($covered)===9,'Did not cover all nine target buyer item numbers');
research_check(isset($locales['de'],$locales['en']),'Mixed-language preparation failed');
research_check(str_contains(aur_researched_20261105_compose($case,$plans[0])['body'],'wir interessieren uns für '),
    'German friendly wording incompatible with review workflow');
$root=sys_get_temp_dir().'/aur-research-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try{
    $saved=aur_researched_20261105_prepare($root,$case);
    research_check($saved['supplier_count']===9,'Incorrect number persisted');
    research_check($saved['status']==='DRAFT_REVIEW','Draft should remain unsent');
    $draft=aur_vendor_rfq_read($root,$case,$saved['draft_id']);
    research_check($draft['source']==='TARGETED_RESEARCH_ROUND_2026_11_05',
        'Wrong outbox source');
    research_check(count($draft['messages'])===9,'Private outbox lost recipients');
    research_check($draft['information_only']===true,'Research became purchase order');
    research_check($draft['template_version']==='pricing-rfq-v3',
        'Review/dispatch version changed');
    $again=aur_researched_20261105_prepare($root,$case);
    research_check($again['draft_id']===$saved['draft_id'],
        'Same research batch created duplicate outbox');
    // The second preparation must not change a previously saved message state.
    research_check(count(aur_vendor_rfq_list($root,$case))===1,
        'Idempotent preparation generated a duplicate RFQ draft');
    $bad=$case;
    $bad['payload']['sourcing_lines'][0]['description_original']='Generic chair';
    $thrown=false;
    try{aur_researched_20261105_plans($bad);}catch(RuntimeException $e){$thrown=true;}
    research_check($thrown,'Original model mismatch was silently accepted');
}finally{
    $it=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
echo "AURENOEVA researched supplier round: PASS\n";
