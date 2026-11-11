<?php
declare(strict_types=1);
/**
 * Two supplier follow-ups requested 11 Nov 2026.
 * A response to Duo Med and a reroute to Meditron's named ultrasound consultant.
 * Draft persistence only, using the existing human-approved RFQ outbox.
 */
require_once __DIR__.'/nov11_supplier_batch.php';

function aur_nov11_followup_leads():array {
    return [
        [
            'id'=>'duomed-configuration-followup',
            'name'=>'Duo Med GmbH — Nelly von Wangenheim',
            'email'=>'info@duo-med.de',
            'country'=>'DE','language'=>'de','items'=>[32,33],
            'kind'=>'DIRECT_REPLY',
            'evidence_url'=>'https://www.duo-med.com/c/behandlungseinheiten/behandlungseinheiten-neu/behandlungseinheiten-castellini/skema-familie',
            'evidence'=>'11 Nov 2026 personal reply from Nelly von Wangenheim requests equipment wishes, says the remaining RFQ list is being evaluated and asks for a telephone number'
        ],
        [
            'id'=>'meditron-nikita-direct-reroute',
            'name'=>'Meditron SA — Nikita Müller, Ultraschall',
            'email'=>'nikita.mueller@meditron.ch',
            'country'=>'CH','language'=>'de','items'=>[3,4],
            'kind'=>'REROUTE_AFTER_GENERAL_MAILBOX_REJECTION',
            'evidence_url'=>'https://www.meditron.ch/de/contact',
            'evidence'=>'11 Nov 2026 general info@meditron.ch mailbox refused the incoming RFQ; named ultrasound specialist email publicly listed on the official Meditron contact page'
        ]
    ];
}
function aur_nov11_followup_plans(array $case):array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return [];
    $patterns=[
      3=>'/\bZ20\b/ui',4=>'/\bV8\b/u',
      32=>'/\bSkema\s*6\b/ui',33=>'/\bSkema\s*8\b/ui'
    ];
    $byNumber=[];
    foreach(($case['payload']['sourcing_lines']??[]) as $row){
        if(!is_array($row)||($row['option_type']??'REQUESTED')!=='REQUESTED')continue;
        $n=(int)($row['customer_item_number']??0);
        if($n>0)$byNumber[$n][]=$row;
    }
    $out=[];
    foreach(aur_nov11_followup_leads() as $lead){
        if(!filter_var($lead['email'],FILTER_VALIDATE_EMAIL)||!str_starts_with($lead['evidence_url'],'https://'))
            throw new RuntimeException('nov11_followup_contact_invalid');
        $rows=[];
        foreach($lead['items'] as $n){
            $matches=$byNumber[$n]??[];
            if(count($matches)!==1 ||
               !preg_match($patterns[$n],(string)($matches[0]['description_original']??'')))
                throw new RuntimeException('nov11_followup_original_item_mismatch_'.$n);
            $line=$matches[0];
            $rows[]=[
                'source_row'=>(int)$line['source_row'],
                'customer_item_number'=>$n,
                'description'=>(string)$line['description_original'],
                'quantity'=>$line['quantity']??null,
                'option_type'=>'REQUESTED'
            ];
        }
        aur_rfq_assert_no_duplicate_supplier_lines($case,$rows);
        $out[]=['lead'=>$lead,'rows'=>$rows];
    }
    return $out;
}
function aur_nov11_followup_compose(array $case,array $plan):array {
    $lead=$plan['lead'];$rows=$plan['rows'];
    $vendor=['id'=>'followup-20261111-'.$lead['id'],'name'=>$lead['name'],
             'email'=>$lead['email'],'evidence_url'=>$lead['evidence_url']];
    $mail=aur_vendor_rfq_compose($case,$vendor,$rows);
    if($lead['id']==='duomed-configuration-followup'){
        $subject='AW: Ihre Anfrage – Castellini Skema 6 / Skema 8 | AUR-1A04A7909185FACD';
        $body="Sehr geehrte Frau von Wangenheim,\n\n"
            ."herzlichen Dank für Ihre schnelle und freundliche Rückmeldung. Wir freuen uns sehr, dass Sie sich auch die weiteren Positionen unserer Geräteliste ansehen.\n\n"
            ."Gern konkretisieren wir unsere Anfrage: wir interessieren uns für folgende Behandlungseinheiten:\n\n"
            ."• RFQ-Position 32: 1 × Castellini Skema 6 (Referenz des Kunden: SD-9099)\n"
            ."• RFQ-Position 33: 2 × Castellini Skema 8 (Referenz des Kunden: 6X-9056)\n\n"
            ."Die Referenzen stammen aus der Kundenanfrage; ob sie einer konkreten Castellini-Werkskonfiguration entsprechen, ist noch offen. Eine verbindliche Auswahl aller Ausstattungsoptionen liegt uns derzeit nicht vor.\n\n"
            ."Könnten Sie uns zunächst je Modell eine Preisindikation für eine übliche vollständige Standardausstattung geben und die wichtigsten wählbaren Optionen mit ihren Aufpreisen darstellen? Besonders hilfreich wären Ihre Konfigurations- bzw. Ausstattungsblätter, damit wir die noch offenen Wünsche gezielt mit dem Kunden abstimmen können. Bitte nennen Sie auch die Lieferzeit sowie gegebenenfalls separat berechnete Montage- und Serviceleistungen.\n\n"
            ."Die vollständige Geräteliste fügen wir nochmals bei. Vielen Dank, dass Sie prüfen, welche weiteren Positionen Sie anbieten können; auch Teilangebote sind willkommen.\n\n"
            ."Für einen kurzen persönlichen Austausch können wir gern einen Telefontermin abstimmen. Die Ausstattungs- und Preisangaben würden wir für die Kundenübersicht zunächst gern schriftlich festhalten.\n\n"
            ."AURENOEVA vermittelt die Anfrage; ein möglicher Kauf erfolgt direkt zwischen Kunde und Anbieter. Es handelt sich nicht um eine Bestellung.\n\n"
            .aur_procurement_signed_closing('de',(string)$lead['email'])."\n";
    }else{
        $subject='Samsung HERA Z20 / V8 – Preisanfrage direkt an Herrn Müller | AUR-1A04A7909185FACD';
        $body="Guten Tag Herr Müller,\n\n"
            ."wir interessieren uns für zwei Samsung-Ultraschallsysteme und wurden auf Sie als Ansprechpartner für Ultraschall bei Meditron aufmerksam. Unsere Anfrage an die allgemeine Adresse info@meditron.ch wurde vom Mailsystem leider nicht angenommen. Deshalb wenden wir uns direkt an Sie.\n\n"
            ."Für ein Krankenhausprojekt benötigen wir unverbindliche Preis- und Lieferangaben zu folgenden RFQ-Positionen:\n\n"
            ."• Position 3: 1 × Samsung HERA Z20, gemäß Kundenangabe mit den Optionen/Sonden CA1-7S, LA2-14A, EA2-11AR und CMV1-10 (weitere Optionsangaben im beigefügten RFQ).\n"
            ."• Position 4: 1 × Samsung V8 mit vier Schallköpfen, Scherwellen-Elastografie, EKG-Funktion, Heart Assist und den angefragten Softwareoptionen.\n\n"
            ."Können Sie diese Konfigurationen anbieten oder uns an den hierfür zuständigen Samsung-Vertriebspartner vermitteln? Hilfreich wären ein schriftlicher Richtpreis je System in der jeweiligen Währung, Lieferumfang und eventuelle Abweichungen von der Kundenanforderung, Verfügbarkeit, Lieferzeit sowie Garantie- und Exportbedingungen.\n\n"
            ."Unsere Firmenvorstellung und die vollständige länderneutrale RFQ-Liste fügen wir bei; falls Sie weitere der Positionen liefern können, nehmen wir gern auch dazu Informationen entgegen.\n\n"
            ."AURENOEVA vermittelt die Anfrage; eine spätere Bestellung würde ausschließlich direkt zwischen Käufer und Anbieter erfolgen.\n\n"
            ."Vielen Dank im Voraus!\n\n"
            .aur_procurement_signed_closing('de',(string)$lead['email'])."\n";
    }
    if(preg_match('/Uzbekistan|Usbekistan|Tashkent|AUR-UZ-/ui',$subject.' '.$body))
        throw new RuntimeException('nov11_followup_destination_visible');
    $mail['subject']=$subject;$mail['body']=$body;
    $mail['items']=$rows;$mail['language']=$lead['language'];
    $mail['supplier_country']=$lead['country'];
    $mail['listing_claim']=$lead['evidence'];
    $mail['listing_evidence_url']=$lead['evidence_url'];
    $mail['source_grade']='DIRECT_SUPPLIER_RESPONSE_OR_CONTACT_REROUTE';
    $mail['previously_contacted']=true;
    $mail['contact_type']=$lead['kind'];
    $mail['sourcing_round']='2026-11-11-followup';
    return $mail;
}
function aur_nov11_followup_index(string $store,array $case):string {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)throw new InvalidArgumentException('nov11_followup_wrong_case');
    return aur_private_directory($store,'supplier-rfqs').'/'.AUR_URGENT_RFQ_REF.'-followup-20261111.json';
}
function aur_nov11_followup_saved(string $store,array $case):?array {
    if(($case['reference']??'')!==AUR_URGENT_RFQ_REF)return null;
    $path=aur_nov11_followup_index($store,$case);
    if(!is_file($path))return null;
    if(is_link($path))throw new RuntimeException('nov11_followup_index_symlink');
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['schema']??'')!=='aurenoeva-nov11-followups/1'||
       ($data['case_source_sha256']??'')!==($case['source_sha256']??''))
        throw new RuntimeException('nov11_followup_saved_case_mismatch');
    $draft=aur_vendor_rfq_read($store,$case,(string)$data['draft_id']);
    $data['messages']=$draft['messages'];$data['status']=$draft['status'];
    return $data;
}
function aur_nov11_followup_prepare(string $store,array $case):array {
    $path=aur_nov11_followup_index($store,$case);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('nov11_followup_lock_failed');
    try{
        $old=aur_nov11_followup_saved($store,$case);
        if($old!==null)return $old;
        // The two recipients are intentional follow-ups to earlier correspondence.
        // Unlike a first-contact campaign, previously contacted vendors are not dropped.
        $messages=[];
        foreach(aur_nov11_followup_plans($case) as $plan)$messages[]=aur_nov11_followup_compose($case,$plan);
        if(count($messages)!==2)throw new RuntimeException('nov11_followup_missing_messages');
        $id=bin2hex(random_bytes(12));
        aur_vendor_rfq_write(aur_vendor_rfq_path($store,(string)$case['reference'],$id),[
            'version'=>'aurenoeva-rfq-outbox/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'destination_country'=>$case['country'],
            'information_only'=>true,'template_version'=>'pricing-rfq-v3',
            'draft_id'=>$id,'created_at'=>gmdate('c'),'approval'=>null,
            'status'=>'DRAFT_REVIEW',
            'source'=>'TARGETED_SUPPLIER_FOLLOWUPS_2026_11_11',
            'messages'=>$messages
        ]);
        aur_vendor_rfq_write($path,[
            'schema'=>'aurenoeva-nov11-followups/1',
            'case_reference'=>$case['reference'],
            'case_source_sha256'=>$case['source_sha256'],
            'draft_id'=>$id,'supplier_count'=>count($messages),
            'prepared_at'=>gmdate('c')
        ]);
        return aur_nov11_followup_saved($store,$case)
            ??throw new RuntimeException('nov11_followup_readback_failed');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
