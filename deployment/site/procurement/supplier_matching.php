<?php
declare(strict_types=1);
/**
 * Evidence-backed, read-only supplier intelligence over the existing private
 * worldwide-vendors.json and case-level research. Not a supplier CRM, quote,
 * inventory check, export clearance or dispatch authorization.
 */
const AUR_SUPPLIER_MATCHING_VERSION='2026-10-27.1';

function aur_supplier_evidence_url(mixed $value): string {
    if(!is_string($value) || strlen($value)>2048)return '';
    $url=trim($value);
    $parts=parse_url($url);
    if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
       || isset($parts['user']) || isset($parts['pass']))return '';
    $host=strtolower((string)($parts['host']??''));
    if(!preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D',$host))return '';
    return $url;
}

function aur_supplier_clean_name(mixed $value): string {
    if(!is_string($value))return '';
    return trim(substr(preg_replace('/[\x00-\x1F\x7F]+/u',' ', $value)??'',0,140));
}

/** A profile is a materialization of evidence, never an inferred authorization. */
function aur_supplier_profile(array $vendor): array {
    $name=aur_supplier_clean_name($vendor['name']??'');
    $site=strtolower(trim((string)($vendor['website']??'')));
    $country=strtoupper(trim((string)($vendor['country']??'')));
    if(!preg_match('/^[A-Z]{2}$/D',$country))$country='NOT_ESTABLISHED';
    $status=(string)($vendor['status']??'UNVERIFIED');
    $page=aur_supplier_evidence_url($vendor['evidence_url']??null);
    $brands=[];$families=[];$models=[];$proof=[];
    foreach((is_array($vendor['catalogue_products']??null)?$vendor['catalogue_products']:[]) as $product) {
        if(!is_array($product))continue;
        $url=aur_supplier_evidence_url($product['source_url']??null);
        if($url==='')continue;
        $model=aur_supplier_clean_name($product['model']??'');
        $brand=aur_supplier_clean_name($product['brand']??'');
        $family=aur_supplier_clean_name($product['category']??($product['family']??''));
        if($brand!=='')$brands[mb_strtolower($brand,'UTF-8')]=['name'=>$brand,'evidence_url'=>$url];
        if($family!=='')$families[mb_strtolower($family,'UTF-8')]=['name'=>$family,'evidence_url'=>$url];
        if($model!=='')$models[mb_strtolower($model,'UTF-8')]=['name'=>$model,'evidence_url'=>$url];
        $proof[$url]=true;
    }
    // No generic historical customer request text becomes a verified capability.
    $capabilities=[];
    foreach((is_array($vendor['supply_capabilities']??null)?$vendor['supply_capabilities']:[]) as $cap) {
        if(!is_array($cap) || ($cap['evidence_state']??'')!=='DOCUMENTED_ON_SOURCE')continue;
        $url=aur_supplier_evidence_url($cap['evidence_url']??null);
        $label=aur_supplier_clean_name($cap['name']??'');
        if($url!=='' && $label!=='')$capabilities[]=['name'=>$label,'evidence_url'=>$url];
    }
    $role='NOT_ESTABLISHED';
    $roleProof=aur_supplier_evidence_url($vendor['role_evidence_url']??null);
    if($roleProof!=='' && ($vendor['role_evidence_state']??'')==='DOCUMENTED_ON_SOURCE'
       && in_array(($vendor['declared_role']??''),['MANUFACTURER','DISTRIBUTOR','RESELLER'],true)) {
        $role=(string)$vendor['declared_role'];
    }
    $checkedAt=(string)($vendor['evidence_checked_at']??'');
    $ts=$checkedAt!==''?strtotime($checkedAt):false;
    $sourceFreshness=$ts===false?'DATE_NOT_ESTABLISHED':(time()-$ts>180*86400?'RECHECK_REQUIRED':'DATED_CHECK');
    return [
        'name'=>$name,'website'=>$site,'country'=>$country,
        'country_basis'=>str_starts_with($status,'PUBLISHED_CONTACT')?'PUBLISHED_DIRECTORY_LOCATION':'LOCATION_NOT_INDEPENDENTLY_VERIFIED',
        'supplier_role'=>$role,'supplier_role_evidence_url'=>$roleProof,
        'brands'=>array_values($brands),'product_families'=>array_values($families),
        'documented_models'=>array_values($models),
        'documented_capabilities'=>$capabilities,
        'primary_evidence_url'=>$page,'source_status'=>$status,
        'source_checked_at'=>$checkedAt,'source_freshness'=>$sourceFreshness,
        'documented_product_pages'=>count($proof),
        'authorization'=>'NOT_VERIFIED','availability'=>'NOT_VERIFIED',
        'delivery_capability'=>'NOT_VERIFIED','quotation'=>'NOT_RECEIVED'
    ];
}

