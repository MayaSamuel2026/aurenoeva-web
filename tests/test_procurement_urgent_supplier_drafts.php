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
    $remaining=aur_vendor_rfq_status_summary($first);
    urgent_check($remaining['remaining']===9 && $remaining['accepted']===3,
        'SMTP transport ledger did not correctly count accepted and unsent');
    $stored=aur_urgent_supplier_saved($root,$case);
    urgent_check($stored['messages'][0]['status']==='ACCEPTED_BY_MAIL_TRANSPORT'
         && $stored['messages'][3]['status']==='DRAFT','per-supplier states are not durable');
    echo "AURENOEVA_URGENT_12_SUPPLIER_DRAFTS=PASS DE=".$locales['de']." EN=".$locales['en']."\n";
}finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
