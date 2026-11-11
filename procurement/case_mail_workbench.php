<?php
declare(strict_types=1);
/**
 * AURENOEVA_CASE_MAIL_WORKBENCH_V1
 * Read-only presentation of all existing per-case supplier email drafts,
 * actual SMTP accepted records, uncertain attempts and matched inbox replies.
 * No parallel RFQ, customer, outbox or mail store. No automatic sending.
 */
require_once __DIR__.'/supplier_inbox_store.php';
require_once __DIR__.'/case_mail_inbox_data.php';
function aur_case_workbench_mail_rows(array $batches):array {
    $drafts=[];$accepted=[];$uncertain=[];
    foreach($batches as $batch) {
        if(!is_array($batch))continue;
        $id=(string)($batch['draft_id']??'');
        $created=(string)($batch['created_at']??'');
        foreach(($batch['messages']??[]) as $msg) {
            if(!is_array($msg))continue;
            $email=strtolower(trim((string)($msg['to']??'')));
            $row=[
                'draft_id'=>$id,'created_at'=>$created,
                'supplier'=>(string)($msg['vendor_name']??$email),
                'to'=>$email,'subject'=>(string)($msg['subject']??''),
                'body'=>(string)($msg['body']??''),
                'items'=>is_array($msg['items']??null)?$msg['items']:[],
                'status'=>(string)($msg['status']??'UNKNOWN'),
                'sent_at'=>(string)($msg['sent_at']??''),
                'attempted_at'=>(string)($msg['attempted_at']??''),
            ];
            if($row['status']==='ACCEPTED_BY_MAIL_TRANSPORT'){
                $accepted[]=$row;
            }elseif($row['status']==='DRAFT' && empty($msg['attempted_at'])
                     && empty($msg['transport_result']) && empty($msg['sent_at'])){
                // Identical revisions are one review item; the original draft
                // files and all different messages remain in the source ledger.
                $key=hash('sha256',$email.'|'.$row['subject'].'|'.$row['body']);
                if(!isset($drafts[$key]))$drafts[$key]=$row;
            }else{
                $uncertain[]=$row;
            }
        }
    }
    return ['prepared'=>array_values($drafts),'smtp_accepted'=>$accepted,
            'unconfirmed'=>$uncertain];
}
function aur_case_workbench_original_lines(array $case,array $message):string {
    $known=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $line){
        if(is_array($line))$known[(int)($line['source_row']??0)]=(int)($line['customer_item_number']??0);
    }
    $numbers=[];
    foreach(($message['items']??[]) as $item){
        if(!is_array($item))continue;
        $source=(int)($item['source_row']??0);
        if(isset($known[$source])&&$known[$source]>0)$numbers[$known[$source]]=true;
    }
    return $numbers?implode(', ',array_map('strval',array_keys($numbers))):'Refer to saved draft';
}
$workbenchBatches=[];$workbenchError=false;
try{
    foreach(aur_vendor_rfq_list($aurStore,$result) as $version){
        try{$workbenchBatches[]=aur_vendor_rfq_read($aurStore,$result,(string)$version['id']);}
        catch(Throwable $error){$workbenchError=true;}
    }
}catch(Throwable $error){$workbenchError=true;error_log('AURENOEVA email list read unavailable: '.get_class($error));}
$workbenchEmails=aur_case_workbench_mail_rows($workbenchBatches);
$workbenchReceived=[];
try{$workbenchReceived=aur_mail_case_events($aurStore,(string)$result['reference']);}
catch(Throwable $error){$workbenchError=true;error_log('AURENOEVA mail evidence read unavailable: '.get_class($error));}
foreach(['smtp_accepted','unconfirmed'] as $kind){
    foreach($workbenchEmails[$kind] as &$row){
        $row['item_numbers']=aur_case_workbench_original_lines($result,$row);
    }
    unset($row);
}
$inbox=aur_correspondence_threads(
    $workbenchEmails['smtp_accepted'],
    $workbenchEmails['unconfirmed'],
    $workbenchReceived
);
$inboxJson=json_encode($inbox,
    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|
    JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);

