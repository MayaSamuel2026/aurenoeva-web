<?php
declare(strict_types=1);
/**
 * Minimal email formatting: plain 11 pt Arial, optional strong emphasis, a
 * verified public logo and one fixed AURENOEVA Purchasing signature.
 * The rich text editor never supplies arbitrary outbound HTML.
 */
/**
 * Published, email-matched city mapping. NEVER infer a city from the email
 * TLD or supplier name. Add new cities only from documented company sources.
 * Official-source verification: 28 October 2026.
 */
function aur_procurement_verified_city(string $email):string {
    return match(strtolower(trim($email))) {
        'info@heinemann-ent.de'=>'Kaltenkirchen',
        'info.emea@pentaxmedical.com'=>'Hamburg',
        'info@smdmedical.ch'=>'St. Gallen',
        'sales@kombi-med.com'=>'Dortmund',
        default=>''
    };
}
/** Fixed business identity and mandatory commercial-register particulars. */
function aur_procurement_legal_signature(string $language='en'):string {
    if($language==='de') {
        return "AURENOEVA Einkauf\n".
            "NOEVA Systems e.K.\n".
            "Marienburger Straße 16\n".
            "56112 Lahnstein\n".
            "Deutschland\n\n".
            "Handelsregister\n".
            "Eingetragen beim Amtsgericht Koblenz\n".
            "HRA 23554\n\n".
            "info@aurenoeva.com | https://aurenoeva.com";
    }
    return "AURENOEVA Purchasing\n".
        "NOEVA Systems e.K.\n".
        "Marienburger Straße 16\n".
        "56112 Lahnstein\n".
        "Germany\n\n".
        "Commercial register\n".
        "Registered with Amtsgericht Koblenz\n".
        "HRA 23554\n\n".
        "info@aurenoeva.com | https://aurenoeva.com";
}
function aur_procurement_signed_closing(string $language='en',string $recipientEmail=''):string {
    if($language==='de') {
        $city=aur_procurement_verified_city($recipientEmail);
        return ($city!==''?'Herzliche Grüße nach '.$city:'Herzliche Grüße')
            ."\n\n".aur_procurement_legal_signature('de');
    }
    return "Thank you and kind regards,\n\n".aur_procurement_legal_signature('en');
}
/** The Mail Center adds only the fixed legal block, not a double greeting. */
function aur_mailcenter_signature_plain(string $language='en'):string {
    return aur_procurement_legal_signature($language);
}
function aur_mailcenter_logo_url():string {
    return 'https://aurenoeva.com/procurement/aurenoeva-logo.png';
}
function aur_mailcenter_clean_rich_text(string $html,string $plain):string {
    if($html===''||strlen($html)>150000)throw new InvalidArgumentException('invalid_mail_format');
    $allowed=['<br>'=>true,'<strong>'=>true,'</strong>'=>true];
    $clean=preg_replace_callback('/<[^>]*>/u',static function(array $hit)use($allowed):string{
        if(!isset($allowed[$hit[0]]))throw new InvalidArgumentException('invalid_mail_format');
        return $hit[0];
    },$html);
    if(!is_string($clean)||str_contains($clean,'<')&&!preg_match('/^(?:[^<]|<br>|<strong>|<\/strong>)*$/usD',$clean))
        throw new InvalidArgumentException('invalid_mail_format');
    $balance=0;
    foreach(preg_split('/(<strong>|<\/strong>)/',$clean,-1,PREG_SPLIT_DELIM_CAPTURE)?:[] as $part){
        if($part==='<strong>')$balance++;
        elseif($part==='</strong>')$balance--;
        if($balance<0||$balance>20)throw new InvalidArgumentException('invalid_mail_format');
    }
    if($balance!==0)throw new InvalidArgumentException('invalid_mail_format');
    $readable=html_entity_decode(str_replace('<br>',"\n",strip_tags(str_replace('<br>',"\n",$clean),'<br>')),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $readable=trim(str_replace(["\r\n","\r"],"\n",$readable));
    $expected=trim(str_replace(["\r\n","\r"],"\n",$plain));
    if($readable!==$expected)throw new InvalidArgumentException('mail_text_format_mismatch');
    return $clean;
}
function aur_mailcenter_html_document(string $safeRichText):string {
    $logo='cid:aurenoeva-logo@aurenoeva.com';
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'.
        '<body style="font-family:Arial,Helvetica,sans-serif;font-size:11pt;line-height:1.55;color:#202b2b;margin:0;padding:0">'.
        '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:740px;border-collapse:collapse">'.
        '<tr><td style="padding:4px 0 23px 0">'.
        '<img src="'.$logo.'" alt="AURENOEVA — Procurement · Components · Intelligence" width="215"'.
        ' style="display:block;width:215px;max-width:100%;height:auto;border:0"></td></tr>'.
        '<tr><td style="font-family:Arial,Helvetica,sans-serif;font-size:11pt;line-height:1.55;white-space:normal">'.
        $safeRichText.'</td></tr>'.
        '<tr><td style="padding-top:24px;font-family:Arial,Helvetica,sans-serif;font-size:11pt;line-height:1.55">'.
        '<strong>AURENOEVA Purchasing</strong><br>'.
        'NOEVA Systems e.K.<br>'.
        'Marienburger Straße 16<br>'.
        '56112 Lahnstein<br>'.
        'Germany<br><br>'.
        'Commercial register<br>'.
        'Registered with Amtsgericht Koblenz<br>'.
        'HRA 23554<br><br>'.
        '<a href="mailto:info@aurenoeva.com" style="color:#19493f">info@aurenoeva.com</a> | '.
        '<a href="https://aurenoeva.com" style="color:#19493f">aurenoeva.com</a><br>'.
        '</td></tr></table></body></html>';
}
