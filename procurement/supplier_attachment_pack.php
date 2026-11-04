<?php
declare(strict_types=1);
/**
 * Private supplier-facing PDF assembled from the canonical, already imported RFQ.
 * No copy of the buyer file, buyer identity, destination or price comparisons
 * enters the public web directory or source repository.
 */
function aur_supplier_pdf_text(string $value):string {
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
    if(preg_match('/\b(?:Uzbekistan|Usbekistan|O.zbekiston|Tashkent)\b|AUR-UZ-|@[a-z0-9.-]+\.[a-z]{2,}/iu',$value))
        throw new RuntimeException('supplier_rfq_private_detail_in_description');
    $converted=function_exists('iconv')?@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value):false;
    $value=$converted!==false?$converted:preg_replace('/[^\x20-\x7e]/','?', $value);
    return trim(preg_replace('/[\x00-\x1f\x7f]/',' ', $value)??'');
}
function aur_supplier_pdf_escape(string $value):string {
    return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);
}
function aur_supplier_full_rfq_pdf(array $case):string {
    $rows=$case['payload']['sourcing_lines']??null;
    if(!is_array($rows)||!$rows)throw new RuntimeException('supplier_rfq_canonical_lines_missing');
    $lines=[
        'AURENOEVA  |  REQUEST FOR QUOTATION',
        'Medical equipment  |  AUR-MED-2026-10-26  |  26 October 2026',
        '',
        'We would welcome an offer for any of the equipment listed below.',
        'Please quote only the items you can supply. Partial offers are welcome.',
        'Please state exact model, configuration, condition, quantity, price/currency,',
        'availability, lead time, warranty and quotation validity.',
        'The purchaser will arrange installation, training and maintenance locally.',
        'Any purchase will be contracted directly with the supplier, subject to checks.',
        '',
        'ITEM  |  QTY  |  EQUIPMENT / REQUIRED CONFIGURATION',
    ];
    foreach($rows as $row) {
        if(!is_array($row))throw new RuntimeException('supplier_rfq_invalid_line');
        $number=(int)($row['customer_item_number']??0);
        if($number<1)throw new RuntimeException('supplier_rfq_invalid_item');
        $description=aur_supplier_pdf_text((string)($row['description_original']??''));
        if($description==='')throw new RuntimeException('supplier_rfq_empty_description');
        $quantity=$row['quantity']===null?'TBC':(string)(int)$row['quantity'];
        $option=($row['option_type']??'REQUESTED')==='ALTERNATIVE'?' [ALTERNATIVE]':'';
        $prefix=sprintf('%02d  |  %3s  |  ',$number,$quantity);
        $wrapped=explode("\n",wordwrap($description.$option,99,"\n",true));
        foreach($wrapped as $i=>$part)$lines[]=($i===0?$prefix:str_repeat(' ',strlen($prefix))).$part;
    }
    $lines[]='';
    $lines[]='Please reply to info@aurenoeva.com with the item numbers you can quote.';
    $lines[]='This enquiry is non-binding. No supplier authorization or stock is presumed.';
    // Prefer a legible two-page A4 schedule; never silently truncate an item.
    $step=count($lines)<=142?10:8.5;
    $font=count($lines)<=142?8.3:7.2;
    $capacity=$step===10?73:86;
    $pages=array_chunk($lines,$capacity);
    if(count($pages)>2)throw new RuntimeException('supplier_rfq_exceeds_two_page_layout');
    $objects=[
        1=>'<< /Type /Catalog /Pages 2 0 R >>',
        3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    $kids=[];
    foreach($pages as $pageIndex=>$pageLines){
        $pageId=4+$pageIndex*2;$streamId=$pageId+1;
        $kids[]=$pageId.' 0 R';
        $stream='';
        foreach($pageLines as $lineIndex=>$line){
            $size=$lineIndex<2&&$pageIndex===0?($lineIndex===0?13:9):$font;
            $y=794-$lineIndex*$step;
            $safe=aur_supplier_pdf_escape($line);
            $stream.="BT /F1 ".sprintf('%.2f',$size)." Tf 40 ".sprintf('%.2f',$y)." Td (".$safe.") Tj ET\n";
        }
        $stream.="BT /F1 8 Tf 40 22 Td (AURENOEVA  |  aurenoeva.com  |  Page ".($pageIndex+1)." of ".count($pages).") Tj ET\n";
        $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$streamId.' 0 R >>';
        $objects[$streamId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
    }
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($pages).' >>';
    ksort($objects);
    $pdf="%PDF-1.4\n";$offsets=[0];
    foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}
    $xref=strlen($pdf);$count=max(array_keys($objects))+1;
    $pdf.="xref\n0 ".$count."\n0000000000 65535 f \n";
    for($id=1;$id<$count;$id++)$pdf.=sprintf('%010d 00000 n '."\n",$offsets[$id]??0);
    $pdf.="trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    return $pdf;
}
function aur_supplier_outbound_attachments(string $store,array $case):array {
    $brochure=__DIR__.'/assets/AURENOEVA_Company_Introduction_A4.pdf';
    if(!is_file($brochure)||is_link($brochure)||!is_readable($brochure))
        throw new RuntimeException('supplier_brochure_unavailable');
    $base=aur_private_directory($store,'supplier-rfqs');
    $pdfPath=$base.'/'.(string)$case['reference'].'-supplier-safe-rfq.pdf';
    $pdf=aur_supplier_full_rfq_pdf($case);
    if(file_put_contents($pdfPath,$pdf,LOCK_EX)!==strlen($pdf))
        throw new RuntimeException('supplier_rfq_attachment_write_failed');
    @chmod($pdfPath,0600);
    $out=[];
    foreach([
        ['path'=>$brochure,'name'=>'AURENOEVA_Company_Introduction.pdf'],
        ['path'=>$pdfPath,'name'=>'AURENOEVA_RFQ_Medical_Equipment_26_Oct_2026.pdf'],
    ] as $file){
        $size=filesize($file['path']);
        if(!is_int($size)||$size<100||$size>8388608)
            throw new RuntimeException('supplier_attachment_invalid_size');
        $head=file_get_contents($file['path'],false,null,0,8);
        if(!is_string($head)||!str_starts_with($head,'%PDF-1.'))
            throw new RuntimeException('supplier_attachment_not_pdf');
        $out[]=$file+['size'=>$size,'type'=>'application/pdf'];
    }
    if(array_sum(array_column($out,'size'))>10485760)
        throw new RuntimeException('supplier_attachment_pack_too_large');
    return $out;
}
