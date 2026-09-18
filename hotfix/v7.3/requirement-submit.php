<?php
declare(strict_types=1);
require __DIR__ . '/_form_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { aur_json(['ok' => false, 'error' => 'method'], 405); }
if (!aur_same_origin_ok()) { aur_json(['ok' => false, 'error' => 'origin'], 403); }
if (aur_text('website', 200) !== '') { aur_json(['ok' => true, 'reference' => 'AUR-RECEIVED']); }
if (!aur_rate_limit('requirement', 12)) { aur_json(['ok' => false, 'error' => 'rate_limit'], 429); }

$lang = aur_text('lang', 2) === 'de' ? 'de' : 'en';
$mode = aur_text('mode', 40);
$industry = aur_text('industry', 120);
$category = aur_text('category', 180);
$identifier = aur_text('identifier', 250);
$requirement = aur_text('requirement', 5000);
$condition = aur_text('condition', 120);
$quantity = aur_text('quantity', 120);
$delivery = aur_text('delivery_location', 180);
$needBy = aur_text('need_by', 160);
$budget = aur_text('budget', 160);
$evidence = aur_text('evidence', 2000);
$company = aur_text('company', 180);
$name = aur_text('name', 120);
$email = aur_text('email', 190);
$selectedPartsRaw = aur_text('selected_parts', 16000);
$selectedParts = [];
if ($selectedPartsRaw !== '') {
    $decoded = json_decode($selectedPartsRaw, true);
    if (is_array($decoded)) {
        foreach (array_slice($decoded, 0, 20) as $row) {
            if (!is_array($row)) { continue; }
            $canonicalId = trim((string)($row['canonical_id'] ?? ''));
            $manufacturerId = trim((string)($row['manufacturer_id'] ?? ''));
            $manufacturer = trim((string)($row['manufacturer'] ?? ''));
            $partNumber = trim((string)($row['part_number'] ?? ''));
            $model = trim((string)($row['model'] ?? ''));
            $description = trim((string)($row['description'] ?? ''));
            $identifierType = trim((string)($row['identifier_type'] ?? ''));
            $lifecycleStatus = trim((string)($row['lifecycle_status'] ?? ''));
            $relationshipType = trim((string)($row['relationship_type'] ?? ''));
            $sourceRef = trim((string)($row['source_ref'] ?? ''));
            $confidence = isset($row['confidence']) && is_numeric($row['confidence']) ? max(0, min(1, (float)$row['confidence'])) : null;
            $originalSearch = trim((string)($row['original_search_query'] ?? ''));
            $selectedAt = trim((string)($row['selected_at'] ?? ''));
            if ($partNumber === '' && $model === '' && $canonicalId === '') { continue; }
            $selectedParts[] = [
                'canonical_id' => substr($canonicalId, 0, 220),
                'manufacturer_id' => substr($manufacturerId, 0, 220),
                'manufacturer' => substr($manufacturer, 0, 220),
                'part_number' => substr($partNumber, 0, 220),
                'model' => substr($model, 0, 220),
                'description' => substr($description, 0, 500),
                'identifier_type' => substr($identifierType, 0, 80),
                'lifecycle_status' => substr($lifecycleStatus, 0, 120),
                'relationship_type' => substr($relationshipType, 0, 120),
                'confidence' => $confidence,
                'source_ref' => substr($sourceRef, 0, 220),
                'original_search_query' => substr($originalSearch, 0, 220),
                'selected_at' => substr($selectedAt, 0, 80)
            ];
        }
    }
}

if (($identifier === '' && $requirement === '' && count($selectedParts) === 0) || $company === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    aur_json(['ok' => false, 'error' => 'validation'], 422);
}

try { $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); }
catch (Throwable $e) { $suffix = strtoupper(substr(hash('sha256', microtime(true) . $email), 0, 6)); }
$reference = 'AUR-' . gmdate('Ymd-His') . '-' . $suffix;
$subject = '[Aurenoeva requirement ' . $reference . '] ' . ($category !== '' ? $category : $industry);
$selectedText = "None selected";
if ($selectedParts) {
    $lines = [];
    foreach ($selectedParts as $i => $part) {
        $label = trim(($part['manufacturer'] !== '' ? $part['manufacturer'] . ' · ' : '') . ($part['part_number'] !== '' ? $part['part_number'] : $part['model']));
        $detail = [];
        if ($part['model'] !== '' && $part['model'] !== $part['part_number']) { $detail[] = 'model=' . $part['model']; }
        if ($part['canonical_id'] !== '') { $detail[] = 'canonical_id=' . $part['canonical_id']; }
        if ($part['relationship_type'] !== '') { $detail[] = 'relationship=' . $part['relationship_type']; }
        if ($part['confidence'] !== null) { $detail[] = 'confidence=' . number_format($part['confidence'], 3, '.', ''); }
        if ($part['source_ref'] !== '') { $detail[] = 'source_ref=' . $part['source_ref']; }
        $lines[] = ($i + 1) . '. ' . $label . ($detail ? " [" . implode('; ', $detail) . "]" : '');
    }
    $selectedText = implode("\n", $lines);
}
$body = "AURENOEVA PROCUREMENT REQUIREMENT\n\n"
      . "Reference: {$reference}\n"
      . "Language: {$lang}\n"
      . "Starting mode: {$mode}\n"
      . "Sector: {$industry}\n"
      . "Category: {$category}\n"
      . "Raw identifier / search input: " . ($identifier !== '' ? $identifier : 'Not specified') . "\n"
      . "Selected catalogue parts:\n{$selectedText}\n"
      . "Requirement: " . ($requirement !== '' ? $requirement : 'Not specified') . "\n"
      . "Condition: {$condition}\n"
      . "Quantity: " . ($quantity !== '' ? $quantity : 'Not specified') . "\n"
      . "Delivery location: " . ($delivery !== '' ? $delivery : 'Not specified') . "\n"
      . "Need by: " . ($needBy !== '' ? $needBy : 'Not specified') . "\n"
      . "Budget: " . ($budget !== '' ? $budget : 'Not specified') . "\n"
      . "Evidence requested: " . ($evidence !== '' ? $evidence : 'None specified') . "\n\n"
      . "Company: {$company}\n"
      . "Name: {$name}\n"
      . "Email: {$email}\n\n"
      . "Submitted: " . gmdate('Y-m-d H:i:s') . " UTC\n"
      . "Selected parts JSON: " . json_encode($selectedParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (!aur_send($subject, $body, $email)) { aur_json(['ok' => false, 'error' => 'delivery'], 500); }
aur_json(['ok' => true, 'reference' => $reference]);
