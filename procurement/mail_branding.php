<?php
declare(strict_types=1);
/**
 * Minimal email formatting: plain 11 pt Arial, optional strong emphasis, a
 * verified public logo and one fixed AURENOEVA Purchasing signature.
 * The rich text editor never supplies arbitrary outbound HTML.
 */
function aur_mailcenter_signature_plain():string {
    return "AURENOEVA Purchasing\n".
        "Operated by NOEVA Systems e.K.\n".
        "Marienburger Straße 16 | 56112 Lahnstein | Germany\n".
        "info@aurenoeva.com | https://aurenoeva.com\n".
        "Registered with Amtsgericht Koblenz | HRA 23554";
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
        'Operated by NOEVA Systems e.K.<br>'.
        'Marienburger Straße 16 | 56112 Lahnstein | Germany<br>'.
        '<a href="mailto:info@aurenoeva.com" style="color:#19493f">info@aurenoeva.com</a> | '.
        '<a href="https://aurenoeva.com" style="color:#19493f">aurenoeva.com</a><br>'.
        'Registered with Amtsgericht Koblenz | HRA 23554'.
        '</td></tr></table></body></html>';
}
