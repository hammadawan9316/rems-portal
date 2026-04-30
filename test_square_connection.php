<?php
// Test Square API connectivity
$url = 'https://connect.squareupsandbox.com/v2/invoices';
$token = 'EAAAlwt5Lr2MJLu-IlgwNcCHBcoiqhKbUZwK0PmzvwezMiWzme_8r6DDrYWwwWUA';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json",
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'query' => ['filter' => ['location_ids' => ['L9K3HSR1ZVA8P']]],
    'limit' => 1,
]));

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Error: {$error}\n";
echo "Response: {$response}\n";
?>
