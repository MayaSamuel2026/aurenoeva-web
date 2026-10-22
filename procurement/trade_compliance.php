<?php
declare(strict_types=1);
/**
 * Pre-RFQ destination/trade screening record. NOT an export licence or
 * sanctions-list screening engine. One short authenticated review per case.
 * Saved in the canonical private Hostinger store, never in public_html.
 */
const AUR_TRADE_SOURCE_BAFA = 'https://www.bafa.de/SharedDocs/Downloads/DE/Aussenwirtschaft/afk_embargo_uebersicht_laenderbezogene_embargos.pdf';
const AUR_TRADE_SOURCE_EU = 'https://www.consilium.europa.eu/en/topics/sanctions/';
const AUR_TRADE_SOURCE_ENTITIES = 'https://data.europa.eu/data/datasets/consolidated-list-of-persons-groups-and-entities-subject-to-eu-financial-sanctions';
const AUR_TRADE_SOURCE_DILIGENCE = 'https://finance.ec.europa.eu/system/files/2023-12/guidance-eu-operators-russia-sanctions-circumvention_en.pdf';
const AUR_TRADE_SOURCE_GOODS = 'https://policy.trade.ec.europa.eu/help-exporters-and-importers/exporting-dual-use-items_en';

function aur_trade_country_assessment(array $case):array {
    $code=strtoupper(trim((string)($case['country']??'')));
    if($code==='UZ')return [
        'code'=>'UZ','name'=>'Uzbekistan',
        'finding'=>'No comprehensive EU country-wide embargo identified in the official country-regime overviews reviewed.',
        'risk'=>'Enhanced vigilance: diversion or re-export to Russia/Belarus; parties, end user, goods and applicable licensing still require checks.',
        'source_reviewed_on'=>'2026-10-22',
        'scope'=>'Country-level indication only; no automated individual/beneficial-owner or product screening',
        'flag'=>'DESTINATION_REVIEW_REQUIRED'
    ];
    return [
        'code'=>$code,'name'=>$code,
        'finding'=>'No stored country-specific assessment. Check EU/BAFA embargo sources before approving an RFQ.',
        'risk'=>'Destination restrictions, third-party sanctions and item controls have not been verified.',
        'source_reviewed_on'=>null,
        'scope'=>'Not screened automatically',
        'flag'=>'DESTINATION_REVIEW_REQUIRED'
    ];
}
function aur_trade_review_file(string $store,array $case):string {
    $ref=(string)($case['reference']??'');
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$ref))
        throw new InvalidArgumentException('invalid_trade_review_case');
    return aur_private_directory($store,'trade-screenings').'/'.$ref.'.json';
}
function aur_trade_review_read(string $store,array $case):?array {
    $path=aur_trade_review_file($store,$case);
    if(is_link($path)||!is_file($path))return null;
    $data=json_decode((string)@file_get_contents($path),true);
    if(!is_array($data)||($data['case_reference']??'')!==($case['reference']??null))
        return null;
    return $data;
}
function aur_trade_review_write(string $store,array $case,array $form):array {
    $decision=(string)($form['trade_decision']??'');
    if(!in_array($decision,['RFQ_REVIEWED','HOLD'],true))
        throw new InvalidArgumentException('select_trade_review_decision');
    $notes=trim((string)($form['trade_notes']??''));
    if(strlen($notes)>3000)throw new InvalidArgumentException('trade_review_notes_too_long');
    if(strlen($notes)<12)throw new InvalidArgumentException('explain_trade_review_in_notes');
    $checks=['country'=>'trade_country_checked','parties'=>'trade_parties_checked',
        'diversion'=>'trade_diversion_checked','items'=>'trade_items_checked',
        'scope'=>'trade_scope_acknowledged'];
    $attested=[];
    foreach($checks as $name=>$field) {
        $attested[$name]=($form[$field]??'')==='1';
        if($decision==='RFQ_REVIEWED'&&!$attested[$name])
            throw new InvalidArgumentException('complete_trade_review_checks');
    }
    $now=time();
    $current=aur_trade_review_read($store,$case);
    $history=[];
    if(is_array($current)) {
        $history=is_array($current['history']??null)?$current['history']:[];
        $summary=$current;
        unset($summary['history']);
        $history[]=$summary;
    }
    $history=array_slice($history,-25);
    $data=['version'=>'aurenoeva-pre-rfq-screen/1',
        'case_reference'=>(string)$case['reference'],
        'case_source_sha256'=>(string)$case['source_sha256'],
        'destination'=>strtoupper(trim((string)($case['country']??''))),
        'decision'=>$decision,'reviewed_at'=>gmdate('c',$now),
        'review_expires_at'=>gmdate('c',$now+7*86400),
        'reviewer'=>'AUTHENTICATED_ADMIN','notes'=>$notes,
        'checks'=>$attested,
        'country_assessment'=>aur_trade_country_assessment($case),
        'sources'=>[AUR_TRADE_SOURCE_BAFA,AUR_TRADE_SOURCE_EU,
            AUR_TRADE_SOURCE_ENTITIES,AUR_TRADE_SOURCE_DILIGENCE,
            AUR_TRADE_SOURCE_GOODS],
        'history'=>$history];
    $path=aur_trade_review_file($store,$case);
    $temp=dirname($path).'/.review-'.bin2hex(random_bytes(8));
    $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))
        throw new RuntimeException('trade_review_write_failed');
    @chmod($temp,0600);
    if(!rename($temp,$path)) {
        @unlink($temp);
        throw new RuntimeException('trade_review_commit_failed');
    }
    return $data;
}
function aur_trade_review_state(string $store,array $case):array {
    $data=aur_trade_review_read($store,$case);
    if(!is_array($data))
        return ['ready'=>false,'code'=>'NOT_REVIEWED','label'=>'Destination review required'];
    if(($data['decision']??'')==='HOLD')
        return ['ready'=>false,'code'=>'HOLD','label'=>'On hold — trade review'];
    if(($data['case_source_sha256']??'')!==($case['source_sha256']??null)
        ||($data['destination']??'')!==strtoupper((string)($case['country']??'')))
        return ['ready'=>false,'code'=>'CASE_CHANGED','label'=>'Case changed — review again'];
    if(strtotime((string)($data['review_expires_at']??''))<time())
        return ['ready'=>false,'code'=>'EXPIRED','label'=>'Review outdated — recheck'];
    if(($data['decision']??'')!=='RFQ_REVIEWED')
        return ['ready'=>false,'code'=>'NOT_REVIEWED','label'=>'Destination review required'];
    foreach(['country','parties','diversion','items','scope'] as $check) {
        if(($data['checks'][$check]??false)!==true)
            return ['ready'=>false,'code'=>'INCOMPLETE','label'=>'Destination review incomplete'];
    }
    return ['ready'=>true,'code'=>'RFQ_REVIEWED',
        'label'=>'Pre-RFQ screening recorded — not export clearance',
        'reviewed_at'=>$data['reviewed_at']];
}
function aur_trade_review_require(string $store,array $case):void {
    $state=aur_trade_review_state($store,$case);
    if(!$state['ready'])throw new RuntimeException('pre_rfq_trade_review_'.$state['code']);
}
