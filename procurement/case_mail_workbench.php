<?php
declare(strict_types=1);
/**
 * AURENOEVA_CASE_MAIL_WORKBENCH_V1
 * Read-only presentation of all existing per-case supplier email drafts,
 * actual SMTP accepted records, uncertain attempts and matched inbox replies.
 * No parallel RFQ, customer, outbox or mail store. No automatic sending.
 */
require_once __DIR__.'/supplier_inbox_store.php';
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
?>
<section id="aur-mail-trail" class="card" aria-label="RFQ sent and received mail evidence">
<h2>Emails for RFQ <?=aur_escape((string)$result['reference'])?></h2>
<p class="muted">Every recorded sending-server acceptance and case-matched supplier response. Unmatched emails can be checked in the live inbox below. An SMTP acceptance is not an inbox delivery confirmation.</p>
<p class="aur-workbench-totals">
<span class="pill"><b><?=count($workbenchEmails['smtp_accepted'])?></b> SMTP accepted</span>
<span class="pill"><b><?=count($workbenchReceived)?></b> inbound RFQ-referenced</span>
<span class="pill"><b><?=count($workbenchEmails['unconfirmed'])?></b> unconfirmed attempts</span></p>
<?php if($workbenchError):?><p role="alert">Some source records could not be read. They have not been removed; inspect the original mail ledger.</p><?php endif;?>
<div class="aur-workbench-mail-columns">
<div><h3>Sent emails · saved SMTP evidence</h3>
<?php if(!$workbenchEmails['smtp_accepted']):?><p class="muted">No accepted outgoing emails are recorded for this RFQ.</p><?php endif;?>
<?php foreach($workbenchEmails['smtp_accepted'] as $message):?>
<details class="aur-mail-evidence-item">
<summary><?=aur_escape($message['supplier'])?><small><?=aur_escape($message['to'])?> · <?=aur_escape($message['sent_at']?:$message['attempted_at'])?></small></summary>
<p><b>Subject:</b> <?=aur_escape($message['subject'])?></p>
<p class="muted">Buyer item(s): <?=aur_escape(aur_case_workbench_original_lines($result,$message))?></p>
<pre><?=aur_escape($message['body'])?></pre>
</details>
<?php endforeach;?></div>
<div><h3>Received correspondence · same RFQ reference</h3>
<?php if(!$workbenchReceived):?><p class="muted">No incoming messages linked to this RFQ yet. Open the Inbox below to check unmatched mail.</p><?php endif;?>
<?php foreach($workbenchReceived as $event):if(!is_array($event))continue;?>
<div class="aur-mail-evidence-item"><b><?=aur_escape((string)($event['supplier']??$event['supplier_email']??'Unknown supplier'))?></b>
<small><?=aur_escape((string)($event['received_at']??$event['processed_at']??''))?> · <?=aur_escape(str_replace('_',' ',(string)($event['classification']??'UNCLASSIFIED')))?></small>
<?php if(empty($event['matched_to_sent_rfq'])):?><small>Sender or batch unverified · review before attributing a quotation</small><?php endif;?>
</div><?php endforeach;?></div></div>
<?php if($workbenchEmails['unconfirmed']):?>
<details class="aur-tool-drawer"><summary><?=count($workbenchEmails['unconfirmed'])?> messages need transport-status review</summary>
<?php foreach($workbenchEmails['unconfirmed'] as $message):?>
<p><?=aur_escape($message['supplier'])?> · <?=aur_escape($message['status'])?> · <?=aur_escape($message['subject'])?></p>
<?php endforeach;?></details><?php endif;?>
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
