<?php
// 1. Cấu hình hệ thống
set_time_limit(0); 
ini_set('memory_limit', '512M');
ini_set('zlib.output_compression', 0);
ini_set('implicit_flush', 1);
ignore_user_abort(true);

define('AJAX_SCRIPT', true);
require('../../config.php');
require_login();

// 2. Thiết lập Header Streaming
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level()) { ob_end_clean(); }

global $USER, $DB;

try {
    // 3. Nhận dữ liệu
    $question = optional_param('question', '', PARAM_TEXT);
    $courseId = optional_param('course_id', optional_param('courseid', 0, PARAM_INT), PARAM_INT);
    $userId = (int)$USER->id;

    if (empty($question) || $courseId === 0) {
        echo "data: " . json_encode(['error' => 'Thiếu dữ cabbage: Question hoặc Course ID']) . "\n\n";
        exit;
    }

    // --- BƯỚC DEBUG CHIẾN THUẬT ---
    // Tạo thư mục data nếu chưa có
    $debugPath = __DIR__ . '/data';
    if (!is_dir($debugPath)) { @mkdir($debugPath, 0777, true); }
    
    // Ghi log NGAY LẬP TỨC để xác nhận request đã tới server
    $logMsg = "[" . date('H:i:s') . "] Request: User $userId, Course $courseId, Question: $question\n";
    file_put_contents($debugPath . '/request_log.txt', $logMsg, FILE_APPEND);

    // 4. Xử lý Logic qua Service
    $aiService = new \block_ai_tutor\service();
    $repo = $aiService->get_repo();
    
    // Lưu câu hỏi vào DB
    $repo->save_chat_log($userId, $courseId, 'user', $question);
    
    // Bắt đầu xây dựng Prompt (Đoạn này dễ gây treo nếu RAG lỗi)
    $systemPrompt = $aiService->build_context_prompt($courseId, $USER, $question);
    
    // Ghi file Prompt Debug ngay sau khi xây dựng xong để kiểm tra nội dung RAG bốc được gì
    file_put_contents($debugPath . '/prompt_debug.txt', "=== PROMPT [" . date('H:i:s') . "] ===\n" . $systemPrompt . "\n\n", FILE_APPEND);
    
    // 5. Gọi AI (Ollama)
    $full_ai_answer = $aiService->call_llm($question, $systemPrompt, $userId, $courseId);
    
    // 6. Lưu câu trả lời của AI
    if (!empty($full_ai_answer)) {
        $repo->save_chat_log($userId, $courseId, 'ai', $full_ai_answer);
    }

} catch (\Throwable $e) {
    // Ghi lỗi hệ thống vào log để không bị mất dấu
    $errorMsg = "[" . date('H:i:s') . "] ERROR: " . $e->getMessage() . " tại " . $e->getFile() . " dòng " . $e->getLine() . "\n";
    file_put_contents(__DIR__ . '/data/php_error.log', $errorMsg, FILE_APPEND);
    
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
}