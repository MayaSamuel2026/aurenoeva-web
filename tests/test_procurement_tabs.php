<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$entry=file_get_contents($root.'/deployment/site/procurement/index.php');
$ui=file_get_contents($root.'/deployment/site/procurement/workspace-tabs.js');
$portal=file_get_contents($root.'/deployment/site/procurement/supplier_portal.php');
function check_tab(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException($label);
}
foreach(['rfq','sources','mail','drafts','more'] as $panel) {
 check_tab(str_contains($entry,'data-aur-tab="'.$panel.'"'),'Missing accessible tab '.$panel);
 check_tab(str_contains($ui,"'".$panel."'"),'Tab not wired '.$panel);
}
check_tab(str_contains($entry,'workspace-tabs.js?v=7'),'UI script not installed');
foreach(['aur-upload','saved-cases','destination-screen','supplier-workspace','aur-rfq-approval','supplier-replies','aur-mail-settings'] as $target) {
 check_tab(str_contains($ui,"'".$target."'"),'Existing workflow target not retained: '.$target);
}
check_tab(str_contains($ui,'openHash') && str_contains($ui,'hashchange'), 'Deep link reopening unavailable');
check_tab(str_contains($ui,"'rfq','sources','mail','drafts','more'"),'Five user functions must be available');
check_tab(str_contains($ui,"'item-sources'") && str_contains($ui,"'supplier-replies'")
    && str_contains($ui,"'aur-mail-center'") && str_contains($ui,"'aur-rfq-approval'"),
    'Sources, mail or prepared-email actions not present');
check_tab(str_contains($entry,'id="aur-one-rfq"') && str_contains($entry,'AURENOEVA_ONE_RFQ_20261111'),
    'Canonical original RFQ header missing');
check_tab(!str_contains($entry,'$nextSourcing=aur_researched_next_20261106_prepare($aurStore,$result);'),
    'GET request still automatically prepares another RFQ version');
check_tab(str_contains($portal,"['DRAFT_REVIEW','DISPATCHING']"),'Sent RFQs still show SMTP setup warning');
check_tab(str_contains($portal,'id="aur-latest-supplier-rfq"') && str_contains($portal,'prepare_followup_supplier_rfqs'), 'New source-specific RFQ previews and save control missing');
check_tab(str_contains($portal,'id="aur-next-sourcing-round"') && str_contains($portal,'prepare_next_sourcing_rfqs'), '6 November sourcing review and save control missing');
$rfqDispatch=file_get_contents($root.'/deployment/site/procurement/supplier_rfq.php');
check_tab(str_contains($portal,'aur_vendor_rfq_is_selected_batch(')
    && str_contains($rfqDispatch,'TARGETED_FOLLOWUP_ROUND_2026_11_05')
    && str_contains($rfqDispatch,'CURATED_STOCK_LISTING_ROUND_2026_11_03'),
    'Seven-supplier selectable batch not recognized by shared dispatch source');
check_tab(str_contains($entry,'Download original enquiry'),'Original enquiry label still says draft');
echo "AURENOEVA_COMPACT_TABS=PASS\n";

check_tab(str_contains($entry, 'id="aur-diagnostics-fallback"') &&
          str_contains($entry, 'id="aur-legacy-functions"'),
          'Fallback must keep the original data and forms accessible without JavaScript');
check_tab(str_contains($entry,'NOEVA_AURENOEVA_WORKSPACE_FOCUS_20261111'),
          'Compact shared design CSS missing');
check_tab(str_contains($ui,'aur-legacy-functions') && str_contains($ui,'aur-diagnostics-fallback'),
          'Saved original functions and history not retained');

check_tab(str_contains($portal,'$itemSuggestions=$rankedByRow'), 'Supplier leads for each buyer item still limited to four');

check_tab(str_contains($entry,'case_mail_workbench.php'),'Case mail evidence view not included');
check_tab(str_contains($ui,"'aur-mail-trail'")&&str_contains($ui,"'aur-email-review-queue'"),
          'Sent/received mail and prepared emails must be direct working areas');

check_tab(str_contains($ui,'setupSourceNavigator(itemSource)') && str_contains($ui,'sourceItems.forEach') && str_contains($ui,'pageSize=9'), 'All RFQ sources must be searchable and paginated without deletion');
check_tab(str_contains($entry,'AURENOEVA_COMPACT_ITEM_MAIL_20261111'), 'Compact single-item and mail layout absent');
$inbox=file_get_contents($root.'/deployment/site/procurement/mail-center.js');
check_tab(str_contains($inbox,'pageRows = 9') && str_contains($inbox,'visible.slice(start, start + pageRows)'), 'Mail inbox must be paginated without truncating saved messages');

check_tab(str_contains($entry,'AURENOEVA_COMPACT_CORRESPONDENCE_20261111'), 'Compact supplier conversation styling missing');
$threadUi=file_get_contents($root.'/deployment/site/procurement/case-mail-inbox.js');
$board=file_get_contents($root.'/deployment/site/procurement/case_mail_workbench.php');
check_tab(str_contains($threadUi,'pageSize=20') && str_contains($threadUi,'aurenoeva:compose-supplier-followup'),
    'Supplier inbox paging and reviewed follow-up action disconnected');
check_tab(str_contains($board,'case_mail_inbox_data.php') && str_contains($board,'aur_correspondence_threads(')
    && str_contains($board,'JSON_HEX_TAG'), 'Original evidence not delivered safely to compact inbox');
check_tab(str_contains($ui,'Open full Mail Centre, read attachments or compose'),
    'Full mailbox must remain accessible without full-page scrolling');
check_tab(str_contains($inbox,'aurenoeva:compose-supplier-followup') && str_contains($inbox,'openCompose()'),
    'Follow-up must open the existing manual Mail Centre draft');
