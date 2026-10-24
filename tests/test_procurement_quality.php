<?php
declare(strict_types=1);
require __DIR__.'/../deployment/site/procurement/supplier_quality.php';
function line(int $item,int $row,string $description,?int $qty=1,string $type='REQUESTED'):array {
    return ['customer_item_number'=>$item,'source_row'=>$row,
        'description_original'=>$description,'quantity'=>$qty,'option_type'=>$type];
}
$case=['payload'=>['sourcing_lines'=>[
    line(6,11,'MT5000S flexible endoscope washer'),
    line(6,12,'MT5000L flexible endoscope washer',null,'ALTERNATIVE'),
    line(12,13,'DS.Surg 150 electrosurgical unit'),
    line(14,14,'1.5 T MRI system'),
    line(16,15,'Skema 6 dental unit'),
    line(22,16,'DS.Surg 150 high-frequency electrosurgical unit'),
    line(23,17,'Skema 8 dental unit'),
    line(40,18,'Autoclave for the Central Sterile Services Department (CSSD)',null),
]]];
$r=aur_rfq_quality_report($case);
$duplicates=array_values(array_filter($r['issues'],static fn($x)=>$x['type']==='possible_duplicate'));
if(count($duplicates)!==1 || $duplicates[0]['items']!==[12,22]) {
    fwrite(STDERR,"Expected exactly one cross-item duplicate for DS.Surg 150\n"); exit(1);
}
if($r['alternative_options']!==1 || $r['counts']['missing_quantities']!==1) {
    fwrite(STDERR,"Alternatives and null requested quantities misclassified\n");exit(1);
}
if($r['counts']['open_specifications']!==2) {
    fwrite(STDERR,"Expected MRI and CSSD generic equipment labels\n");exit(1);
}
$missing=array_values(array_filter($r['issues'],static fn($x)=>$x['type']==='numbering_gap'&&$x['items']===[15]));
if(count($missing)!==1) {
    fwrite(STDERR,"Expected missing buyer number 15\n");exit(1);
}
// No model: generic descriptions are not duplicate detections.
$generic=['payload'=>['sourcing_lines'=>[
    line(1,1,'Automatic sterilizer'),
    line(2,2,'Automatic sterilizer for instruments'),
]]];
if(aur_rfq_quality_report($generic)['counts']['possible_duplicates']!==0) {
    fwrite(STDERR,"Generic equipment incorrectly inferred to be same model\n");exit(1);
}
echo "AURENOEVA_RFQ_QUALITY_TESTS=PASS\n";
