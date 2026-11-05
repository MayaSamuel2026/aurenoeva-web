<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
require dirname(__DIR__).'/deployment/site/procurement/urgent_supplier_drafts.php';

function urgent_check(bool $yes,string $reason):void {
    if(!$yes)throw new RuntimeException($reason);
}
function urgent_line(int $item,string $description,int $sourceRow,?int $qty=1,string $option='REQUESTED'):array {
    return ['source_row'=>$sourceRow,'customer_item_number'=>$item,
        'description_original'=>$description,'quantity'=>$qty,'option_type'=>$option];
}
$root=sys_get_temp_dir().'/aur-urgent-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
try {
    $case=[
       'reference'=>AUR_URGENT_RFQ_REF,'country'=>'UZ',
       'source_sha256'=>hash('sha256','original-buyer-44-lines'),
       'country_preflight'=>aur_embargo_screen_country('UZ'),
       'payload'=>['sourcing_lines'=>[
           urgent_line(1,'DRGEM DIAMOND Classic Expert X-ray system 52 kW',1),
           urgent_line(3,'Samsung Z20 ultrasound system specification 2 with CA1-7S and all options',3),
           urgent_line(4,'Samsung V8 ultrasound, shear wave ECG Heart Assist',4),
           urgent_line(5,'Samsung V6 ultrasound, 3 transducers basic',5),
           urgent_line(11,'Covidien LigaSure Valleylab LS10 VLLS10GEN vessel generator',11),
           urgent_line(12,'DS.Surg 150 electrosurgical unit',12),
           urgent_line(14,'Pentax CP-1000 processor VNL9-CP nasopharyngolaryngoscope',14),
           urgent_line(16,'Heinemann Modula-Europa 0100-30000 ENT workstation',16),
           urgent_line(16,'KLEVER alternative ENT workstation',17,null,'ALTERNATIVE'),
           urgent_line(19,'Huvitz HRK-1 autorefractor keratometer',19),
           urgent_line(20,'Huvitz HNT-1 non-contact tonometer',20),
           urgent_line(21,'Haag-Streit Octopus 600 Basic automatic perimeter',21),
           urgent_line(22,'DS Surg 150 electrosurgical unit',22),
           urgent_line(26,'Mindray WATO EX-35 anesthesia workstation, 3-gas compressor two vaporizers AGSS',26,2),
           urgent_line(27,'Dräger Savina ventilator, refurbished, year 2010-2013',27)
       ]]
    ];
    $original=$case;
    $plans=aur_urgent_supplier_plans($case);
    urgent_check(count($plans)===12,'must have precisely 12 new supplier plans');
    urgent_check(count(array_unique(array_map(static fn($p)=>$p['vendor']['email'],$plans)))===12,
      'supplier emails not deduplicated');
    $cityByEmail=[
        'info@heinemann-ent.de'=>'Kaltenkirchen',
        'info.emea@pentaxmedical.com'=>'Hamburg',
        'info@smdmedical.ch'=>'St. Gallen'
    ];
    $locales=[];
    $foundIds=[];
    foreach($plans as $plan) {
        $email=$plan['vendor']['email'];$country=$plan['vendor']['country'];
        $lang=in_array($country,['DE','AT','CH'],true)?'de':'en';
        urgent_check($plan['language']===$lang,'supplier locale mismatch '.$email);
        $locales[$lang]=($locales[$lang]??0)+1;
        foreach($plan['rows'] as $line) {
            urgent_check($line['quantity']===2 || $line['quantity']===1,
               'Original quantity not preserved');
            urgent_check($line['option_type']==='REQUESTED','alternative product silently added');
            $foundIds[(int)$line['customer_item_number']]=true;
        }
        $composed=aur_urgent_supplier_compose($case,$plan);
        urgent_check($composed['to']===$email,'outgoing email mismatch');
        urgent_check($composed['status']==='DRAFT','draft unexpectedly sent');
        urgent_check($composed['language']===$lang,'saved draft language mismatch');
        urgent_check(str_contains($composed['body'],'NOEVA Systems e.K.')
            && str_contains($composed['body'],'Marienburger Straße 16')
            && str_contains($composed['body'],'56112 Lahnstein')
            && str_contains($composed['body'],'HRA 23554'),
            'Mandatory business signature incomplete');
        if($lang==='de') {
            urgent_check(str_contains($composed['body'],'Handelsregister')
                && str_contains($composed['body'],'Eingetragen beim Amtsgericht Koblenz')
                && str_contains($composed['body'],'Herzliche Grüße nach '.($cityByEmail[$email]??'')),
                'German city or legal register line missing: '.$email);
            urgent_check(!str_contains($composed['body'],'Mit freundlichen Grüßen')
                && !str_contains($composed['body'],'Vielen Dank und freundliche Grüße'),
                'Old generic German closing remains');
        }else {
            urgent_check(str_contains($composed['body'],'Commercial register')
                && str_contains($composed['body'],'Registered with Amtsgericht Koblenz')
                && str_contains($composed['body'],'Thank you and kind regards,'),
                'English legal footer incomplete');
        }
        if($lang==='de'){
            urgent_check(str_contains($composed['body'],'Sehr geehrtes Vertriebsteam')
                && str_contains($composed['subject'],'Preisanfrage')
                && str_contains($composed['body'],'Einzelpreis/Währung'),
                'German supplier content not localized');
        }else{
            urgent_check(str_contains($composed['body'],'Dear Sales / Export Team')
                && str_contains($composed['body'],'Unit price/currency'),
                'English supplier content not localized');
        }
        urgent_check(!str_contains($composed['body'],'DS.Surg 150'),
            'Unrelated duplicate electrosurgical model leaked into supplier requests');
        urgent_check(str_contains($composed['body'],'AUR-UZ-1A04A7909185FACD'),
            'Buyer RFQ reference missing');
    }
    urgent_check($locales['de']===3 && $locales['en']===9,'Expected DE/CH split 3/9');
    urgent_check(count($foundIds)===12 && !isset($foundIds[12],$foundIds[22]),
        'Incorrect buyer item coverage');
    $saved=aur_urgent_supplier_prepare($root,$case);
    urgent_check(($saved['supplier_count']??0)===12,'supplier record count not saved');
    urgent_check(count($saved['messages'])===12,'supplier messages not saved');
    urgent_check($saved['status']==='DRAFT_REVIEW','draft status prematurely advanced');
    $indexPath=aur_urgent_supplier_index_path($root,$case);
    urgent_check(is_file($indexPath),'case-owned import was not durably persisted');
    $draft=aur_vendor_rfq_read($root,$case,$saved['draft_id']);
    urgent_check($draft['information_only']===true && $draft['template_version']==='pricing-rfq-v3',
        'draft does not use established controlled RFQ transport');
    $firstAddress=(string)$draft['messages'][0]['to'];
    $beforeBody=(string)$draft['messages'][0]['body'];
    // Simulate a pre-existing unsent draft prepared with the old signature:
    // the owner can refresh only unattempted text, without creating new mail.
    $draft['messages'][0]['body']="OLDER UNSENT SIGNATURE";
    aur_vendor_rfq_write(aur_vendor_rfq_path($root,AUR_URGENT_RFQ_REF,$saved['draft_id']),$draft);
    $refresh=aur_urgent_supplier_refresh_signatures($root,$case);
    urgent_check($refresh['updated_unsent']===1
       && $refresh['untouched_attempted']===0,
       'Legacy unsent signature was not safely updated');
    $reloaded=aur_vendor_rfq_read($root,$case,$saved['draft_id']);
    urgent_check($reloaded['messages'][0]['body']===$beforeBody
       && $reloaded['messages'][0]['to']===$firstAddress
       && $reloaded['messages'][0]['status']==='DRAFT',
       'Safe signature refresh modified draft recipient or content');
    $secondRefresh=aur_urgent_supplier_refresh_signatures($root,$case);
    urgent_check($secondRefresh['updated_unsent']===0,
       'Signature update is not idempotent');
    $again=aur_urgent_supplier_prepare($root,$case);
    urgent_check($again['draft_id']===$saved['draft_id'],
        'repeated preparation created duplicate outbox');
    urgent_check(count(aur_vendor_rfq_list($root,$case))===1,
        'an unintended second outbox was created');
    urgent_check($case===$original,'original customer case was mutated');
    $bad=$case;
    $bad['payload']['sourcing_lines']=array_values(array_filter(
        $case['payload']['sourcing_lines'],
        static fn($line)=>(int)$line['customer_item_number']!==3));
    $failed=false;
    try{aur_urgent_supplier_plans($bad);}catch(RuntimeException $e){$failed=true;}
    urgent_check($failed,'missing model should fail safe before draft creation');
    $sends=[];
    $first=aur_vendor_rfq_dispatch($root,$case,$saved['draft_id'],true,
        static function($to,$subject,$body)use(&$sends):bool {
            $sends[]=['to'=>$to,'subject'=>$subject,'body'=>$body];
            return true;
        },3);
    urgent_check(count($sends)===3,'one approval may dispatch only 3 recipients');
    urgent_check(($first['dispatch_progress']['processed_this_request']??null)===3,
        'progress receipt missing');
    urgent_check(($first['last_batch']['indices']??null)===[0,1,2]
        &&!empty($first['last_batch']['recorded_at']),
        'Last attempted supplier batch receipt not durably recorded');
    $remaining=aur_vendor_rfq_status_summary($first);
    urgent_check($remaining['remaining']===9 && $remaining['accepted']===3,
        'SMTP transport ledger did not correctly count accepted and unsent');
    $afterSend=aur_vendor_rfq_read($root,$case,$saved['draft_id']);
    $sentBody=(string)$afterSend['messages'][0]['body'];
    $noRewrite=aur_urgent_supplier_refresh_signatures($root,$case);
    urgent_check($noRewrite['untouched_attempted']===3
       && $noRewrite['updated_unsent']===0
       && aur_vendor_rfq_read($root,$case,$saved['draft_id'])['messages'][0]['body']===$sentBody,
       'Sent/attempted supplier email was modified by signature refresh');
    $stored=aur_urgent_supplier_saved($root,$case);
    urgent_check($stored['messages'][0]['status']==='ACCEPTED_BY_MAIL_TRANSPORT'
         && $stored['messages'][3]['status']==='DRAFT','per-supplier states are not durable');
    // 1 November sourcing round remains private, concise and unsent until approval.
    $novCase=$original;
    $novCase['source_sha256']=hash('sha256','november-test-source');
    foreach([
        urgent_line(2,'Anthos A5 Continental dental unit',2),
        urgent_line(6,'MT5000S endoscope washer for one endoscope',6),
        urgent_line(7,'EVORAY EC-5BD endoscope drying cabinet',7),
        urgent_line(17,'MediWorks S260S slit lamp',18),
        urgent_line(30,'New Vatech 16x9 CBCT with cephalostat',30),
        urgent_line(32,'Castellini Skema 6 SD-9099 dental unit',32),
        urgent_line(33,'Castellini Skema 8 6X-9056 dental unit',33,2),
        urgent_line(34,'Euronda E8 24 L autoclave, Euroseal 2001 Plus and Aquadis',34),
        urgent_line(35,'1.5 T MRI system',35),
        urgent_line(36,'CATTANI dental compressor 45L 238 L/min',36),
        urgent_line(38,'CSSD autoclave',38,null)
    ] as $novLine)$novCase['payload']['sourcing_lines'][]=$novLine;
    $novPlans=aur_nov_short_plans($novCase);
    urgent_check(count($novPlans)===12,'November round must contain 12 email leads');
    urgent_check(count(aur_nov_short_contact_forms())===3,'November round must retain three manual form routes');
    foreach($novPlans as $plan) {
        $msg=aur_nov_short_compose($novCase,$plan);
        urgent_check($msg['status']==='DRAFT','November message was not a draft');
        urgent_check(!preg_match('/Uzbekistan|Usbekistan|AUR-UZ-1A04A7909185FACD/ui',$msg['body'].' '.$msg['subject']),
            'Destination exposed in November outgoing message');
        urgent_check(str_contains($msg['body'],'HRA 23554')
            && str_contains($msg['body'],'info@aurenoeva.com'),'November footer missing');
    }
    $novSaved=aur_nov_short_prepare($root,$novCase);
    urgent_check(count($novSaved['messages'])===12 && $novSaved['status']==='DRAFT_REVIEW',
        'November preparation must save all 12 unsent messages');
    $novAgain=aur_nov_short_prepare($root,$novCase);
    urgent_check($novAgain['draft_id']===$novSaved['draft_id'],
        'November preparation must be idempotent');

    // Friendly German default and controlled revision of already saved emails.
    $newGerman=array_values(array_filter($novPlans,
        static fn($p)=>$p['lead']['language']==='de'));
    urgent_check(count($newGerman)===2,'Expected two German contacts in short round');
    foreach($newGerman as $plan) {
        $message=aur_nov_short_compose($novCase,$plan);
        urgent_check(str_contains($message['body'],'wir interessieren uns für ')
            &&str_contains($message['body'],'unverbindliches Angebot freuen')
            &&str_contains($message['body'],'Über eine kurze Rückmeldung'),
            'German short email is not friendly');
        urgent_check(!str_contains($message['body'],'bitte erstellen Sie uns ein Angebot'),
            'Demanding German wording remains');
    }
    $novPath=aur_vendor_rfq_path($root,AUR_URGENT_RFQ_REF,$novSaved['draft_id']);
    $draft=aur_vendor_rfq_read($root,$novCase,$novSaved['draft_id']);
    $draft['messages'][0]['body']="Guten Tag, bitte erstellen Sie uns ein Angebot.";
    $draft['messages'][7]['body']="Historic already-attempted text";
    $draft['messages'][7]['status']='ACCEPTED_BY_MAIL_TRANSPORT';
    $draft['messages'][7]['attempted_at']=gmdate('c');
    aur_vendor_rfq_write($novPath,$draft);
    $refreshed=aur_nov_short_refresh_unsent($root,$novCase);
    urgent_check($refreshed['updated_unsent']===1
        &&$refreshed['untouched_attempted']===1,
        'Friendly refresh must change one unsent draft only');
    $savedAgain=aur_vendor_rfq_read($root,$novCase,$novSaved['draft_id']);
    urgent_check(str_contains($savedAgain['messages'][0]['body'],'wir interessieren uns für ')
        &&$savedAgain['messages'][7]['body']==='Historic already-attempted text',
        'Refresh changed sent email or failed to revise draft');
    urgent_check(aur_nov_short_refresh_unsent($root,$novCase)['updated_unsent']===0,
        'Friendly refresh should be idempotent');

    // Checked batches are exactly the selected 1-3 never an automatic full send.
    $testSend=[];
    $sendMock=static function($to,$subject,$body)use(&$testSend):bool {
        $testSend[]=['to'=>$to,'subject'=>$subject,'body'=>$body];
        return true;
    };
    foreach([null,[],[0,1,2,3],[0,0],[99],['untrusted']] as $badSelection) {
        $rejected=false;
        try {
            aur_vendor_rfq_dispatch($root,$novCase,$novSaved['draft_id'],true,
                $sendMock,3,$badSelection);
        }catch(InvalidArgumentException $e){$rejected=true;}
        urgent_check($rejected,'Short round accepted an unsafe selection');
    }
    urgent_check(count($testSend)===0,'Rejected selection sent an email');
    $selected=[0,2,3];
    $batch=aur_vendor_rfq_dispatch($root,$novCase,$novSaved['draft_id'],true,
        $sendMock,3,$selected);
    urgent_check(count($testSend)===3
        &&($batch['dispatch_progress']['processed_this_request']??0)===3,
        'Three selected suppliers were not the entire sending batch');
    $expectedEmails=array_map(
      static fn($i)=>(string)$savedAgain['messages'][$i]['to'],$selected);
    urgent_check(array_column($testSend,'to')===$expectedEmails,
        'Nonselected supplier was contacted');
    $after=aur_vendor_rfq_read($root,$novCase,$novSaved['draft_id']);
    urgent_check(($after['messages'][1]['status']??'')==='DRAFT'
        &&($after['messages'][7]['body']??'')==='Historic already-attempted text',
        'Unselected or previously attempted recipient changed');
    $againSelectionRejected=false;
    try {aur_vendor_rfq_dispatch($root,$novCase,$novSaved['draft_id'],true,
        $sendMock,3,[0]);}
    catch(InvalidArgumentException $e){$againSelectionRejected=true;}
    urgent_check($againSelectionRejected&&count($testSend)===3,
        'An already sent recipient was automatically resent');
    // A stale German draft must not disable unrelated English recipients.
    $isolated=aur_vendor_rfq_read($root,$novCase,$novSaved['draft_id']);
    $isolated['draft_id']=bin2hex(random_bytes(12));
    $isolated['status']='DRAFT_REVIEW';
    $isolated['approval']=null;
    $isolated['messages'][7]['body']='OLD DE wording: Bitte erstellen Sie uns ein Angebot.';
    $isolated['messages'][7]['status']='DRAFT';
    $isolated['messages'][7]['attempted_at']=null;
    $isolated['messages'][7]['sent_at']=null;
    unset($isolated['messages'][7]['transport_result']);
    aur_vendor_rfq_write(aur_vendor_rfq_path($root,AUR_URGENT_RFQ_REF,$isolated['draft_id']),$isolated);
    $isolatedSends=[];
    $isolatedMock=static function($to,$subject,$body)use(&$isolatedSends):bool {
        $isolatedSends[]=$to; return true;
    };
    $staleRefused=false;
    try{aur_vendor_rfq_dispatch($root,$novCase,$isolated['draft_id'],true,
        $isolatedMock,3,[7]);}
    catch(InvalidArgumentException $e){$staleRefused=$e->getMessage()==='refresh_german_supplier_wording_first';}
    urgent_check($staleRefused && !$isolatedSends,
        'Unrefreshed German email should not be sent');
    $selectedResult=aur_vendor_rfq_dispatch($root,$novCase,$isolated['draft_id'],true,
        $isolatedMock,3,[1]);
    urgent_check(($selectedResult['last_batch']['indices']??null)===[1],
        'Receipt must preserve only the checked supplier, never all batch entries');
    urgent_check(count($isolatedSends)===1
        &&$isolatedSends[0]===$isolated['messages'][1]['to'],
        'Old unrelated German draft incorrectly blocked an English supplier');
    $portalCode=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_portal.php');
    $dispatchCode=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php');
    urgent_check(str_contains($portalCode,'$aurShortBatch?aur_vendor_rfq_smtp_ready():aur_vendor_rfq_mail_verified($aurStore)')
        &&str_contains($portalCode,'Update unsent German email wording')
        &&str_contains($dispatchCode,'if(!$selectedOnly && !aur_vendor_rfq_mail_verified($store))')
        &&!str_contains($dispatchCode,'aur_vendor_rfq_send_smoke_test($store);'),
        'Short supplier SMTP recovery or inline German refresh missing');
    require_once dirname(__DIR__).'/deployment/site/procurement/staff_auth.php';
    $request=json_encode(['task'=>'send','csrf'=>'test','case_ref'=>AUR_URGENT_RFQ_REF,
      'draft_id'=>$novSaved['draft_id'],'manual_review'=>true,'selected_indices'=>[1,4,5]]);
    $parsed=aur_staff_procurement_json_post($request);
    urgent_check($parsed['selected_indices']===[1,4,5]
      &&($parsed['send_vendor_rfqs']??'')==='1',
      'Browser selected recipient list not received by existing action');

    // Stockholder round: 9 listing-specific messages, original item provenance,
    // no destination in public mail, exact three-checkbox dispatch.
    $stockPlans=aur_stock_listing_plans($novCase);
    urgent_check(count($stockPlans)===9,'Expected nine independently evidenced listings');
    foreach($stockPlans as $stockPlan) {
        $stockMsg=aur_stock_listing_compose($novCase,$stockPlan);
        urgent_check(($stockMsg['status']??'')==='DRAFT' &&
            !preg_match('/Uzbekistan|Usbekistan|AUR-UZ-1A04A7909185FACD/u',
                $stockMsg['subject'].' '.$stockMsg['body']),
            'Stockholder email exposed destination or sent prematurely');
        urgent_check(str_contains($stockMsg['body'],$stockPlan['lead']['evidence_url'])
            &&str_contains($stockMsg['body'],'HRA 23554')
            &&($stockMsg['source_grade']??'')==='ADVERTISED_IN_STOCK_NOT_SELLER_CONFIRMED',
            'Listing source or original NOEVA signature missing');
        urgent_check(count($stockMsg['items'])===count($stockPlan['rows']),
            'Stockholder request lost buyer item');
    }
    $stockDraft=aur_stock_listing_prepare($root,$novCase);
    urgent_check(count($stockDraft['messages'])===9 &&
        ($stockDraft['status']??'')==='DRAFT_REVIEW',
        'Stockholder draft was not saved without sending');
    urgent_check(aur_stock_listing_prepare($root,$novCase)['draft_id']===$stockDraft['draft_id'],
        'Stockholder preparation duplicated the saved outbox');
    $stockSent=[];
    $stockMock=static function($to,$subject,$body)use(&$stockSent):bool {
        $stockSent[]=$to;return true;
    };
    foreach([null,[],[0,1,2,3],[0,0],[99]] as $bad) {
        $rejected=false;
        try{aur_vendor_rfq_dispatch($root,$novCase,$stockDraft['draft_id'],
              true,$stockMock,3,$bad);}
        catch(InvalidArgumentException $e){$rejected=true;}
        urgent_check($rejected,'Stockholder outbox accepted invalid selection');
    }
    urgent_check(!$stockSent,'Invalid stock selection sent a supplier email');
    $stockBatch=aur_vendor_rfq_dispatch($root,$novCase,$stockDraft['draft_id'],
        true,$stockMock,3,[1,3,7]);
    urgent_check(count($stockSent)===3 &&
        ($stockBatch['dispatch_progress']['processed_this_request']??0)===3 &&
        $stockSent[0]===$stockDraft['messages'][1]['to'] &&
        $stockSent[1]===$stockDraft['messages'][3]['to'] &&
        $stockSent[2]===$stockDraft['messages'][7]['to'],
        'Only exactly checked stockholders should receive a batch');
    $stockReload=aur_stock_listing_saved($root,$novCase);
    urgent_check(($stockReload['messages'][0]['status']??'')==='DRAFT' &&
        ($stockReload['messages'][1]['status']??'')==='ACCEPTED_BY_MAIL_TRANSPORT',
        'Stockholder outbox statuses did not persist');
    $portalText=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_portal.php');
    urgent_check(str_contains($portalText,'aur-stock-supplier-rfqs') &&
        str_contains($portalText,'aur_vendor_rfq_is_selected_batch(') &&
        aur_vendor_rfq_is_selected_batch('CURATED_STOCK_LISTING_ROUND_2026_11_03'),
        'Stockholder UI or selected sending path not installed');
    $workJs=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/worldwide.js');
    $indexHtml=(string)file_get_contents(dirname(__DIR__).'/deployment/site/procurement/index.php');
    urgent_check(str_contains($portalText,'id="aur-vendor-send-form" novalidate') &&
        str_contains($portalText,'aria-live="polite" tabindex="-1" id="aur-send-progress"') &&
        str_contains($workJs,"const reviewBox=sendForm.querySelector('input[name=\"manual_review\"]')") &&
        str_contains($workJs,'if (!reviewBox?.checked)') &&
        str_contains($workJs,'Sending… Please do not click again') &&
        str_contains($workJs,'displayBatchReceipt(verified,selected)') &&
        str_contains($workJs,"sendForm.dataset.sending='1'") &&
        str_contains($workJs,'event.preventDefault();') &&
        str_contains($portalText,'id="aur-send-receipt"') &&
        str_contains($indexHtml,'worldwide.js?v=12'),
        'Visible send feedback and real checkbox acknowledgment missing');
    echo "AURENOEVA_SEND_BUTTON_VISIBLE_FEEDBACK=PASS\n";
    echo "AURENOEVA_STOCKHOLDER_9_DRAFTS_CHECKED_BATCH=PASS\n";
    echo "AURENOEVA_URGENT_12_SUPPLIER_DRAFTS=PASS DE=".$locales['de']." EN=".$locales['en']."\n";
}finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
