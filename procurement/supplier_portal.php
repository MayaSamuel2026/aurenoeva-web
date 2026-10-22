<?php
declare(strict_types=1);
if(!defined('AUR_PROCUREMENT_VIEW')){http_response_code(403);exit;}
$researchRows=is_array($supplierResearch['rows']??null)?$supplierResearch['rows']:[];
try{$worldStats=aur_worldwide_stats($supplierResearch);}
catch(Throwable $e){error_log('AURENOEVA vendor stats: '.get_class($e));$worldStats=['scanned'=>0,'total'=>count($researchRows),'unique_vendors'=>0,'new_source_pages'=>0];}
$dashboard=[];$regions=[];
foreach($researchRows as $row) {
    if(!is_array($row))continue;
    $key=(string)($row['source_row']??'');
    $description=(string)($row['description']??'');
    if($key==='')continue;
    try{$candidates=aur_worldwide_case_candidates($supplierResearch,$key,$description);}
    catch(Throwable $e){error_log('AURENOEVA supplier candidates: '.get_class($e));$candidates=aur_worldwide_seed_candidates($description);}
    // Manufacturer evidence is shown even without a seller email; never
    // pretend this is a verified distribution or available-stock offer.
    foreach((is_array($row['sources']??null)?$row['sources']:[]) as $oem) {
        if(!is_array($oem))continue;
        $url=aur_worldwide_candidate_text($oem['evidence_url']??null);
        $website=(string)parse_url($url,PHP_URL_HOST);
        $name=aur_worldwide_candidate_text($oem['company']??null);
        if($name==='')continue;
        $candidates[]=['name'=>$name,'website'=>$website,'country'=>'Manufacturer / global',
          'email'=>'','phone'=>'','evidence_url'=>$url,
          'status'=>'MANUFACTURER_INFORMATION_ONLY','match_basis'=>aur_worldwide_candidate_text($oem['match_quality']??null)];
    }
    foreach($candidates as $vendor) {
        if(!is_array($vendor))continue;
        $name=aur_worldwide_candidate_text($vendor['name']??null);
        $website=aur_worldwide_candidate_text($vendor['website']??null);
        $email=strtolower(trim(aur_worldwide_candidate_text($vendor['email']??null)));
        $country=aur_worldwide_candidate_text($vendor['country']??null,'Not established');
        $status=aur_worldwide_candidate_text($vendor['status']??null,'UNVERIFIED');
        $eligible=$email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)
            && in_array($status,['PUBLISHED_CONTACT_RESEARCH_CANDIDATE','SOURCE_CHECKED_EMAIL_PUBLISHED'],true);
        if($name===''&&$website==='')continue;
        $groupKey=$eligible?'mail:'.$email:'site:'.strtolower($website?:$name);
        if(!isset($dashboard[$groupKey])) {
            $dashboard[$groupKey]=['name'=>$name?:$website,'country'=>$country,
                'website'=>$website,'email'=>$eligible?$email:'',
                'phone'=>aur_worldwide_candidate_text($vendor['phone']??null),
                'status'=>$status,'contactable'=>$eligible,'items'=>[],
                'sources'=>[],'roles'=>[]];
        }
        if($eligible) {
            $dashboard[$groupKey]['contactable']=true;
            $dashboard[$groupKey]['email']=$email;
            $dashboard[$groupKey]['status']='PUBLISHED_CONTACT';
        }
        $dashboard[$groupKey]['items'][$key]=[
            'number'=>(int)($row['customer_item_number']??0),
            'description'=>$description,
            'option'=>aur_worldwide_candidate_text($row['option_type']??null,'REQUESTED')];
        $url=aur_worldwide_candidate_text($vendor['evidence_url']??null);
        if(preg_match('~^https?://~i',$url))$dashboard[$groupKey]['sources'][$url]=true;
        $basis=aur_worldwide_candidate_text($vendor['match_basis']??null);
        if($basis!=='')$dashboard[$groupKey]['roles'][$basis]=true;
        $regions[$country]=true;
    }
}
uasort($dashboard,static function($a,$b):int{
    if($a['contactable']!==$b['contactable'])return $a['contactable']?-1:1;
    return strcasecmp($a['name'],$b['name']);
});
$contactableCount=count(array_filter($dashboard,static fn($s)=>$s['contactable']));
$approvedDrafts=[];
try{$approvedDrafts=aur_vendor_rfq_list($aurStore,$result);}
catch(Throwable $e){error_log('AURENOEVA draft register: '.get_class($e));}
?>
<section id="supplier-workspace" style="border-top:1px solid #dbe4df;margin-top:24px;padding-top:20px">
<h2><?=is_array($rfqPreview)?'RFQ prepared for approval':'Select suppliers for this RFQ'?></h2>
<p class="muted">Select published supplier contacts, prepare one consolidated enquiry per email address, review and approve once. Research records and sources remain available in the advanced section.</p>
<p class="aur-quick-stats">
<span class="pill"><b><?=count($researchRows)?></b> equipment lines</span>
<span class="pill"><b><?=$contactableCount?></b> suppliers with email</span>
<span class="pill"><b><?=count($dashboard)-$contactableCount?></b> research-only leads</span>
</p>
<?php if(!empty($supplierResearchMode)):?><p class="muted" role="status"><?=aur_escape((string)$supplierResearchMode)?></p><?php endif;?>
<?php if(!empty($supplierDiscoveryMessage)):?><p role="status"><?=aur_escape((string)$supplierDiscoveryMessage)?></p><?php endif;?>
<p class="aur-small-links"><a href="#supplier-search-controls">Worldwide discovery</a> · <a href="#supplier-history">Past RFQs</a> · <a href="#item-sources">Detailed research</a></p>
<?php if(is_array($rfqPreview)): ?>
<section id="rfq-step-review" class="aur-rfq-review">
<div class="aur-stage-label">Step 2 / 3 — Review and authorize</div>
<h2>Ready to review: <?=count($rfqPreview['messages']??[])?> supplier emails</h2>
<p><strong>From:</strong> info@aurenoeva.com · <strong>Reply requested:</strong> within five working days · <strong>Status:</strong> <?=aur_escape(aur_supplier_status_label((string)$rfqPreview['status']))?></p>
<p class="muted">Scan the recipients and equipment counts below. Open any row to check its complete customized English RFQ. Approval below is given only once for the entire case.</p>
<div class="aur-mail-scroller">
<table class="aur-compact-table">
<thead><tr><th>Supplier</th><th>Email recipient</th><th>Requested items</th><th>Status / draft</th></tr></thead>
<tbody>
<?php foreach($rfqPreview['messages'] as $msg):
  $msgLines=$msg['items']??[];
  $numbers=[];
  foreach($msgLines as $mi){
      $n=(int)($mi['customer_item_number']??0);
      $row=(int)($mi['source_row']??0);
      $numbers[]=$n>0?'#'.$n:($row>0?'L'.$row:'—');
  }
  $numbers=array_values(array_unique($numbers));
