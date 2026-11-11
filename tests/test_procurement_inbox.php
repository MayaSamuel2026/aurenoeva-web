<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/supplier_inbox_store.php';
require dirname(__DIR__).'/deployment/site/procurement/case_mail_inbox_data.php';
function must_mail(bool $v,string $msg):void {if(!$v)throw new RuntimeException($msg);}
$root=sys_get_temp_dir().'/aur-mail-test-'.bin2hex(random_bytes(5));
mkdir($root.'/public_html',0700,true);
$store=aur_store_root($root.'/public_html');
$ref='AUR-UZ-1A04A7909185FACD';
$case=['reference'=>$ref,'source_sha256'=>hash('sha256','buyer original'),'country'=>'UZ',
    'line_count'=>3,'created_at'=>gmdate('c'),'payload'=>['sourcing_lines'=>[
        ['source_row'=>2,'customer_item_number'=>1,'option_type'=>'REQUESTED',
         'description_original'=>'Model AX-200','quantity'=>1],
        ['source_row'=>3,'customer_item_number'=>6,'option_type'=>'REQUESTED',
         'description_original'=>'MT5000S washer','quantity'=>1],
        ['source_row'=>4,'customer_item_number'=>6,'option_type'=>'ALTERNATIVE',
         'description_original'=>'MT5000L washer','quantity'=>null]
    ]]];
aur_mail_write_json(aur_private_directory($store,'cases').'/'.$ref.'.json',$case);
$draftId=bin2hex(random_bytes(12));
$draft=['case_reference'=>$ref,'case_source_sha256'=>$case['source_sha256'],
   'draft_id'=>$draftId,'status'=>'TRANSPORT_ACCEPTED','created_at'=>gmdate('c'),
   'messages'=>[['to'=>'info@humanova.ae','vendor_name'=>'HumaNova',
       'status'=>'ACCEPTED_BY_MAIL_TRANSPORT','items'=>[
           ['source_row'=>2,'description'=>'Model AX-200'],
           ['source_row'=>3,'description'=>'MT5000S washer'],
           ['source_row'=>4,'description'=>'MT5000L washer']]]]];
aur_vendor_rfq_write(aur_vendor_rfq_path($store,$ref,$draftId),$draft);
$raw="From: info@humanova.ae\r\nTo: info@aurenoeva.com\r\n"
    ."Subject: Re: AURENOEVA Purchasing | ".$ref."\r\n"
    ."Message-ID: <test-humanova@humanova.ae>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
    ."Dear team,\r\nWe noticed that multiple products meet the same specifications.\r\n"
    ."Kindly provide the exact part number so we can quote accurately.\r\n"
    ."___________\r\nFrom: AURENOEVA Purchasing\r\n1. Buyer item 6\r\nQuantity: 1";
$event=aur_mail_import($store,14,4242,$raw);
must_mail($event['classification']==='CLARIFICATION_REQUIRED','HumaNova reply type');
must_mail($event['case_reference']===$ref,'RFQ case association failed');
must_mail($event['buyer_items']===[],'Original quoted RFQ must not be parsed as a new item request');
must_mail(!$event['quote_recorded'],'Clarification cannot become supplier quotation');
must_mail(is_file(aur_mail_dir($store).'/'.$event['key'].'.eml'),'Original email evidence missing');
must_mail(count(aur_mail_events($store))===1,'Mailbox event not persisted');
$again=aur_mail_import($store,14,4242,$raw);
must_mail(count(aur_mail_events($store))===1&&$again['key']===$event['key'],'Duplicate UID not idempotent');
$offer="From: info@humanova.ae\r\nSubject: Re: ".$ref."\r\n"
    ."Message-ID: <offer@humanova.ae>\r\nContent-Type: text/plain\r\n\r\n"
    ."Quotation for buyer item 1: unit price EUR 2350. Delivery time: 3 weeks.";
