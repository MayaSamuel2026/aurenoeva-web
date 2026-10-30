<?php
declare(strict_types=1);
/**
 * AURENOEVA actionable, evidence-linked Uzbekistan sourcing routes.
 * No external API, no crawler dependency, no additional database, no RFQ sent.
 * Sources are supplier-owned public product/portfolio and contact pages.
 * A category route is never represented as confirmation of the requested SKU.
 */
const AUR_SUPPLIER_ROUTE_VERSION='2026-10-28.2-urgent-rfq-exact-and-public-price';

function aur_supplier_route_directory(): array {
    return [
      'intermed'=>[
        'name'=>'Intermed Innovation','country'=>'UZ','city'=>'Tashkent',
        'email'=>'info@imed.uz','phone'=>'+998 78 150-47-47',
        'url'=>'https://imed.uz/en/equipment',
        'scope'=>'Medical equipment and anesthesia; EXACT human-use Mindray WATO EX-35 product listing.',
        'exact_url'=>'https://imed.uz/en/products/95-mindray-wato-ex-35/',
      ],
      'diatech'=>[
        'name'=>'Diatech S.A. Uzbekistan','country'=>'UZ','city'=>'Tashkent',
        'email'=>'info.uz@diatech-sa.com','phone'=>'+998 95 302-00-08',
        'url'=>'https://diatech.uz/',
        'scope'=>'Diagnostic imaging, MRI, radiology, ultrasound and flexible endoscopy; exact requested brands/models not established.',
      ],
      'medsyst'=>[
        'name'=>'MEDSYST (MST)','country'=>'UZ','city'=>'Tashkent',
        'email'=>'yes@medsyst.uz','phone'=>'+998 78 555-74-63',
        'url'=>'https://medsyst.uz/contacts/toshkent/',
        'scope'=>'Medical diagnostic technology, imaging and clinical systems; model-specific sourcing to confirm.',
      ],
      'dentaltrading'=>[
        'name'=>'Dental Trading Uzbekistan','country'=>'UZ','city'=>'Tashkent',
        'email'=>'info@dentaltrading.uz','phone'=>'+998 77 282 08 00',
        'url'=>'https://dentaltrading.uz/en/brands',
        'scope'=>'Dental equipment and infection control; Euronda brand on portfolio, not proof of exact E8 stock.',
      ],
      'abform'=>[
        'name'=>'AB FORM SISTEM','country'=>'UZ','city'=>'Tashkent',
        'email'=>'abform@abform.uz','phone'=>'+998 90 185-55-73',
        'url'=>'https://abform.uz/en/sterilizaczionnoe-oborudovanie/',
        'scope'=>'Sterilization, disinfection, medical diagnostic and rehabilitation equipment; specific models require request.',
      ],
      'meditech'=>[
        'name'=>'MediTech Uzbekistan','country'=>'UZ','city'=>'Tashkent',
        'email'=>'info@meditech.uz','phone'=>'+998 90 925-88-22',
        'url'=>'https://meditech.uz/product-category/diagnostics-en/?lang=en',
        'scope'=>'Ophthalmic diagnostic equipment including autorefractors, tonometers and slit lamps; exact Huvitz/Haag-Streit model unverified.',
      ],
      'skymed'=>[
        'name'=>'SkyMed Technologies','country'=>'UZ','city'=>'Tashkent',
        'email'=>'skymedtechnologiesllc@gmail.com','phone'=>'+998 90 820-55-11',
        'url'=>'https://en.skymed.uz/ophtalmology',
        'scope'=>'Published ophthalmology equipment portfolio includes slit lamps, autorefractometers, tonometers and perimeters; exact requested models unverified.',
      ],
      'medsc'=>[
        'name'=>'Med Service Centre','country'=>'UZ','city'=>'Tashkent',
        'email'=>'info@medsc.uz','phone'=>'+998 94 444 48 85',
        'url'=>'https://medsc.uz/catalog/manufacturer/pentax-medical',
        'scope'=>'Uzbekistan medical-equipment integrator with published PENTAX Medical endoscopy listing, operating-room and sterilization portfolios; exact CP-1000/VNL9-CP not confirmed.',
      ],
      'deltamax'=>[
        'name'=>'DeltaMaxGroup','country'=>'UZ','city'=>'Tashkent',
        'email'=>'info@deltamaxgroup.uz','phone'=>'+998 94 867 60 27',
        'url'=>'https://deltamax.uz/en/about',
        'scope'=>'Hospital installations, anesthesia, ICU and operating room equipment; exact requested models not established.',
      ],
    ];
}
/**
 * International source-backed outreach directory, 21 Oct 2026.
 * Published official contact and category/exact-model source links ONLY.
 * Country describes vendor location, never stock or permitted export route.
 * Relevance is an invitation to quote, not stock or model verification.
 */