?>
<tr><td><b><?=aur_escape((string)$msg['vendor_name'])?></b></td>
<td><?=aur_escape((string)$msg['to'])?></td>
<td><strong><?=count($msgLines)?> item(s)</strong>
<div class="muted"><?=aur_escape(implode(', ',array_slice($numbers,0,6)))?><?=count($numbers)>6?' +'.(count($numbers)-6).' more':''?></div></td>
<td><span class="pill"><?=aur_escape(aur_supplier_status_label((string)$msg['status']))?></span>
<details class="aur-email-details">
<summary>Review email</summary>
<p><b>RFQ:</b> <?=aur_escape((string)($msg['rfq_reference']??'Earlier draft'))?></p>
<p><b>Subject:</b> <?=aur_escape((string)$msg['subject'])?></p>
<?php if(!empty($msg['response_due_on'])):?>
<p><b>Response due:</b> <?=aur_escape((string)$msg['response_due_on'])?></p>
<?php endif;?>
<pre class="aur-email-preview"><?=aur_escape((string)$msg['body'])?></pre>
</details></td></tr>
<?php endforeach;?>
</tbody></table>
</div>
<?php $legacyRfqTemplate=($rfqPreview['status']??'')==='DRAFT_REVIEW'
    && empty($rfqPreview['messages'][0]['rfq_reference']); ?>
