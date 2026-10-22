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
<h2>Worldwide supplier sourcing dashboard</h2>
<p>One supplier, one consolidated email covering every selected equipment item. Search broadly and compare supplier quotations after responses arrive. A source link is not a verified offer, stock confirmation or distribution authorization.</p>
<p>
<span class="pill"><b><?=count($researchRows)?></b> requested lines</span>
<span class="pill"><b><?=count($dashboard)?></b> supplier/manufacturer leads</span>
<span class="pill"><b><?=$contactableCount?></b> published email contacts</span>
<span class="pill"><b><?=(int)$worldStats['scanned']?> / <?=(int)$worldStats['total']?></b> lines scanned</span>
<span class="pill"><b><?=(int)$worldStats['new_source_pages']?></b> saved online source links</span>
</p>
<?php if(!empty($supplierResearchMode)):?><p class="muted" role="status"><?=aur_escape((string)$supplierResearchMode)?></p><?php endif;?>
<?php if(!empty($supplierDiscoveryMessage)):?><p role="status"><?=aur_escape((string)$supplierDiscoveryMessage)?></p><?php endif;?>
<p><a href="#supplier-search-controls">↑ Run worldwide discovery</a> · <a href="#supplier-history">Previous RFQ drafts</a> · <a href="#item-sources">Research by equipment item</a></p>
<section style="background:#f1f5f2;padding:14px 18px;border-radius:8px;margin:16px 0">
<h3 style="margin:0 0 8px">Export control, sanctions and embargo review</h3>
<p style="margin:0">Case legal status: <b><?=aur_escape((string)($result['legal_review']['compliance']['status']??'REVIEW_REQUIRED'))?></b>. Before sending RFQs, assess counterparties and beneficial owners, destination, end users/end use, product controls, applicable EU/UN/UK/US and other relevant sanctions, embargoes, export licences and supplier-country requirements. Uzbekistan is a destination, not a restriction on which countries may be searched. Being an intermediary does not bypass applicable controls. No sanctions clearance or lawful shipment is implied by this dashboard.</p>
</section>
<section id="supplier-history">
<h3>Saved consolidated RFQs</h3>
<?php if(!$approvedDrafts):?><p class="muted">No drafts yet. Prepared and sent RFQs will remain selectable here.</p>
<?php else: ?><div class="scroll"><table><thead><tr><th>Created (UTC)</th><th>Suppliers</th><th>Status</th><th>Review</th></tr></thead><tbody>
<?php foreach($approvedDrafts as $priorDraft):?>
<tr><td><?=aur_escape($priorDraft['created_at'])?></td><td><?=(int)$priorDraft['vendors']?></td>
<td><?=aur_escape($priorDraft['status'])?></td>
<td><a href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode($priorDraft['id'])?>#supplier-history">Open RFQ ↗</a></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<section id="supplier-dashboard">
<h3>Consolidated supplier selection</h3>
<p class="muted">Select the suppliers you want to approach; each receives only one email listing all matching equipment and original quantities. Published email contacts are eligible for RFQ. Other international leads stay visible for follow-up research. Price comparison starts when supplier quotations are received; no prices are assumed.</p>
<form method="post" action="/procurement/" id="aur-vendor-selection-form">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="vendor_tokens" id="aur-compact-vendor-tokens" value="">
<input type="hidden" name="prepare_vendor_rfqs" value="1">
<p style="display:flex;gap:10px;flex-wrap:wrap">
<button type="button" id="aur-select-all-vendors" class="ghost">Select all contactable suppliers</button>
<button type="button" id="aur-clear-vendors" class="ghost">Clear selection</button>
<span class="muted" id="aur-vendor-selection-count">0 suppliers selected</span>
</p>
<div class="scroll"><table><thead><tr><th>Select</th><th>Supplier / country</th><th>Requested items</th><th>Contact / source</th><th>Quote</th></tr></thead><tbody>
<?php foreach($dashboard as $supplier):
 $numbers=array_map(static fn($v)=>(string)$v['number'],$supplier['items']);
 $itemDescriptions=array_map(static fn($v)=>(string)$v['description'],$supplier['items']);
