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
function aur_supplier_report_pdf(array $report):string {
    $pages=[];$stream='';$y=0.0;$pageNum=0;
    $ref=(string)$report['reference'];
    $ink=[0.12,0.17,0.23];$muted=[0.39,0.46,0.52];
    $teal=[0.04,0.38,0.43];$pale=[0.92,0.96,0.97];$light=[0.96,0.97,0.98];
    $draw=static function(string $str,float $x,float $pos,float $size=9.0,bool $bold=false,array $rgb=[0.12,0.17,0.23]) use (&$stream):void {
        $s=aur_supplier_pdf_text($str);
        $s=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);
        $stream.=sprintf("%.3f %.3f %.3f rg BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $rgb[0],$rgb[1],$rgb[2],$bold?'F2':'F1',$size,$x,$pos,$s);
    };
    $rect=static function(float $x,float $pos,float $w,float $h,array $rgb)use (&$stream):void {
        $stream.=sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0],$rgb[1],$rgb[2],$x,$pos,$w,$h);
    };
    $newPage=static function()use (&$pages,&$stream,&$y,&$pageNum,$rect,$draw,$teal,$muted):void {
        if($pageNum>0)$pages[]=$stream;
        $pageNum++;$stream='';$y=777.0;
        $rect(0,815,612,27,$teal);
        $draw('AURENOEVA  /  PROCUREMENT INTELLIGENCE',40,824,10,true,[1,1,1]);
        $draw('Confidential case report',428,825,7.5,false,[1,1,1]);
        $rect(40,802,532,0.7,[0.8,0.84,0.86]);
    };
    $newPage();
    $ensure=static function(float $height)use (&$y,$newPage):void {
        if($y-$height<52)$newPage();
    };
    $line=static function(string $s,float $x=44.0,float $size=9.0,bool $bold=false,?array $color=null,
        float $spacing=12.3)use (&$y,$draw,$ink,$ensure):void {
        $ensure($spacing);
        $draw($s,$x,$y,$size,$bold,$color??$ink);
        $y-=$spacing;
    };
    $para=static function(string $str,float $x=44.0,float $width=524.0,float $size=9.0,
        ?array $color=null,float $leading=12.2)use ($line,$muted):void {
        $cols=max(24,(int)floor($width/($size*0.53)));
        foreach(aur_supplier_pdf_wrap($str,$cols) as $wrapped)
            $line($wrapped,$x,$size,false,$color??$muted,$leading);
    };
    $section=static function(string $str)use (&$y,$ensure,$rect,$draw,$teal,$pale):void {
        $ensure(62);
        $y-=10;$rect(40,$y-9,532,26,$pale);
        $draw($str,49,$y,12,true,$teal);
        $y-=35;
    };
    $contactable=count(array_filter($report['vendors'],static fn($v)=>!empty($v['contactable'])));
    $leads=count($report['vendors']);
    $primary=count(array_filter($report['items'],static fn($i)=>$i['option']!=='ALTERNATIVE'));
    $alternatives=count($report['items'])-$primary;
    $line('SUPPLIER MAPPING & RFQ PREPARATION',44,18,true,$ink,27);
    $line('Case '.$ref.'  |  Destination: Uzbekistan',44,10,false,$teal,19);
    $line('Prepared '.gmdate('d M Y',strtotime((string)$report['generated_at'])).' (UTC)',44,8.3,false,$muted,18);
    $rect(40,$y-31,532,48,$light);
    $draw((string)count($report['items']),56,$y-4,17,true,$teal);
    $draw('Equipment entries',56,$y-20,8,false,$muted);
    $draw((string)$contactable,233,$y-4,17,true,$teal);
    $draw('Published email contacts',233,$y-20,8,false,$muted);
    $draw((string)$leads,440,$y-4,17,true,$teal);
    $draw('Supplier / maker leads',440,$y-20,8,false,$muted);
    $y-=61;
    $para($primary.' original equipment entries and '.$alternatives.' alternative choices. '.
          'Every candidate is a sourcing lead, not an offer, stock confirmation or approved distributor.',44,523,9,$muted,13);
    $y-=5;
    $section('01  |  Equipment-to-supplier register');
    $para('Original technical descriptions and quantities are preserved. Published emails are shown below; '.
          'full supplier contact details and combined item references are in section 02.',44,521,8.7,$muted,12);
    $y-=10;
    $altCount=[];
    foreach($report['items'] as $item) {
        $no=(int)$item['number'];$isAlt=$item['option']==='ALTERNATIVE';
        if($isAlt) $altCount[$no]=($altCount[$no]??0)+1;
        $designation='ITEM '.str_pad((string)$no,2,'0',STR_PAD_LEFT).
            ($isAlt?chr(64+$altCount[$no]):'');
        $qty=$item['quantity']===null?'To confirm':(string)$item['quantity'];
        $vendorIds=array_keys($item['vendors']);
        $rows=(int)ceil(count($vendorIds)/2);
        $headingHeight=26+max(1,count(aur_supplier_pdf_wrap($item['description'],100)))*12;
        $ensure(min(265,$headingHeight+38));
        $rect(40,$y-4,532,23,$pale);
        $draw($designation,49,$y+3,10.3,true,$teal);
        $draw($isAlt?'ALTERNATIVE':'ORIGINAL REQUEST',166,$y+3,8.2,false,$muted);
        $draw('Quantity: '.$qty,475,$y+3,8.6,true,$ink);
        $y-=30;
        $para($item['description'],49,512,8.8,$ink,12);
        $y-=4;
        $line('POTENTIAL SUPPLIER CONTACTS  |  '.count($vendorIds).' leads',49,7.7,true,$teal,13);
        if(!$vendorIds) {
            $line('No lead identified yet. Research remains open.',55,8.3,false,$muted,16);
        } else {
            $pairs=array_chunk($vendorIds,2);
            foreach($pairs as $pair) {
                $ensure(29);
                foreach($pair as $col=>$id) {
                    $v=$report['vendors'][$id];$x=49+$col*267;
                    $name=(string)$v['name'];
                    $draw($name,$x,$y,8.0,true,$ink);
                    $caption=$v['email']!==''?$v['email']:'Email not established';
                    $draw($caption,$x,$y-11,7.4,false,$v['email']!==''?$teal:$muted);
                }
                $y-=25;
            }
        }
        $y-=8;
        $rect(40,$y+3,532,0.4,[0.84,0.88,0.89]);
        $y-=8;
    }
    $newPage();
    $section('02  |  Consolidated supplier directory');
    $para('A supplier is listed once, regardless of how many equipment entries match. '.
          'The item references below map to the full original specifications in section 01. '.
          'Supplier availability, legal eligibility and exact model matching still require confirmation.',44,525,8.8,$muted,12);
    $y-=14;
    foreach($report['vendors'] as $v) {
        $items=array_values($v['items']);
        $tokens=[];
        foreach($items as $i) {
            $tokens[]='#'.(int)$i['number'].($i['option']==='ALTERNATIVE'?' (alt)':'').
                ': '.($i['quantity']===null?'qty TBC':'qty '.$i['quantity']);
        }
        $tokenString=implode('  |  ',$tokens);
        $itemLines=aur_supplier_pdf_wrap($tokenString,116);
        $height=92+count($itemLines)*11;
        $ensure(min(250,$height));
        $rect(40,$y-7,532,27,$pale);
        $draw((string)$v['name'],49,$y+2,10,true,$ink);
        $draw(count($items).' equipment entries',439,$y+2,8.0,false,$teal);
        $y-=34;
        $para('Address / location: '.(string)$v['location'],49,515,8.3,$ink,12);
        $para('Email: '.($v['email']!==''?$v['email']:'Not published').
            '    Phone: '.($v['phone']!==''?$v['phone']:'Not established'),49,515,8.3,$ink,12);
        $para('Website: '.($v['website']!==''?$v['website']:'Not established'),49,515,8.2,$muted,12);
        $para('Contact status: '.aur_supplier_status_label((string)$v['status']),49,515,8.2,$teal,12);
        $line('MATCHING ITEM NUMBERS & REQUESTED QUANTITIES',49,7.7,true,$teal,12);
        $para($tokenString,49,519,8.4,$ink,11.6);
        $y-=15;
    }
    $ensure(45);
    $line('REPORT NOTES',44,9,true,$teal,16);
    $para('Published contact details are not evidence of current stock or ability to supply any named item. '.
          'No supplier quote has been assumed. RFQ dispatch remains a separate, explicit administrator action.',44,520,8.5,$muted,12);
    $pages[]=$stream;
    // Standard PDF 1.4 / Helvetica, vector text and fills, no external libraries.
    $objects=[
        1=>'<< /Type /Catalog /Pages 2 0 R >>',
        3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'
    ];
    $kids=[];$id=5;
    foreach($pages as $n=>$s) {
        $pageId=$id++;$contentId=$id++;
        $kids[]="$pageId 0 R";
        $footer='AURENOEVA  /  '.$ref.'   |   Page '.($n+1).' of '.count($pages);
        $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],aur_supplier_pdf_text($footer));
        $s.="0.44 0.50 0.53 rg BT /F1 8 Tf 40 32 Td ($escaped) Tj ET\n";
        $objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] ".
            "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contentId 0 R >>";
        $objects[$contentId]="<< /Length ".strlen($s)." >>\nstream\n".$s."endstream";
    }
    $objects[2]='<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ',$kids).'] >>';
    ksort($objects);
    $pdf="%PDF-1.4\n";$offsets=[0=>0];
    foreach($objects as $n=>$object){$offsets[$n]=strlen($pdf);$pdf.="$n 0 obj\n$object\nendobj\n";}
    $xref=strlen($pdf);$max=max(array_keys($objects));
    $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',(int)($offsets[$i]??0))."\n";
    $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