<?php if($legacyRfqTemplate):?>
<p role="alert" class="aur-safety-notes">This is an earlier email format. Open <a href="#supplier-dashboard">supplier selection</a> and prepare a fresh five-working-day RFQ draft before authorizing.</p>
<?php endif;?>
<?php if(in_array($rfqPreview['status'],['DRAFT_REVIEW','DISPATCHING'],true) && !$legacyRfqTemplate): ?>
<form method="post" action="/procurement/" id="aur-vendor-send-form"
  class="aur-primary-action"
  data-total="<?=count($rfqPreview['messages']??[])?>"
  data-approved="<?=($rfqPreview['status']??'')==='DISPATCHING'?'1':'0'?>">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="draft_id" value="<?=aur_escape((string)$rfqPreview['draft_id'])?>">
<?php if(($rfqPreview['status']??'')==='DRAFT_REVIEW'):?>
<label class="aur-approval-label"><input type="checkbox" name="manual_review" value="1" required>
<span>I have checked the recipient list, equipment and applicable sanctions, export and end-use requirements. I authorize one non-binding RFQ per supplier, sent from info@aurenoeva.com. This single approval covers the full selected queue.</span></label>
<?php endif;?>
<button name="<?=($rfqPreview['status']??'')==='DISPATCHING'?'continue_vendor_rfqs':'send_vendor_rfqs'?>" value="1" type="submit" id="aur-send-all-rfqs"><?=($rfqPreview['status']??'')==='DISPATCHING'?'Continue approved dispatch':'Approve once & send all RFQs'?></button>
<p role="status" id="aur-send-progress" class="muted">Only an explicit approval sends email. The authorized queue is processed in controlled batches, recorded against this case; accepted emails are not resent automatically.</p>
</form>
<?php else:?><p class="aur-safety-notes">Dispatch is recorded in this case. Transport acceptance does not confirm delivery or a supplier response.</p><?php endif;?>
</section>
<?php endif;?>

<details class="aur-disclosure">
<summary>Export control, sanctions and embargo review requirements</summary>
<h3 style="margin:13px 0 8px">Compliance review</h3>
<p style="margin:0">Case legal status: <b><?=aur_escape((string)($result['legal_review']['compliance']['status']??'REVIEW_REQUIRED'))?></b>. Before sending RFQs, assess counterparties and beneficial owners, destination, end users/end use, product controls, applicable EU/UN/UK/US and other relevant sanctions, embargoes, export licences and supplier-country requirements. Uzbekistan is a destination, not a restriction on which countries may be searched. Being an intermediary does not bypass applicable controls. No sanctions clearance or lawful shipment is implied by this dashboard.</p>
</details>
<details id="supplier-history" class="aur-disclosure">
<summary>Previously prepared RFQs (<?=count($approvedDrafts)?>)</summary>
<h3>Saved consolidated RFQs</h3>
<?php if(!$approvedDrafts):?><p class="muted">No drafts yet. Prepared and sent RFQs will remain selectable here.</p>
<?php else: ?><div class="scroll"><table><thead><tr><th>Created (UTC)</th><th>Suppliers</th><th>Status</th><th>Review</th></tr></thead><tbody>
<?php foreach($approvedDrafts as $priorDraft):?>
<tr><td><?=aur_escape($priorDraft['created_at'])?></td><td><?=(int)$priorDraft['vendors']?></td>
<td><?=aur_escape($priorDraft['status'])?></td>
<td><a href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode($priorDraft['id'])?>#supplier-history">Open RFQ ↗</a></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?>
</details>
<details id="supplier-dashboard" class="aur-disclosure aur-supplier-selection" <?=is_array($rfqPreview)?'':'open'?>>
<summary>Step 1 — Choose suppliers (<?=$contactableCount?> contactable)</summary>
<h3>Suppliers available for RFQ</h3>
<p class="muted">Tick the suppliers to contact. Multiple items are consolidated into one email per recipient. Research-only companies without published email contacts are listed under detailed research.</p>
<form method="post" action="/procurement/" id="aur-vendor-selection-form">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="vendor_tokens" id="aur-compact-vendor-tokens" value="">
<input type="hidden" name="prepare_vendor_rfqs" value="1">
<p style="display:flex;gap:10px;flex-wrap:wrap">
<button type="button" id="aur-select-all-vendors" class="ghost">Select all suppliers</button>
<button type="button" id="aur-clear-vendors" class="ghost">Clear selection</button>
<span class="muted" id="aur-vendor-selection-count">0 suppliers selected</span>
</p>
<div class="scroll aur-supplier-scroll"><table class="aur-compact-table"><thead><tr><th>Select</th><th>Supplier</th><th>Items</th><th>Email</th></tr></thead><tbody>
<?php foreach($dashboard as $supplier):
 if(!$supplier['contactable'])continue;
 $numbers=array_values(array_unique(array_map(static fn($v)=>(string)$v['number'],$supplier['items'])));