function aur_supplier_rank_one(string $description,array $vendor): ?array {
    $url=aur_supplier_evidence_url($vendor['evidence_url']??null);
    if($url==='')return null; // Never rank a supplier on a fabricated/missing link.
    $status=(string)($vendor['status']??'UNVERIFIED');
    $basis=(string)($vendor['match_basis']??'');
    $profile=aur_supplier_profile($vendor);
    $score=0;$why='Unverified research lead';$tier='RESEARCH_ONLY';
    if($basis==='EXACT_PRODUCT_LISTED') {
        $score=91;$why='Published page identifies the requested product';$tier='EXACT_PRODUCT_EVIDENCE';
    } elseif($basis==='REUSED_CATALOGUE_EXACT_MODEL_REFERENCE') {
        $score=84;$why='Reused product-page model reference; configuration requires confirmation';$tier='MODEL_REFERENCE';
    } elseif($basis==='OEM_PRODUCT_MODEL') {
        $score=80;$why='Manufacturer model information';$tier='MODEL_REFERENCE';
    } elseif($basis==='OEM_PRODUCT_FAMILY') {
        $score=60;$why='Manufacturer product-family information';$tier='BRAND_OR_FAMILY';
    } elseif(str_contains($basis,'REUSED_WORLDWIDE_CATALOGUE_')) {
        $score=50;$why='Related previously researched supplier category';$tier='CATEGORY_EVIDENCE';
    } elseif($basis==='OFFICIAL_DEALER_CONTACT' || $basis==='OEM_BRAND_ROUTE') {
        $score=52;$why='Published manufacturer or brand enquiry route';$tier='BRAND_OR_FAMILY';
    } elseif($basis==='CATEGORY_ENQUIRY') {
        $score=32;$why='Published category portfolio, exact product unconfirmed';$tier='CATEGORY_EVIDENCE';
    } elseif(str_starts_with($basis,'SEARCH_RESULT_') || $status==='SEARCH_RESULT_NOT_SITE_VERIFIED') {
        $score=12;$why='Search lead not checked against supplier website';$tier='RESEARCH_ONLY';
    } elseif(str_starts_with($status,'SOURCE_CHECKED_')) {
        $score=38;$why='Supplier page checked; model and supply capability not proven';$tier='CATEGORY_EVIDENCE';
    } elseif($status==='PUBLISHED_CONTACT_RESEARCH_CANDIDATE') {
        $score=24;$why='Published contact route; requested model not proven';$tier='RESEARCH_ONLY';
    } elseif($status==='MANUFACTURER_INFORMATION_ONLY') {
        $score=30;$why='Manufacturer information, not a quotation channel';$tier='RESEARCH_ONLY';
    }
    // Product-level evidence in the existing master can improve research leads.
    // A buyer model must contain a real model token, not just a generic category.
    foreach($profile['documented_models'] as $m) {
        $model=trim((string)$m['name']);
        if(strlen($model)<4 || !preg_match('/\d/',$model))continue;
        $pattern='~(?<![\p{L}\p{N}])'.preg_quote($model,'~').'(?![\p{L}\p{N}])~iu';
        if(@preg_match($pattern,$description)===1 && $score<82) {
            $score=82;$why='Supplier catalogue records the requested model';$tier='MODEL_REFERENCE';
            $url=$m['evidence_url'];
            break;
        }
    }
    if($score===0)return null;
    $checked=$profile['source_freshness'];
    if($checked==='RECHECK_REQUIRED') {
        $score=min($score,45);
        $why.='; last recorded source check is older than 180 days';
    }
    if($status==='SEARCH_RESULT_NOT_SITE_VERIFIED') {
        $score=min($score,18);$tier='RESEARCH_ONLY';
    }
    $mail=(string)($vendor['email']??'');
    $contactable=filter_var($mail,FILTER_VALIDATE_EMAIL)!==false
        && in_array($status,['PUBLISHED_CONTACT_RESEARCH_CANDIDATE','SOURCE_CHECKED_EMAIL_PUBLISHED'],true);
    // Country is a directory location, never proof of shipping to a destination.
    $site=(string)$profile['website'];
    $key=$site!==''?$site:('lead-'.substr(hash('sha256',$profile['name'].'|'.$url),0,16));
    return [
        'supplier_key'=>$key,'name'=>$profile['name']?:$site,'country'=>$profile['country'],
        'score'=>$score,'tier'=>$tier,'reason'=>$why,'match_basis'=>$basis,
        'evidence_url'=>$url,'source_freshness'=>$checked,'contactable'=>$contactable,
        'email'=>$contactable?$mail:'',
        'profile'=>$profile,
        'availability'=>'NOT_VERIFIED','authorization'=>'NOT_VERIFIED',
        'outreach'=>'NOT_SENT'
    ];
}

