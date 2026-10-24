<?php
declare(strict_types=1);
/** Pure parsing / classification of incoming RFC822 messages. */
function aur_mail_headers(string $raw):array {
    $h=preg_split("/\r?\n\r?\n/",$raw,2)[0]??'';
    $h=preg_replace("/\r?\n[ \t]+/"," ",$h)??$h;
    $out=[];
    foreach(preg_split("/\r?\n/",$h)?:[] as $line)
        if(preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/',$line,$m))$out[strtolower($m[1])]=$m[2];
    return $out;
}
function aur_mail_text(string $raw,int $depth=0):string {
    if($depth>4)return '';
    $part=preg_split("/\r?\n\r?\n/",$raw,2);
    $h=aur_mail_headers($raw);$body=$part[1]??'';
    $type=(string)($h['content-type']??'text/plain');
    if(stripos($type,'multipart/')!==false
       &&preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i',$type,$m)){
        $boundary=($m[1]??'')?:($m[2]??'');
        if($boundary===''||strlen($boundary)>120)return '';
        $pieces=preg_split('/(?:^|\r?\n)--'.preg_quote($boundary,'/').'(?:--)?[ \t]*(?:\r?\n|$)/m',$body)?:[];
        $html='';
        foreach($pieces as $piece){
            $piece=ltrim($piece,"\r\n");
            if(!preg_match('/^Content-Type:/im',$piece))continue;
            $result=aur_mail_text($piece,$depth+1);
            $ph=aur_mail_headers($piece);
            if(stripos((string)($ph['content-type']??''),'text/plain')!==false&&$result!=='')return $result;
            if($html==='')$html=$result;
        }
        return $html;
    }
    if(stripos((string)($h['content-disposition']??''),'attachment')!==false)return '';
    if(!preg_match('~text/(?:plain|html)~i',$type))return '';
    $encoding=strtolower((string)($h['content-transfer-encoding']??''));
    if(str_contains($encoding,'base64'))$body=base64_decode(preg_replace('/\s+/','',$body)??'',true)?:'';
    elseif(str_contains($encoding,'quoted-printable'))$body=quoted_printable_decode($body);
    if(stripos($type,'text/html')!==false){
        $body=preg_replace('/<(?:br|\/p|\/div|\/li)\b[^>]*>/i',"\n",$body)??$body;
        $body=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
    }
    if(preg_match('/charset\s*=\s*"?([^";\s]+)/i',$type,$m)
       &&!in_array(strtolower($m[1]),['utf-8','us-ascii'],true)&&function_exists('iconv')){
        $converted=@iconv($m[1],'UTF-8//IGNORE',$body);
        if(is_string($converted))$body=$converted;
    }
    return substr(trim(str_replace("\0",'',$body)),0,80000);
}
function aur_mail_parse(string $raw):array {
    $h=aur_mail_headers($raw);$from=(string)($h['from']??'');
    preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',$from,$m);
    $subject=(string)($h['subject']??'');
    if(function_exists('iconv_mime_decode')){
        $decoded=@iconv_mime_decode($subject,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');
        if(is_string($decoded))$subject=$decoded;
    }
    return ['from'=>strtolower($m[0]??''),'subject'=>substr($subject,0,250),
        'message_id'=>substr((string)($h['message-id']??''),0,250),
        'received_at'=>substr((string)($h['date']??''),0,100),'text'=>aur_mail_text($raw)];
}
function aur_mail_classify(array $mail):array {
    $body=(string)$mail['text'];
    $isBounce=preg_match('/(?:mailer-daemon|postmaster)@/i',(string)$mail['from'])
        ||preg_match('/(?:Undelivered Mail|Delivery Status Notification|Mail delivery failed)/i',(string)$mail['subject']);
    $new=preg_split('/^\s*(?:_{8,}|-{2,}\s*Original Message\s*-{2,}|On .{5,160} wrote:|From:\s*AURENOEVA\b)/im',$body,2)[0]??$body;
    $new=trim(substr($new,0,12000));
    $status='OTHER';
    if($isBounce)$status='DELIVERY_FAILED';
    elseif(preg_match('/(?:exact (?:part|model) number|kindly provide|please (?:clarify|specify|confirm)|need (?:clarification|more information)|multiple products meet)/iu',$new))$status='CLARIFICATION_REQUIRED';
    elseif(preg_match('/(?:not available|out of stock|cannot (?:quote|supply)|unable to (?:quote|supply))/iu',$new))$status='NOT_AVAILABLE';
    elseif(preg_match('/(?:alternative|equivalent|substitut(?:e|ion))/iu',$new))$status='ALTERNATIVE_OFFERED';
    elseif(preg_match('/(?:quote|quotation|offer|price|EUR|USD|GBP|AED)/iu',$new))$status='QUOTE_RECEIVED';
    elseif(preg_match('/(?:received|acknowledge|thank you|reviewing|will revert)/iu',$new))$status='ACKNOWLEDGED';
    $items=[];
    if(!$isBounce&&preg_match_all('/\b(?:buyer\s+)?items?\s*(?:nos?\.?\s*)?#?\s*(\d{1,4})\b/iu',$new,$m))
        foreach($m[1] as $item)$items[(int)$item]=true;
    $price='';$currency='';
    if(!$isBounce&&preg_match('/\b(?:(EUR|USD|GBP|AED)\s*([0-9]{1,9}(?:[.,][0-9]{1,4})?)|([0-9]{1,9}(?:[.,][0-9]{1,4})?)\s*(EUR|USD|GBP|AED))\b/iu',$new,$p)){
        $currency=strtoupper(($p[1]??'')?:($p[4]??''));
        $price=str_replace(',','.',($p[2]??'')?:($p[3]??''));
    }
    $lead='';
    if(!$isBounce&&preg_match('/\b(?:lead\s*time|delivery\s*time|dispatch)\s*[:\-]?\s*([^\r\n]{1,90})/iu',$new,$lm))$lead=trim($lm[1]);
    return ['status'=>$status,'body'=>$new,'items'=>array_keys($items),
        'price'=>$price,'currency'=>$currency,'lead_time'=>$lead];
}