$quoted=aur_mail_import($store,15,4242,$offer);
must_mail($quoted['classification']==='QUOTE_RECEIVED','Quote classifier failed');
must_mail($quoted['quote_recorded']===true,'Clear item quote did not enter original register');
$answers=aur_reply_read($store,$case);
must_mail(count($answers)===1&&reset($answers)['unit_price']==='2350','Price or source row not saved');
must_mail(reset($answers)['evidence']==='MAILBOX_IMAP_UID_VERIFIED','Source provenance lost');
$alt="From: info@humanova.ae\r\nSubject: Re: ".$ref."\r\n\r\n"
    ."We can quote buyer item 6 for EUR 500.";
$ambiguous=aur_mail_import($store,16,4242,$alt);
must_mail(!$ambiguous['quote_recorded'],'Alternative item ambiguity must not be auto-quoted');
must_mail(count(aur_reply_read($store,$case))===1,'Ambiguous quote contaminated supplier register');

// Another supplier received a message from a DIFFERENT saved email batch
// belonging to this same original RFQ. The old primary-only lookup missed it.
$secondId=bin2hex(random_bytes(12));
$second=['case_reference'=>$ref,'case_source_sha256'=>$case['source_sha256'],
    'draft_id'=>$secondId,'status'=>'TRANSPORT_ACCEPTED','created_at'=>gmdate('c'),
    'messages'=>[['to'=>'sales@secondary-example.com','vendor_name'=>'Second Example',
        'status'=>'ACCEPTED_BY_MAIL_TRANSPORT',
        'items'=>[['source_row'=>2,'description'=>'Model AX-200']]]]];
aur_vendor_rfq_write(aur_vendor_rfq_path($store,$ref,$secondId),$second);
$secondaryRaw="From: sales@secondary-example.com\r\n"
    ."Subject: Re: ".$ref."\r\nContent-Type: text/plain\r\n\r\n"
    ."Please confirm which exact model number is required.";
$secondary=aur_mail_import($store,17,4242,$secondaryRaw);
must_mail($secondary['case_reference']===$ref && $secondary['matched_to_sent_rfq']===true,
    'Reply to secondary supplier batch was lost from original case');
// A subject containing the original reference is visibly case-linked, but
// an unknown sender is not proof of a valid supplier quote.
$unknownRaw="From: unknown@example.net\r\n"
    ."Subject: Re: ".$ref."\r\nContent-Type: text/plain\r\n\r\n"
    ."Quotation for buyer item 1: unit price EUR 1.";
$unknown=aur_mail_import($store,18,4242,$unknownRaw);
must_mail($unknown['case_reference']===$ref && !$unknown['matched_to_sent_rfq']
    && !$unknown['quote_recorded'] && $unknown['requires_sender_review'],
    'Unverified supplier mail was silently accepted as a quote');
// Duplicate sent contact across batches must never be attributed to the first
// draft arbitrarily: it is saved under the case for manual clarification.
$thirdId=bin2hex(random_bytes(12));
$third=$second;
$third['draft_id']=$thirdId;
$third['messages'][0]['items']=[['source_row'=>3,'description'=>'MT5000S washer']];
aur_vendor_rfq_write(aur_vendor_rfq_path($store,$ref,$thirdId),$third);
$ambiguousBatchRaw="From: sales@secondary-example.com\r\n"
    ."Subject: Re: ".$ref."\r\nContent-Type: text/plain\r\n\r\n"
    ."Quotation for buyer item 6: unit price EUR 500.";
$ambiguousBatch=aur_mail_import($store,19,4242,$ambiguousBatchRaw);
must_mail($ambiguousBatch['case_reference']===$ref
    && !$ambiguousBatch['matched_to_sent_rfq']
    && !$ambiguousBatch['quote_recorded'],
    'Ambiguous multiple-batch reply must not fabricate an item price');

