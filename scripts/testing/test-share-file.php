<?php

// Test the share_file tool

$orgUuid = '0cd3a714-adc1-4540-bd08-7316a80b34f3';
$workflowUserId = '45908692-019e-4436-810c-b417f58f5f4f';
$authUser = 'workoflow';
$authPassword = 'workoflow';

// Read the actual PDF file
$pdfPath = __DIR__ . '/dummy.pdf';
if (!file_exists($pdfPath)) {
    die("Error: PDF file not found at {$pdfPath}\n");
}
$pdfContent = file_get_contents($pdfPath);
if ($pdfContent === false) {
    die("Error: Failed to read PDF file\n");
}
$testContent = base64_encode($pdfContent);

// Prepare the request
$url = "http://localhost:3979/api/integration/{$orgUuid}/execute?id={$workflowUserId}";
$headers = [
    'Authorization: Basic ' . base64_encode("{$authUser}:{$authPassword}"),
    'Content-Type: application/json'
];

$data = [
    'tool_id' => 'share_file',
    'parameters' => [
        'binaryData' => $testContent,
//        'contentType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        'contentType' => 'application/pdf'
    ]
];

// Execute the request
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Response: {$response}\n";

if ($httpCode === 200) {
    $result = json_decode($response, true);
    if (isset($result['result']['url'])) {
        echo "\nShared file URL: " . $result['result']['url'] . "\n";
        
        // Test accessing the shared file
        $ch2 = curl_init($result['result']['url']);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_NOBODY, true);
        curl_exec($ch2);
        $fileHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        echo "File access test - HTTP Code: {$fileHttpCode}\n";
    }
}
