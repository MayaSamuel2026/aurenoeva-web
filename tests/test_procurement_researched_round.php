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
    'country_preflight'=>aur_embargo_screen_country('UZ'),
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
      research_line(26,'Mindray WATO EX-35 3-gas compressor AGSS',2),
      research_line(27,'Dräger Savina ventilator refurbished 2010-2013'),
      research_line(34,'Set Euronda E8 24 L Class B autoclave, Euroseal 2001 Plus and Aquadis'),
      research_line(35,'1.5 T MRI system'),
      research_line(17,'Dixion MediWorks S260S ophthalmic slit lamp'),
      research_line(21,'Haag-Streit Octopus 600 Basic automatic perimeter'),
      research_line(29,'Neurosoft Spiro-Spectrum computerized spirometer'),
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
$followPlans=aur_researched_20261105_plans($case,aur_researched_20261105_followup_leads());
research_check(count($followPlans)===7,'Seven source-backed new supplier emails expected');
$followEmails=[];$followItems=[];
foreach($followPlans as $followPlan){
    $mail=aur_researched_20261105_compose($case,$followPlan);
    research_check(!isset($followEmails[strtolower($mail['to'])]),'Repeat followup recipient');
    $followEmails[strtolower($mail['to'])]=true;
    research_check($mail['status']==='DRAFT' && str_contains($mail['body'],'intermediary')
        || $mail['status']==='DRAFT' && str_contains($mail['body'],'Vermittler'),
        'Not an information-only brokerage draft');
    research_check(!preg_match('/Uzbekistan|Usbekistan|AUR-UZ-|Tashkent/ui',
        $mail['subject'].' '.$mail['body']),'Destination leaked in followup draft');
    foreach($mail['items'] as $item){
        $n=(int)$item['customer_item_number'];$followItems[$n]=true;
        if($n===26)research_check($item['quantity']===2,'WATO quantity 2 not retained');
    }
}
research_check(count($followItems)===5,'New supplier round did not cover five requested item numbers');
research_check(count(aur_researched_20261105_followup_manual())===2,
    'Two marketplace-form leads missing');
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
    $next=aur_researched_20261105_followup_prepare($root,$case);
    research_check($next['supplier_count']===7,'Seven new messages not durably saved');
    research_check($next['status']==='DRAFT_REVIEW','Followup prematurely sent');
    $nextAgain=aur_researched_20261105_followup_prepare($root,$case);
    research_check($nextAgain['draft_id']===$next['draft_id'],
        'Followup saved duplicates on repeat');
    research_check(count(aur_vendor_rfq_list($root,$case))===2,
        'Followup should add only one new private outbox');
    $draft2=aur_vendor_rfq_read($root,$case,$next['draft_id']);
    research_check($draft2['source']==='TARGETED_FOLLOWUP_ROUND_2026_11_05'
        && count($draft2['messages'])===7,'Unexpected followup outbox metadata');
    // The seven-supplier source must use the SAME selected-only dispatch rules
    // as the UI, with attachments enabled and no unselected messages sent.
    research_check(aur_vendor_rfq_is_selected_batch('TARGETED_FOLLOWUP_ROUND_2026_11_05')
        && aur_vendor_rfq_is_selected_batch('TARGETED_RESEARCH_ROUND_2026_11_05')
        && !aur_vendor_rfq_is_selected_batch('GENERIC_LEGACY_RFQ'),
        'New research sources differ between preview and dispatch');
    $calls=[];
    $sendStub=static function(string $to,string $subject,string $body)use(&$calls):bool {
        $calls[]=['to'=>$to,'subject'=>$subject];
        return true;
    };
    $firstTry=aur_vendor_rfq_dispatch($root,$case,$next['draft_id'],true,$sendStub,3,[0,1,2]);
    research_check(count($calls)===3
        && ($firstTry['last_batch']['indices']??null)===[0,1,2]
        && ($firstTry['dispatch_progress']['processed_this_request']??0)===3,
        'Selected first three suppliers were rejected or not recorded');
    research_check($firstTry['messages'][3]['status']==='DRAFT',
        'Unselected fourth supplier was contacted');
    $duplicateRejected=false;
    try {
        aur_vendor_rfq_dispatch($root,$case,$next['draft_id'],true,$sendStub,3,[0,1,2]);
    }catch(InvalidArgumentException $e) {
        $duplicateRejected=$e->getMessage()==='selected_supplier_already_attempted';
    }
    research_check($duplicateRejected && count($calls)===3,
        'Already attempted suppliers could be sent twice');
    $secondTry=aur_vendor_rfq_dispatch($root,$case,$next['draft_id'],true,$sendStub,3,[3,4,5]);
    research_check(count($calls)===6
        && ($secondTry['last_batch']['indices']??null)===[3,4,5],
        'Second selected three-recipient batch failed');
    $finalTry=aur_vendor_rfq_dispatch($root,$case,$next['draft_id'],true,$sendStub,3,[6]);
    research_check(count($calls)===7
        && $finalTry['status']==='TRANSPORT_ACCEPTED'
        && ($finalTry['last_batch']['indices']??null)===[6],
        'Remaining one supplier was not processed as selected');
    // The 6 November batch builds exactly 10 product-linked proposals:
    // 3 deliberate follow-ups, 7 first enquiries. Older unsent drafts own two
    // of the first-contact addresses, which must not be duplicated.
    $nextPlans=aur_researched_20261105_plans($case,aur_researched_next_20261106_leads());
    research_check(count($nextPlans)===10,'Next round should propose ten model-matched contacts');
    $types=[];$addresses=[];
    foreach($nextPlans as $plan){
        $msg=aur_researched_next_20261106_compose($case,$plan);
        research_check(($msg['status']??'')==='DRAFT','Next round mail should remain draft');
        research_check($msg['contact_type']===$plan['lead']['kind'],
            'Follow-up and first-contact distinction lost');
        research_check(count($msg['items'])===count($plan['lead']['items']),
            'RFQ item mapping mismatch in new sourcing round');
        research_check(!isset($addresses[strtolower($msg['to'])]),
            'Duplicate next round email contact');
        $addresses[strtolower($msg['to'])]=true;
        $types[$msg['contact_type']]=($types[$msg['contact_type']]??0)+1;
        research_check(!preg_match('/Uzbekistan|Usbekistan|AUR-UZ-|Tashkent/ui',
            $msg['body'].' '.$msg['subject']),'Supplier-facing message leaks destination');
        if($msg['language']==='de') research_check(
            str_contains($msg['body'],'wir interessieren uns für '),
            'German first/follow-up message fails existing dispatch wording contract');
    }
    research_check($types['FOLLOWUP']===3 && $types['FIRST_CONTACT']===7,
        'Wrong new vs existing supplier split');
    research_check(aur_vendor_rfq_is_selected_batch('TARGETED_NEXT_SOURCING_ROUND_2026_11_06'),
        'New round not supported by the selected-recipient sender');
    $nextSaved=aur_researched_next_20261106_prepare($root,$case);
    research_check($nextSaved['supplier_count']===8
        && count($nextSaved['skipped_existing_recipients'])===2,
        'Existing Duo Med and EGGERT draft contacts were not deduplicated');
    research_check($nextSaved['status']==='DRAFT_REVIEW',
        'Preparing next round must not send');
    research_check(aur_researched_next_20261106_prepare($root,$case)['draft_id']===$nextSaved['draft_id'],
        'Next round preparation is not idempotent');
    research_check(count(aur_vendor_rfq_list($root,$case))===3,
        'Next round should create exactly one additional private outbox');
    $nextOutbox=aur_vendor_rfq_read($root,$case,$nextSaved['draft_id']);
    research_check(($nextOutbox['source']??'')==='TARGETED_NEXT_SOURCING_ROUND_2026_11_06',
        'Wrong source prevents three-recipient sending and attachments');
    research_check(count($nextOutbox['messages'])===8,
        'Eight deduplicated emails not present in saved outbox');
    $nextFirst=[];
    foreach($nextOutbox['messages'] as $msg) {
        if(($msg['contact_type']??'')==='FIRST_CONTACT')$nextFirst[]=$msg['to'];
    }
    research_check(!in_array('info@duo-med.de',$nextFirst,true)
        && !in_array('anfrage@dental-eggert.de',$nextFirst,true),
        'Older unsent supplier contacts duplicated');
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
