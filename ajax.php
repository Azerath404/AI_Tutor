<?php
/**
 * AI Tutor AJAX Handler
 * Xử lý luồng Streaming từ Ollama và phản hồi về Client qua SSE
 */

// 1. Cấu hình hệ thống tối ưu cho Streaming
set_time_limit(300); 
ini_set('memory_limit', '512M');
ini_set('zlib.output_compression', 0);
ini_set('implicit_flush', 1);
ignore_user_abort(true);

define('AJAX_SCRIPT', true);
require('../../config.php');
require_login();

// 2. Thiết lập Header Server-Sent Events (SSE)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Xóa toàn bộ output buffer cũ để đảm bảo dữ liệu đi thẳng tới trình duyệt
while (ob_get_level()) { ob_end_clean(); }

echo ":" . str_repeat(" ", 16384) . "\n\n";
echo "data: " . json_encode(['text' => '']) . "\n\n";
flush();

global $USER, $DB;

try {
    // 3. Nhận và kiểm tra dữ liệu từ Client
    $question = optional_param('question', '', PARAM_TEXT);
    $courseId = optional_param('course_id', optional_param('courseid', 0, PARAM_INT), PARAM_INT);
    $userId = (int)$USER->id;

    if (empty($question) || $courseId === 0) {
        echo "data: " . json_encode(['error' => 'Thiếu dữ liệu: Question hoặc Course ID']) . "\n\n";
        exit;
    }

    // --- LOG DEBUG ---
    $debugPath = __DIR__ . '/data';
    if (!is_dir($debugPath)) { @mkdir($debugPath, 0777, true); }
    
    $logMsg = "[" . date('H:i:s') . "] REQUEST: User $userId, Course $courseId, Question: $question\n";
    file_put_contents($debugPath . '/request_log.txt', $logMsg, FILE_APPEND);

    // 4. Khởi tạo Service và các Engine
    $aiService = new \block_ai_tutor\service();
    $repo = $aiService->get_repo();

    // 5. ĐẢM BẢO CHUNKS TỒN TẠI (Cơ chế Fallback)
    // Kích hoạt xử lý tài liệu nếu cron chưa chạy kịp.
    // Điều này đảm bảo người dùng luôn có câu trả lời ngay cả sau khi vừa cập nhật tài liệu.
    $aiService->get_doc_parser()->ensure_chunks_exist($courseId);
    
    // Lưu câu hỏi của người dùng vào Database ngay lập tức
    $repo->save_chat_log($userId, $courseId, 'user', $question);
    
    // 6. TRUY VẤN NGỮ CẢNH (RAG) VÀ XÂY DỰNG PROMPT
    // Sử dụng service để xây dựng prompt, đã bao gồm RAG bên trong.
    // Điều này giúp gom logic vào một chỗ và tránh lặp code.
    $systemPrompt = $aiService->build_context_prompt($courseId, $USER, $question);

    // Log Prompt để kiểm tra kết quả RAG bốc được từ Database
    file_put_contents($debugPath . '/prompt_debug.txt', "=== PROMPT [" . date('H:i:s') . "] ===\n" . $systemPrompt . "\n\n", FILE_APPEND);
    
    // 7. GỌI OLLAMA VÀ STREAMING KẾT QUẢ VỀ GIAO DIỆN
    $full_ai_answer = "";
    
    // Đảm bảo truyền đúng closure (function ẩn danh)
    $aiService->call_llm_stream($question, $systemPrompt, function($chunk) use (&$full_ai_answer) {
        $full_ai_answer .= $chunk;
        
        // Gửi về trình duyệt theo chuẩn SSE
        echo "data: " . json_encode(['text' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
        
        // Thông luồng dữ liệu
        if (ob_get_level() > 0) ob_flush();
        flush();
    });
    
    // 8. Lưu câu trả lời đầy đủ của AI vào Database sau khi stream xong
    if (!empty($full_ai_answer)) {
        $repo->save_chat_log($userId, $courseId, 'ai', $full_ai_answer);
    }

    // Gửi tín hiệu kết thúc luồng cho Client
    echo "data: [DONE]\n\n";
    flush();

} catch (\Throwable $e) {
    // Ghi lỗi hệ thống vào log để gỡ lỗi
    $errorMsg = "[" . date('H:i:s') . "] ERROR: " . $e->getMessage() . " tại " . $e->getFile() . " dòng " . $e->getLine() . "\n";
    file_put_contents(__DIR__ . '/data/php_error.log', $errorMsg, FILE_APPEND);
    
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
}