<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$entry=file_get_contents($root.'/deployment/site/procurement/index.php');
$ui=file_get_contents($root.'/deployment/site/procurement/workspace-tabs.js');
$portal=file_get_contents($root.'/deployment/site/procurement/supplier_portal.php');
function check_tab(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException($label);
}
foreach(['overview','enquiry','suppliers','rfq','settings'] as $panel) {
 check_tab(str_contains($entry,'data-aur-tab="'.$panel.'"'),'Missing accessible tab '.$panel);
 check_tab(str_contains($ui,"'".$panel."'"),'Tab not wired '.$panel);
}
check_tab(str_contains($entry,'workspace-tabs.js?v=3'),'UI script not installed');
foreach(['aur-upload','saved-cases','destination-screen','supplier-workspace','aur-rfq-approval','supplier-replies','aur-mail-settings'] as $target) {
 check_tab(str_contains($ui,"'".$target."'"),'Existing workflow target not retained: '.$target);
}
check_tab(str_contains($ui,'openDeepLink') && str_contains($ui,'hashchange'), 'Deep link reopening unavailable');
check_tab(str_contains($ui,'move(newRound, panels.rfq)') && str_contains($ui,'nextSourcingRound') && str_contains($ui,'Review next sourcing round') && str_contains($ui,'historical.appendChild(followupRound)'), 'Newly researched supplier RFQ not primary in workspace');
check_tab(str_contains($ui,"extra(reminders, 'Supplier reminders") && str_contains($ui,'historical.appendChild(section)'), 'Historical supplier material not collapsed in RFQ tab');
check_tab(str_contains($entry,'$nextSourcing=aur_researched_next_20261106_prepare($aurStore,$result);') && str_contains($entry,'$followupBatch=aur_researched_20261105_followup_prepare($aurStore,$result);') && str_contains($entry,'$newBatch=aur_next_stock_prepare($aurStore,$result);'), 'Latest supplier round not prepared first or earlier campaigns lost');
check_tab(str_contains($entry, '$aurNext=' . "'Prepare individual supplier reminders'"), 'Overview does not direct sent cases to reminder preparation');
check_tab(str_contains($portal,'id="aur-price-reminders"'), 'Reminder controls not present in supplier RFQ workspace');
check_tab(str_contains($ui,'panels.rfq') && str_contains($ui,'panels.settings'), 'Panels not partitioned');
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
