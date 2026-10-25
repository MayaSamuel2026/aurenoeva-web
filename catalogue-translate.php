<?php
declare(strict_types=1);
/**
 * Browser-independent fallback for public catalogue descriptions.
 * Free open-source LibreTranslate-compatible API; no customer/RFQ data.
 * Never translates immutable product or enquiry identifiers.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST'); http_response_code(405);
    echo '{"ok":false,"error":"method_not_allowed"}'; exit;
}
try {
    $body=file_get_contents('php://input',false,null,0,2200);
    $input=json_decode((string)$body,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('invalid_body');
    $text=trim((string)($input['text']??''));
    $target=(string)($input['target']??'');
    if (!in_array($target,['en','de'],true) || $text==='' || strlen($text)>1000
        || !preg_match('//u',$text)) throw new InvalidArgumentException('invalid_text');
    if (preg_match('/^[A-Z0-9][A-Z0-9._+\-]{2,}$/D',$text)) {
        echo json_encode(['ok'=>true,'text'=>$text,'source'=>'identifier'],JSON_THROW_ON_ERROR); exit;
    }
    $cacheDir=sys_get_temp_dir().'/aurenoeva-public-translations';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir,0700,true) && !is_dir($cacheDir))
        throw new RuntimeException('translation_cache_unavailable');
    $cache=$cacheDir.'/'.hash('sha256',$target."\n".$text).'.json';
    if (is_file($cache)) {
        $stored=json_decode((string)@file_get_contents($cache),true);
        if (is_array($stored) && is_string($stored['text']??null)) {
            echo json_encode(['ok'=>true,'text'=>$stored['text'],'source'=>'cache'],
                JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
        }
    }
    // Low-cost public endpoint: bounded, cacheable supplier descriptions only.
    // Prevent an anonymous user from making unlimited upstream requests.
    $client=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $bucket=$cacheDir.'/rate-'.hash('sha256',$client.'|'.gmdate('YmdHi'));
    $handle=@fopen($bucket,'c+');
    if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('rate_unavailable');
    $used=(int)trim((string)stream_get_contents($handle));
    if ($used>=100) {
        flock($handle,LOCK_UN);fclose($handle);
        http_response_code(429);echo '{"ok":false,"error":"rate_limited"}';exit;
    }
    rewind($handle);ftruncate($handle,0);fwrite($handle,(string)($used+1));fflush($handle);
    flock($handle,LOCK_UN);fclose($handle);
    if (!function_exists('curl_init')) throw new RuntimeException('curl_unavailable');
    $url='https://translate.argosopentech.com/translate';
    $payload=json_encode(['q'=>$text,'source'=>'auto','target'=>$target,'format'=>'text'],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'AurenoevaCatalogueTranslation/1.0',
    ]);
    $result=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($result) || strlen($result)>8000 || $http!==200)
        throw new RuntimeException('translation_service_unavailable');
    $data=json_decode($result,true,16,JSON_THROW_ON_ERROR);
    $translated=trim((string)($data['translatedText']??''));
    if ($translated==='' || strlen($translated)>2500 || !preg_match('//u',$translated))
        throw new RuntimeException('translation_invalid_response');
    $tmp=@tempnam($cacheDir,'.translate-');
    if ($tmp!==false) {
        @chmod($tmp,0600);
        if (@file_put_contents($tmp,json_encode(['text'=>$translated],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)!==false)
            @rename($tmp,$cache);
        if (is_file($tmp))@unlink($tmp);
    }
    echo json_encode(['ok'=>true,'text'=>$translated,'source'=>'server'],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(422);echo '{"ok":false,"error":"invalid_request"}';
} catch (Throwable $e) {
    http_response_code(503);echo '{"ok":false,"error":"translation_unavailable"}';
}
