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
check_tab(str_contains($entry,'workspace-tabs.js?v=1'),'UI script not installed');
foreach(['aur-upload','saved-cases','destination-screen','supplier-workspace','aur-rfq-approval','supplier-replies','aur-mail-settings'] as $target) {
 check_tab(str_contains($ui,"'".$target."'"),'Existing workflow target not retained: '.$target);
}
check_tab(str_contains($ui,'openDeepLink') && str_contains($ui,'hashchange'), 'Deep link reopening unavailable');
check_tab(str_contains($ui,'panels.rfq') && str_contains($ui,'panels.settings'), 'Panels not partitioned');
check_tab(str_contains($portal,"['DRAFT_REVIEW','DISPATCHING']"),'Sent RFQs still show SMTP setup warning');
check_tab(str_contains($entry,'Download original enquiry'),'Original enquiry label still says draft');
echo "AURENOEVA_COMPACT_TABS=PASS\n";
