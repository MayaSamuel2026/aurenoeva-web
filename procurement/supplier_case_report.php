<?php
declare(strict_types=1);
/**
 * Case-owned procurement report: original lines -> candidate vendors ->
 * one recipient/email per supplier. No external PDF library or new database.
 * The PDF is private and can only be downloaded through the staff page.
 */
function aur_supplier_report_data(array $case,array $intel):array {
    $items=[];$vendors=[];
    $research=[];
    foreach(($intel['rows']??[]) as $row) {
        if(is_array($row)) $research[(string)($row['source_row']??'')]=$row;
    }
    $directory=function_exists('aur_supplier_route_directory')?aur_supplier_route_directory():[];
    $cities=[];
    foreach($directory as $v) {
        if(is_array($v)&&!empty($v['email']))$cities[strtolower(trim((string)$v['email']))]=(string)($v['city']??'');
    }
    foreach(($case['payload']['sourcing_lines']??[]) as $line) {
        if(!is_array($line))continue;
        $key=(string)($line['source_row']??'');
        $description=(string)($line['description_original']??'');
        $number=(int)($line['customer_item_number']??0);
        $item=['key'=>$key,'number'=>$number,'description'=>$description,
            'quantity'=>$line['quantity']??null,
            'option'=>(string)($line['option_type']??'REQUESTED'),'vendors'=>[]];
        try{$candidates=aur_worldwide_case_candidates($intel,$key,$description);}
        catch(Throwable $e){$candidates=aur_worldwide_seed_candidates($description);}
        // Show source-backed manufacturer leads even where no contact email is available.
        foreach(($research[$key]['sources']??[]) as $source) {
            if(!is_array($source)||empty($source['company']))continue;
            $url=(string)($source['evidence_url']??'');
            $candidates[]=['name'=>(string)$source['company'],'website'=>(string)parse_url($url,PHP_URL_HOST),
                'country'=>'Not established','email'=>'','phone'=>'','evidence_url'=>$url,
                'match_basis'=>(string)($source['match_quality']??'OEM_INFORMATION'),
                'status'=>'MANUFACTURER_INFORMATION_ONLY'];
        }
        foreach($candidates as $candidate) {
            if(!is_array($candidate))continue;
            $name=trim((string)($candidate['name']??''));
            $website=strtolower(trim((string)($candidate['website']??'')));
            if($name===''&&$website==='')continue;
            $email=strtolower(trim((string)($candidate['email']??'')));
            $status=(string)($candidate['status']??'UNVERIFIED');
            $contactable=filter_var($email,FILTER_VALIDATE_EMAIL)!==false
                && in_array($status,['PUBLISHED_CONTACT_RESEARCH_CANDIDATE','SOURCE_CHECKED_EMAIL_PUBLISHED'],true);
            $id=$contactable?'mail:'.$email:'site:'.($website!==''?$website:strtolower($name));
            $country=trim((string)($candidate['country']??''));
            $city=trim((string)($candidate['city']??($cities[$email]??'')));
            $street=trim((string)($candidate['address']??''));
            $location=$street!==''?$street:trim($city.($city!==''&&$country!==''?', ':'').$country);
            if($location==='')$location='Address not established';
            if(!isset($vendors[$id])) {
                $vendors[$id]=['id'=>$id,'name'=>$name!==''?$name:$website,
                    'location'=>$location,'street_known'=>$street!=='',
                    'email'=>$contactable?$email:'','phone'=>trim((string)($candidate['phone']??'')),
                    'country'=>$country,'website'=>$website,'status'=>$status,
                    'contactable'=>$contactable,'items'=>[],'evidence'=>[]];
            } else {
                if(!$vendors[$id]['street_known']&&$street!=='') {
                    $vendors[$id]['location']=$location;$vendors[$id]['street_known']=true;
                }
                if($vendors[$id]['location']==='Address not established'&&$location!=='Address not established')
                    $vendors[$id]['location']=$location;
                if($vendors[$id]['phone']===''&&!empty($candidate['phone']))
                    $vendors[$id]['phone']=(string)$candidate['phone'];
            }
            $url=trim((string)($candidate['evidence_url']??''));
            if(preg_match('~^https?://~i',$url))$vendors[$id]['evidence'][$url]=true;
            $vendors[$id]['items'][$key]=['key'=>$key,'number'=>$number,'quantity'=>$line['quantity']??null,
                'option'=>$item['option'],'description'=>$description];
            $item['vendors'][$id]=true;
        }
        $items[]=$item;
    }
    uasort($vendors,static function($a,$b):int{
        if($a['contactable']!==$b['contactable'])return $a['contactable']?-1:1;
        return strcasecmp($a['name'],$b['name']);
    });
    return ['reference'=>(string)$case['reference'],'generated_at'=>gmdate('c'),
        'items'=>$items,'vendors'=>$vendors];
}
/* A compact built-in PDF generator, suitable for private procurement reports.
 * Courier/WinAnsi standard fonts: no install, no browser or external API.
 */
