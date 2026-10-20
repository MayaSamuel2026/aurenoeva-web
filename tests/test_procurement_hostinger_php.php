<?php
declare(strict_types=1);
require dirname(__DIR__).'/deployment/site/procurement/private_cases.php';
$root=sys_get_temp_dir().'/aurenoeva-qa-'.bin2hex(random_bytes(6));
mkdir($root.'/public_html',0700,true);
$_SERVER['DOCUMENT_ROOT']=$root.'/public_html';
function check(bool $condition,string $why):void {if(!$condition)throw new RuntimeException($why);}
function csvFile(string $dir,string $name):string {
  $file="$dir/$name";
  file_put_contents($file,"No.,Equipment description,Qty.,Selection\n1,Mindray WATO EX-35,2,Requested\n,Alternative ventilator,,Alternative\n2,1.5T MRI scanner,1,Requested\n");
  return $file;
}
try {
    $store=aur_store_root();
    $src=csvFile($root,'test.csv');
    $one=aur_case_import($store,$src,'UZ','original.csv');
    check($one['ok']===true && $one['line_count']===3,'CSV item count');
    check($one['requirements']===2 && $one['alternatives']===1,'alternative detection');
    $twice=aur_case_import($store,$src,'UZ','original.csv');
    check($twice['reference']===$one['reference'],'duplicate not detected');
    $de=aur_case_import($store,$src,'DE','second.csv');
    check($de['country']==='DE' && $de['reference']!==$one['reference'],'country scoped identity');
    check(count(aur_case_list($store)['cases'])===2,'case listing did not deduplicate');
    check($one['payload']['supplier_contact_conducted']===false,'incorrect supplier send');
    check($one['payload']['legal_status']==='REVIEW_REQUIRED','incorrect legal clearance');
    check(is_file($store.'/uploads/'.$one['reference'].'.csv'),'original evidence absent');
    check(!str_starts_with(realpath($store),realpath($root.'/public_html')),'unsafe private directory');

    if (class_exists('ZipArchive') && function_exists('simplexml_load_string')) {
        $file=$root.'/sample.xlsx';$z=new ZipArchive();
        check($z->open($file, ZipArchive::CREATE)===true,'cannot create workbook fixture');
        $z->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml','<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>No.</t></si><si><t>Equipment description</t></si><si><t>Qty.</t></si><si><t>MRI scanner</t></si><si><t>Alternative MRI</t></si></sst>');
        $z->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row><row r="2"><c r="A2"><v>1</v></c><c r="B2" t="s"><v>3</v></c><c r="C2"><v>1</v></c></row><row r="3"><c r="B3" t="s"><v>4</v></c></row></sheetData></worksheet>');
        $z->close();
        $xlsxRows=aur_xlsx_rows($file);
        fwrite(STDERR,"AURENOEVA_XLSX_DIAGNOSTIC=".json_encode(array_slice($xlsxRows,0,4))."\n");
        $xl=aur_case_import($store,$file,'UZ','customer.xlsx');
        check($xl['line_count']===2 && $xl['alternatives']===1,'XLSX alternative parsing failed');
    } else throw new RuntimeException('Required ZIP and SimpleXML extensions unavailable');
    echo "AURENOEVA_NATIVE_PHP_IMPORT=PASS\nAURENOEVA_PRIVATE_STORAGE=PASS\nAURENOEVA_COUNTRY_DUPLICATE=PASS\n";
} finally {
    // Test fixtures only, never production files.
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item){$item->isDir()?@rmdir((string)$item):@unlink((string)$item);}
    @rmdir($root);
}