// Older archived mailbox events without a case match are NOT rewritten. Exact
// original RFQ references can still be shown read-only in correspondence.
$all=aur_mail_events($store);
$all['historical-uid']=[
    'case_reference'=>'','subject'=>'Re: '.$ref,'from'=>'old-supplier@example.org',
    'supplier'=>'','supplier_email'=>'old-supplier@example.org',
    'processed_at'=>'2026-10-27T09:00:00Z','classification'=>'OTHER',
    'matched_to_sent_rfq'=>false,'quote_recorded'=>false
];
aur_mail_write_json(aur_mail_dir($store).'/events.json',
    ['schema'=>'aurenoeva-mailbox-events/1','events'=>$all]);
$historic=aur_mail_case_events($store,$ref);
$projection=array_values(array_filter($historic,
    static fn($row)=>($row['case_reference_projection_only']??false)===true));
must_mail(count($projection)===1 && $projection[0]['requires_sender_review']===true,
    'Existing RFQ-referenced historical correspondence was hidden');
must_mail((aur_mail_events($store)['historical-uid']['case_reference']??null)==='',
    'Read-only historical projection rewrote the original inbox evidence');

// One supplier must occupy one inbox row even across multiple saved SMTP
// batches. Only verified case/sender matches count as replies.
$sentExamples=[
 ['to'=>'INFO@humanova.ae','supplier'=>'HumaNova','subject'=>'RFQ enquiry 1',
  'body'=>'First enquiry','sent_at'=>'2026-11-09T09:00:00Z'],
 ['to'=>'info@humanova.ae','supplier'=>'HumaNova','subject'=>'RFQ enquiry 2',
  'body'=>'Another equipment model','sent_at'=>'2026-11-10T09:00:00Z'],
 ['to'=>'waiting@vendor.example','supplier'=>'Waiting Vendor','subject'=>'RFQ enquiry',
  'body'=>'Please quote','sent_at'=>'2026-11-11T09:00:00Z'],
];
$unknownAttempts=[['to'=>'uncertain@vendor.example','supplier'=>'Uncertain Vendor',
 'subject'=>'RFQ enquiry','status'=>'TRANSPORT_UNCONFIRMED','body'=>'Maybe sent',
 'attempted_at'=>'2026-11-11T10:00:00Z']];
$incomingExamples=[
 ['supplier_email'=>'info@humanova.ae','supplier'=>'HumaNova',
  'subject'=>'Re: RFQ','classification'=>'CLARIFICATION_REQUIRED',
  'matched_to_sent_rfq'=>true,'preview'=>'Need product model number',
  'processed_at'=>'2026-11-11T12:00:00Z'],
 ['from'=>'unknown@example.net','subject'=>'Re: RFQ',
  'classification'=>'QUOTE_RECEIVED','matched_to_sent_rfq'=>false,
  'requires_sender_review'=>true,'preview'=>'Price not verified',
  'processed_at'=>'2026-11-11T12:05:00Z'],
];
$projected=aur_correspondence_threads($sentExamples,$unknownAttempts,$incomingExamples);
must_mail($projected['summary']['all']===4 &&
    $projected['summary']['replied']===1 &&
    $projected['summary']['awaiting']===1 &&
    $projected['summary']['review']===1 &&
    $projected['summary']['unconfirmed']===1,
    'Thread status classification or one-row-per-supplier grouping incorrect');
$humanova=array_values(array_filter($projected['threads'],
    static fn($x)=>$x['email']==='info@humanova.ae'));
must_mail(count($humanova)===1 && $humanova[0]['sent_count']===2
    && $humanova[0]['reply_count']===1
    && count($humanova[0]['messages'])===3,
    'Sending batches were duplicated or reply evidence lost');
$unverified=array_values(array_filter($projected['threads'],
    static fn($x)=>$x['email']==='unknown@example.net'));
must_mail(count($unverified)===1 && $unverified[0]['status']==='review'
    && $unverified[0]['reply_count']===0,
    'Unverified inbound mail was incorrectly shown as a verified reply');
must_mail(count($sentExamples)===3&&count($incomingExamples)===2,
    'Read-only conversation projection unexpectedly altered the source');
echo "AURENOEVA_INCOMING_MAIL_RECONCILIATION=PASS\n";
