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
function aur_supplier_pdf_text(string $s):string {
    $s=str_replace(["\r","\n","\t","₂","₃","₄","₁","≤","≥"],[' ',' ',' ','2','3','4','1','<=','>='],$s);
    $s=preg_replace('/\s+/u',' ',$s)??$s;
    $out=@iconv('UTF-8','WINDOWS-1252//TRANSLIT//IGNORE',$s);
    return $out===false?preg_replace('/[^\x20-\x7E]/','?',$s):$out;
}
function aur_supplier_pdf_wrap(string $text,int $cols=98):array {
    $plain=aur_supplier_pdf_text($text);
    $words=preg_split('/\s+/',$plain)?:[];
    $output=[];$current='';
    foreach($words as $word) {
        if($word==='')continue;
        while(strlen($word)>$cols) {
            if($current!==''){$output[]=$current;$current='';}
            $output[]=substr($word,0,$cols);$word=substr($word,$cols);
        }
        if($word==='')continue;
        if(strlen($current)+strlen($word)+1>$cols){$output[]=$current;$current=$word;}
        else $current.=($current===''?'':' ').$word;
    }
    if($current!=='')$output[]=$current;
    return $output?:[''];
}
function aur_supplier_report_pdf(array $report):string {
    $pages=[];$content='';$y=780;$page=0;
    $addPage=static function()use (&$content,&$y,&$pages,&$page,$report):void {
        if($content!=='')$pages[]=$content;
        $page++;$y=784;$content='';
        $content.="0.18 0.24 0.31 rg\n";
        $title='AURENOEVA  |  SUPPLIER INTELLIGENCE  |  '.(string)$report['reference'];
        $escaped=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],aur_supplier_pdf_text($title));
        $content.="BT /F2 10 Tf 38 808 Td ($escaped) Tj ET\n";
        $content.="0.77 0.82 0.84 RG 38 796 m 574 796 l S\n";
    };
    $addPage();
    $write=static function(string $line,bool $bold=false,int $indent=0) use (&$content,&$y,$addPage):void {
        if($y<49)$addPage();
        $line=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],aur_supplier_pdf_text($line));
        $font=$bold?'F2':'F1';$x=38+$indent;
        $content.="0.13 0.18 0.23 rg BT /$font 8.15 Tf $x $y Td ($line) Tj ET\n";
        $y-=12;
    };
    $block=static function(string $text,bool $bold=false,int $indent=0) use ($write):void {
        foreach(aur_supplier_pdf_wrap($text,98-($indent>0?6:0)) as $line)$write($line,$bold,$indent);
    };
    $block('Generated: '.$report['generated_at'].' UTC  |  Confidential procurement research',true);
    $block('Evidence indicates a possible supplier lead, not confirmed availability, authorized distribution or a quotation.');
    $write('');
    $block('LIST 1  -  REQUESTED ITEMS AND POTENTIAL SUPPLIERS',true);
    foreach($report['items'] as $item) {
        $write('');
        $label='BUYER ITEM '.(int)$item['number'].' | SOURCE ROW '.$item['key'].' | QTY '.
            ($item['quantity']===null?'TO CONFIRM':(string)$item['quantity']).' | '.$item['option'];
        $block($label,true);
        $block('ORIGINAL SPECIFICATION: '.$item['description']);
        if(!$item['vendors']){$block('SUPPLIERS: none identified - research required.',false,10);continue;}
        foreach(array_keys($item['vendors']) as $id) {
            $v=$report['vendors'][$id];
            $block('SUPPLIER: '.$v['name'].' | '.$v['location'].' | EMAIL: '.
                ($v['email']!==''?$v['email']:'not published'). ' | PHONE: '.
                ($v['phone']!==''?$v['phone']:'not published'),false,10);
            $block('Website: '.$v['website'].' | Research status: '.$v['status'],false,10);
        }
    }
    $write('');
    $block('LIST 2  -  SUPPLIERS CONSOLIDATED ACROSS ALL MATCHING ITEMS',true);
    $block('One email per unique published recipient address. Unverified/no-email leads remain research-only.');
    foreach($report['vendors'] as $v) {
        $write('');
        $block($v['name'].'  |  '.count($v['items']).' distinct equipment line(s)',true);
        $block('LOCATION: '.$v['location'].' | EMAIL: '.($v['email']!==''?$v['email']:'not published')
            .' | PHONE: '.($v['phone']!==''?$v['phone']:'not published'));
        $block('WEBSITE: '.$v['website'].' | STATE: '.($v['contactable']?'PUBLISHED EMAIL - REVIEW BEFORE SEND':'RESEARCH ONLY'));
        foreach($v['items'] as $i) {
            $block('#'.(int)$i['number'].' / source row '.$i['key'].' / qty '.
                ($i['quantity']===null?'TBC':$i['quantity']).' / '.$i['option'].' / '.$i['description'],false,10);
        }
    }
    $write('');
    $block('AURENOEVA is the intermediary. Export controls, sanctions, end user and import approvals require review before sending/contracting.');
    $pages[]=$content;
    // PDF 1.4 objects and xref, with self-contained standard fonts.
    $objects=[
        1=>'<< /Type /Catalog /Pages 2 0 R >>',
        3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
        4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>'
    ];
    $kids=[];$id=5;
    foreach($pages as $i=>$stream) {
        $pid=$id++;$sid=$id++;
        $kids[]="$pid 0 R";
        $footer='PAGE '.($i+1).' OF '.count($pages).'  |  '.(string)$report['reference'];
        $f=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],aur_supplier_pdf_text($footer));
        $stream.="0.45 0.5 0.55 rg BT /F1 7 Tf 38 30 Td ($f) Tj ET\n";
        $objects[$pid]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $sid 0 R >>";
        $objects[$sid]="<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
    }
    $objects[2]='<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ',$kids).'] >>';
    ksort($objects);
    $out="%PDF-1.4\n";$offsets=[0=>0];
    foreach($objects as $n=>$object){$offsets[$n]=strlen($out);$out.="$n 0 obj\n$object\nendobj\n";}
    $start=strlen($out);$max=max(array_keys($objects));
    $out.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
    for($n=1;$n<=$max;$n++)$out.=sprintf('%010d 00000 n ',(int)($offsets[$n]??0))."\n";
    $out.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$start\n%%EOF";
    return $out;
}
function aur_supplier_report_save(string $store,array $case,array $intel):array {
    $report=aur_supplier_report_data($case,$intel);
    $ref=$report['reference'];
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))throw new InvalidArgumentException('invalid_report_case');
    $dir=aur_private_directory($store,'supplier-reports');
    $path="$dir/$ref-supplier-mapping.pdf";
    $fingerprint=hash('sha256',json_encode([$case['source_sha256']??'', $report['items'],$report['vendors']],JSON_THROW_ON_ERROR));
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
