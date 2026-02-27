<?php

// Test the share_file tool with PPTX file

$orgUuid = '0cd3a714-adc1-4540-bd08-7316a80b34f3';
$workflowUserId = '45908692-019e-4436-810c-b417f58f5f4f';
$authUser = 'workoflow';
$authPassword = 'workoflow';

// For testing, we'll create a minimal PPTX structure
// In real usage, you would read an actual PPTX file
$testContent = base64_encode("Test PPTX content"); // Placeholder

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
        'contentType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
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
        echo "Expected file extension: .pptx\n";
        
        // Extract the file ID from the URL to show what the downloaded filename would be
        if (isset($result['result']['fileId'])) {
            echo "File will be downloaded as: {$result['result']['fileId']}.pptx\n";
        }
    }
}