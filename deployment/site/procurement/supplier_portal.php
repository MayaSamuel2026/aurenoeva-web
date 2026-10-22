<?php
declare(strict_types=1);
if(!defined('AUR_PROCUREMENT_VIEW')){http_response_code(403);exit;}
require_once __DIR__.'/supplier_replies.php';
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
<h2>Worldwide supplier quotations and availability</h2>
<p>Research suppliers worldwide and identify seller contacts and stated product availability. One supplier receives at most one consolidated, non-binding request for prices and availability. A catalogue listing does not establish current stock or lawful export. AURENOEVA does not participate in sales or purchasing.</p>
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
<p style="margin:0">Preliminary destination status: <b><?=aur_escape((string)($result['country_preflight']['status']??'NOT_SCREENED'))?></b>. The country warning above is automatic; it is not live sanctions-list screening. Counterparties, beneficial owners, goods, end users, end use and applicable export laws still require a separate review. Supplier search remains worldwide, regardless of destination. An information-only service does not bypass sanctions rules.</p>
</section>
<details id="supplier-history" class="aux-disclosure">
<summary>Previous supplier enquiries (<?=count($approvedDrafts)?>)</summary>
<?php if(!$approvedDrafts):?><p class="muted">No drafts yet. Prepared and sent RFQs will remain selectable here.</p>
<?php else: ?><div class="scroll"><table><thead><tr><th>Created (UTC)</th><th>Suppliers</th><th>Status</th><th>Review</th></tr></thead><tbody>
<?php foreach($approvedDrafts as $priorDraft):?>
<tr><td><?=aur_escape($priorDraft['created_at'])?></td><td><?=(int)$priorDraft['vendors']?></td>
<td><?=aur_escape($priorDraft['status'])?></td>
<td><a href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode($priorDraft['id'])?>#supplier-history">Open RFQ ↗</a></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?>
</details>
<section id="supplier-dashboard">
<h3>Consolidated supplier selection</h3>
<p class="aur-workflow-note"><b>Step 1 of 2 · Prepare enquiries.</b> Eligible suppliers with a published email address are selected automatically when there are 300 or fewer. You can change the selection below. Preparing a draft does not send any email.</p>
<?php if($contactableCount>300):?><p role="status"><b>Large supplier list:</b> Choose up to 300 contacts in each draft. Use the remaining contacts in a subsequent batch; no automatic outreach is triggered.</p><?php endif;?>
<p class="muted">Select the suppliers you want to approach; each receives only one email listing all matching equipment and original quantities. Published email contacts are eligible for RFQ. Other international leads stay visible for follow-up research. AURENOEVA requests indicative unit prices, currency, availability, delivery, freight and warranty details. This is not an order, contract or sales negotiation.</p>
<form method="post" action="/procurement/" id="aur-vendor-selection-form">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="vendor_tokens" id="aur-compact-vendor-tokens" value="">
<input type="hidden" name="prepare_vendor_rfqs" value="1">
<p style="display:flex;gap:10px;flex-wrap:wrap">
<button type="button" id="aur-select-all-vendors" class="ghost"><?=$contactableCount<=300?'Select all contactable suppliers':'Select first 300 contactable suppliers'?></button>
<button type="button" id="aur-clear-vendors" class="ghost">Clear selection</button>
<span class="muted" id="aur-vendor-selection-count"><?=$contactableCount<=300?$contactableCount:0?> suppliers selected</span>
</p>
<p><button name="prepare_vendor_rfqs" id="aur-prepare-rfqs" value="1" type="submit" <?=$contactableCount<1?'disabled':''?>>Prepare consolidated availability enquiries (<?=$contactableCount<=300?$contactableCount:'selected'?> suppliers) →</button></p>
<details id="aur-supplier-detail" class="aux-disclosure">
<summary>Review supplier contacts and change selection · <?=count($dashboard)?> leads</summary>
<div class="scroll"><table><thead><tr><th>Select</th><th>Supplier / country</th><th>Requested items</th><th>Contact / source</th><th>Availability</th></tr></thead><tbody>
<?php foreach($dashboard as $supplier):
 $numbers=array_map(static fn($v)=>(string)$v['number'],$supplier['items']);
 $itemDescriptions=array_map(static fn($v)=>(string)$v['description'],$supplier['items']);
