<?php
declare(strict_types=1);
if(!defined('AUR_PROCUREMENT_VIEW')){http_response_code(403);exit;}
$worldStats=aur_worldwide_stats($supplierResearch);
$routeDirectory=aur_supplier_route_directory();
$routeStats=aur_supplier_route_stats($supplierResearch['rows']??[]);
?>
<section id="supplier-workspace" style="border-top:1px solid #dbe4df;margin-top:24px;padding-top:20px">
<?php if(!empty($supplierResearchMode)): ?><p class="muted" role="status"><?=aur_escape((string)$supplierResearchMode)?></p><?php endif; ?>
<h2>Worldwide supplier discovery and RFQ workflow</h2>
<p>Find candidates globally and approach selected vendors with the original equipment specification. A website citation is evidence of a lead, not stock, authority to distribute or regulatory clearance.</p>
<p>
<span class="pill"><b><?= (int)$worldStats['scanned']?> / <?= (int)$worldStats['total']?></b> lines scanned worldwide</span>
<span class="pill"><b><?= (int)$worldStats['unique_vendors']?></b> contactable vendor identities</span>
<span class="pill"><b><?= (int)$worldStats['new_source_pages']?></b> additional worldwide research leads</span>
<span class="pill"><b><?= count($supplierResearch['worldwide_vendor_master']??[])?></b> reusable worldwide catalogue vendors</span>
<span class="pill"><b><?= (int)$routeStats['exact_product_listings']?></b> exact local model listings</span>
</p>
<p><a href="#supplier-search-controls">↑ Worldwide search controls are always available with the original enquiry</a></p>
<p class="muted">Broad discovery preserves plausible leads, including suppliers without confirmed email addresses. Fetched product pages and published supplier emails are identified separately. Nothing is sent automatically.</p>
<?php if(!empty($supplierDiscoveryMessage)): ?><p><?=aur_escape((string)$supplierDiscoveryMessage)?></p><?php endif;?>
<h3>Published supplier directory</h3>
<div class="scroll"><table>
<thead><tr><th>Vendor</th><th>Public contact</th><th>Evidence</th></tr></thead><tbody>
<?php foreach($routeDirectory as $v): ?>
<tr>
<td><b><?=aur_escape($v['name'])?></b><div class="muted"><?=aur_escape($v['city'])?> · <?=aur_escape($v['country'])?></div></td>
<td><div><a href="mailto:<?=aur_escape($v['email'])?>"><?=aur_escape($v['email'])?></a></div><?=aur_escape($v['phone'])?></td>
<td><a target="_blank" rel="noopener noreferrer" href="<?=aur_escape($v['url'])?>">Published supplier site ↗</a><div class="muted"><?=aur_escape($v['scope'])?></div></td>
</tr>
<?php endforeach;?></tbody></table></div>
<h3 style="margin-top:26px">All saved supplier sources — select vendor enquiries per equipment line</h3>
<p class="muted">Earlier search links and checked supplier sources remain visible after repeat scans. Unverified search results are labelled separately from verified website contacts.</p>
<form method="post" action="/procurement/">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<div class="scroll"><table>
<thead><tr><th>Item</th><th>Requested equipment</th><th>Potential worldwide suppliers and contacts</th><th>Manufacturer evidence</th></tr></thead><tbody>
<?php foreach(($supplierResearch['rows']??[]) as $row):
  $key=(string)$row['source_row'];
  $vendors=aur_worldwide_case_candidates($supplierResearch,$key,(string)$row['description']);
