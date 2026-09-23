<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/api/internal/v1/revenue-loop/event' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'not_found']);
    exit;
}
$body=json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)
    || ($body['vertical'] ?? '') !== 'aurenoeva'
    || ($body['site_id'] ?? '') !== 'aurenoeva.com'
    || !in_array(($body['event_type'] ?? ''), ['RECOMMENDATION_SHOWN','ENQUIRY_SUBMITTED'], true)
) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'invalid_event']);
    exit;
}
header('Content-Type: application/json');
http_response_code(201);
echo json_encode(['ok'=>true,'event'=>['inserted'=>true,'event_id'=>$body['event_id'] ?? null]]);