?>
<tr>
<td><?php if($supplier['contactable']):?>
<label><input type="checkbox" class="aur-vendor-check" name="selected_vendors[]" value="<?=hash('sha256',$supplier['email'])?>" <?=$contactableCount<=300?'checked':''?> aria-label="Select <?=aur_escape($supplier['name'])?>"></label>
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
<td class="muted">Not verified</td>
</tr>
<?php endforeach;?>
</tbody></table></div></details>
<?php if(!$dashboard):?><p>No supplier research saved yet. Run worldwide discovery above to populate leads.</p><?php endif;?>
<p id="aur-draft-progress" role="status" class="muted">Selecting suppliers does not send emails. The next step creates a saved draft for your review.</p>
</form>
</section>
<details id="item-sources" class="aux-disclosure"><summary>Equipment-by-equipment source research · <?=count($researchRows)?> requested lines</summary>
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
<?php if(is_array($rfqPreview)): ?>
<?php $mailLedger=aur_vendor_rfq_status_summary($rfqPreview); ?>
<section id="aur-rfq-approval" style="padding:25px 0;border-top:1px solid #dae4e0">
<h2>Review supplier price and availability RFQs — <?=aur_escape((string)$rfqPreview['status'])?></h2>
<p><b>Step 2 of 2 · Review stored dispatch outcome.</b> <?=count($rfqPreview['messages']??[])?> deduplicated recipient(s). Statuses below are calculated from the saved per-recipient outbox, not browser progress. <a href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode((string)$rfqPreview['draft_id'])?>#aur-rfq-approval">Reload actual RFQ status ↻</a></p>
<p id="aur-dispatch-ledger" style="display:flex;gap:9px;flex-wrap:wrap">
<span class="pill"><b><?=(int)$mailLedger['accepted']?></b> accepted by sending server</span>
<span class="pill"><b><?=(int)$mailLedger['remaining']?></b> not attempted</span>
<span class="pill"><b><?=(int)$mailLedger['failed_or_unconfirmed']?></b> failed / unconfirmed</span>
<?php if($mailLedger['in_flight']||$mailLedger['other']):?><span class="pill"><b><?=(int)($mailLedger['in_flight']+$mailLedger['other'])?></b> uncertain / interrupted</span><?php endif;?>
</p>
<?php if($mailLedger['needs_reconciliation']):?>
<p role="alert"><b>Partial delivery or uncertain results:</b> do not start a new batch for these recipients. Check the individual statuses and reconcile mail-server outcomes first; an unconfirmed transport attempt is not proof of delivery or failure.</p>
<?php elseif($mailLedger['accepted']===$mailLedger['total'] && $mailLedger['total']>0):?>
<p role="status">All <?= (int)$mailLedger['total']?> supplier messages were accepted by the sending server. This does not prove inbox delivery or a supplier reply.</p>
<?php endif;?>

<?php $pricingTemplateOutdated=(($rfqPreview['status']??'')==='DRAFT_REVIEW' &&
    ($rfqPreview['template_version']??'')!=='pricing-rfq-v3'); ?>