/**
 * Human-readable labels are presentation only. Never change the saved supplier
 * research statuses or the email eligibility rules.
 */
function aur_supplier_status_label(string $status):string {
    return match($status) {
        'PUBLISHED_CONTACT_RESEARCH_CANDIDATE'=>'Email found - supplier not yet qualified',
        'SOURCE_CHECKED_EMAIL_PUBLISHED'=>'Email published on checked website',
        'SOURCE_CHECKED_CONTACT_PENDING'=>'Website checked - contact details needed',
        'MANUFACTURER_INFORMATION_ONLY'=>'Manufacturer information - no email',
        'SEARCH_RESULT_NOT_SITE_VERIFIED'=>'Search lead - website not checked',
        'PUBLISHED_CONTACT'=>'Published supplier email - unverified offer',
        'DRAFT'=>'Ready for review',
        'IN_FLIGHT'=>'Sending in progress',
        'ACCEPTED_BY_MAIL_TRANSPORT'=>'Accepted by mail server',
        'TRANSPORT_FAILED_OR_UNCONFIRMED'=>'Delivery not confirmed',
        'DRAFT_REVIEW'=>'Ready for review',
        'DISPATCHING'=>'Sending',
        'TRANSPORT_ACCEPTED'=>'Accepted by mail server',
        'PARTIAL_OR_FAILED'=>'Partly sent / requires attention',
        default=>'Research lead - verification pending',
    };
}
function aur_supplier_pdf_text(string $s):string {
    // Standard PDF WinAnsi fonts cover European accents, multiplication and
    // typographic punctuation. Only unsupported subscript glyphs are reduced.
    $s=str_replace(["\r","\n","\t","₂","₃","₄","₁","₀","₅","₆","₇","₈","₉"],
        [' ',' ',' ','2','3','4','1','0','5','6','7','8','9'],$s);
    $s=preg_replace('/\s+/u',' ',$s)??$s;
    $out=@iconv('UTF-8','WINDOWS-1252//TRANSLIT//IGNORE',$s);
    return $out===false?preg_replace('/[^\x20-\x7E]/','?',$s):$out;
}
function aur_supplier_pdf_wrap(string $text,int $cols=93):array {
    $words=preg_split('/\s+/u',trim(str_replace(["\n","\r"],' ',$text)))?:[];
    $out=[];$line='';
    foreach($words as $word) {
        if($word==='')continue;
        $len=strlen(aur_supplier_pdf_text($word));
        if($line!==''&&strlen(aur_supplier_pdf_text($line))+$len+1>$cols) {
            $out[]=$line;$line='';
        }
        $line.=($line===''?'':' ').$word;
    }
    if($line!=='')$out[]=$line;
    return $out?:[''];
}
/**
 * Self-contained, styled A4 PDF; two lists, no commercial data invented.
 * Original specification appears once per item. Supplier addresses and
 * consolidated item numbers appear in the second section.
 */
/**
 * Client-forwardable procurement report:
 * serif section headings / sans-serif body, A4, source indexes and
 * consolidated supplier directory. No external PDF library required.
 */