?>
<tr>
<td><label><input type="checkbox" class="aur-vendor-check" name="selected_vendors[]" value="<?=hash('sha256',$supplier['email'])?>" aria-label="Select <?=aur_escape($supplier['name'])?>"></label></td>
<td><strong><?=aur_escape($supplier['name'])?></strong>
<div class="muted"><?=aur_escape($supplier['country'])?></div></td>
<td><b><?=count($supplier['items'])?> items</b>
<div class="muted">#<?=aur_escape(implode(', #',array_slice($numbers,0,5)))?><?=count($numbers)>5?' +'.(count($numbers)-5).' more':''?></div>
<details class="aur-email-details"><summary>See equipment</summary><div class="aur-item-scroll">
<?php foreach($supplier['items'] as $equipment):?>
<div style="margin:7px 0"><b>#<?=(int)$equipment['number']?></b> <?=aur_escape($equipment['description'])?></div>
<?php endforeach;?></div></details></td>
<td><span class="aur-email-cell"><?=aur_escape($supplier['email'])?></span>
<details class="aur-email-details"><summary>Contact & evidence</summary>
<?php if($supplier['phone']!==''):?><div><?=aur_escape($supplier['phone'])?></div><?php endif;?>
<?php if($supplier['website']!==''):?><div><?=aur_escape($supplier['website'])?></div><?php endif;?>
<?php foreach(array_slice(array_keys($supplier['sources']),0,4) as $url):?>
<div><a href="<?=aur_escape($url)?>" target="_blank" rel="noopener noreferrer">Source ↗</a></div>
<?php endforeach;?></details></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php if(!$dashboard):?><p>No supplier research saved yet. Run worldwide discovery above to populate leads.</p><?php endif;?>
<p class="aur-prepare-action"><button name="prepare_vendor_rfqs" id="aur-prepare-rfqs" value="1" type="submit" <?=$contactableCount<1?'disabled':''?>>Prepare consolidated RFQs for selected suppliers</button></p>
<p id="aur-draft-progress" role="status" class="muted">Selecting suppliers does not send emails. The next step creates a saved draft for your review.</p>
</form>
</details>
<details id="item-sources" class="aur-disclosure">
<summary>Advanced research — original sources and worldwide searches</summary>
<h3>Equipment-by-equipment source research</h3>
<p class="muted">Every uploaded equipment specification remains available in the original RFQ above. Use these worldwide searches for additional suppliers that have not yet published an address.</p>
<?php foreach($researchRows as $row):
 if(!is_array($row))continue;
 $desc=(string)($row['description']??'');
 $regionsToSearch=aur_supplier_global_search_links($desc,aur_supplier_match($desc));
?>
<details style="border-bottom:1px solid #e1e9e4;padding:9px 0">
<summary>Item <?=(int)($row['customer_item_number']??0)?> · <?=aur_escape($desc)?></summary>
<p><?php foreach($regionsToSearch as $regionName=>$link):?>
<a href="<?=aur_escape($link)?>" target="_blank" rel="noopener noreferrer"><?=aur_escape($regionName)?> ↗</a> &nbsp; 
<?php endforeach;?></p>
<?php foreach((is_array($row['sources']??null)?$row['sources']:[]) as $source):if(!is_array($source))continue;?>
<div><?=aur_escape(aur_worldwide_candidate_text($source['company']??null))?> — <a target="_blank" rel="noopener noreferrer" href="<?=aur_escape(aur_worldwide_candidate_text($source['evidence_url']??null))?>">OEM/source evidence ↗</a></div>
<?php endforeach;?>
</details>
<?php endforeach;?>
</details>
</section>
