#!/usr/bin/env php
<?php

// Test Integration API endpoints

// You need to replace this with an actual organization UUID
$orgUuid = 'YOUR-ORG-UUID-HERE';
$baseUrl = 'http://localhost:3979/api/integration/' . $orgUuid;
$auth = base64_encode('workoflow:workoflow');

// Test 1: List tools without workflow ID (should fail)
echo "Test 1: List tools without workflow ID\n";
$ch = curl_init($baseUrl . '/tools');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . $auth,
    'Accept: application/json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";

// Test 2: List tools with workflow ID
echo "Test 2: List tools with workflow ID\n";
$ch = curl_init($baseUrl . '/tools?id=test-workflow-id');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . $auth,
    'Accept: application/json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";

// Test 3: Execute tool
echo "Test 3: Execute tool (test request)\n";
$data = [
    'tool_id' => 'jira_search_1',
    'parameters' => [
        'jql' => 'project = TEST',
        'maxResults' => 10
    ]
];

$ch = curl_init($baseUrl . '/execute');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . $auth,
    'Content-Type: application/json',
    'Accept: application/json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";

// Test 4: Test without auth (should fail)
echo "Test 4: Request without authentication\n";
$ch = curl_init($baseUrl . '/tools?id=test-workflow-id');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n\n";