/** Caller supplies only candidates already relevant to this line: no global full-master scan. */
function aur_supplier_rank_candidates(string $description,array $candidates,int $limit=8):array {
    $best=[];
    foreach($candidates as $vendor) {
        if(!is_array($vendor))continue;
        $rank=aur_supplier_rank_one($description,$vendor);
        if($rank===null)continue;
        $key=$rank['supplier_key'];
        if(!isset($best[$key]) || $rank['score']>$best[$key]['score']
           || ($rank['score']===$best[$key]['score'] && $rank['contactable'] && !$best[$key]['contactable']))$best[$key]=$rank;
    }
    $out=array_values($best);
    usort($out,static fn(array $a,array $b):int=>
        ($b['score']<=>$a['score'])?:strcasecmp($a['name'],$b['name']));
    return array_slice($out,0,max(0,min($limit,200)));
}

/** Rank suppliers across an entire RFQ without authorizing or selecting recipients. */
function aur_supplier_rank_rfq(array $rankedByLine,int $limit=10):array {
    $overall=[];$lineCount=count($rankedByLine);
    foreach($rankedByLine as $line=>$matches) {
        if(!is_array($matches))continue;
        foreach($matches as $match) {
            if(!is_array($match) || !isset($match['supplier_key'],$match['score']))continue;
            $key=(string)$match['supplier_key'];
            if(!isset($overall[$key]))$overall[$key]=[
                'supplier_key'=>$key,'name'=>(string)$match['name'],
                'country'=>(string)$match['country'],'matched_items'=>[],
                'score_total'=>0,'contactable'=>(bool)$match['contactable'],
                'best_evidence_url'=>(string)$match['evidence_url'],'best_item_score'=>0,
                'authorization'=>'NOT_VERIFIED','availability'=>'NOT_VERIFIED'];
            $overall[$key]['matched_items'][(string)$line]=(int)$match['score'];
            $overall[$key]['score_total']+=(int)$match['score'];
            if((int)$match['score']>$overall[$key]['best_item_score']){
                $overall[$key]['best_item_score']=(int)$match['score'];
                $overall[$key]['best_evidence_url']=(string)$match['evidence_url'];
            }
            $overall[$key]['contactable']=$overall[$key]['contactable']||(bool)$match['contactable'];
        }
    }
    foreach($overall as &$item) {
        $item['matched_lines']=count($item['matched_items']);
        $item['coverage']=$lineCount?round(100*$item['matched_lines']/$lineCount):0;
        $item['average_evidence_score']=$item['matched_lines']?
            (int)round($item['score_total']/$item['matched_lines']):0;
        unset($item['score_total']);
    }
    unset($item);
    $out=array_values($overall);
    usort($out,static fn(array $a,array $b):int=>
        ($b['matched_lines']<=>$a['matched_lines'])
        ?:($b['average_evidence_score']<=>$a['average_evidence_score'])
        ?:strcasecmp($a['name'],$b['name']));
    return array_slice($out,0,max(0,min($limit,100)));
}