function aur_supplier_international_contacts(string $description):array {
    $items=[
      'mindray-global'=>[
        'name'=>'Mindray Global (OEM)','country'=>'CN','email'=>'intl-market@mindray.com',
        'phone'=>'+86 755 8188 8997','url'=>'https://www.mindray-usa.com/en/contact',
        'scope'=>'WATO EX-35 manufacturer sales channel','pattern'=>'/\b(?:mindray|wato\s*ex.?35)\b/ui',
        'grade'=>'OFFICIAL_MANUFACTURER_CONTACT'],
      'mindray-uk'=>[
        'name'=>'Mindray UK (OEM)','country'=>'GB','email'=>'ukcustomerservice@mindray.com',
        'phone'=>'+44 1480 416840','url'=>'https://www.mindray.com/uk/products/anesthesia/wato-ex-35',
        'scope'=>'WATO EX-35 manufacturer market contact','pattern'=>'/\b(?:mindray|wato\s*ex.?35)\b/ui',
        'grade'=>'OFFICIAL_MANUFACTURER_CONTACT'],
      'soma-us'=>[
        'name'=>'Soma Technology (US)','country'=>'US','email'=>'Soma@SomaTechnology.com',
        'phone'=>'+1 800 438 7662',
        'url'=>'https://www.somatechnology.com/anesthesia-machines/mindray-wato-ex-35/',
        'scope'=>'Published refurbished WATO EX-35 listing; current stock not verified',
        'pattern'=>'/\b(?:mindray|wato\s*ex.?35)\b/ui',
        'grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'kombi-de'=>[
        'name'=>'KombiMED GmbH','country'=>'DE','email'=>'sales@kombi-med.com',
        'phone'=>'','url'=>'https://www.kombi-med.com/customer',
        'scope'=>'German hospital and laboratory equipment; Central Asian procurement enquiry',
        'pattern'=>'/.+/us',
        'grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'hce-uk'=>[
        'name'=>'HCE Export Medical Equipment','country'=>'GB','email'=>'sales@hce-uk.com',
        'phone'=>'+44 1784 220 400','url'=>'https://www.hce-uk.com/contact/export-enquiries/',
        'scope'=>'UK medical equipment international export enquiries; specific models not confirmed',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'europ-fr'=>[
        'name'=>'EuropExport','country'=>'FR','email'=>'info@europexport.com',
        'phone'=>'+33 6 09 08 69 81','url'=>'https://www.europexport.com/',
        'scope'=>'French medical equipment sourcing and international trade',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'rensino-cn'=>[
        'name'=>'Rensino Medical Equipment','country'=>'CN',
        'email'=>'export@medical-equipment.com.cn','phone'=>'+86 19195531707',
        'url'=>'https://www.medical-equipment.com.cn/contact-us/',
        'scope'=>'Chinese medical/dental/anesthesia equipment exporter; exact model not established',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'jme-jp'=>[
        'name'=>'Japan Medical Equipment','country'=>'JP',
        'email'=>'info@japanmedicalequipment.com','phone'=>'',
        'url'=>'https://www.japanmedicalequipment.com/',
        'scope'=>'Japanese used medical equipment exporter; model and availability unverified',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'medworld-ca'=>[
        'name'=>'Med World Equipment','country'=>'CA','email'=>'Sales@medworld.ca',
        'phone'=>'+1 416 666 6006','url'=>'https://medworld.ca/contact/',
        'scope'=>'Canadian used/refurbished medical equipment exporter; category inquiry only',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'humanova-uae'=>[
        'name'=>'HumaNova (UAE)','country'=>'AE','email'=>'info@humanova.ae',
        'phone'=>'+971 58 525 3411','url'=>'https://www.humanova.ae/export',
        'scope'=>'Dubai medical equipment exporter listing Uzbekistan among markets; exact model not confirmed',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      'novosic-uae'=>[
        'name'=>'NOVOSIC Trading FZCO','country'=>'AE','email'=>'info@novosic.com',
        'phone'=>'+971 50 313 7086','url'=>'https://www.novosic.com/contact/',
        'scope'=>'Dubai multi-brand medical equipment export sourcing; exact model not verified',
        'pattern'=>'/.+/us','grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
      // Urgent follow-on for Uzbekistan buyer RFQ AUR-UZ-1A04A7909185FACD.
      // Primary supplier-owned product & contact evidence checked 28 Oct 2026.
      // Existing original recipients are deliberately NOT duplicated here.
      // A matching catalogue page is NOT proof of stock, export rights, or a quote.
      'drgem-global-kr'=>[
        'name'=>'DRGEM Global Sales (manufacturer)','country'=>'KR',
        'email'=>'sales@drgemhealthcare.com','phone'=>'+82 70 8230 8423',
        'url'=>'https://www.drgemhealthcare.com/diamond/',
        'scope'=>'Buyer #1 DRGEM DIAMOND; exact Classic Expert 52kW configuration needs confirmation. Official DRGEM product and sales source.',
        'pattern'=>'/\b(?:DRGEM|DIAMOND)\b/ui','grade'=>'OFFICIAL_MANUFACTURER_CONTACT'],
      'medicinos-lt'=>[
        'name'=>'UAB Medicinos iranga','country'=>'LT',
        'email'=>'info@medicinosiranga.com','phone'=>'+370 614 24164',
        'url'=>'https://medicinosiranga.com/en/produkto-kategorija/ultrasound-equipment/',
        'scope'=>'Buyer #3 Samsung Z20, #4 Samsung V8 and #5 Samsung V6 separately listed. Exact probes/software/options must be checked.',
        'pattern'=>'/\b(?:Z20|V8|V6)\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'smdmedical-ch'=>[
        'name'=>'SMD Medical AG','country'=>'CH',
        'email'=>'info@smdmedical.ch','phone'=>'+41 71 666 78 78',
        'url'=>'https://smdmedical.ch/en/products/samsung-hera-z20',
        'scope'=>'Buyer #3 Samsung HERA Z20 model shown on product page; required probe and option bundle unverified.',
        'pattern'=>'/\b(?:HERA\s+)?Z20\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'opptica-lv'=>[
        'name'=>'Opptica / OC VISION','country'=>'LV',
        'email'=>'opptica@ocvision.eu','phone'=>'+371 26011632',
        'url'=>'https://opptica.eu/en/huvitz/',
        'scope'=>'Buyer #19 Huvitz HRK-1 and #20 Huvitz HNT-1/1P listed; exact requested tonometer suffix and export rights to confirm.',
        'pattern'=>'/\b(?:HRK[\s.-]*1|HNT[\s.-]*1)\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'uandi-nl'=>[
        'name'=>'U & I Optic Instrument','country'=>'NL',
        'email'=>'info@uandiopticinstrument.com','phone'=>'+31 6 4082 1781',
        'url'=>'https://www.uandiopticinstrument.com/listings/8726074-demo-2018-haag-streit-octopus-600',
        'scope'=>'Buyer #21 Haag-Streit Octopus 600 direct seller listing (2018 demo); Basic specification and updated stock/price to confirm.',
        'pattern'=>'/\bOctopus\s*600\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'mandarin-sg'=>[
        'name'=>'Mandarin Opto-Medic','country'=>'SG',
        'email'=>'inquiry@mandarinoptomedic.com','phone'=>'+65 6747 8777',
        'url'=>'https://www.mandarinoptomedic.com/products/p/haag-streit-octopus-600',
        'scope'=>'Buyer #21 Haag-Streit Octopus 600 on company-owned product page; Basic configuration needs confirmation.',
        'pattern'=>'/\bOctopus\s*600\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'haag-uk'=>[
        'name'=>'Haag-Streit UK (manufacturer channel)','country'=>'GB',
        'email'=>'info.unitedkingdom@haag-streit.com','phone'=>'+44 1279 883720',
        'url'=>'https://uk.haag-streit.com/products/categories/speciality-diagnostics/perimetry/octopus-600',
        'scope'=>'Buyer #21 Octopus 600, official manufacturer country channel; request appropriate Uzbekistan distributor.',
        'pattern'=>'/\bOctopus\s*600\b/ui','grade'=>'OFFICIAL_MANUFACTURER_CONTACT'],
      'bhmedical-ae'=>[
        'name'=>'BH Medical Equipment','country'=>'AE',
        'email'=>'sales@bhmedicalequip.com','phone'=>'+971 58 294 1644',
        'url'=>'https://bhmedicalequip.com/products/drager-savina-ventilator-3',
        'scope'=>'Buyer #27 Drager Savina refurbished listed by Sharjah trader; requested 2010-2013 manufacture year not verified.',
        'pattern'=>'/\bSavina\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'phase-us'=>[
        'name'=>'Phase Medical Partners','country'=>'US',
        'email'=>'sales@phasemedicalpartners.com','phone'=>'+1 602 842 5433',
        'url'=>'https://www.phasemedicalpartners.com/products/mindray-wato-ex-35-anesthesia-machine/',
        'scope'=>'Buyer #26 Mindray WATO EX-35 quote-only listing. 2 units and all 3-gas/AGSS/vaporizer options require check; no manufacturer authorization assumed.',
        'pattern'=>'/\bWATO[\s.-]*EX[\s.-]*35\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'sakomed-us'=>[
        'name'=>'SakoMed Biomedical Services','country'=>'US',
        'email'=>'info@sakomed.com','phone'=>'+1 844 433 7256',
        'url'=>'https://sakomed.com/products/electrosurgical-units/covidien-valleylab-ls10-vessel-sealing-generator/',
        'scope'=>'Buyer #11 Valleylab LS10 VLLS10GEN listed; new/refurbished, 240V, original accessories and export terms to check.',
        'pattern'=>'/\b(?:VLLS10GEN|LigaSure|Valleylab|LS10)\b/ui','grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'heinemann-de'=>[
        'name'=>'Heinemann HNO Medizintechnik GmbH (manufacturer)','country'=>'DE',
        'email'=>'info@heinemann-ent.de','phone'=>'+49 4191 95379 0',
        'url'=>'https://www.heinemann-ent.de/en/behandlungseinheiten/modula-europa/',
        'scope'=>'Buyer #16 MODULA Europa ENT unit official producer; 0100-30000 configuration, electric chair and endoscopy options to confirm.',
        'pattern'=>'/\b(?:Heinemann|MODULA[\s.-]*Europa)\b/ui','grade'=>'OFFICIAL_MANUFACTURER_CONTACT'],
      'pentax-emea-de'=>[
        'name'=>'PENTAX Medical EMEA (manufacturer channel)','country'=>'DE',
        'email'=>'info.emea@pentaxmedical.com','phone'=>'+49 40 56192 0',
        'url'=>'https://events-fr.pentaxmedical.com/jfhod-2026',
        'scope'=>'Buyer #14 PENTAX VIVIDEO CP-1000 / VNL9-CP ENT system; exact product registration documented, ask EMEA for authorized Uzbekistan channel.',
        'pattern'=>'/\b(?:PENTAX|CP[\s.-]*1000|VNL9[\s.-]*CP)\b/ui','grade'=>'OFFICIAL_MANUFACTURER_CONTACT'],
      // Additional Europe exact-model leads (28 Oct 2026). Public retail
      // prices are benchmarks, never confirmed Uzbekistan export offers.
      'eggert-de'=>[
        'name'=>'dental EGGERT GmbH','country'=>'DE',
        'email'=>'anfrage@dental-eggert.de','phone'=>'+49 741 17400 0',
        'url'=>'https://www.praxiseinrichtung-zahnarzt.de/Behandlungseinheiten/',
        'scope'=>'Buyer #2 Anthos A5 dental unit officially listed by dealer; exact Continental over-patient configuration and export rights to confirm.',
        'pattern'=>'/\b(?:Anthos|A5\s+Continental)\b/ui',
        'grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'duomed-de'=>[
        'name'=>'Duo Med GmbH','country'=>'DE',
        'email'=>'info@duo-med.de','phone'=>'+49 8851 9401896',
        'url'=>'https://www.duo-med.com/c/behandlungseinheiten',
        'scope'=>'Buyer #32 SKEMA 6 and #33 SKEMA 8 product pages published by authorized Castellini dealer. Listed starting prices are not export quotes; product codes/options to confirm.',
        'pattern'=>'/\b(?:Skema[\s.-]*(?:6|8)|SD[\s.-]*9099|6X[\s.-]*9056)\b/ui',
        'grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'mediadental-de'=>[
        'name'=>'mediaDental','country'=>'DE',
        'email'=>'info@media-dental.de','phone'=>'+49 511 64214741',
        'url'=>'https://www.media-dental.de/cattani-3-zylinder-kompressor-MD027-013330',
        'scope'=>'Buyer #36 Cattani 3-cylinder dental compressor 45L and 238L/min at 5 bar. German retail item listing; 230V/50Hz, dryer, price and Uzbekistan shipment to confirm.',
        'pattern'=>'/\b(?:CATTANI|3[\s.-]*[Zz]ylinder|238\s*(?:Nl|L)\/min|45\s*(?:L|litre))\b/ui',
        'grade'=>'EXACT_PRODUCT_LISTED_UNVERIFIED_AVAILABILITY'],
      'termedis-pl'=>[
        'name'=>'TerMedis','country'=>'PL','email'=>'export@termedis.pl',
        'phone'=>'+48 603 398 840','url'=>'https://www.termedis.pl/export',
        'scope'=>'Polish rehabilitation, oxygen and medical equipment; model unverified',
        'pattern'=>'/rehab|therapy|physiother|oxygen|concentrator|respirat|ventilat/ui',
        'grade'=>'GLOBAL_CATEGORY_ENQUIRY_UNVERIFIED_MODEL'],
    ];
    $routes=[];
    foreach($items as $id=>$vendor) {
        if(preg_match($vendor['pattern'],$description)!==1)continue;
        unset($vendor['pattern']);
        $vendor['id']='global-'.$id;
        $routes[]=$vendor;
    }
    return $routes;
}
function aur_supplier_contact_routes(string $description): array {
    $routes=[];
    // Product-level evidence, not an assumption of stock.
    if (preg_match('/\bwato\s*ex.?35\b/ui',$description)) {
        $routes[]=['id'=>'intermed','grade'=>'EXACT_PRODUCT_LISTED',
            'reason'=>'Human medical Mindray WATO EX-35 appears on the supplier product page; confirm full required configuration.'];
        $routes[]=['id'=>'deltamax','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local anesthesia-equipment supply/installation portfolio.'];
    } elseif (preg_match('/anthos|dental|skema|cattani|vatech|cone.beam|euronda|euroseal|aquadis/ui',$description)) {
        $routes[]=['id'=>'dentaltrading','grade'=>'CATEGORY_ENQUIRY','reason'=>'Uzbekistan dental equipment/sterilization portfolio; exact requested model unconfirmed.'];
        $routes[]=['id'=>'abform','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local medical/dental equipment and sterilization provider; exact make/model unconfirmed.'];
    } elseif (preg_match('/slit.lamp|ophthalm|huvitz|hrk.1|hnt.1|octopus\\s*600|haag.streit|keratomet|tonomet|perimet|VS.2000/ui',$description)) {
        $routes[]=['id'=>'meditech','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local ophthalmic diagnostics catalogue: autorefractometers, tonometers and slit lamps; exact requested model unverified.'];
        $routes[]=['id'=>'skymed','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local ophthalmology equipment supplier with slit lamps, tonometers and perimeter categories.'];
    } elseif (preg_match('/autoclave|steriliz|dry.heat|plasma|CSSD|endoscope washer|drying.*cabinet|SPDS.5/ui',$description)) {
        $routes[]=['id'=>'abform','grade'=>'CATEGORY_ENQUIRY','reason'=>'Uzbekistan sterilization equipment portfolio; for hospital CSSD verify capacity, process and model.'];
    } elseif (preg_match('/drgem|x.ray|radiolog|ultrasound|transducer|1\.5\s*T\s*MRI|\bMRI\b|\bV[68]\b/ui',$description)) {
        $routes[]=['id'=>'diatech','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local MRI/radiology/ultrasound supplier and installation channel; requested OEM not established.'];
        $routes[]=['id'=>'medsyst','grade'=>'CATEGORY_ENQUIRY','reason'=>'Medical imaging/diagnostics portfolio; requested model requires enquiry.'];
    } elseif (preg_match('/endoscop|cysto|pentax|nasopharyng|heinemann|\bENT\b|\bUORL\b|\bUE.3000\b|ds\.vision/ui',$description)) {
        $routes[]=['id'=>'medsc','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local PENTAX Medical endoscopy supplier catalogue; specific ENT processor and scope require enquiry.'];
        $routes[]=['id'=>'diatech','grade'=>'CATEGORY_ENQUIRY','reason'=>'Supplier lists flexible endoscopy; verify endoscope/ENT scope and exact model.'];
    } elseif (preg_match('/anesthes|anaesthes|ventilat|savina|defibrillat|umed|monitor.defibrillat/ui',$description)) {
        $routes[]=['id'=>'intermed','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local anesthesia, ventilation and defibrillation equipment portfolio.'];
        $routes[]=['id'=>'deltamax','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local anesthesia/ICU systems; verify requested configuration.'];
    } elseif (preg_match('/coagulat|electrosurg|ligasure|valleylab|rf.magic|surg 150/ui',$description)) {
        $routes[]=['id'=>'intermed','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local coagulator and surgical-equipment category; manufacturer/model not confirmed.'];
        $routes[]=['id'=>'medsc','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local operating-room equipment catalogue, exact model not confirmed.'];
    } elseif (preg_match('/therapy|magnetic|polyus|spiro|neuron|EEG|EMG/ui',$description)) {
        $routes[]=['id'=>'abform','grade'=>'CATEGORY_ENQUIRY','reason'=>'Local diagnostics/physiotherapy portfolio; verify requested specification.'];
        $routes[]=['id'=>'medsyst','grade'=>'CATEGORY_ENQUIRY','reason'=>'Clinical diagnostic systems sourcing enquiry, exact manufacturer not listed.'];
    } elseif (preg_match('/trolley|stretcher|patient.*transport|gynecolog|urolog.*chair/ui',$description)) {
        $routes[]=['id'=>'deltamax','grade'=>'CATEGORY_ENQUIRY','reason'=>'Hospital equipment and installations; confirm whether this device category is supplied.'];
    }
    return array_slice($routes,0,2);
}
function aur_supplier_route_stats(array $rows):array {
    $covered=0;$exact=0;$unique=[];
    foreach($rows as $row) {
        $routes=aur_supplier_contact_routes((string)($row['description']??''));
        if ($routes) $covered++;
        foreach($routes as $route) {
            $unique[$route['id']]=true;
            if ($route['grade']==='EXACT_PRODUCT_LISTED') $exact++;
        }
    }
    return ['entries_with_contacts'=>$covered,'exact_product_listings'=>$exact,
            'contactable_companies'=>count($unique),'total'=>count($rows)];
}
