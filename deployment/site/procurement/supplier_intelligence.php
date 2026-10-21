<?php
declare(strict_types=1);
/**
 * AURENOEVA V1 evidence-first supplier identification.
 * Exact descriptions from the existing private case store, never a second CRM.
 * Public official product pages establish potential manufacturer channels only.
 * Zero vendor outreach, zero quote claims, zero sanctions approval.
 */
const AUR_SUPPLIER_RESEARCH_VERSION = '2026-10-21.1';

function aur_supplier_rules(): array {
    // Matching is case-insensitive. Specific product names precede family hints.
    // All URLs are public evidence previously checked against actual webpages.
    // "local_lead" has not been reverified as a current authorised distributor.
    return [
      ['pattern'=>'~\b(drgem|diamond classic expert)\b~iu','name'=>'DRGEM',
       'model'=>'DIAMOND (Classic Expert configuration to verify)','quality'=>'OEM_PRODUCT_FAMILY',
       'url'=>'https://www.drgemhealthcare.com/diamond/','note'=>'Official DIAMOND system; confirm exact Classic Expert variant and configuration.'],
      ['pattern'=>'~\banthos\b|a5 continental~iu','name'=>'Anthos / Cefla','model'=>'A5 Continental',
       'quality'=>'OEM_PRODUCT_MODEL','url'=>'https://www.anthos.it/en/dental-units/a5/',
       'note'=>'Manufacturer product page explicitly describes Continental version.',
       'channel'=>'https://www.anthos.it/en/contacts'],
      ['pattern'=>'~\b(samsung\s*)?z20\b.*(ultrasound|transducer)|\bsamsung z20\b~iu',
       'name'=>'Samsung Medison','model'=>'Z20','quality'=>'OEM_PRODUCT_MODEL',
       'url'=>'https://usa.samsunghealthcare.com/ultrasound/womens-health/z20',
       'note'=>'Model confirmed; local offer and configuration not yet checked.','local_company'=>'Ordamed',
       'local_url'=>'https://uzbekleasing.uz/ru/about/news/4208/'],
      ['pattern'=>'~^\s*v8\b.*(transducer|shear wave|ecg)|\bsamsung\s+v8\b~iu',
       'name'=>'Samsung Medison','model'=>'V8 (probable; confirm make)','quality'=>'PROBABLE_MODEL_FAMILY',
       'url'=>'https://usa.samsunghealthcare.com/ultrasound/womens-health',
       'note'=>'V8 appears in Samsung ultrasound range; request did not explicitly name Samsung.','local_company'=>'Ordamed','local_url'=>'https://uzbekleasing.uz/ru/about/news/4208/'],
      ['pattern'=>'~^\s*v6\b.*transducer|\bsamsung\s+v6\b~iu',
       'name'=>'Samsung Medison','model'=>'V6 (probable; confirm make)','quality'=>'PROBABLE_MODEL_FAMILY',
       'url'=>'https://usa.samsunghealthcare.com/ultrasound/womens-health',
       'note'=>'V6 appears in Samsung ultrasound range; OEM identification requires verification.','local_company'=>'Ordamed','local_url'=>'https://uzbekleasing.uz/ru/about/news/4208/'],
      ['pattern'=>'~\b(valleylab|ligasure|covidien)\b~iu','name'=>'Medtronic (Covidien)',
       'model'=>'Valleylab LS10 / LigaSure (verify exact version)','quality'=>'OEM_PRODUCT_MODEL',
       'url'=>'https://www.medtronic.com/animal-health/en-gb/products/electrosurgical-hardware/valleylab-ls10-generator.html',
       'note'=>'Public LS10 page is in an animal-health division; explicitly verify intended human medical device market/version.'],
      ['pattern'=>'~\b(pentax|vnl9.cp|cp.1000)\b~iu','name'=>'PENTAX Medical','model'=>'ENT video endoscopy family',
       'quality'=>'OEM_BRAND_ROUTE','url'=>'https://www.pentaxmedical.com/',
       'note'=>'Manufacturer channel only; exact processor/scope compatibility and model availability require confirmation.'],
      ['pattern'=>'~\b(heinemann|modula.europa)\b~iu','name'=>'Heinemann Medizintechnik','model'=>'MODULA ENT workstation',
       'quality'=>'OEM_BRAND_ROUTE','url'=>'https://www.heinemann-medizintechnik.de/',
       'note'=>'Manufacturer enquiry required to verify MODULA-Europa and any accessories.'],
      ['pattern'=>'~\bhuvitz\b.*\bhrk.1\b|\bhrk.1\b~iu','name'=>'Huvitz','model'=>'HRK-1',
       'quality'=>'OEM_PRODUCT_MODEL','url'=>'https://huvitz.com/product/hrk-1/',
       'note'=>'Official HRK-1 product page; verify local importer.'],
      ['pattern'=>'~\bhuvitz\b.*\bhnt.1\b|\bhnt.1\b~iu','name'=>'Huvitz','model'=>'HNT-1',
       'quality'=>'OEM_BRAND_ROUTE','url'=>'https://huvitz.com/',
       'note'=>'Huvitz brand indicated in customer request; confirm tonometer model.'],
      ['pattern'=>'~\b(octopus\s*600|haag.streit)\b~iu','name'=>'Haag-Streit',
       'model'=>'Octopus 600','quality'=>'OEM_PRODUCT_MODEL',
       'url'=>'https://haag-streit.com/en',
       'note'=>'Octopus 600 listed by manufacturer. Confirm current local distributor and service.'],
      ['pattern'=>'~\b(spiro.spectrum|spiro spectrum)\b~iu','name'=>'Neurosoft',
       'model'=>'Spiro-Spectrum','quality'=>'OEM_PRODUCT_MODEL',
       'url'=>'https://neurosoft.com/en/catalog/spirometers/spiro-spectrum',
       'note'=>'Exact product name listed by manufacturer; destination registration to verify.'],
      ['pattern'=>'~\bneuron.spectrum\b~iu','name'=>'Neurosoft','model'=>'Neuron-Spectrum',
       'quality'=>'OEM_PRODUCT_FAMILY','url'=>'https://neurosoft.com/en/catalog/eeg',
       'note'=>'Several Neuron-Spectrum variants exist; original specifications needed for exact selection.'],
      ['pattern'=>'~\bwato\b|\bmindray\b~iu','name'=>'Mindray','model'=>'WATO EX-35',
       'quality'=>'OEM_BRAND_ROUTE','url'=>'https://www.mindray.com/',
       'note'=>'Confirm human clinical WATO EX-35 model: Uzbekistan Intermed listing found is for WATO EX-35 VET and must NOT be treated as equivalent human-use supply.'],
      ['pattern'=>'~\bdr[aä]ger\b|\bsavina\b~iu','name'=>'Dräger','model'=>'Savina (legacy)',
       'quality'=>'OEM_END_OF_SALES','url'=>'https://www.draeger.com/en_me/Products/Savina',
       'note'=>'Official Dräger page marks this Savina model END OF SALES. Refurbished unit requires service/parts/history confirmation.'],
      ['pattern'=>'~\b(skema\s*6)\b~iu','name'=>'Castellini / Cefla','model'=>'Skema 6',
       'quality'=>'OEM_PRODUCT_MODEL','url'=>'https://www.castellini.com/en/dental-units/skema-6',
       'note'=>'Official manufacturer model; validate SD-9099 variant and local installation.'],
      ['pattern'=>'~\b(skema\s*8)\b~iu','name'=>'Castellini / Cefla','model'=>'Skema 8',
       'quality'=>'OEM_PRODUCT_MODEL','url'=>'https://www.castellini.com/en/dental-units/skema-8',
       'note'=>'Official model; validate exact SD-9056 configuration.'],
      ['pattern'=>'~\b(euronda|e8\s*24|euroseal|aquadist)\b~iu',
       'name'=>'Euronda','model'=>'E8 / Euroseal equipment set',
       'quality'=>'OEM_BRAND_ROUTE','url'=>'https://www.euronda.com/',
       'note'=>'Check separate autoclave, sealing and distilled water components and device generation.'],
      ['pattern'=>'~\b(cattani|three.cylinder|3.cylinder)\b~iu','name'=>'CATTANI',
       'model'=>'3-cylinder 45 L compressor (spec verification)',
       'quality'=>'OEM_PRODUCT_FAMILY','url'=>'https://www.cattani.de/57_3-Zylinder.php',
       'note'=>'Manufacturer representation lists 45 L 3-cylinder models, not yet a seller quote.'],
      ['pattern'=>'~\b(vatech|cone.beam|cephalostat)\b~iu','name'=>'Vatech',
       'model'=>'Green X 16 (candidate, 16×9 FOV + CEPH)',
       'quality'=>'CATEGORY_ALTERNATIVE_NOT_EXACT',
       'url'=>'https://www.vatecheurope.com/products/green-x-16/',
       'note'=>'Only a candidate for unspecified Vatech 16×9 CBCT; user must approve an alternative model.'],
      ['pattern'=>'~\b(derma|vistascan|d[üu]rr)\b~iu','name'=>'DÜRR DENTAL',
       'model'=>'VistaScan imaging family','quality'=>'OEM_BRAND_ROUTE',
       'url'=>'https://www.duerrdental.com/en/AE/',
       'note'=>'Product family contact route; check actual device model.'],
      ['pattern'=>'~\b(mediworks|s260s)\b~iu','name'=>'MediWorks',
       'model'=>'S260S slit lamp','quality'=>'OEM_BRAND_ROUTE',
       'url'=>'https://www.mediworks.biz/',
       'note'=>'Manufacturer named in request; independently confirm current S260S sales/contact.'],
      ['pattern'=>'~\b(erbe|vio\s*3)\b~iu','name'=>'Erbe Elektromedizin',
       'model'=>'VIO 3','quality'=>'OEM_PRODUCT_MODEL',
       'url'=>'https://vio.erbegroup.com/',
       'note'=>'Official Erbe VIO 3 system; check RFQ model and market version.'],
      ['pattern'=>'~\b(getinge|steris)\b~iu','name'=>'Sterilization manufacturer (verify)',
       'model'=>'Sterilization category','quality'=>'CATEGORY_ONLY',
       'url'=>'https://www.getinge.com/','note'=>'Broad sterilization equipment route; no exact model or supplier match.'],
    ];
}
function aur_supplier_search_url(string $description,string $country): string {
    // Human-initiated web research; generating a link does not count as crawl evidence.
    $text=preg_replace('/[\x00-\x1F\x7F]/u',' ',mb_substr($description,0,160));
    return 'https://www.google.com/search?'.http_build_query(
        ['q'=>trim($text).' manufacturer distributor medical equipment Uzbekistan '.$country],
        '', '&', PHP_QUERY_RFC3986);
}
function aur_supplier_match(string $description): ?array {
    foreach (aur_supplier_rules() as $rule) {
        if (@preg_match($rule['pattern'],$description)===1) return $rule;
    }
    return null;
}
function aur_supplier_preview(array $case): array {
    if (!isset($case['reference'],$case['payload']['sourcing_lines'])
        || !is_array($case['payload']['sourcing_lines'])) {
        throw new InvalidArgumentException('unsupported_case_shape');
    }
    $rows=[];$found=0;$local=0;
    foreach ($case['payload']['sourcing_lines'] as $line) {
        $description=(string)($line['description_original']??'');
        $country=(string)($case['country']??'');
        $match=aur_supplier_match($description);
        $sources=[];
        $status='IDENTITY_RESEARCH_REQUIRED';
        if ($match!==null) {
            $found++;
            $sources[]=[
                'company'=>$match['name'],'role'=>'OEM_OR_PRODUCT_INFORMATION',
                'model'=>$match['model'],'match_quality'=>$match['quality'],
                'evidence_url'=>$match['url'],'note'=>$match['note'],
                'availability'=>'NOT_CHECKED','authorization'=>'NOT_CHECKED',
                'quotation'=>'NOT_RECEIVED','outreach'=>'NOT_SENT',
            ];
            if (!empty($match['channel'])) $sources[]= [
                'company'=>$match['name'],'role'=>'MANUFACTURER_DISTRIBUTOR_ENQUIRY_ROUTE',
                'evidence_url'=>$match['channel'],
                'match_quality'=>'OFFICIAL_DEALER_CONTACT','availability'=>'NOT_CHECKED',
                'authorization'=>'NOT_CHECKED','quotation'=>'NOT_RECEIVED','outreach'=>'NOT_SENT',
            ];
            if ($country==='UZ' && !empty($match['local_url'])) {
                $local++;
                $sources[]=[
                    'company'=>$match['local_company'],'role'=>'HISTORICAL_UZ_DISTRIBUTOR_LEAD',
                    'evidence_url'=>$match['local_url'],'match_quality'=>'HISTORICAL_THIRD_PARTY_EVIDENCE',
                    'note'=>'Historical local distributor statement; not reverified as authorized today.',
                    'availability'=>'NOT_CHECKED','authorization'=>'NOT_CHECKED',
                    'quotation'=>'NOT_RECEIVED','outreach'=>'NOT_SENT',
                ];
            }
            $status='OEM_OR_CHANNEL_IDENTIFIED_UNVERIFIED_SUPPLY';
        }
        $rows[]=[
            'source_row'=>(int)($line['source_row']??0),
            'customer_item_number'=>(int)($line['customer_item_number']??0),
            'option_type'=>(string)($line['option_type']??''),
            'description'=>$description,
            'status'=>$status,
            'sources'=>$sources,
            'search_url'=>aur_supplier_search_url($description,$country),
        ];
    }
    return [
        'schema'=>'aurenoeva-supplier-research/1.0',
        'case_reference'=>(string)$case['reference'],
        'case_source_sha256'=>(string)($case['source_sha256']??''),
        'rule_version'=>AUR_SUPPLIER_RESEARCH_VERSION,
        'compiled_at'=>gmdate('c'),
        'summary'=>[
            'equipment_entries'=>count($rows),
            'entries_with_oem_route'=>$found,
            'entries_needing_identity_research'=>count($rows)-$found,
            'historical_local_leads'=>$local,
            'supplier_quotes_confirmed'=>0,'supplier_availability_confirmed'=>0,
            'supplier_authorization_confirmed'=>0,
        ],
        'legal_review_required'=>true,
        'outreach_sent'=>false,
        'rows'=>$rows,
    ];
}
function aur_supplier_private_file(string $store,string $reference): string {
    if (!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$reference)) {
        throw new InvalidArgumentException('invalid_case_reference');
    }
    return aur_private_directory($store,'supplier-intelligence').'/'.$reference.'.json';
}
function aur_supplier_save(string $store,array $case): array {
    $result=aur_supplier_preview($case);
    $target=aur_supplier_private_file($store,(string)$case['reference']);
    $dir=dirname($target);
    $lock=@fopen($dir.'/.research.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('supplier_research_lock_unavailable');
    try {
        $prior=is_file($target)?json_decode((string)file_get_contents($target),true):null;
        if (is_array($prior) && ($prior['case_source_sha256']??'')===$result['case_source_sha256']
            && ($prior['rule_version']??'')===AUR_SUPPLIER_RESEARCH_VERSION) {
            return $prior;
        }
        $tmp=$dir.'/.new-'.bin2hex(random_bytes(10));
        $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)) throw new RuntimeException('supplier_research_save_failed');
        @chmod($tmp,0600);
        if (!@rename($tmp,$target)) throw new RuntimeException('supplier_research_commit_failed');
        return aur_supplier_read($store,(string)$case['reference'],(string)$case['source_sha256']);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function aur_supplier_read(string $store,string $reference,string $expectedSha): ?array {
    $file=aur_supplier_private_file($store,$reference);
    if (!is_file($file) || is_link($file)) return null;
    $data=json_decode((string)file_get_contents($file),true,128,JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['case_reference']??'')!==$reference
        || ($data['case_source_sha256']??'')!==$expectedSha) {
        throw new RuntimeException('supplier_intelligence_case_mismatch');
    }
    return $data;
}
