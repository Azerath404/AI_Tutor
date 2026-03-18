<?php
define('AJAX_SCRIPT', true);
require('../../config.php');

// Tắt hiển thị lỗi HTML để không làm hỏng JSON
error_reporting(E_ALL); // Nên log lỗi vào server log thay vì tắt hẳn để dễ debug
ini_set('display_errors', 0); // Tắt hiển thị ra màn hình

// Bắt buộc đăng nhập
require_login(); 

// Thiết lập Header trả về JSON
header('Content-Type: application/json; charset=utf-8');
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
// Xóa bộ đệm đầu ra để đảm bảo không có HTML thừa (ví dụ warning của Moodle) lọt vào JSON
while (ob_get_level()) {
    ob_end_clean();
}

try {
    // 1. INPUT: Nhận dữ liệu đầu vào & Validate (Controller logic)
    $question = optional_param('question', '', PARAM_TEXT);
    $courseId = optional_param('course_id', 1, PARAM_INT);
    $userId = $USER->id;

    if (empty($question)) {
        echo "data: " . json_encode(['error' => 'Câu hỏi trống']) . "\n\n";
        exit;
    }

    // 2. PROCESS: Gọi Service Layer để xử lý
    // Khởi tạo Service (Dependency Injection could happen here in a framework like Laravel)
    $aiService = new \block_ai_tutor\service();
    $repo = $aiService->get_repo();
    
    // Bước 2.1: Lưu câu hỏi user vào db
    $repo->save_chat_log($userId, $courseId, 'user', $question);
    
    // Bước 2.2: Gọi AI
    $systemPrompt = $aiService->build_context_prompt($courseId, $USER);
    $full_ai_answer = $aiService->call_llm($question, $systemPrompt, $userId, $courseId);
    
    // 3. Sau khi AI gõ xong, lưu toàn bộ câu trả lời vào db
    if (!empty($full_ai_answer)) {
        $repo->save_chat_log($userId, $courseId, 'ai', $full_ai_answer);
    }

} catch (Exception $e) {
    // Nếu có lỗi, trả về JSON báo lỗi
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
}