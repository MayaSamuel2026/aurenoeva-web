<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/rfq_duplicates.php';
function check_duplicates(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
}
function test_line(int $row,int $item,string $description,string $option='REQUESTED',?int $qty=1):array {
    return ['source_row'=>$row,'customer_item_number'=>$item,
        'description_original'=>$description,'option_type'=>$option,'quantity'=>$qty];
}
$lines=[
    test_line(3,12,'DS.Surg 150 electrosurgical unit'),
    test_line(13,22,'DS Surg 150 - Electrosurgical Unit'),
    test_line(4,6,'MT5000S flexible endoscope washer'),
    test_line(5,6,'MT5000L flexible endoscope washer','ALTERNATIVE',null),
    test_line(7,4,'V8 (4 transducers + shear wave + ECG + Heart Assist + all options)'),
    test_line(8,5,'V6 (3 transducers basic configuration)'),
];
$review=aur_rfq_line_review($lines);
check_duplicates($review['requires_review']===true,'duplicate not detected');
check_duplicates(count($review['duplicate_pairs'])===1,'alternatives or different models wrongly flagged');
check_duplicates($review['duplicate_pairs'][0]['kind']==='EXACT_DUPLICATE','duplicate classification incorrect');
check_duplicates($review['duplicate_pairs'][0]['source_rows']===[3,13],'source row evidence lost');
check_duplicates($review['alternative_options']===1,'alternative count incorrect');
$case=['payload'=>['sourcing_lines'=>$lines]];
$rejected=false;
try{aur_rfq_assert_no_duplicate_supplier_lines($case,[
    ['source_row'=>3],['source_row'=>13]
]);}catch(InvalidArgumentException $e){
    $rejected=$e->getMessage()==='rfq_duplicate_equipment_review_required';
}
check_duplicates($rejected,'repeated equipment was not blocked');
aur_rfq_assert_no_duplicate_supplier_lines($case,[['source_row'=>3],['source_row'=>4],['source_row'=>5]]);
$qtyReview=aur_rfq_line_review([
    test_line(1,101,'One imaging system','REQUESTED',1),
    test_line(2,102,'One imaging system','REQUESTED',2),
]);
check_duplicates(count($qtyReview['duplicate_pairs'])===1,'different quantities were silently treated as distinct products');
check_duplicates($qtyReview['source_line_count']===2,'original counts not preserved');
$optReview=aur_rfq_line_review([
    test_line(1,7,'A specification','REQUESTED'),
    test_line(2,7,'A specification','ALTERNATIVE',null)
]);
check_duplicates($optReview['duplicate_pairs'][0]['kind']==='REDUNDANT_ALTERNATIVE','identical alternative missed');
echo "RFQ_DUPLICATE_REVIEW_TESTS_PASSED\n";
