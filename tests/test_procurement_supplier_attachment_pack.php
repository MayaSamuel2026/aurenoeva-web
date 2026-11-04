<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/deployment/site/procurement/supplier_attachment_pack.php';
function pack_check(bool $value,string $label):void {
    if(!$value){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
}
$rows=[];
for($i=1;$i<=44;$i++){
    $rows[]=[
        'customer_item_number'=>$i>39?$i-39:$i,
        'option_type'=>$i>39?'ALTERNATIVE':'REQUESTED',
        'description_original'=>'Example technical medical product model ZM-'.sprintf('%04d',$i).' with standard accessories and hospital use',
        'quantity'=>1
    ];
}
$case=['payload'=>['sourcing_lines'=>$rows]];
$pdf=aur_supplier_full_rfq_pdf($case);
pack_check(str_starts_with($pdf,'%PDF-1.4'),'PDF header');
pack_check(str_contains($pdf,'%%EOF'),'PDF trailer');
pack_check(preg_match_all('~/Type /Page\s*/Parent~',$pdf)>=1
    &&preg_match_all('~/Type /Page\s*/Parent~',$pdf)<=2,'two page maximum');
pack_check(str_contains($pdf,'ZM-0044'),'last equipment entry retained');
pack_check(str_contains($pdf,'[ALTERNATIVE]'),'alternative equipment not lost');
pack_check(!preg_match('/AUR-UZ-|Uzbekistan|Usbekistan|Tashkent|Buyer Company/i',$pdf),
    'supplier PDF must not expose country, reference or buyer');
$invalid=$case;
$invalid['payload']['sourcing_lines'][0]['description_original']='Shipped to Uzbekistan';
$stopped=false;
try{aur_supplier_full_rfq_pdf($invalid);}catch(RuntimeException $e){
    $stopped=$e->getMessage()==='supplier_rfq_private_detail_in_description';
}
pack_check($stopped,'stop disclosure of a country in an equipment line');
$rfqCode=file_get_contents(dirname(__DIR__).'/deployment/site/procurement/supplier_rfq.php');
pack_check(str_contains($rfqCode,'$attachmentPack=aur_supplier_outbound_attachments($store,$case);'),
    'supplier dispatch must prepare both files before sending');
pack_check(str_contains($rfqCode,'$attachmentPack);'),
    'supplier SMTP must pass prepared attachments');
$en=aur_supplier_broader_offer_note("Please quote model ZM-1.\n\nThank you and kind regards,\nAURENOEVA",'en');
pack_check(str_contains($en,'If you can supply any other items')
   &&strpos($en,'full equipment list')<strpos($en,'Thank you'),'English invitation before signature');
$de=aur_supplier_broader_offer_note("Guten Tag,\n\nwir interessieren uns für Modell ZM-1.\n\nHerzliche Grüße\nAURENOEVA",'de');
pack_check(str_contains($de,'weitere Positionen')
   &&strpos($de,'vollständige Geräteliste')<strpos($de,'Herzliche Grüße'),'German invitation before signature');
pack_check($en===aur_supplier_broader_offer_note($en,'en'),'invitation idempotent');
echo "AURENOEVA_SUPPLIER_ATTACHMENT_PACK_TESTS=PASS\n";