?>
<tr>
<td><?php if($supplier['contactable']):?>
<label><input type="checkbox" class="aur-vendor-check" name="selected_vendors[]" value="<?=hash('sha256',$supplier['email'])?>" aria-label="Select <?=aur_escape($supplier['name'])?>"></label>
<?php else:?><span class="muted">Research</span><?php endif;?></td>
<td><strong><?=aur_escape($supplier['name'])?></strong>
<div class="muted"><?=aur_escape($supplier['country'])?></div>
<?php if($supplier['website']!==''):?><div class="muted"><?=aur_escape($supplier['website'])?></div><?php endif;?>
<div class="muted"><?=aur_escape(implode(', ',array_slice(array_keys($supplier['roles']),0,2)))?></div></td>
<td><b><?=count($supplier['items'])?> lines</b> — items <?=aur_escape(implode(', ',$numbers))?>
<details><summary>View equipment</summary><div style="max-width:390px">
<?php foreach($supplier['items'] as $equipment):?>
<p style="margin:7px 0">#<?=(int)$equipment['number']?> <?=aur_escape($equipment['description'])?></p>
<?php endforeach;?></div></details></td>
<td><?php if($supplier['contactable']):?><a href="mailto:<?=aur_escape($supplier['email'])?>"><?=aur_escape($supplier['email'])?></a>
<?php else:?><span class="muted">Email not confirmed</span><?php endif;?>
<?php if($supplier['phone']!==''):?><div><?=aur_escape($supplier['phone'])?></div><?php endif;?>
<?php foreach(array_slice(array_keys($supplier['sources']),0,4) as $url):?>
<div><a href="<?=aur_escape($url)?>" target="_blank" rel="noopener noreferrer">Supplier evidence ↗</a></div>
<?php endforeach;?></td>
<td class="muted">Awaiting quotation</td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php if(!$dashboard):?><p>No supplier research saved yet. Run worldwide discovery above to populate leads.</p><?php endif;?>
<p><button name="prepare_vendor_rfqs" id="aur-prepare-rfqs" value="1" type="submit" <?=$contactableCount<1?'disabled':''?>>Prepare consolidated RFQs for selected suppliers</button></p>
<p id="aur-draft-progress" role="status" class="muted">Selecting suppliers does not send emails. The next step creates a saved draft for your review.</p>
</form>
</section>
<section id="item-sources">
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
</section>
</section>
<?php if(is_array($rfqPreview)): ?>
<section style="padding:25px 0;border-top:1px solid #dae4e0">
<h2>Supplier RFQ dispatch — <?=aur_escape(aur_supplier_status_label((string)$rfqPreview['status']))?></h2>
<p>Draft <?=aur_escape((string)$rfqPreview['draft_id'])?>. <strong>From: info@aurenoeva.com</strong>. Every supplier receives one tailored English message containing all relevant equipment. The requested response period is <strong>five working days after dispatch</strong>.</p>
<?php if(($rfqPreview['status']??'')==='DRAFT_REVIEW'):?>
<p class="muted">Review the complete recipients and draft emails below. Your approval releases this whole case-specific queue, without separate authorization for each supplier.</p>
<?php else:?><p class="muted">Previously approved queue. Sending can continue without a second legal acknowledgement; the original authorization is saved with this draft.</p><?php endif;?>
<?php foreach($rfqPreview['messages'] as $msg):?>
<article style="border-bottom:1px solid #dae4e0;padding:16px 0">
<h3><?=aur_escape((string)$msg['vendor_name'])?> — <?=aur_escape(aur_supplier_status_label((string)$msg['status']))?></h3>
<p><b>To:</b> <?=aur_escape((string)$msg['to'])?></p>
<p><b>RFQ:</b> <?=aur_escape((string)($msg['rfq_reference']??'Legacy draft'))?> · <b>Equipment:</b> <?=count($msg['items']??[])?> line(s)
<?php if(!empty($msg['response_due_on'])):?> · <b>Response due:</b> <?=aur_escape((string)$msg['response_due_on'])?><?php endif;?></p>
<p><b>Subject:</b> <?=aur_escape((string)$msg['subject'])?></p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:440px;overflow:auto;padding:16px;background:#f2f5f3"><?=aur_escape((string)$msg['body'])?></pre>
</article>
<?php endforeach;?>
<?php $legacyRfqTemplate=($rfqPreview['status']??'')==='DRAFT_REVIEW'
    && empty($rfqPreview['messages'][0]['rfq_reference']); ?>
<?php if($legacyRfqTemplate):?>
<p role="alert" style="padding:12px;background:#fff7ed;border:1px solid #e7c9a9;border-radius:8px">This draft uses the previous email template. To request responses within five working days and include unique tracking references, return to supplier selection and prepare a fresh draft. No messages have been sent from this draft.</p>
<?php endif;?>
<?php if(in_array($rfqPreview['status'],['DRAFT_REVIEW','DISPATCHING'],true) && !$legacyRfqTemplate): ?>
<form method="post" action="/procurement/" id="aur-vendor-send-form"
 data-total="<?=count($rfqPreview['messages']??[])?>"
 data-approved="<?=($rfqPreview['status']??'')==='DISPATCHING'?'1':'0'?>">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="draft_id" value="<?=aur_escape((string)$rfqPreview['draft_id'])?>">
<?php if(($rfqPreview['status']??'')==='DRAFT_REVIEW'):?>
<label style="display:block;margin:12px 0"><input type="checkbox" name="manual_review" value="1" required> I have reviewed all supplier recipient addresses, equipment, end use, destination, beneficial ownership where applicable, and trade-compliance requirements. I authorize one consolidated non-binding RFQ to each listed supplier, sent from info@aurenoeva.com. I understand this single approval releases the full saved queue.</label>
<?php endif;?>
<button name="<?=($rfqPreview['status']??'')==='DISPATCHING'?'continue_vendor_rfqs':'send_vendor_rfqs'?>" value="1" type="submit" id="aur-send-all-rfqs"><?=($rfqPreview['status']??'')==='DISPATCHING'?'Continue approved queue':'Approve once & send all supplier RFQs'?></button>
<p role="status" id="aur-send-progress" class="muted">The complete queue will be processed automatically in bounded batches. Accepted messages are recorded under this enquiry and will not be resent automatically. A server-side scheduled worker can continue if your browser closes.</p>
</form>
<?php else: ?><p>Mail transport acceptance is recorded separately from confirmed delivery or a supplier reply. If a send failed, prepare a new draft after examining the recipient.</p><?php endif;?>
</section>
<?php endif;?>
