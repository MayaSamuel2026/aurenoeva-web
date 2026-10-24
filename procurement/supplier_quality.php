<?php
declare(strict_types=1);
/**
 * Read-only RFQ quality report from the immutable original case.
 * No edits to customer requirements, sent messages, quotes or mail ledgers.
 */
function aur_rfq_quality_tokens(string $description):array {
    $description=function_exists('mb_strtolower')
        ? mb_strtolower($description,'UTF-8') : strtolower($description);
    $tokens=preg_split('/[^\p{L}\p{N}]+/u',$description,-1,PREG_SPLIT_NO_EMPTY) ?: [];
    $generic=array_fill_keys([
        'high','frequency','electrosurgical','unit','units','device','devices',
        'system','systems','equipment','with','without','the','and','for','of',
        'a','an','in','model','version','specification','basic','configuration',
        'medical','surgical','set','new','automatic','automated','including',
        'plus','electronic','manufactured','technical','to','by','type'
    ],true);
    $unique=[];
    foreach($tokens as $t) {
        if(isset($generic[$t])||strlen($t)<2)continue;
        $unique[$t]=true;
    }
    $result=array_keys($unique);
    sort($result,SORT_STRING);
    return $result;
}
function aur_rfq_quality_report(array $case):array {
    $lines=$case['payload']['sourcing_lines'] ?? [];
    if(!is_array($lines))$lines=[];
    $issues=[];$byNumber=[];$seen=[];
    $alternatives=0;$primary=0;
    foreach($lines as $line) {
        if(!is_array($line))continue;
        $number=(int)($line['customer_item_number']??0);
        if($number<1)continue;
        $isAlternative=($line['option_type']??'REQUESTED')==='ALTERNATIVE';
        $byNumber[$number]=true;
        if($isAlternative){$alternatives++;continue;}
        $primary++;
        $sourceRow=(int)($line['source_row']??0);
        $description=trim((string)($line['description_original']??''));
        if(($line['quantity']??null)===null) {
            $issues[]=['type'=>'missing_quantity','items'=>[$number],
                'rows'=>[$sourceRow],
                'label'=>'Requested item '.$number.' has no quantity.',
                'detail'=>'Confirm the quantity with the customer before asking for a total price.'];
        }
        // A canonical fingerprint is conservative: an identifying numeric
        // model token is mandatory. Generic equipment descriptions never
        // become duplicate products merely because their wording is similar.
        $tokens=aur_rfq_quality_tokens($description);
        $hasModel=false;
        foreach($tokens as $t)if(preg_match('/\d/',(string)$t)){$hasModel=true;break;}
        if($hasModel && count($tokens)>=2) {
            $signature=implode('|',$tokens);
            if(isset($seen[$signature]) && $seen[$signature]['number']!==$number) {
                $prior=$seen[$signature];
                $issues[]=['type'=>'possible_duplicate','items'=>[$prior['number'],$number],
                    'rows'=>[$prior['row'],$sourceRow],
                    'label'=>'Possible repeated specification: buyer items '.$prior['number'].' and '.$number.'.',
                    'detail'=>'Compare the original requirements. The same identified model appears under two buyer item numbers; do not automatically delete either item.'];
            } else {
                $seen[$signature]=['number'=>$number,'row'=>$sourceRow];
            }
        }
        // These labels indicate a buyer specification rather than an exact
        // manufacturer/model; supplier alternatives may be appropriate.
        if(preg_match('/^\s*(?:\d+(?:[.,]\d+)?\s*T\s*)?MRI\s+system\s*$/iu',$description)
            || preg_match('/^\s*(?:autoclave|dry[- ]heat sterilizer|plasma sterilizer)\s+for\s+the\s+central\s+sterile\s+services\s+department/iu',$description)) {
            $issues[]=['type'=>'open_specification','items'=>[$number],
                'rows'=>[$sourceRow],
                'label'=>'Buyer item '.$number.' specifies equipment type, not an exact part number.',
                'detail'=>'Ask the supplier to identify a proposed manufacturer, exact model and deviations. Do not invent a part number.'];
        }
    }
    if($byNumber) {
        $ids=array_keys($byNumber);sort($ids,SORT_NUMERIC);
        $lowest=$ids[0];$highest=end($ids);
        // Only flag gaps within the customer numbering range, never treat
        // intentional alternative rows as missing customer requirements.
        if($highest-$lowest<=1000) {
            for($i=$lowest;$i<=$highest;$i++)if(!isset($byNumber[$i])) {
                $issues[]=['type'=>'numbering_gap','items'=>[$i],'rows'=>[],
                    'label'=>'Buyer item number '.$i.' is absent.',
                    'detail'=>'Verify against the original buyer spreadsheet; numbering gaps alone do not prove missing equipment.'];
            }
        }
    }
    $counts=['possible_duplicates'=>0,'open_specifications'=>0,'numbering_gaps'=>0,'missing_quantities'=>0];
    foreach($issues as $issue) {
        $key=match($issue['type']) {
            'possible_duplicate'=>'possible_duplicates',
            'open_specification'=>'open_specifications',
            'numbering_gap'=>'numbering_gaps',
            default=>'missing_quantities'
        };
        $counts[$key]++;
    }
    return ['issues'=>$issues,'counts'=>$counts,
        'requested_entries'=>$primary,'alternative_options'=>$alternatives,
        'numbered_requirements'=>count($byNumber)];
}
