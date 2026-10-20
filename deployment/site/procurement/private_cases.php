<?php
declare(strict_types=1);
/**
 * Single Hostinger-native AURENOEVA private procurement authority.
 * Case records + original evidence live in ONE directory, outside public_html.
 * No Python process, external service, or duplicate CRM.
 */
const AUR_MAX_BYTES = 20000000;
const AUR_MAX_ROWS = 5000;

function aur_store_root(): string {
    $docroot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($docroot === false || !is_dir($docroot)) throw new RuntimeException('invalid_web_document_root');
    $base = dirname($docroot);
    $root = $base . '/.aurenoeva-private';
    if (!is_dir($root) && !@mkdir($root, 0700, true)) throw new RuntimeException('private_storage_unavailable');
    $canonical = realpath($root);
    if (!$canonical || str_starts_with($canonical . '/', rtrim($docroot, '/') . '/')
        || !str_starts_with($canonical . '/', rtrim($base, '/') . '/')) {
        throw new RuntimeException('private_storage_location_invalid');
    }
    @chmod($canonical, 0700);
    return $canonical;
}
function aur_private_directory(string $store, string $sub): string {
    $path = $store . '/' . $sub;
    if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700))) {
        throw new RuntimeException('private_directory_unavailable');
    }
    return $path;
}
function aur_xml(string $value): SimpleXMLElement {
    $xml = @simplexml_load_string($value, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    if ($xml === false) throw new InvalidArgumentException('invalid_xlsx_xml');
    return $xml;
}
function aur_xlsx_rows(string $file): array {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('xlsx_support_disabled_in_hostinger_php');
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new InvalidArgumentException('invalid_xlsx_file');
    try {
        if ($zip->numFiles > 500) throw new InvalidArgumentException('suspicious_workbook');
        $unzipped = 0;
        for ($i=0; $i<$zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $unzipped += (int)($stat['size'] ?? 0);
            if ($unzipped > 35000000) throw new InvalidArgumentException('oversized_workbook');
        }
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $packageNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $workbook = aur_xml((string)$zip->getFromName('xl/workbook.xml'));
        $sheets = $workbook->children($mainNs)->sheets->children($mainNs)->sheet;
        if (count($sheets) < 1) throw new InvalidArgumentException('workbook_has_no_sheets');
        $rid = (string)$sheets[0]->attributes($relNs)['id'];
        $rels = aur_xml((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
        $target = 'worksheets/sheet1.xml';
        foreach ($rels->children($packageNs)->Relationship as $relationship) {
            if ((string)$relationship['Id'] === $rid) $target = (string)$relationship['Target'];
        }
        $target = ltrim($target, '/');
        if (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
        if (str_contains($target, '..') || !str_starts_with($target, 'xl/worksheets/')) {
            throw new InvalidArgumentException('invalid_worksheet_location');
        }
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $strings = aur_xml($sharedXml);
            foreach ($strings->children($mainNs)->si as $si) {
                $pieces=[];
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $part) $pieces[]=(string)$part;
                $shared[] = implode('', $pieces);
                if (count($shared) > 20000) throw new InvalidArgumentException('too_many_shared_strings');
            }
        }
        $worksheet = $zip->getFromName($target);
        if ($worksheet === false) throw new InvalidArgumentException('worksheet_missing');
        $xml = aur_xml($worksheet);
        $out = [];
        foreach ($xml->children($mainNs)->sheetData->children($mainNs)->row as $row) {
            $cells=[];
            foreach ($row->children($mainNs)->c as $cell) {
                $ref=(string)$cell['r'];
                if (!preg_match('/^([A-Z]+)/', $ref, $match)) continue;
                $column=0;
                foreach (str_split($match[1]) as $letter) $column=$column*26+ord($letter)-64;
                if ($column < 1 || $column > 40) continue;
                $type=(string)$cell['t'];
                $data=(string)$cell->children($mainNs)->v;
                if ($type === 's') {
                    $index = ctype_digit($data) ? (int)$data : -1;
                    $data = $shared[$index] ?? '';
                } elseif ($type === 'inlineStr') {
                    $fragments=[];
                    foreach ($cell->children($mainNs)->is->xpath('.//*[local-name()="t"]') ?: [] as $part) $fragments[]=(string)$part;
                    $data=implode('', $fragments);
                }
                $cells[$column-1] = $data;
            }
            $out[] = $cells ? array_replace(array_fill(0, max(array_keys($cells))+1, ''), $cells) : [];
            if (count($out) > AUR_MAX_ROWS) throw new InvalidArgumentException('too_many_rows');
        }
        return $out;
    } finally {
        $zip->close();
    }
}
function aur_csv_rows(string $file): array {
    $fp = fopen($file, 'rb');
    if (!$fp) throw new InvalidArgumentException('csv_unreadable');
    try {
        $sample = (string)fread($fp, 4096);
        $delim = ',';
        $counts = [','=>substr_count($sample, ','), ';'=>substr_count($sample, ';'), "\t"=>substr_count($sample, "\t")];
        arsort($counts);
        $delim = (string)array_key_first($counts);
        rewind($fp);
        $rows=[];
        while (($cells=fgetcsv($fp,0,$delim,'"','')) !== false) {
            if (count($rows)===0 && isset($cells[0])) $cells[0]=preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            $rows[]=$cells;
            if (count($rows)>AUR_MAX_ROWS) throw new InvalidArgumentException('too_many_rows');
        }
        return $rows;
    } finally { fclose($fp); }
}
function aur_lines(array $rows): array {
    $norm = static function($s): string {
        $v=trim(preg_replace('/\s+/u',' ',(string)$s) ?? (string)$s);
        return str_replace('.', '', function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v));
    };
    $titles=['equipment description','equipment specification (normalized verbatim)','description','description_original'];
    $header=-1;
    foreach (array_slice($rows,0,20) as $idx=>$r) {
        foreach ($r as $cell) if (in_array($norm($cell),$titles,true)) {$header=$idx;break 2;}
    }
    if ($header<0) throw new InvalidArgumentException('unsupported_headers_expected_equipment_description');
    $names=array_map($norm,$rows[$header]);
    $col = static function(array $choices) use ($names): ?int {
        foreach ($names as $idx=>$name) if(in_array($name,$choices,true)) return (int)$idx;
        return null;
    };
    $descCol=$col($titles);$numCol=$col(['no','buyer no','customer item number','customer_item_number']);
    $qtyCol=$col(['qty','quantity']);$selectCol=$col(['selection','option type','option_type']);
    if ($numCol===null || $descCol===null) throw new InvalidArgumentException('missing_customer_line_number_column');
    $lines=[];$last=null;
    foreach (array_slice($rows,$header+1) as $i=>$r) {
        $at=static fn(?int $index): string => trim((string)($index===null?'':($r[$index] ?? '')));
        $description=trim(preg_replace('/\s+/u',' ',$at($descCol)) ?? $at($descCol));
        if ($description==='') continue;
        $itemRaw=$at($numCol);
        if (preg_match('/^\d+(?:\.0+)?$/D',$itemRaw)) {
            $item=(int)(float)$itemRaw;$alt=$selectCol!==null && str_starts_with(strtolower($at($selectCol)),'alternative');
            $last=$item;
        } elseif ($itemRaw==='' && $last!==null) {
            $item=$last;$alt=true;
        } else throw new InvalidArgumentException('invalid_line_number_at_row_'.($header+$i+2));
        $qtyRaw=$at($qtyCol);
        if ($qtyRaw==='' || $qtyCol===null) $qty=null;
        else {
            if (!preg_match('/^\d+(?:\.0+)?$/D',$qtyRaw) || (float)$qtyRaw<1) {
                throw new InvalidArgumentException('invalid_quantity_at_row_'.($header+$i+2));
            }
            $qty=(int)(float)$qtyRaw;
        }
        $lines[]=['source_row'=>$header+$i+2,'customer_item_number'=>$item,
            'option_group'=>sprintf('ITEM-%02d',$item),
            'option_type'=>$alt?'ALTERNATIVE':'REQUESTED',
            'description_original'=>$description,'quantity'=>$qty,
            'canonical_part_id'=>null,'technical_status'=>'UNRESOLVED','rfq_status'=>'NOT_SENT'];
    }
    if (!$lines) throw new InvalidArgumentException('no_equipment_lines');
    return $lines;
}
function aur_case_import(string $store,string $source,string $country,string $originalName): array {
    if (!preg_match('/^[A-Z]{2}$/D',$country) || !is_file($source) || is_link($source)
        || filesize($source)<1 || filesize($source)>AUR_MAX_BYTES) throw new InvalidArgumentException('invalid_source');
    $extension=strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension,['csv','xlsx'],true)) throw new InvalidArgumentException('only_csv_xlsx_supported');
    $digest=hash_file('sha256',$source);
    $reference='AUR-'.$country.'-'.strtoupper(substr($digest,0,16));
    $records=aur_private_directory($store,'cases');
    $uploads=aur_private_directory($store,'uploads');
    $lock=fopen($store.'/case.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('private_case_lock_failed');
    try {
        $casepath="$records/$reference.json";
        if (is_file($casepath)) {
            $prior=aur_case_read($store,$reference);
            if (($prior['source_sha256'] ?? '') !== $digest) throw new RuntimeException('case_id_conflict');
            return $prior;
        }
        $rows=$extension==='csv'?aur_csv_rows($source):aur_xlsx_rows($source);
        $lines=aur_lines($rows);
        $sourcePath="$uploads/$reference.$extension";
        if (!is_file($sourcePath)) {
            if (!@copy($source,$sourcePath)) throw new RuntimeException('private_original_preservation_failed');
            @chmod($sourcePath,0600);
        }
        $payload=['schema'=>'aurenoeva-procurement-case-import/1.0',
            'case_reference'=>$reference,'enquiry_country'=>$country,'delivery_country'=>$country,
            'source_file_name'=>basename($originalName),'source_sha256'=>$digest,
            'import_state'=>'PERSISTED_PRIVATE_APP_CASE','catalogue_publication'=>false,
            'supplier_offers_received'=>0,'supplier_contact_conducted'=>false,
            'buyer_contracts_vendor_directly'=>true,'legal_status'=>'REVIEW_REQUIRED',
            'counts'=>['numbered_requirements'=>count(array_unique(array_column($lines,'customer_item_number'))),
              'equipment_entries'=>count($lines),'alternative_options'=>count(array_filter($lines,
                static fn(array $line):bool=>$line['option_type']==='ALTERNATIVE'))],
            'sourcing_lines'=>$lines];
        $case=['ok'=>true,'reference'=>$reference,'country'=>$country,'source_sha256'=>$digest,
            'original_name'=>basename($originalName),'created_at'=>gmdate('c'),
            'line_count'=>count($lines),'requirements'=>$payload['counts']['numbered_requirements'],
            'alternatives'=>$payload['counts']['alternative_options'],
            'import_state'=>'PERSISTED_PRIVATE_APP_CASE','payload'=>$payload,'legal_review'=>null];
        $tmp="$records/.new-".bin2hex(random_bytes(12));
        $bytes=json_encode($case,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('private_case_write_failed');
        @chmod($tmp,0600);
        if (!rename($tmp,$casepath)) throw new RuntimeException('private_case_commit_failed');
        $back=aur_case_read($store,$reference);
        if ($back['line_count']!==count($lines) || $back['source_sha256']!==$digest) {
            throw new RuntimeException('private_case_readback_failed');
        }
        return $back;
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
}
function aur_case_read(string $store,string $reference): array {
    if (!preg_match('/^AUR-[A-Z]{2}-[A-F0-9]{16}$/D',$reference)) {
        throw new InvalidArgumentException('invalid_case_reference');
    }
    $path=$store.'/cases/'.$reference.'.json';
    if (!is_file($path) || is_link($path)) throw new InvalidArgumentException('case_not_found');
    $case=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if (!is_array($case) || ($case['reference'] ?? '')!==$reference) {
        throw new RuntimeException('invalid_case_readback');
    }
    return $case;
}
function aur_case_list(string $store): array {
    $dir=aur_private_directory($store,'cases');
    $files=glob($dir.'/AUR-??-*.json') ?: [];
    usort($files,static fn($a,$b)=>(filemtime($b) ?: 0)<=>(filemtime($a) ?: 0));
    $out=[];
    foreach (array_slice($files,0,250) as $path) {
        try {
            $case=aur_case_read($store,pathinfo($path,PATHINFO_FILENAME));
            $out[]=['reference'=>$case['reference'],'country'=>$case['country'],
                'line_count'=>$case['line_count'],'created_at'=>$case['created_at']];
        } catch (Throwable $e) {error_log('AURENOEVA case list: corrupted record');}
    }
    return ['ok'=>true,'cases'=>$out];
}