<?php if($pricingTemplateOutdated):?>
<p role="alert"><b>Previous letter saved for history.</b> This unsent draft uses the old template without the approved latest pricing letter and AURENOEVA business signature. Please prepare a fresh consolidated RFQ above before sending.</p>
<?php endif;?>
<?php if(in_array($rfqPreview['status'],['DRAFT_REVIEW','DISPATCHING'],true)): ?>
<form method="post" action="/procurement/" id="aur-vendor-send-form" data-total="<?=count($rfqPreview['messages']??[])?>">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="draft_id" value="<?=aur_escape((string)$rfqPreview['draft_id'])?>">
<label style="display:block;margin:12px 0"><input type="checkbox" name="manual_review" value="1" required> I have reviewed the actual destination, known seller and recipient identities, beneficial ownership where relevant, end use, and applicable sanctions and export controls. I authorize these information-only enquiries, not a sale or purchase.</label>
<button name="send_vendor_rfqs" value="1" type="submit" id="aur-send-all-rfqs" <?=(!empty($result['country_preflight']['enhanced_review'])||$pricingTemplateOutdated)?'disabled':''?>>Approve and send <?=count($rfqPreview['messages']??[])?> supplier enquiries →</button>
<?php if(!empty($result['country_preflight']['enhanced_review'])):?><p role="alert"><b>Country review hold:</b> sending is unavailable pending a documented restrictions assessment. Supplier research and saved equipment are unaffected.</p><?php endif;?>
<p role="status" id="aur-send-progress" class="muted">One consolidated email per supplier. Each recipient has an individual saved status; uncertain outcomes are never resent automatically.</p>
<p><a href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode((string)$rfqPreview['draft_id'])?>#aur-rfq-approval">Refresh the saved recipient ledger</a> if progress stops or the page appears stale.</p>
</form>
<?php else: ?><p>Mail transport acceptance is recorded separately from confirmed delivery or a supplier reply. If a send failed, prepare a new draft after examining the recipient.</p><?php endif;?>
<details id="aur-rfq-message-preview" class="aux-disclosure">
<summary>Review <?=count($rfqPreview['messages']??[])?> individual recipient statuses and messages</summary>
<div class="scroll"><table><thead><tr><th>Supplier</th><th>Recipient</th><th>Saved transport status</th></tr></thead><tbody>
<?php foreach(($rfqPreview['messages']??[]) as $msg):?>
<tr><td><?=aur_escape((string)($msg['vendor_name']??''))?></td><td><?=aur_escape((string)($msg['to']??''))?></td><td><?=aur_escape((string)($msg['status']??'UNKNOWN'))?></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php foreach($rfqPreview['messages'] as $msg):?>
<details class="aur-mail-preview"><summary><?=aur_escape((string)$msg['vendor_name'])?> · <?=aur_escape((string)$msg['to'])?> — <?=aur_escape((string)$msg['status'])?></summary>
<p><b>To:</b> <?=aur_escape((string)$msg['to'])?></p><p><b>Subject:</b> <?=aur_escape((string)$msg['subject'])?></p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:440px;overflow:auto;padding:16px;background:#f2f5f3"><?=aur_escape((string)$msg['body'])?></pre>
</details>
<?php endforeach;?>
</details>
</section>
<?php endif;?>


