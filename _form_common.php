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

function aur_canonical_host(string $host): string {
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $host = rtrim($host, '.');
    if (str_starts_with($host, 'www.')) { $host = substr($host, 4); }
    return $host;
}

function aur_same_origin_ok(): bool {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') { return true; }

    $originHost = aur_canonical_host((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    $serverHost = aur_canonical_host((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($originHost === '' || $serverHost === '') { return false; }
    return hash_equals($serverHost, $originHost);
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

function aur_smtp_read($socket): string {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) { break; }
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') { break; }
    }
    return $response;
}

function aur_smtp_expect($socket, array $codes): bool {
    $response = aur_smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    return in_array($code, $codes, true);
}

function aur_smtp_write($socket, string $line, array $expect): bool {
    if (fwrite($socket, $line . "\r\n") === false) { return false; }
    return aur_smtp_expect($socket, $expect);
}

function aur_send_via_smtp(string $to, string $subject, string $body, string $replyTo, string $from): bool {
    $host = trim((string)(getenv('AURENOEVA_SMTP_HOST') ?: ''));
    $user = trim((string)(getenv('AURENOEVA_SMTP_USER') ?: ''));
    $pass = (string)(getenv('AURENOEVA_SMTP_PASSWORD') ?: '');
    if ($host === '' || $user === '' || $pass === '') { return false; }

    $port = (int)(getenv('AURENOEVA_SMTP_PORT') ?: 465);
    $security = strtolower(trim((string)(getenv('AURENOEVA_SMTP_SECURITY') ?: ($port === 465 ? 'ssl' : 'tls'))));
    $transport = $security === 'ssl' ? 'ssl://' . $host : $host;

    $errno = 0; $errstr = '';
    $socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) { return false; }
    stream_set_timeout($socket, 8);

    $ok = aur_smtp_expect($socket, [220]);
    if ($ok) { $ok = aur_smtp_write($socket, 'EHLO aurenoeva.com', [250]); }

    if ($ok && $security === 'tls') {
        $ok = aur_smtp_write($socket, 'STARTTLS', [220]);
        if ($ok) {
            $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok) { $ok = aur_smtp_write($socket, 'EHLO aurenoeva.com', [250]); }
        }
    }

    if ($ok) { $ok = aur_smtp_write($socket, 'AUTH LOGIN', [334]); }
    if ($ok) { $ok = aur_smtp_write($socket, base64_encode($user), [334]); }
    if ($ok) { $ok = aur_smtp_write($socket, base64_encode($pass), [235]); }
    if ($ok) { $ok = aur_smtp_write($socket, 'MAIL FROM:<' . $from . '>', [250]); }
    if ($ok) { $ok = aur_smtp_write($socket, 'RCPT TO:<' . $to . '>', [250, 251]); }
    if ($ok) { $ok = aur_smtp_write($socket, 'DATA', [354]); }

    if ($ok) {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = implode("\r\n", [
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: Aurenoeva Website <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@aurenoeva.com>',
            '',
            str_replace(["\r\n.\r\n", "\n.\n"], ["\r\n..\r\n", "\n..\n"], $body),
            '.'
        ]);
        if (fwrite($socket, $message . "\r\n") === false) {
            $ok = false;
        } else {
            $ok = aur_smtp_expect($socket, [250]);
        }
    }

    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return (bool)$ok;
}

function aur_send_via_sendmail(string $to, string $subject, string $body, string $replyTo, string $from): bool {
    if (!function_exists('proc_open')) { return false; }

    $candidates = [];
    $configured = trim((string)ini_get('sendmail_path'));
    if ($configured !== '') {
        $binary = preg_split('/\s+/', $configured, 2)[0] ?? '';
        if ($binary !== '') { $candidates[] = $binary; }
    }
    $candidates[] = '/usr/sbin/sendmail';
    $candidates[] = '/usr/lib/sendmail';

    $binary = '';
    foreach (array_unique($candidates) as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }
    if ($binary === '') { return false; }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $message = implode("\r\n", [
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: Aurenoeva Website <' . $from . '>',
        'Sender: ' . $from,
        'Reply-To: ' . $replyTo,
        'Date: ' . date(DATE_RFC2822),
        '',
        $body,
        ''
    ]);

    $cmd = escapeshellarg($binary) . ' -t -i -f ' . escapeshellarg($from);
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = @proc_open($cmd, $spec, $pipes);
    if (!is_resource($process)) { return false; }

    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0 && $stderr !== '') {
        error_log('Aurenoeva sendmail transport failed: ' . substr(aur_safe_header($stderr), 0, 300));
    }
    return $status === 0;
}

function aur_send(string $subject, string $body, string $replyTo): bool {
    if (getenv('AURENOEVA_FORM_TEST_MODE') === '1') { return true; }

    $to = 'info@aurenoeva.com';
    $from = aur_safe_header((string)(getenv('AURENOEVA_FORM_FROM') ?: 'info@aurenoeva.com'));
    $subject = aur_safe_header($subject);
    $replyTo = aur_safe_header($replyTo);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) { $from = 'info@aurenoeva.com'; }
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $replyTo = $from; }

    // Prefer authenticated SMTP when hosting credentials are configured.
    if (aur_send_via_smtp($to, $subject, $body, $replyTo, $from)) { return true; }

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

    // Hostinger/local MTA path, with and without explicit envelope sender.
    if (function_exists('mail')) {
        if (@mail($to, $subject, $body, $headerText, '-f' . $from)) { return true; }
        if (@mail($to, $subject, $body, $headerText)) { return true; }
    }

    // Direct sendmail fallback for shared-hosting configurations where mail()
    // is disabled or rejects PHP's fifth parameter while the local MTA works.
    if (aur_send_via_sendmail($to, $subject, $body, $replyTo, $from)) { return true; }

    error_log('Aurenoeva form delivery failed after SMTP, mail() and sendmail fallbacks for subject: ' . $subject);
    return false;
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