function aur_supplier_report_save(string $store,array $case,array $intel):array {
    $report=aur_supplier_report_data($case,$intel);
    $ref=$report['reference'];
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))throw new InvalidArgumentException('invalid_report_case');
    $dir=aur_private_directory($store,'supplier-reports');
    $path="$dir/$ref-supplier-mapping.pdf";
    $fingerprint=hash('sha256',json_encode(['pdf-layout-v2', $case['source_sha256']??'', $report['items'],$report['vendors']],JSON_THROW_ON_ERROR));
    $statePath="$dir/$ref-supplier-mapping.sha256";
    if(is_file($path)&&is_file($statePath)&&trim((string)file_get_contents($statePath))===$fingerprint)
        return ['path'=>$path,'report'=>$report];
    $lock=@fopen("$dir/.$ref-report.lock",'c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('report_lock_unavailable');
    try {
        if(!is_file($path)||!is_file($statePath)||trim((string)file_get_contents($statePath))!==$fingerprint) {
            $pdf=aur_supplier_report_pdf($report);
            $tmp="$dir/.report-".bin2hex(random_bytes(8));
            if(file_put_contents($tmp,$pdf,LOCK_EX)!==strlen($pdf))throw new RuntimeException('report_pdf_write_failed');
            @chmod($tmp,0600);
            if(!rename($tmp,$path))throw new RuntimeException('report_pdf_commit_failed');
            file_put_contents($statePath,$fingerprint."\n",LOCK_EX);
            @chmod($statePath,0600);
        }
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    return ['path'=>$path,'report'=>$report];
}