<?php
// Source-of-truth quotations live in the private case, not in crawler data.
try {$savedReplies=aur_reply_read($aurStore,$result);}
catch(Throwable $e){error_log('AURENOEVA reply register: '.get_class($e));$savedReplies=[];}
$replyChoices=is_array($rfqPreview)?aur_reply_choices($rfqPreview):[];
$replyByRow=[];
foreach($savedReplies as $reply)if(is_array($reply))$replyByRow[(int)($reply['source_row']??0)][]=$reply;
$quotedItemCount=count($replyByRow);
?>
<section id="supplier-replies" style="border-top:1px solid #dbe4df;margin-top:24px;padding-top:20px">
<h3>Supplier quotation dashboard</h3>
<p class="muted">Supplier prices are matched to the original customer equipment item number, not guessed from a product description. New replies are recorded manually until the AURENOEVA incoming mailbox is connected. Different currencies are not automatically converted.</p>
<p>
<span class="pill"><b><?=count($savedReplies)?></b> supplier-item responses recorded</span>
<span class="pill"><b><?=$quotedItemCount?></b> equipment items with replies</span>
<span class="pill"><b><?=count($result['payload']['sourcing_lines']??[])-$quotedItemCount?></b> awaiting responses</span>
</p>
<?php if(isset($_GET['response_saved'])):?><p role="status"><b>Supplier reply saved against the original equipment item.</b></p><?php endif;?>
<?php if($replyChoices):?>
<details class="aux-disclosure">
<summary>Record a supplier quotation or availability reply</summary>
<form method="post" action="/procurement/" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;align-items:end">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="draft_id" value="<?=aur_escape((string)$rfqPreview['draft_id'])?>">
<label style="grid-column:1/-1">Supplier and original equipment item
<select name="supplier_item" required style="padding:12px;max-width:100%">
<option value="">Choose supplier and item</option>
<?php foreach($replyChoices as $token=>$candidate):?>
<option value="<?=aur_escape((string)$token)?>"><?=aur_escape((string)$candidate['supplier'])?> — item <?= (int)(array_values(array_filter($result['payload']['sourcing_lines']??[],static fn($v)=>(int)($v['source_row']??0)===(int)$candidate['source_row']))[0]['customer_item_number']??$candidate['source_row']) ?> — <?=aur_escape(substr((string)$candidate['description'],0,75))?></option>
<?php endforeach;?>
</select></label>
<label>Supplier response
<select name="availability" required style="padding:12px">
<option value="QUOTED">Price / quotation received</option>
<option value="ALTERNATIVE_OFFERED">Alternative offered</option>
<option value="NOT_AVAILABLE">Not available</option>
<option value="DETAILS_PENDING">Further details requested</option>
</select></label>
<label>Unit price (optional)<input name="unit_price" placeholder="1250.00" inputmode="decimal"></label>
<label>Currency (ISO)<input name="currency" placeholder="EUR" maxlength="3"></label>
<label>Lead time<input name="lead_time" maxlength="100" placeholder="e.g. 3–4 weeks"></label>
<label style="grid-column:1/-1">Reply reference / email subject<input name="reply_reference" maxlength="180" placeholder="Email date or supplier quotation number"></label>
<label style="grid-column:1/-1">Other quotation details<textarea name="notes" rows="2" maxlength="900" placeholder="Quantity available, delivery terms, alternative model, warranty ..."></textarea></label>
<p><button type="submit" name="record_supplier_reply" value="1">Save quotation against equipment item</button></p>
</form>
</details>
<?php else:?>
<p class="muted">To record a response, open a sent RFQ under “Previous supplier enquiries” above. Only previously contacted supplier/item combinations can be entered.</p>
<?php endif;?>
<details class="aux-disclosure" <?=$savedReplies?'open':''?>>
<summary>Compare suppliers across original equipment items · <?=count($result['payload']['sourcing_lines']??[])?> entries</summary>
<div class="scroll"><table>
<thead><tr><th>Customer item</th><th>Original equipment</th><th>Supplier / offer</th><th>Unit price</th><th>Lead time / source</th></tr></thead><tbody>
<?php foreach(($result['payload']['sourcing_lines']??[]) as $line):
  $rowNumber=(int)($line['source_row']??0);$offers=$replyByRow[$rowNumber]??[];
?>
<tr><td><b>#<?=(int)($line['customer_item_number']??0)?></b></td>
<td><?=aur_escape((string)($line['description_original']??''))?></td>
<td><?php if(!$offers):?><span class="muted">Awaiting quotation</span><?php else:?>
<?php foreach($offers as $offer):?>
<div><b><?=aur_escape((string)$offer['supplier'])?></b> · <?=aur_escape((string)$offer['availability'])?></div>
<?php endforeach;?><?php endif;?></td>
<td><?php foreach($offers as $offer):?><div><?=aur_escape((string)($offer['unit_price']??''))?> <?=aur_escape((string)($offer['currency']??''))?></div><?php endforeach;?></td>
<td><?php foreach($offers as $offer):?><div><?=aur_escape((string)($offer['lead_time']??''))?> · <?=aur_escape((string)($offer['reply_reference']??''))?></div><?php endforeach;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
</details>
</section>