?>
<tr><td><?= (int)$row['customer_item_number']?><?php if(($row['option_type']??'')==='ALTERNATIVE'):?><div class="muted">Alternative</div><?php endif;?></td>
<td><?=aur_escape((string)$row['description'])?>
<div><a target="_blank" rel="noopener noreferrer" href="<?=aur_escape((string)$row['search_url'])?>">Find more sources worldwide ↗</a></div></td>
<td>
<?php if(!$vendors):?><span class="muted">No supplier discovered yet. Run worldwide search.</span><?php endif;?>
<?php foreach($vendors as $v): ?>
<div style="border-bottom:1px solid #e0e7e3;margin:0 0 13px;padding:0 0 12px">
<div><b><?=aur_escape((string)$v['name'])?></b> <span class="muted"><?=aur_escape((string)$v['country'])?></span></div>
<div class="muted"><?=aur_escape((string)$v['match_basis'])?> · <?=aur_escape((string)$v['status'])?></div>
<?php if(!empty($v['email'])):?><div><a href="mailto:<?=aur_escape((string)$v['email'])?>"><?=aur_escape((string)$v['email'])?></a></div>
<?php else:?><div class="muted">No published vendor email yet</div><?php endif;?>
<?php if(!empty($v['phone'])):?><div><?=aur_escape((string)$v['phone'])?></div><?php endif;?>
<?php if(!empty($v['evidence_url'])):?>
<div><a target="_blank" rel="noopener noreferrer" href="<?=aur_escape((string)$v['evidence_url'])?>">Inspect source ↗</a></div>
<?php endif;?>
<?php if(in_array((string)$v['status'],['PUBLISHED_CONTACT_RESEARCH_CANDIDATE','SOURCE_CHECKED_EMAIL_PUBLISHED'],true)
      && filter_var((string)($v['email']??''),FILTER_VALIDATE_EMAIL)):?>
<label style="display:block;margin-top:7px"><input type="checkbox" name="selected_routes[]" value="<?=aur_escape($key.'~'.$v['id'])?>"> Select for RFQ</label>
<?php endif;?>
</div>
<?php endforeach;?>
</td><td>
<?php if(empty($row['sources'])):?><span class="muted">Manufacturer identification pending</span><?php endif;?>
<?php foreach(($row['sources']??[]) as $oem): ?>
<div style="margin-bottom:12px"><b><?=aur_escape((string)$oem['company'])?></b>
<div class="muted"><?=aur_escape((string)$oem['match_quality'])?></div>
<a target="_blank" rel="noopener noreferrer" href="<?=aur_escape((string)$oem['evidence_url'])?>">Manufacturer evidence ↗</a>
<?php if(!empty($oem['note'])):?><div class="muted"><?=aur_escape((string)$oem['note'])?></div><?php endif;?>
</div>
<?php endforeach;?>
</td></tr>
<?php endforeach;?>
</tbody></table></div>
<p><button name="prepare_vendor_rfqs" value="1" type="submit">Prepare RFQs for selected suppliers</button></p>
</form>
<p class="muted">RFQs are generated for suppliers with published email addresses only. Selection does not send anything. Drafts are grouped by vendor, with quantities and original descriptions preserved.</p>
</section>
<?php if(is_array($rfqPreview)): ?>
<section style="padding:25px 0;border-top:1px solid #dae4e0">
<h2>Review RFQs — <?=aur_escape((string)$rfqPreview['status'])?></h2>
<p>Draft <?=aur_escape((string)$rfqPreview['draft_id'])?>. One consolidated email per vendor. Please review recipient, equipment and terms before dispatch.</p>
<?php foreach($rfqPreview['messages'] as $msg):?>
<article style="border-bottom:1px solid #dae4e0;padding:16px 0">
<h3><?=aur_escape((string)$msg['vendor_name'])?> — <?=aur_escape((string)$msg['status'])?></h3>
<p><b>To:</b> <?=aur_escape((string)$msg['to'])?></p><p><b>Subject:</b> <?=aur_escape((string)$msg['subject'])?></p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:440px;overflow:auto;padding:16px;background:#f2f5f3"><?=aur_escape((string)$msg['body'])?></pre>
</article>
<?php endforeach;?>
<?php if($rfqPreview['status']==='DRAFT_REVIEW'): ?>
<form method="post" action="/procurement/">
<input type="hidden" name="csrf" value="<?=aur_escape($csrf)?>">
<input type="hidden" name="case_ref" value="<?=aur_escape((string)$result['reference'])?>">
<input type="hidden" name="draft_id" value="<?=aur_escape((string)$rfqPreview['draft_id'])?>">
<label style="display:block;margin:12px 0"><input type="checkbox" name="manual_review" value="1" required> I have reviewed the selected suppliers, destination and applicable legal/trade restrictions and authorize these non-binding RFQs.</label>
<button name="send_vendor_rfqs" value="1" type="submit">Send approved supplier RFQs</button>
</form>
<?php else: ?><p>Mail transport acceptance is recorded separately from confirmed delivery or a supplier reply. If a send failed, prepare a new draft after examining the recipient.</p><?php endif;?>
</section>
<?php endif;?>
