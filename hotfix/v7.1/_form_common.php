<?php
declare(strict_types=1);

function aur_text(string $key, int $max = 4000): string {
    $value = $_POST[$key] ?? '';
    if (is_array($value)) { return ''; }
    $value = trim((string)$value);
    $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($len > $max) {
        $value = function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
    return $value;
}

function aur_safe_header(string $value): string {
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function aur_same_origin_ok(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') { return true; }
    $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    $serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $serverHost = preg_replace('/:\d+$/', '', $serverHost) ?? $serverHost;
    return $originHost !== '' && hash_equals($serverHost, $originHost);
}

function aur_rate_limit(string $scope, int $seconds = 15): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', date('Y-m-d') . '|' . $scope . '|' . $ip);
    $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'aur_' . substr($key, 0, 32) . '.rate';
    $now = time();
    $last = is_file($file) ? (int)@file_get_contents($file) : 0;
    if ($last > 0 && ($now - $last) < $seconds) { return false; }
    @file_put_contents($file, (string)$now, LOCK_EX);
    return true;
}

function aur_send(string $subject, string $body, string $replyTo): bool {
    if (getenv('AURENOEVA_FORM_TEST_MODE') === '1') { return true; }

    $to = 'info@aurenoeva.com';
    $from = aur_safe_header((string)(getenv('AURENOEVA_FORM_FROM') ?: 'info@aurenoeva.com'));
    $subject = aur_safe_header($subject);
    $replyTo = aur_safe_header($replyTo);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: Aurenoeva Website <' . $from . '>',
        'Sender: ' . $from,
        'Reply-To: ' . $replyTo,
        'X-Mailer: Aurenoeva Website'
    ];
    $headerText = implode("\r\n", $headers);

    // Hostinger's web-hosting mail transport is more reliable when the
    // envelope sender is explicitly aligned with the site's own domain.
    $sent = false;
    if (function_exists('mail')) {
        $sent = @mail($to, $subject, $body, $headerText, '-f' . $from);
        if (!$sent) {
            // Graceful fallback for hosts that do not allow the fifth mail()
            // parameter even though their local MTA accepts normal PHP mail.
            $sent = @mail($to, $subject, $body, $headerText);
        }
    }

    if (!$sent) {
        error_log('Aurenoeva form delivery failed for subject: ' . $subject);
    }
    return $sent;
}

function aur_json(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aur_redirect(string $url): never {
    header('Location: ' . $url, true, 303);
    exit;
}
