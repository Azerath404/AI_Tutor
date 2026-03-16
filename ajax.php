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

// Xóa bộ đệm đầu ra để đảm bảo không có HTML thừa (ví dụ warning của Moodle) lọt vào JSON
while (ob_get_level()) {
    ob_end_clean();
}

try {
    // 1. INPUT: Nhận dữ liệu đầu vào & Validate (Controller logic)
    $question = optional_param('question', '', PARAM_TEXT);
    $courseId = optional_param('course_id', 1, PARAM_INT);

    if (empty($question)) {
        throw new Exception('Câu hỏi không được để trống.');
    }

    // 2. PROCESS: Gọi Service Layer để xử lý
    // Khởi tạo Service (Dependency Injection could happen here in a framework like Laravel)
    $aiService = new \block_ai_tutor\service();

    // Bước 2.1: Xây dựng ngữ cảnh (RAG logic)
    $systemPrompt = $aiService->build_context_prompt($courseId, $USER);
    
    // Bước 2.2: Gọi AI (Integrator logic)
    $response = $aiService->call_llm($question, $systemPrompt);

    // 3. OUTPUT: Trả về kết quả
    echo $response;

} catch (Exception $e) {
    // Nếu có lỗi, trả về JSON báo lỗi
    echo json_encode(['error' => $e->getMessage()]);
}