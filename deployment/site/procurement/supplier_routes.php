<?php
declare(strict_types=1);
/**
 * AURENOEVA actionable, evidence-linked Uzbekistan sourcing routes.
 * No external API, no crawler dependency, no additional database, no RFQ sent.
 * Sources are supplier-owned public product/portfolio and contact pages.
 * A category route is never represented as confirmation of the requested SKU.
 */
const AUR_SUPPLIER_ROUTE_VERSION='2026-10-21.5-directory';

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
