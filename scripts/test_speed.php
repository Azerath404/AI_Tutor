<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../classes/service.php');

$courseid = 3;
$question = "Trong slide 9 của file C02.2, liệt kê 4 thông tin cần xác định khi viết hàm là gì?";

// Lấy admin user
$user = $DB->get_record('user', ['username' => 'admin']);
if (!$user) {
    die("User admin not found.\n");
}

echo "Bắt đầu đo thời gian...\n";
$start_time = microtime(true);

$service = new \block_ai_tutor\service();
$systemPrompt = $service->build_context_prompt($courseid, $user, $question);

$prompt_time = microtime(true);
echo "1. Thời gian xây dựng ngữ cảnh (RAG): " . round($prompt_time - $start_time, 2) . "s\n";
echo "Độ dài Prompt: " . strlen($systemPrompt) . " ký tự.\n";

$first_token_time = null;
$full_answer = "";

echo "2. Đang gửi tới Ollama (Streaming)...\n";
$service->call_llm_stream($question, $systemPrompt, function($chunk) use (&$first_token_time, &$full_answer, $prompt_time) {
    if ($first_token_time === null) {
        $first_token_time = microtime(true);
        echo " -> Time to First Token (TTFT): " . round($first_token_time - $prompt_time, 2) . "s\n";
    }
    $full_answer .= $chunk;
    echo $chunk;
});

$end_time = microtime(true);
echo "\n----------------------------------------\n";
echo "3. KẾT QUẢ ĐO THỜI GIAN:\n";
echo "- RAG + Build Prompt: " . round($prompt_time - $start_time, 2) . "s\n";
echo "- LLM Time to First Token: " . round($first_token_time - $prompt_time, 2) . "s\n";
echo "- LLM Stream Completion: " . round($end_time - $first_token_time, 2) . "s\n";
echo "- TỔNG CỘNG TỪ LÚC GỬI ĐẾN KHI XONG: " . round($end_time - $start_time, 2) . "s\n";
echo "----------------------------------------\n";
