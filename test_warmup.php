<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$ollama_url = get_config('block_ai_tutor', 'ollama_url');
$model      = get_config('block_ai_tutor', 'ollama_model');

echo "Ollama URL: $ollama_url\n";
echo "Ollama Model: $model\n\n";

// Test 1: calling /api/ps
echo "--- Testing /api/ps ---\n";
$ch = curl_init($ollama_url . '/api/ps');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => ['ngrok-skip-browser-warning: true'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "HTTP Code: $code\n";
echo "Raw Response: $raw\n\n";

// Test 3: calling /api/generate to load the model
echo "--- Testing /api/generate (Warmup) ---\n";
$payload = json_encode([
    'model'      => $model,
    'prompt'     => 'Xin chao',
    'stream'     => false,
    'keep_alive' => '120m',
    'options'    => [
        'num_predict' => 1,
        'num_ctx'     => 2048,
        'num_thread'  => 6,
    ],
]);

$ch = curl_init($ollama_url . '/api/generate');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'ngrok-skip-browser-warning: true'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 30, // 30s to allow loading
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$start = microtime(true);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$elapsed = microtime(true) - $start;
curl_close($ch);

echo "HTTP Code: $code\n";
echo "Time elapsed: " . round($elapsed, 2) . " seconds\n";
echo "Error: $err\n";
echo "Raw Response: $raw\n\n";

// Test 4: calling /api/ps again after generate
echo "--- Testing /api/ps again ---\n";
$ch = curl_init($ollama_url . '/api/ps');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => ['ngrok-skip-browser-warning: true'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "HTTP Code: $code\n";
echo "Raw Response: $raw\n\n";
