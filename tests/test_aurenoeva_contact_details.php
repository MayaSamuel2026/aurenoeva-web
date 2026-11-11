<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/mail_branding.php';
require dirname(__DIR__).'/deployment/site/procurement/supplier_attachment_pack.php';
function contact_assert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException($why);
}
$tel='+49 176 471 77751';$href='tel:+4917647177751';
$email='info@aurenoeva.com';
foreach(['de','en'] as $language){
    $signature=aur_procurement_legal_signature($language);
    contact_assert(str_contains($signature,$tel) && str_contains($signature,$email),
        "Canonical $language contact details missing");
    contact_assert(str_contains(aur_procurement_signed_closing($language,'supplier@example.org'),$tel),
        "RFQ $language signed closing missing telephone");
    contact_assert(str_contains(aur_mailcenter_signature_plain($language),$tel),
        "Mail center $language signature missing telephone");
    $html=aur_mailcenter_html_document('Test enquiry',$language);
    contact_assert(str_contains($html,$href) && str_contains($html,'mailto:'.$email),
        "Mail center $language HTML not clickable");
    contact_assert(!str_contains($signature,'info@noevasystems.com'),
        'Internal mail forwarding must not be published to suppliers');
}
foreach([
    dirname(__DIR__).'/hotfix/v8.2.6.8/index.html',
    dirname(__DIR__).'/hotfix/v8.2.6.5/site.js',
    dirname(__DIR__).'/hotfix/v8.2.6.2/about.html'
] as $path){
    $source=file_get_contents($path);
    contact_assert(is_string($source) && str_contains($source,$href) &&
        str_contains($source,$tel) && str_contains($source,'mailto:'.$email),
        'Public footer/contact page is missing clickable contact details: '.basename($path));
    contact_assert(!str_contains($source,'info@noevasystems.com'),
        'Private forwarding information should not be published');
}
$case=['payload'=>['sourcing_lines'=>[
    ['customer_item_number'=>1,'quantity'=>1,'description_original'=>'Test equipment model A',
     'option_type'=>'REQUESTED'],
    ['customer_item_number'=>2,'quantity'=>2,'description_original'=>'Test equipment model B',
     'option_type'=>'REQUESTED'],
]]];
$pdf=aur_supplier_full_rfq_pdf($case);
contact_assert(str_starts_with($pdf,'%PDF-1.') &&
    str_contains($pdf,'Telephone: '.$tel) &&
    str_contains($pdf,'Email: '.$email),
    'Future supplier-safe RFQ attachment missing phone/email');
contact_assert(!str_contains($pdf,'info@noevasystems.com'),
    'Forwarding detail included in supplier-safe RFQ');
echo "AURENOEVA public contact and future supplier RFQ signature: PASS\n";