?>
<section id="aur-mail-trail" class="card aur-case-correspondence" aria-label="RFQ supplier correspondence inbox">
<div class="aur-correspondence-head">
  <div><span class="aur-workflow-kicker">RFQ CORRESPONDENCE</span>
  <h2>Supplier conversations</h2>
  <p class="muted">One row per supplier for RFQ <?=aur_escape((string)$result['reference'])?>.
  Only saved SMTP acceptances count as sent; unverified messages are flagged separately.</p></div>
  <div class="aur-case-mail-actions">
    <button type="button" id="aur-case-mail-refresh" class="aur-correspondence-open-mail">Refresh supplier replies</button>
    <a href="#aur-mail-center" class="aur-correspondence-open-mail">Open full mailbox</a>
  </div>
</div>
<div id="aur-case-mail-refresh-status" class="aur-case-mail-refresh-status" role="status" aria-live="polite"></div>
<div class="aur-correspondence-stats" id="aur-case-mail-stats" role="group" aria-label="Correspondence status">
  <span>Loading supplier correspondence…</span>
</div>
<?php if($workbenchError):?><p role="alert">Some source records were unavailable. Existing mail was not altered. Verify the original mailbox before making a sending decision.</p><?php endif;?>
<div class="aur-correspondence-controls">
  <label class="aur-correspondence-search-label" for="aur-case-mail-search">Find supplier or email
  <input type="search" id="aur-case-mail-search" placeholder="Supplier, email address or subject" autocomplete="off"></label>
  <div class="aur-correspondence-filters" role="group" aria-label="Conversation filter">
    <button type="button" data-aur-mail-filter="awaiting" aria-pressed="true">Awaiting reply</button>
    <button type="button" data-aur-mail-filter="replied" aria-pressed="false">Replies received</button>
    <button type="button" data-aur-mail-filter="review" aria-pressed="false">Needs review</button>
    <button type="button" data-aur-mail-filter="unconfirmed" aria-pressed="false">Send unconfirmed</button>
    <button type="button" data-aur-mail-filter="all" aria-pressed="false">All</button>
  </div>
  <label class="aur-correspondence-sort-label" for="aur-case-mail-sort">Order
    <select id="aur-case-mail-sort">
      <option value="recent">Most recent</option>
      <option value="oldest">Oldest</option>
      <option value="supplier">Supplier A–Z</option>
    </select>
  </label>
</div>
<div class="aur-correspondence-grid">
  <div class="aur-correspondence-list-side">
    <div class="aur-correspondence-list-info" id="aur-case-mail-list-info" role="status"></div>
    <div id="aur-case-mail-list" class="aur-correspondence-rows" aria-label="Supplier conversation list"></div>
    <div id="aur-case-mail-pagination" class="aur-correspondence-pagination"></div>
  </div>
  <article id="aur-case-mail-preview" class="aur-correspondence-preview" aria-label="Selected supplier conversation">
    <p class="muted">Choose a supplier to view the original sent messages and recorded replies.</p>
  </article>
</div>
<p class="muted aur-correspondence-footnote">An SMTP acceptance is not proof of delivery. A case-referenced but unmatched reply needs verification before being counted as a supplier response. Live mailbox unread states are not inferred here.</p>
<script id="aur-case-mail-data" type="application/json"><?=$inboxJson?></script>
<script defer src="/procurement/case-mail-inbox.js?v=2"></script>
</section>
<section id="aur-email-review-queue" class="card" aria-label="Saved emails requiring owner review">
<h2>Prepared emails awaiting your review</h2>
<p class="muted">Messages are prepared from the original RFQ and saved in the existing case outbox. Click an email to inspect its exact subject and body. Approve and send it with the original guarded controls below—nothing is sent by opening this view.</p>
<?php if(!$workbenchEmails['prepared']):?><p>No unsent supplier emails are currently saved. Use <b>Prepare additional supplier emails</b> below.</p><?php endif;?>
<p><span class="pill"><b><?=count($workbenchEmails['prepared'])?></b> prepared email(s)</span></p>
<?php foreach($workbenchEmails['prepared'] as $message):?>
<details class="aur-mail-evidence-item">
<summary><?=aur_escape($message['supplier'])?> <small><?=aur_escape($message['to'])?> · Draft not sent · Item(s) <?=aur_escape(aur_case_workbench_original_lines($result,$message))?></small></summary>
<p><b>Subject:</b> <?=aur_escape($message['subject'])?></p>
<pre><?=aur_escape($message['body'])?></pre>
<a class="aur-primary-link" href="?case=<?=rawurlencode((string)$result['reference'])?>&amp;draft=<?=rawurlencode($message['draft_id'])?>#aur-rfq-approval">Inspect attachment selection and send approval →</a>
</details>
<?php endforeach;?>
</section>
