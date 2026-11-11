<?php
declare(strict_types=1);
/**
 * 11 November 2026 source-first procurement round.
 * Source research is visible even where no published email is established.
 * Only explicitly saved, staff-reviewed, selected recipients enter the existing outbox.
 */
require_once __DIR__.'/researched_supplier_round.php';

function aur_nov11_supplier_leads():array {
    return [
      ['id'=>'drgem-specialist','name'=>'DRGEM specialist reseller','email'=>'','country'=>'RU','lang'=>'en','items'=>[1],'model'=>'DRGEM DIAMOND Classic Expert 52 kW','url'=>'https://drgem.su/','note'=>'Exact model family; export route and seller credentials require verification'],
      ['id'=>'cefla-anthos','name'=>'Cefla / Anthos official channel','email'=>'','country'=>'DE','lang'=>'de','items'=>[2],'model'=>'Anthos A5 Continental','url'=>'https://www.anthos.it/de/kontakte/','note'=>'Manufacturer contact form only; Continental package and authorized reseller to confirm'],
      ['id'=>'meditron-samsung','name'=>'Meditron SA','email'=>'info@meditron.ch','country'=>'CH','lang'=>'en','items'=>[3,4],'model'=>'Samsung HERA Z20 and Samsung V8','url'=>'https://www.meditron.ch/de/solutions/ultrasound','note'=>'Samsung distributor; exact probe and software options and export territory unconfirmed'],
      ['id'=>'sonomedical-v6','name'=>'SonoMedical GmbH','email'=>'info@sono-medical.com','country'=>'DE','lang'=>'de','items'=>[5],'model'=>'Samsung V6','url'=>'https://sono-medical.com/ultraschall/v6/','note'=>'Exact model web listing; specified three-probe kit requires written quote'],
      ['id'=>'avmedica-mt5000s','name'=>'AVMEDICA','email'=>'','country'=>'RU','lang'=>'en','items'=>[6],'model'=>'MT5000S endoscope washer','url'=>'https://avmedica.ru/mojki-dlya-endoskopov/','note'=>'Exact model listed; trade and export feasibility unresolved'],
      ['id'=>'evoray-ec5bd','name'=>'EVORAY / MEDTRADE','email'=>'info@evoray.ru','country'=>'RU','lang'=>'en','items'=>[7],'model'=>'EVORAY EC-5BD drying cabinet','url'=>'https://evoray.ru/ec-5bd/','note'=>'Exact product page, website price not a quotation; export feasibility unresolved'],
      ['id'=>'dsmed-devices','name'=>'DS.Med','email'=>'info@ds-med.ru','country'=>'RU','lang'=>'en','items'=>[8,9,12,22],'model'=>'DS.Vision CystoFiber, DS.Vision FHD 3-in-1 and DS.Surg 150','url'=>'https://ds-med.ru/product/ds-vision-fhd3in1/','note'=>'Manufacturer-specific products; four buyer items, export feasibility unresolved'],
      ['id'=>'intelmed-rfmagic','name'=>'Intelmed','email'=>'','country'=>'RU','lang'=>'en','items'=>[10],'model'=>'RF-Magic II coagulator','url'=>'https://intelmed.ru/brands/itc-ltd/','note'=>'Exact family listed; supplier email and cross-border sale unverified'],
      ['id'=>'maxline-ls10','name'=>'Maxline Medical','email'=>'maxlinemedical@gmail.com','country'=>'US','lang'=>'en','items'=>[11],'model'=>'Medtronic Valleylab LS10 VLLS10GEN','url'=>'https://maxlinemedical.com/electrosurgical/medtronic-valleylab-vlls10gen-ls10-ligasure-vessel-sealing-generator/','note'=>'Exact used/refurbished equipment listing; stock, serial and accessories not confirmed'],
      ['id'=>'milon-proctology','name'=>'Milon Laser','email'=>'','country'=>'RU','lang'=>'en','items'=>[13],'model'=>'LAKHTA-MILON proctology set','url'=>'https://www.milon.ru/index.phtml?tid=131','note'=>'Laser family; full proctology configuration and email unverified'],
      ['id'=>'troostwijk-pentax','name'=>'Troostwijk Auctions via Bimedis','email'=>'','country'=>'NL','lang'=>'en','items'=>[14],'model'=>'Pentax VNL9-CP scope with CP-1000 package','url'=>'https://bimedis.com/a-item/video-laryngoscopes-pentax-vnl9-cp-2876611','note'=>'Only a partial scope listing; full CP-1000 and cart NOT confirmed'],
      ['id'=>'otoplug-modula','name'=>'Otoplug Oy','email'=>'otoplug@otoplug.fi','country'=>'FI','lang'=>'en','items'=>[16],'model'=>'Heinemann MODULA Europa 0100-30000','url'=>'https://otoplug.fi/tuote/heinemann-korvantutkimusyksikko-modula-europa/','note'=>'Exact base model; chair, endoscopy and package accessories to confirm'],
      ['id'=>'ophthallmart-s260s','name'=>'Ophthall Mart','email'=>'','country'=>'IN','lang'=>'en','items'=>[17],'model'=>'MediWorks S260S slit lamp','url'=>'https://www.ophthallmart.com/product/mediworks-slit-lamp-s260-s260s/','note'=>'Exact S260/S260S listing; seller identity/contact route to verify'],
      ['id'=>'viscope-vs2000','name'=>'Viscope Optical Instruments','email'=>'frank@viscope.com.cn','country'=>'CN','lang'=>'en','items'=>[18],'model'=>'VS-2000 ophthalmologist workstation','url'=>'https://www.viscope.com.cn/product_detail_cn/id/2.html','note'=>'Exact model on manufacturer page; email on that manufacturer page'],
      ['id'=>'polus2m','name'=>'Kompleksnye Medpostavki','email'=>'info@polus2m.ru','country'=>'RU','lang'=>'en','items'=>[23],'model'=>'POLYUS-2M magnetotherapy','url'=>'https://polus2m.ru/apparaty-magnitoterapii/polyus-2m-apparat-magnitoterapii/','note'=>'Product-specific seller; trade/export eligibility unresolved'],
      ['id'=>'neurosoft-24-29','name'=>'Neurosoft LLC','email'=>'info@neurosoft.com','country'=>'RU','lang'=>'en','items'=>[24,29],'model'=>'Neuron-Spectrum-1 and Spiro-Spectrum','url'=>'https://neurosoft.com/en/catalog/eeg/neuron-spectrum-1','note'=>'Manufacturer; verify EEG/EP/EMG requirements and complete Lilly-tube kits; export eligibility unresolved'],
      ['id'=>'universal-umed20','name'=>'Universal Medisystems','email'=>'','country'=>'XX','lang'=>'en','items'=>[25],'model'=>'Mindray uMED 20','url'=>'https://universalmedisystems.com/umed-20/','note'=>'Seller credentials and valid contact not verified'],
      ['id'=>'global-wato','name'=>'Global Healthcare Medical','email'=>'','country'=>'US','lang'=>'en','items'=>[26],'model'=>'Mindray WATO EX-35','url'=>'https://www.globalhealthcaremedical.com/products/used-mindray-wato-ex-35-anesthesia-machine','note'=>'Used model listing; two exact 3-gas configurations and seller identity to verify'],
      ['id'=>'auramed-e8','name'=>'Aura-Med','email'=>'','country'=>'RU','lang'=>'en','items'=>[28],'model'=>'Meditsinoff E-8 transport trolley','url'=>'https://aura-med.ru/med-uchregdenia/mebel/medicinskie-katalki/meditsinoff-tk/','note'=>'Exact model advertised; three units, 200 mm wheels and export feasibility unconfirmed'],
      ['id'=>'vatech-europe','name'=>'Vatech Europe','email'=>'','country'=>'EU','lang'=>'en','items'=>[30],'model'=>'Vatech Green X 16 with Ceph','url'=>'https://www.vatecheurope.com/products/green-x-16/','note'=>'Manufacturer referral channel; existing Vatech UK, Dentalair and Dental Discovery already contacted'],
      ['id'=>'goldident-gd0046','name'=>'Goldident','email'=>'zakaz@goldident.ru','country'=>'RU','lang'=>'en','items'=>[31],'model'=>'GD-0046 X-ray room set','url'=>'https://goldident.ru/catalog/komplekty-oborudovanie-dlya-rentgenologii/komplekt-oborudovaniya-gd-0046/','note'=>'Exact bundle listed; email in seller contact page; trade feasibility unverified'],
      ['id'=>'garlichs-skema6','name'=>'Garlichs Dental','email'=>'einrichtung@garlichs-dental.de','country'=>'DE','lang'=>'de','items'=>[32],'model'=>'Castellini Skema 6 SD-9099','url'=>'https://www.garlichs-dental.de/aktuelles/aktionen/castellini-austauschaktion-2026/','note'=>'Product family sold; promotional site pricing NOT a formal RFQ quote; model/configuration unverified'],
      ['id'=>'interdent-skema8','name'=>'Interdent trgovina','email'=>'info@interdent.cc','country'=>'SI','lang'=>'en','items'=>[33],'model'=>'Castellini Skema 8 6X-9056','url'=>'https://shop-interdent.eu/en/Furniture/castellini-dental-chair-skema-8/','note'=>'Exact family listed but website said unavailable; ask two-unit future availability'],
      ['id'=>'agito-mri','name'=>'AGITO Medical','email'=>'sales@agitomedical.com','country'=>'DK','lang'=>'en','items'=>[35],'model'=>'1.5 T MRI system','url'=>'https://www.agitomedical.com/medical-equipment/mri','note'=>'Specialist used-equipment supplier; system brand, site works and installation need quotation'],
      ['id'=>'dentalgreen-ac300','name'=>'Dental-Green','email'=>'','country'=>'EU','lang'=>'de','items'=>[36],'model'=>'CATTANI AC300 compressor 013330','url'=>'https://www.dental-green.de/de/geraete/22576-ac300-kompressor-mit-trockner.html','note'=>'Exact listing; no verified recipient email, website price not a quote'],
      ['id'=>'medtehno-kgem01','name'=>'Medtehno','email'=>'','country'=>'RU','lang'=>'en','items'=>[37],'model'=>'Kler KGEM-01 chair','url'=>'https://www.medtehno.ru/catalog/ginekol_kresla/Kgem01/','note'=>'Exact model; two units and three electrical drives to confirm; export feasibility unresolved'],
      ['id'=>'melag-cssd','name'=>'MELAG','email'=>'info@melag.de','country'=>'DE','lang'=>'de','items'=>[38],'model'=>'CSSD steam sterilizer','url'=>'https://www.melag.com/en/products/large-autoclaves','note'=>'Capacity, sterilizer class and installation requirements missing from buyer schedule'],
      ['id'=>'biobase-dryheat','name'=>'BIOBASE','email'=>'','country'=>'CN','lang'=>'en','items'=>[39],'model'=>'CSSD dry-heat sterilizer','url'=>'https://www.medicalexpo.com/prod/biobase/product-84845-1123940.html','note'=>'Capacity unspecified; exact clinical use and contact not yet verified'],
      ['id'=>'sanqiang-plasma','name'=>'Sanqiang Medical','email'=>'','country'=>'CN','lang'=>'en','items'=>[40],'model'=>'H2O2 plasma sterilizer SQ-DS60 or equivalent','url'=>'https://www.sanqiangmedical.com/Contact','note'=>'Manufacturer contact form; chamber size, consumables and approvals need confirmation']
    ];
}
function aur_nov11_supplier_plans(array $case):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return [];
    $byItem=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $line) {
        if(!is_array($line)||($line['option_type']??'REQUESTED')!=='REQUESTED')continue;
        $n=(int)($line['customer_item_number']??0);
        if($n>0)$byItem[$n][]=$line;
    }
    $plans=[];$emails=[];
    foreach(aur_nov11_supplier_leads() as $lead) {
        if($lead['email']==='')continue;
        $email=strtolower(trim($lead['email']));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||isset($emails[$email])
           ||!preg_match('~^https://~',$lead['url']))
            throw new RuntimeException('nov11_invalid_or_duplicate_supplier_contact');
        $emails[$email]=true;
        $rows=[];
        foreach($lead['items'] as $n) {
            $matches=$byItem[$n]??[];
            if(count($matches)!==1 || trim((string)($matches[0]['description_original']??''))==='')
                throw new RuntimeException('nov11_original_rfq_item_mismatch_'.$n);
            $line=$matches[0];
            $rows[]=['source_row'=>(int)$line['source_row'],
                'customer_item_number'=>(int)$n,'description'=>(string)$line['description_original'],
                'quantity'=>$line['quantity']??null,'option_type'=>'REQUESTED'];
        }
        aur_rfq_assert_no_duplicate_supplier_lines($case,$rows);
        $plans[]=['lead'=>$lead,'rows'=>$rows];
    }
    return $plans;
}
function aur_nov11_supplier_compose(array $case,array $plan):array {
    $lead=$plan['lead'];$de=$lead['lang']==='de';
    $vendor=['id'=>'nov11-'.$lead['id'],'name'=>$lead['name'],'email'=>$lead['email'],'evidence_url'=>$lead['url']];
    $message=aur_vendor_rfq_compose($case,$vendor,$plan['rows']);
    $body=$de?"Guten Tag,\n\nwir haben Ihr Angebot bzw. Ihre Produktinformationen zu ".$lead['model']." gefunden und würden uns über ein konkretes schriftliches Angebot freuen.\n\n":
      "Dear Sales Team,\n\nWe found your product information for ".$lead['model']." and would appreciate a specific written quotation.\n\n";
    foreach($plan['rows'] as $row) {
        $body.=($de?'RFQ-Position ':'RFQ item ').'#'.$row['customer_item_number'].' — '.$row['description']
            .' | '.($de?'Menge: ':'Quantity: ').($row['quantity']===null?'TBC':(string)$row['quantity'])."\n";
    }
    $body.=$de
      ?"\nBitte bestätigen Sie die genaue Ausführung (oder Abweichungen), Netto-Stückpreis und Währung, Lieferbarkeit, Zustand, Lieferzeit, Gewährleistung sowie Versand- und Exportmöglichkeiten. Auch technische Unterlagen wären hilfreich.\n\nDie AURENOEVA-Firmenvorstellung und die vollständige produktneutrale RFQ-Liste fügen wir bei: Bitte nennen Sie gern weitere Positionen, die Sie liefern können.\n\nAURENOEVA vermittelt die Anfrage. Ein möglicher Kaufvertrag wird unmittelbar zwischen Kunde und Lieferant geschlossen; diese Anfrage ist keine Bestellung.\n\nwir interessieren uns für ein unverbindliches schriftliches Angebot.\n\n"
      :"\nPlease confirm the exact configuration (or deviations), net unit price and currency, genuine stock/condition, dispatch time, warranty, technical documents and possible international supply or freight terms.\n\nWe also attach the AURENOEVA company introduction and the full country-neutral RFQ schedule in case you can supply further items. AURENOEVA introduces the parties; any purchase is contracted directly between buyer and supplier. This enquiry is not a purchase order.\n\n";
    $body.=aur_procurement_signed_closing($de?'de':'en',(string)$lead['email'])."\n";
    $subject=($de?'Preisanfrage – ':'Price and availability enquiry – ').$lead['model'].' | AUR-1A04A7909185FACD';
    if(preg_match('/Uzbekistan|Usbekistan|Tashkent|AUR-UZ-/ui',$subject.' '.$body))
        throw new RuntimeException('nov11_destination_visible');
    $message['subject']=$subject;$message['body']=$body;
    $message['items']=$plan['rows'];
    $message['language']=$lead['lang'];$message['supplier_country']=$lead['country'];
    $message['listing_claim']=$lead['note'];$message['listing_evidence_url']=$lead['url'];
    $message['source_grade']='PUBLIC_PRODUCT_SPECIFIC_SOURCE_NOT_QUOTED';
    $message['sourcing_round']='2026-11-11';
    $message['previously_contacted']=false;
    return $message;
}
function aur_nov11_supplier_index(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)throw new InvalidArgumentException('nov11_wrong_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-nov11-20261111.json';
}
function aur_nov11_supplier_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $path=aur_nov11_supplier_index($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('nov11_index_symlink');
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??'')!=='aurenoeva-nov11-sourcing/1'||
       ($data['case_source_sha256']??'')!==($case['source_sha256']??''))
        throw new RuntimeException('nov11_saved_case_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$data['draft_id']);
    $data['messages']=$draft['messages'];$data['status']=$draft['status'];
    return $data;
}
function aur_nov11_supplier_prepare(string $store,array $case):array {
    $path=aur_nov11_supplier_index($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('nov11_outbox_lock_failed');
    try {
        $old=aur_nov11_supplier_saved($store,$case);
        if($old!==null)return $old; // Stable once prepared, no duplicate attempts.
        $already=[];
        foreach(aur_vendor_rfq_list($store,$case) as $item) {
            $older=aur_vendor_rfq_read($store,$case,(string)$item['id']);
            foreach(($older['messages']??[]) as $mail) {
                $email=strtolower(trim((string)($mail['to']??'')));
                if(filter_var($email,FILTER_VALIDATE_EMAIL))$already[$email]=true;
            }
        }
        if(function_exists('aur_mailcenter_local')) {
            foreach(aur_mailcenter_local($store) as $entry) {
                $email=strtolower(trim((string)($entry['to']??'')));
                if(filter_var($email,FILTER_VALIDATE_EMAIL))$already[$email]=true;
            }
        }
        $messages=[];$skipped=[];
        foreach(aur_nov11_supplier_plans($case) as $plan) {
            $email=strtolower($plan['lead']['email']);
            if(isset($already[$email])){$skipped[]=$email;continue;}
            $messages[]=aur_nov11_supplier_compose($case,$plan);
        }
        if(!$messages)throw new RuntimeException('nov11_all_contacts_already_queued');
        $id=bin2hex(random_bytes(12));
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),[
            'version'=>'aurenoeva-rfq-outbox/1','case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],'destination_country'=>$case['country'],
            'information_only'=>true,'template_version'=>'pricing-rfq-v3',
            'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
            'status'=>'DRAFT_REVIEW','source'=>'TARGETED_NEW_SOURCES_ROUND_2026_11_11',
            'messages'=>$messages
        ]);
        aur_vendor_rfq_write($path,[
            'schema'=>'aurenoeva-nov11-sourcing/1','case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],'draft_id'=>$id,
            'supplier_count'=>count($messages),'prepared_at'=>gmdate('c'),
            'skipped_existing_recipients'=>$skipped]);
        return aur_nov11_supplier_saved($store,$case)
            ??throw new RuntimeException('nov11_outbox_readback_failed');
    }finally {flock($lock,LOCK_UN);fclose($lock);}
}
