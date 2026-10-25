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
    // The original community LibreTranslate host is intermittently unavailable.
    // Try the documented free MyMemory segment API for short, public product
    // descriptions first. Keep LibreTranslate as an independent fallback.
    // Customer enquiries, contact details and RFQ records never use this route.
    $source='en';
    if (preg_match('/[\\x{0400}-\\x{052F}]/u',$text)) $source='ru';
    elseif (preg_match('/[\\x{3040}-\\x{30FF}]/u',$text)) $source='ja';
    elseif (preg_match('/[\\x{AC00}-\\x{D7AF}]/u',$text)) $source='ko';
    elseif (preg_match('/[\\x{4E00}-\\x{9FFF}]/u',$text)) $source='zh';
    elseif (preg_match('/\\b(?:la|el|las|los|una|funciona|correctamente|configuraciones|detección|evita)\\b/iu',$text)) $source='es';
    elseif (preg_match('/\\b(?:les|des|avec|pour|logiciels|séries|détection|équipement)\\b/iu',$text)) $source='fr';
    elseif (preg_match('/\\b(?:der|die|das|und|für|nicht|prüfung|erkennung|gerät|einstellungen)\\b/iu',$text)) $source='de';
    elseif (preg_match('/\\b(?:della|delle|dell|sono|attrezzature|rilevamento)\\b/iu',$text)) $source='it';
    $translated='';
    $provider='';
    if ($source!==$target && strlen($text)<=500) {
        $params=http_build_query(['q'=>$text,'langpair'=>$source.'|'.$target,
            'de'=>'info@aurenoeva.com'], '', '&', PHP_QUERY_RFC3986);
        $ch=curl_init('https://api.mymemory.translated.net/get?'.$params);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>7,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_USERAGENT=>'AurenoevaPublicCatalogue/1.0']);
        $raw=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($raw) && strlen($raw)<=10000 && $http===200) {
            $data=json_decode($raw,true);
            if (is_array($data) && (int)($data['responseStatus']??0)===200) {
                $candidate=html_entity_decode(trim((string)(
                    $data['responseData']['translatedText']??'')),
                    ENT_QUOTES|ENT_HTML5,'UTF-8');
                if ($candidate!=='' && $candidate!==$text
                    && !str_starts_with(strtoupper($candidate),'MYMEMORY WARNING')
                    && strlen($candidate)<=2500 && preg_match('//u',$candidate)) {
                    $translated=$candidate;
                    $provider='mymemory';
                }
            }
        }
    }
    if ($translated==='') {
        $url='https://translate.argosopentech.com/translate';
        $payload=json_encode(['q'=>$text,'source'=>'auto','target'=>$target,'format'=>'text'],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'AurenoevaCatalogueTranslation/1.0',
        ]);
        $raw=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($raw) && strlen($raw)<=8000 && $http===200) {
            $data=json_decode($raw,true);
            if (is_array($data)) {
                $candidate=trim((string)($data['translatedText']??''));
                if ($candidate!=='' && $candidate!==$text
                    && strlen($candidate)<=2500 && preg_match('//u',$candidate)) {
                    $translated=$candidate;
                    $provider='libretranslate';
                }
            }
        }
    }
    if ($translated==='') throw new RuntimeException('translation_providers_unavailable');
    $tmp=@tempnam($cacheDir,'.translate-');
    if ($tmp!==false) {
        @chmod($tmp,0600);
        if (@file_put_contents($tmp,json_encode(['text'=>$translated],
            JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)!==false)
            @rename($tmp,$cache);
        if (is_file($tmp))@unlink($tmp);
    }
    echo json_encode(['ok'=>true,'text'=>$translated,'source'=>$provider],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(422);echo '{"ok":false,"error":"invalid_request"}';
} catch (Throwable $e) {
    http_response_code(503);echo '{"ok":false,"error":"translation_unavailable"}';
}
