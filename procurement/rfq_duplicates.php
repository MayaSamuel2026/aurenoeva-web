<?php
declare(strict_types=1);
/**
 * Read-only RFQ line quality gate. The customer's uploaded source remains
 * authoritative: never silently delete, renumber, merge, or change quantity.
 * Alternative equipment within one buyer item is not an extra purchase.
 */
function aur_rfq_line_normalize(string $text):string {
    $text=trim((string)(preg_replace('/\s+/u',' ',$text)??$text));
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    $text=preg_replace('/[^\p{L}\p{N}]+/u',' ',$text)??$text;
    return trim(preg_replace('/\s+/u',' ',$text)??$text);
}
function aur_rfq_line_review(array $lines):array {
    $pairs=[];$prepared=[];$alternatives=0;
    foreach($lines as $index=>$line) {
        if(!is_array($line))continue;
        $description=(string)($line['description_original']??$line['description']??'');
        $norm=aur_rfq_line_normalize($description);
        $isAlt=(string)($line['option_type']??'REQUESTED')==='ALTERNATIVE';
        if($isAlt)$alternatives++;
        if($norm==='')continue;
        $prepared[]=['row'=>(int)($line['source_row']??($index+1)),
          'item'=>(int)($line['customer_item_number']??0),
          'alt'=>$isAlt,'norm'=>$norm,'words'=>array_fill_keys(explode(' ',$norm),true)];
    }
    $n=count($prepared);
    for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++) {
        $a=$prepared[$i];$b=$prepared[$j];
        // Distinct advertised alternatives within an item are valid.
        $sameItem=$a['item']===$b['item'];
        $exact=($a['norm']===$b['norm']);
        if($sameItem && !$exact)continue;
        $kind=null;
        if($exact) {
            $kind=$sameItem?'REDUNDANT_ALTERNATIVE':'EXACT_DUPLICATE';
        } elseif(!$sameItem && !$a['alt'] && !$b['alt']
            && count($a['words'])>=8 && count($b['words'])>=8) {
            // High-confidence text near-match ONLY. Different configurations
            // should not be merged; this is a review flag, not a deletion.
            $common=count(array_intersect_key($a['words'],$b['words']));
            $union=count($a['words'])+count($b['words'])-$common;
            if($union>0 && $common/$union>=0.92)$kind='POSSIBLE_DUPLICATE';
        }
        if($kind!==null)$pairs[]=['kind'=>$kind,
          'source_rows'=>[$a['row'],$b['row']],
          'customer_items'=>[$a['item'],$b['item']]];
    }
    $flagged=[];
    foreach($pairs as $pair)foreach($pair['source_rows'] as $row)$flagged[$row]=true;
    return ['schema'=>'aurenoeva-rfq-line-review/1',
      'source_line_count'=>count($lines),'alternative_options'=>$alternatives,
      'duplicate_pairs'=>$pairs,'flagged_source_rows'=>array_map('intval',array_keys($flagged)),
      'requires_review'=>count($pairs)>0];
}
/** Supplier-specific gate: a pair must not appear twice in any one RFQ. */
function aur_rfq_assert_no_duplicate_supplier_lines(array $case,array $selectedRows):void {
    $review=aur_rfq_line_review($case['payload']['sourcing_lines']??[]);
    $selected=[];
    foreach($selectedRows as $line)$selected[(int)($line['source_row']??0)]=true;
    foreach($review['duplicate_pairs'] as $pair) {
        if(isset($selected[$pair['source_rows'][0]],$selected[$pair['source_rows'][1]]))
            throw new InvalidArgumentException('rfq_duplicate_equipment_review_required');
    }
}