function aur_supplier_report_pdf(array $report):string {
    $ref=(string)$report['reference'];
    $contacts=[];$makers=[];
    foreach(($report['vendors']??[]) as $id=>$v) {
        if(!is_array($v))continue;
        if(!empty($v['contactable']))$contacts[$id]=$v;
        else $makers[$id]=$v;
    }
    uasort($contacts,static fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));
    uasort($makers,static fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));
    $codes=[];
    $i=0;foreach($contacts as $id=>$v)$codes[$id]='S'.sprintf('%02d',++$i);
    $i=0;foreach($makers as $id=>$v)$codes[$id]='M'.sprintf('%02d',++$i);

    $navy=[0.098,0.149,0.192];$teal=[0.118,0.365,0.376];
    $dark=[0.153,0.192,0.227];$grey=[0.384,0.451,0.486];
    $wash=[0.961,0.969,0.969];$pale=[0.918,0.945,0.941];
    $border=[0.863,0.890,0.902];
    $pages=[];$stream='';$y=0.0;$pageNumber=0;
    $draw=static function(string $s,float $x,float $yp,float $size=8.5,string $font='F1',array $rgb=[0.153,0.192,0.227])
      use (&$stream):void {
        $txt=aur_supplier_pdf_text($s);
        $txt=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$txt);
        $stream.=sprintf("%.3f %.3f %.3f rg BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $rgb[0],$rgb[1],$rgb[2],$font,$size,$x,$yp,$txt);
    };
    $rect=static function(float $x,float $yp,float $width,float $height,array $rgb)use (&$stream):void {
        $stream.=sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0],$rgb[1],$rgb[2],$x,$yp,$width,$height);
    };
    $rule=static function(float $x,float $yp,float $end,array $rgb)use (&$stream):void {
        $stream.=sprintf("%.3f %.3f %.3f RG 0.45 w %.2f %.2f m %.2f %.2f l S\n",
            $rgb[0],$rgb[1],$rgb[2],$x,$yp,$end,$yp);
    };
    $newPage=static function()use (&$pages,&$stream,&$y,&$pageNumber,$draw,$rect,$navy):void {
        if($pageNumber>0)$pages[]=$stream;
        $pageNumber++;$stream='';$y=762;
        $rect(0,798,595.28,44,$navy);
        $draw('AURENOEVA',57,813,9.5,'F2',[1,1,1]);
        $draw('MEDICAL EQUIPMENT  /  SUPPLIER SOURCING',320,813,7.2,'F1',[1,1,1]);
    };
    $newPage();
    $ensure=static function(float $height)use (&$y,$newPage):void {
        if($y-$height<65)$newPage();
    };
    $wrap=static function(string $text,float $width=480,float $size=9):array {
        return aur_supplier_pdf_wrap($text,max(18,(int)floor($width/($size*0.51))));
    };
    $paragraph=static function(string $s,float $x=57,float $width=481,float $size=9.0,
      float $leading=13,array $rgb=[0.153,0.192,0.227])use ($wrap,$ensure,$draw,&$y):void {
        foreach($wrap($s,$width,$size) as $text) {
            $ensure($leading);
            $draw($text,$x,$y,$size,'F1',$rgb);
            $y-=$leading;
        }
    };
    $kicker=static function(string $s)use ($ensure,$draw,&$y,$teal):void {
        $ensure(19);$draw($s,57,$y,8,'F2',$teal);$y-=20;
    };
    $heading=static function(string $s,float $size=18)use ($ensure,$draw,&$y,$navy):void {
        $ensure($size+24);$draw($s,57,$y,$size,'F4',$navy);$y-=$size+16;
    };
    $small=static function(string $s,float $size=9)use ($ensure,$draw,&$y,$dark):void {
        $ensure($size+10);$draw($s,57,$y,$size,'F1',$dark);$y-=$size+11;
    };
    $equipHeader=static function()use ($ensure,$draw,$rect,$teal,$pale,&$y):void {
        $ensure(34);
        $rect(57,$y-19,481,29,$pale);
        $draw('ITEM / QTY',63,$y-9,7.2,'F2',$teal);
        $draw('ORIGINAL EQUIPMENT SPECIFICATION',124,$y-9,7.2,'F2',$teal);
        $draw('POTENTIAL SUPPLIERS',408,$y-9,7.2,'F2',$teal);
        $y-=35;
    };
    $manufacturerHeader=static function()use ($ensure,$draw,$rect,$teal,$pale,&$y):void {
        $ensure(32);$rect(57,$y-19,481,29,$pale);
        foreach([['REF.',63],['MANUFACTURER / SOURCE',107],['WEBSITE',278],['RELATED ITEMS',436]] as $pair)
            $draw($pair[0],$pair[1],$y-9,7.2,'F2',$teal);
        $y-=35;
    };

    // Cover - typography and structure match the customer reference style.
    $y-=63;
    $kicker('PROCUREMENT INTELLIGENCE  /  UZBEKISTAN');
    $heading('Medical Equipment',27);
    $heading('Supplier Sourcing Report',27);
    $paragraph('A structured overview of requested medical equipment, potential sourcing channels and supplier contact information.',57,475,10,16);
    $y-=28;
    $ensure(80);
    $rect(57,$y-62,481,65,$wash);
    $draw('CASE REFERENCE',70,$y-16,7.7,'F2',$teal);
    $draw('REPORT PREPARED',307,$y-16,7.7,'F2',$teal);
    $draw($ref,70,$y-36,10,'F2',$navy);
    $draw(gmdate('d F Y',strtotime((string)$report['generated_at'])).' UTC',307,$y-36,9,'F2',$navy);
    $y-=91;
    $rect(57,$y-80,481,82,$pale);
    foreach([
        [count($report['items']),'Equipment entries'],
        [count($contacts),'Published email contacts'],
        [count($makers),'Manufacturer / source leads']
    ] as $idx=>$data) {
        $xx=71+$idx*159;
        $draw((string)$data[0],$xx,$y-30,22,'F4',$navy);
        $draw($data[1],$xx,$y-52,7.8,'F1',$grey);
    }
    $y-=114;
    $heading('How to read this report',16);
    $paragraph('The equipment register retains the customer’s original specifications and quantities. Supplier index codes (S01, S02, etc.) link to their contact details in Section 02. Additional manufacturer and source references use the M prefix.',57,481,9.1,14);
    $y-=12;
    $paragraph('This report identifies possible sourcing channels. No company listed here has thereby confirmed stock, exact-model availability, distributor authorisation, pricing or delivery. Supplier quotations and relevant trade-compliance checks must be completed separately.',57,481,9.1,14);
    $y-=25;
    $heading('Contents',16);
    $small('01     Requested equipment and potential suppliers',9.3);
    $small('02     Supplier contact directory and combined equipment coverage',9.3);
    $small('03     Additional manufacturer references and sourcing notes',9.3);

    // Section 1: compact table; specifications appear exactly once.
    $newPage();
    $kicker('01  /  EQUIPMENT REGISTER');
    $heading('Requested equipment & potential suppliers',18);
    $paragraph('Original requested specifications are retained. Quantities marked To confirm were not supplied; alternatives are shown separately and are not additional confirmed orders.',57,480,8.8,12.5,$grey);
    $y-=17;
    $equipHeader();
    $alternatives=[];
    foreach($report['items'] as $rowIndex=>$item) {
        $no=(int)$item['number'];$isAlt=($item['option']==='ALTERNATIVE');
        if($isAlt)$alternatives[$no]=($alternatives[$no]??0)+1;
        $itemLabel=str_pad((string)$no,2,'0',STR_PAD_LEFT).($isAlt?' Alt. '.chr(64+$alternatives[$no]):'');
        $qty=$item['quantity']===null?'To confirm':(string)$item['quantity'];
        $sourceIds=[];$makerIds=[];
        foreach(array_keys($item['vendors']) as $id) {
            if(!isset($codes[$id]))continue;
            if(isset($contacts[$id]))$sourceIds[]=$codes[$id];
            else $makerIds[]=$codes[$id];
        }
        $spec=$wrap((string)$item['description'],271,8.2);
        $sup=$sourceIds?$wrap('Supplier contacts: '.implode(', ',$sourceIds),119,7.6):[];
        $makes=$makerIds?$wrap('Manufacturer leads: '.implode(', ',$makerIds),119,7.6):[];
        $h=max(2,count($spec),count($sup)+count($makes)+($sup&&$makes?1:0))*11.4+16;
        $before=$pageNumber;$ensure($h+8);
        if($pageNumber!==$before)$equipHeader();
        $top=$y;
        if($rowIndex%2===1)$rect(57,$top-$h+2,481,$h+1,$wash);
        $draw($itemLabel,64,$top-11,8.7,'F2',$dark);
        $draw('Qty: '.$qty,64,$top-25,7.7,'F1',$grey);
        foreach($spec as $lineNo=>$str)$draw($str,124,$top-11-$lineNo*11.4,8.2,'F1',$dark);
        $j=0;
        foreach($sup as $str){$draw($str,409,$top-11-$j*11.4,7.6,'F1',$grey);$j++;}
        if($sup&&$makes)$j++;
        foreach($makes as $str){$draw($str,409,$top-11-$j*11.4,7.6,'F1',$grey);$j++;}
        $rule(57,$top-$h,538,$border);
        $y=$top-$h;
    }

    // Section 2: supplier-centric directory, one card per email contact.
    $newPage();
    $kicker('02  /  SUPPLIER DIRECTORY');
    $heading('Supplier contacts & combined item coverage',18);
    $paragraph('Each contact appears once. Item numbers represent potential sourcing opportunities, not a confirmed stock offer or quotation.',57,480,8.8,13,$grey);
    $y-=18;
    foreach($contacts as $id=>$v) {
        $labels=[];
        foreach(($v['items']??[]) as $i) {
            $label='#'.(int)$i['number'].(($i['option']??'')==='ALTERNATIVE'?' alt.':'');
            if(!in_array($label,$labels,true))$labels[]=$label;
        }
        $location=$wrap('Location: '.($v['location']??'Not established'),447,8.3);
        $phone=(string)($v['phone']??'');
        $contact=$wrap('Email: '.(string)$v['email'].($phone!==''?'  |  Telephone: '.$phone:''),447,8.3);
        $website=$wrap('Website: '.(string)$v['website'],447,8.3);
        $coverage=$wrap('Potential item coverage: '.implode(', ',$labels).' ('.count($v['items']).' entries)',447,8.2);
        $h=38+(count($location)+count($contact)+count($website)+count($coverage))*11.6+15;
        $ensure($h+10);
        $top=$y;
        $rect(57,$top-$h,481,$h,$wash);
        $rect(57,$top-$h,2.5,$h,$teal);
        $draw($codes[$id].'   '.$v['name'],71,$top-20,11.8,'F4',$navy);
        $yy=$top-38;
        foreach([$location,$contact,$website,$coverage] as $part){
            foreach($part as $text){$draw($text,72,$yy,8.3,'F1',$dark);$yy-=11.6;}
            $yy-=2.3;
        }
        $y=$top-$h-12;
    }

    // Section 3: noncontact manufacturers/other sources are not presented
    // as sales leads with published recipient email.
    $newPage();
    $kicker('03  /  REFERENCE DIRECTORY');
    $heading('Additional manufacturer & source references',18);
    $paragraph('These references can assist further sourcing. They are not included as contactable suppliers because a published sales email was not established in this enquiry research.',57,480,8.8,13,$grey);
    $y-=16;
    $manufacturerHeader();
    foreach($makers as $id=>$v) {
        $labels=[];
        foreach(($v['items']??[]) as $i) {
            $label='#'.(int)$i['number'];
            if(!in_array($label,$labels,true))$labels[]=$label;
        }
        $name=$wrap((string)$v['name'],160,8.25);
        $site=$wrap((string)$v['website'],148,8.15);
        $coverage=$wrap(implode(', ',$labels),97,7.75);
        $h=max(count($name),count($site),count($coverage))*11.5+17;
        $before=$pageNumber;$ensure($h+8);
        if($pageNumber!==$before)$manufacturerHeader();
        $top=$y;
        if((int)substr($codes[$id],1)%2===0)$rect(57,$top-$h+2,481,$h+1,$wash);
        $draw($codes[$id],63,$top-11,8.3,'F2',$teal);
        foreach($name as $j=>$text)$draw($text,107,$top-11-$j*11.5,8.25,'F1',$dark);
        foreach($site as $j=>$text)$draw($text,278,$top-11-$j*11.5,8.15,'F1',$grey);
        foreach($coverage as $j=>$text)$draw($text,436,$top-11-$j*11.5,7.75,'F1',$grey);
        $rule(57,$top-$h,538,$border);$y=$top-$h;
    }
    $y-=21;
    $heading('Sourcing and quotation notes',16);
    $paragraph('This customer report reflects preliminary research recorded for the enquiry. It does not represent prices, available inventory, shipping schedules, confirmed distribution rights or a purchase commitment. RFQs and evaluation of supplier quotations are handled separately.',57,481,8.9,13);
    $y-=8;
    $paragraph('For quotation coordination and updates: info@aurenoeva.com  |  aurenoeva.com',57,481,9.0,13,$teal);
    $pages[]=$stream;
    $objects=[
      1=>'<< /Type /Catalog /Pages 2 0 R >>',
      3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
      4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
      5=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>'
    ];
    $kids=[];$objId=6;
    foreach($pages as $index=>$pageStream) {
        $pid=$objId++;$sid=$objId++;$kids[]="$pid 0 R";
        $footer=$ref.'   |   AURENOEVA  |  Page '.($index+1).' / '.count($pages);
        $safe=aur_supplier_pdf_text($footer);
        $safe=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$safe);
        $pageStream.="0.863 0.890 0.902 RG 0.5 w 57 55 m 538 55 l S\n";
        $pageStream.="0.384 0.451 0.486 rg BT /F1 7.5 Tf 57 38 Td ($safe) Tj ET\n";
        $objects[$pid]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] ".
            "/Resources << /Font << /F1 3 0 R /F2 4 0 R /F4 5 0 R >> >> /Contents $sid 0 R >>";
        $objects[$sid]="<< /Length ".strlen($pageStream)." >>\nstream\n".$pageStream."endstream";
    }
    $objects[2]='<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ',$kids).'] >>';
    ksort($objects);
    $pdf="%PDF-1.4\n";$offsets=[0=>0];
    foreach($objects as $n=>$object){$offsets[$n]=strlen($pdf);$pdf.="$n 0 obj\n$object\nendobj\n";}
    $xref=strlen($pdf);$max=max(array_keys($objects));
    $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($n=1;$n<=$max;$n++)$pdf.=sprintf('%010d 00000 n ',(int)($offsets[$n]??0))."\n";
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
/**
 * Case PDFs are retained by source fingerprint so a new crawler update
 * never destroys an earlier customer-facing report.
 */
