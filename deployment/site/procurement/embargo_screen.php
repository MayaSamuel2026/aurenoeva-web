<?php
declare(strict_types=1);
/**
 * AURENOEVA country-level sanctions triage for a saved private RFQ case.
 *
 * This is an automatically recorded preliminary country warning, NOT a
 * sanctions-list, controlled-goods, party/ownership or end-use clearance.
 * The official sanctions resources linked below must be reviewed for each
 * actual supplier enquiry. Never silently turn absence from this watchlist
 * into a positive legal determination.
 */
const AUR_EMBARGO_RULES_VERSION = '2026-10-22/1';

function aur_embargo_countries(): array {
    // ISO 3166-1 alpha-2 options; labels from PHP intl if available.
    // Keep the short-code fallback so Hostinger need not install an extension.
    $codes = preg_split('/\s+/', trim(
        'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ '.
        'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR '.
        'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP '.
        'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS '.
        'MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS '.
        'RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW '.
        'TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'
    ));
    $known = [
        'UZ'=>'Uzbekistan','DE'=>'Germany','US'=>'United States','CA'=>'Canada',
        'GB'=>'United Kingdom','RU'=>'Russia','BY'=>'Belarus','IR'=>'Iran',
        'KP'=>'North Korea','KR'=>'South Korea','SY'=>'Syria','MM'=>'Myanmar',
        'CN'=>'China','UA'=>'Ukraine','AE'=>'United Arab Emirates',
        'CU'=>'Cuba','VE'=>'Venezuela','TW'=>'Taiwan'
    ];
    $names = [];
    foreach ($codes as $code) {
        if (isset($known[$code])) $label=$known[$code];
        elseif (class_exists('Locale')) {
            $label=\Locale::getDisplayRegion('und_'.$code, 'en');
            if ($label==='' || strtoupper($label)===$code) $label=$code;
        } else $label=$code;
        $names[$code] = $label;
    }
    // Put the destinations most frequently used by the site first.
    return $names;
}
function aur_embargo_screen_country(string $country): array {
    $country=strtoupper(trim($country));
    if (!isset(aur_embargo_countries()[$country]))
        throw new InvalidArgumentException('invalid_destination_country');
    // Targeted-risk examples, not a purported exhaustive or official embargo
    // list. Country-specific restrictions can exist for ANY destination.
    $flagged = ['BY','RU','IR','KP','MM','SY','CU','LY','SD','SS','VE','IQ','SO','LB','YE','AF','CF','CD','ZW'];
    $enhanced = in_array($country,$flagged,true);
    return [
        'schema'=>'aurenoeva-country-preflight/1',
        'rules_version'=>AUR_EMBARGO_RULES_VERSION,
        'destination_country'=>$country,
        'country_name'=>aur_embargo_countries()[$country],
        'status'=>$enhanced?'ENHANCED_COUNTRY_REVIEW':'PRELIMINARY_COUNTRY_CHECK_RECORDED',
        'enhanced_review'=>$enhanced,
        'checked_at'=>gmdate('c'),
        'source_checked_live'=>false,
        'restricted_parties_checked'=>false,
        'goods_classification_checked'=>false,
        'end_user_and_end_use_checked'=>false,
        'legal_clearance'=>false,
        'note'=>$enhanced
            ? 'Potential destination-related restrictions: review current measures before any supplier outreach.'
            : 'No flag in this limited country watchlist. This DOES NOT mean the destination or enquiry is sanctioned-party, goods or export-control cleared.',
        'official_sources'=>[
            'https://sanctionsmap.eu/',
            'https://finance.ec.europa.eu/eu-and-world/sanctions-restrictive-measures/overview-sanctions-and-related-resources_en',
            'https://www.bafa.de/EN/Foreign_Trade/Export_Control/Embargoes/embargoes_node.html'
        ]
    ];
}
/** Atomic update of the same case; original upload, hash and case reference stay untouched. */
function aur_embargo_record(string $store,array $case,string $country): array {
    $reference=(string)($case['reference']??'');
    if(!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$reference))
        throw new InvalidArgumentException('invalid_case_reference');
    $country=strtoupper(trim($country));
    $screen=aur_embargo_screen_country($country);
    $lock=@fopen($store.'/case.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('country_screen_lock_unavailable');
    try {
        $stored=aur_case_read($store,$reference);
        if(($stored['country']??'')===$country
            && ($stored['country_preflight']['rules_version']??'')===AUR_EMBARGO_RULES_VERSION
            && ($stored['country_preflight']['destination_country']??'')===$country)
            return $stored;
        $old=(string)($stored['country']??'');
        $stored['country']=$country;
        if (is_array($stored['payload']??null))
            $stored['payload']['delivery_country']=$country;
        $stored['country_preflight']=$screen;
        $stored['country_preflight_history'][]=[
            'recorded_at'=>$screen['checked_at'],
            'previous_country'=>$old,
            'new_country'=>$country,
            'status'=>$screen['status'],
            'rules_version'=>AUR_EMBARGO_RULES_VERSION
        ];
        // Constrain historical metadata; original RFQ content remains intact.
        $stored['country_preflight_history']=array_slice($stored['country_preflight_history'],-30);
        $path=$store.'/cases/'.$reference.'.json';
        $tmp=$store.'/cases/.country-'.bin2hex(random_bytes(12));
        $data=json_encode($stored,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data))
            throw new RuntimeException('country_screen_write_failed');
        @chmod($tmp,0600);
        if(!rename($tmp,$path)) {
            @unlink($tmp);
            throw new RuntimeException('country_screen_commit_failed');
        }
        $back=aur_case_read($store,$reference);
        if(($back['country_preflight']['destination_country']??'')!==$country
            || ($back['source_sha256']??'')!==($case['source_sha256']??''))
            throw new RuntimeException('country_screen_readback_failed');
        return $back;
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
}
/** Do not dispatch a supplier enquiry when the destination is flagged/unscreened. */
function aur_embargo_assert_dispatch_ready(array $case): void {
    $screen=$case['country_preflight']??null;
    if(!is_array($screen)
        || ($screen['rules_version']??'')!==AUR_EMBARGO_RULES_VERSION
        || ($screen['destination_country']??'')!==($case['country']??'')
        || !empty($screen['enhanced_review']))
        throw new InvalidArgumentException('destination_requires_sanctions_review');
    // A recorded country check is NOT full clearance: the existing manual
    // recipient, ownership, goods, end-use review must still be acknowledged.
}
