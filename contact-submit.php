<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}
if (!aur_same_origin_ok()) { aur_redirect('about.html?contact=error#contact'); }
if (aur_text('website', 200) !== '') { aur_redirect('about.html?contact=sent#contact'); }

$lang = aur_text('lang', 2) === 'de' ? 'de' : 'en';
$name = aur_text('name', 120);
$email = aur_text('email', 190);
$company = aur_text('company', 180);
$type = aur_text('enquiry_type', 40);
$message = aur_text('message', 5000);
$privacy = aur_text('privacy_ack', 4);

$types = [
    'general' => 'General enquiry',
    'supplier' => 'Supplier / manufacturer',
    'partnership' => 'Partnership / press',
    'existing' => 'Existing procurement case'
];

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || (function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message)) < 10 || $privacy !== '1' || !isset($types[$type])) {
    aur_redirect('about.html?contact=error#contact');
}

// Apply throttling only after a valid submission. This prevents an incomplete
// attempt from locking the user out while correcting the form.
if (!aur_rate_limit('contact', 12)) { aur_redirect('about.html?contact=error#contact'); }

$subject = '[Aurenoeva website] ' . $types[$type] . ' — ' . ($company !== '' ? $company : $name);
$body = "AURENOEVA WEBSITE ENQUIRY\n\n"
      . "Type: {$types[$type]}\n"
      . "Language: {$lang}\n"
      . "Name: {$name}\n"
      . "Company: " . ($company !== '' ? $company : 'Not specified') . "\n"
      . "Email: {$email}\n\n"
      . "Message:\n{$message}\n\n"
      . "Submitted: " . gmdate('Y-m-d H:i:s') . " UTC\n";

if (!aur_send($subject, $body, $email)) {
    aur_redirect('about.html?contact=error#contact');
}
aur_redirect('about.html?contact=sent#contact');