function aur_customer_report_archive_dir(string $store,string $ref):string {
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))
        throw new InvalidArgumentException('invalid_case_reference');
    $parent=aur_private_directory($store,'supplier-reports');
    $folder=$parent.'/'.$ref;
    if(is_link($folder)||(!is_dir($folder)&&!@mkdir($folder,0700)))
        throw new RuntimeException('customer_report_archive_unavailable');
    @chmod($folder,0700);
    return $folder;
}
function aur_customer_report_archive_copy(string $current,string $archive):void {
    if(is_file($archive)&&!is_link($archive))return;
    if(!is_file($current)||is_link($current))throw new RuntimeException('report_source_missing');
    $tmp=dirname($archive).'/.archive-'.bin2hex(random_bytes(8));
    if(!@copy($current,$tmp)||!str_starts_with((string)@file_get_contents($tmp,false,null,0,5),'%PDF-')) {
        @unlink($tmp);throw new RuntimeException('report_archive_copy_failed');
    }
    @chmod($tmp,0600);
    if(!@rename($tmp,$archive)){@unlink($tmp);throw new RuntimeException('report_archive_commit_failed');}
}
function aur_supplier_report_save(string $store,array $case,array $intel):array {
    $report=aur_supplier_report_data($case,$intel);
    $ref=(string)$report['reference'];
    $folder=aur_customer_report_archive_dir($store,$ref);
    $dir=aur_private_directory($store,'supplier-reports');
    // Keep the legacy filename for previous download compatibility.
    $path="$dir/$ref-customer-supplier-report.pdf";
    $fingerprint=hash('sha256',json_encode([
        'customer-layout-serif-v4-compact',$case['source_sha256']??'',
        $report['items'],$report['vendors']
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $statePath="$dir/$ref-customer-supplier-report.sha256";
    $version=substr($fingerprint,0,16);
    $archive=$folder.'/'.$version.'.pdf';
    $lock=@fopen("$dir/.$ref-report.lock",'c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('report_lock_unavailable');
    try {
        $priorHash=is_file($statePath)?trim((string)file_get_contents($statePath)):'';
        $currentValid=is_file($path)&&!is_link($path)
            && $priorHash===$fingerprint;
        if($currentValid) {
            // Existing current PDFs are also migrated into case history.
            aur_customer_report_archive_copy($path,$archive);
        } else {
            // Preserve the last customer-visible version BEFORE replacing it.
            if(preg_match('/^[a-f0-9]{64}$/D',$priorHash)&&is_file($path)&&!is_link($path)) {
                $previousPath=$folder.'/'.substr($priorHash,0,16).'.pdf';
                aur_customer_report_archive_copy($path,$previousPath);
            }
            // Reuse a previously archived revision if research returns to
            // the same state. Never overwrite an immutable case attachment.
            if(!is_file($archive)) {
                $pdf=aur_supplier_report_pdf($report);
                if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)<1000)
                    throw new RuntimeException('invalid_customer_pdf');
                $temp=$folder.'/.new-'.bin2hex(random_bytes(8));
                if(file_put_contents($temp,$pdf,LOCK_EX)!==strlen($pdf))
                    throw new RuntimeException('report_archive_write_failed');
                @chmod($temp,0600);
                if(!rename($temp,$archive))throw new RuntimeException('report_archive_commit_failed');
            }
            if(is_link($archive))throw new RuntimeException('report_archive_invalid');
            $currentTemp=$dir.'/.current-'.bin2hex(random_bytes(8));
            if(!@copy($archive,$currentTemp)||!@rename($currentTemp,$path))
                throw new RuntimeException('report_latest_commit_failed');
            @chmod($path,0600);
            if(file_put_contents($statePath,$fingerprint."\n",LOCK_EX)!==strlen($fingerprint)+1)
                throw new RuntimeException('report_state_save_failed');
            @chmod($statePath,0600);
        }
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    return ['path'=>$archive,'report'=>$report,'version'=>$version];
}
function aur_customer_report_history(string $store,string $ref):array {
    $folder=aur_customer_report_archive_dir($store,$ref);
    $history=[];
    foreach(glob($folder.'/*.pdf')?:[] as $file) {
        if(!is_file($file)||is_link($file))continue;
        $id=substr(basename($file),0,-4);
        if(!preg_match('/^[a-f0-9]{16}$/D',$id))continue;
        $history[]=['version'=>$id,'timestamp'=>(int)(filemtime($file)?:0),
            'size'=>(int)(filesize($file)?:0)];
    }
    usort($history,static fn($a,$b)=>($b['timestamp']<=>$a['timestamp'])?:strcmp($b['version'],$a['version']));
    return array_slice($history,0,100);
}
function aur_customer_report_version_path(string $store,string $ref,string $version):string {
    if(!preg_match('/^[a-f0-9]{16}$/D',$version))
        throw new InvalidArgumentException('invalid_customer_report_version');
    $file=aur_customer_report_archive_dir($store,$ref).'/'.$version.'.pdf';
    if(!is_file($file)||is_link($file))throw new RuntimeException('customer_report_version_missing');
    return $file;
}
