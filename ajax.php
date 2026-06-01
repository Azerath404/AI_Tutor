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
session_write_close(); // Giải phóng session lock ngay — không cần ghi session trong streaming

// 2. Thiết lập Header Server-Sent Events (SSE)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');  // Tắt buffer của Nginx nếu có reverse proxy

// Xóa toàn bộ output buffer cũ để dữ liệu đi thẳng tới trình duyệt
while (ob_get_level()) {
    ob_end_clean();
}

// Padding ban đầu: ép trình duyệt flush ngay (một số browser cần >4KB trước khi render SSE)
echo ":" . str_repeat(" ", 16384) . "\n\n";
echo "data: " . json_encode(['text' => '']) . "\n\n";
flush();

global $USER, $DB;

// gom tất cả log vào mảng, ghi 1 lần cuối request
$debugPath = make_temp_directory('block_ai_tutor/logs');
$logBuffer = [];

// Đăng ký shutdown handler: ghi log 1 lần duy nhất khi request kết thúc
register_shutdown_function(function() use ($debugPath, &$logBuffer) {
    if (!empty($logBuffer)) {
        // 1 lần file_put_contents thay vì 6 lần → giảm file lock trên NTFS
        file_put_contents($debugPath . '/request_log.txt', implode("\n", $logBuffer) . "\n", FILE_APPEND);
    }
});

try {
    // 3. Nhận và kiểm tra dữ liệu từ Client
    $question = optional_param('question', '', PARAM_TEXT);
    $courseId = optional_param('course_id', optional_param('courseid', 0, PARAM_INT), PARAM_INT);
    $userId   = (int)$USER->id;

    $logBuffer[] = "[" . date('H:i:s') . "] REQUEST: User $userId, Course $courseId, Question: $question";

    if (empty($question) || $courseId === 0) {
        echo "data: " . json_encode(['error' => 'Thiếu dữ liệu: Question hoặc Course ID']) . "\n\n";
        echo "data: [DONE]\n\n";
        exit;
    }


    // 4. Khởi tạo Service
    $aiService = new \block_ai_tutor\service();
    $repo      = $aiService->get_repo();

    // 5. KIỂM TRA CHUNKS TỒN TẠI
    $chunks_exist = $DB->record_exists('block_ai_tutor_chunks', ['courseid' => $courseId]);

    if (!$chunks_exist) {
        echo "data: " . json_encode(['text' => '⏳ Hệ thống đang lập chỉ mục tài liệu lần đầu. Vui lòng thử lại sau 30 giây...']) . "\n\n";
        flush();

        $task = new \block_ai_tutor\task\process_course_documents();
        $task->set_custom_data(['courseid' => $courseId]);
        \core\task\manager::queue_adhoc_task($task, true);

        $logBuffer[] = "[" . date('H:i:s') . "] EXIT: No chunks for course $courseId, queued indexing task.";
        echo "data: [DONE]\n\n";
        exit;
    }

    $logBuffer[] = "[" . date('H:i:s') . "] CHUNKS OK ($courseId), building prompt...";

    // Lưu câu hỏi của người dùng vào Database
    $repo->save_chat_log($userId, $courseId, 'user', $question);

    // 6. TRUY VẤN NGỮ CẢNH (RAG) VÀ XÂY DỰNG PROMPT
    try {
        $systemPrompt = $aiService->build_context_prompt($courseId, $USER, $question);
        $logBuffer[]  = "[" . date('H:i:s') . "] Prompt built OK, length=" . strlen($systemPrompt);
    } catch (\Throwable $promptErr) {
        $errMsg      = "[" . date('H:i:s') . "] PROMPT ERROR: " . $promptErr->getMessage()
                     . " at " . $promptErr->getFile() . " line " . $promptErr->getLine();
        $logBuffer[] = $errMsg;
        file_put_contents($debugPath . '/php_error.log', $errMsg . "\n", FILE_APPEND);
        echo "data: " . json_encode(['error' => 'Lỗi xây dựng ngữ cảnh: ' . $promptErr->getMessage()]) . "\n\n";
        echo "data: [DONE]\n\n";
        exit;
    }

    // Log Prompt để kiểm tra kết quả RAG (debug)
    $writeBytes = file_put_contents($debugPath . '/prompt_debug.txt',
        "=== PROMPT [" . date('H:i:s') . "] ===\n" . $systemPrompt . "\n\n", FILE_APPEND);
    $logBuffer[] = "[" . date('H:i:s') . "] prompt_debug.txt "
                 . ($writeBytes === false ? "WRITE FAILED!" : "written OK, bytes=$writeBytes");

    // 7. GỌI OLLAMA VÀ STREAMING KẾT QUẢ VỀ GIAO DIỆN
    $full_ai_answer = "";

    // Flush nằm ở đây — 1 lần/token (đã xóa flush() trong service.php callback).
    $aiService->call_llm_stream($question, $systemPrompt, function($chunk) use (&$full_ai_answer) {
        $full_ai_answer .= $chunk;

        // Gửi về trình duyệt theo chuẩn SSE
        echo "data: " . json_encode(['text' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";

        // Flush duy nhất — service.php không flush nữa
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    });

    // 8. Lưu câu trả lời đầy đủ của AI vào Database sau khi stream xong
    if (!empty($full_ai_answer)) {
        $repo->save_chat_log($userId, $courseId, 'ai', $full_ai_answer);
    }

    $logBuffer[] = "[" . date('H:i:s') . "] STREAM DONE. Answer length=" . strlen($full_ai_answer);

    // Gửi tín hiệu kết thúc luồng cho Client
    echo "data: [DONE]\n\n";
    flush();

} catch (\Throwable $e) {
    $errMsg      = "[" . date('H:i:s') . "] ERROR: " . $e->getMessage()
                 . " tại " . $e->getFile() . " dòng " . $e->getLine();
    $logBuffer[] = $errMsg;
    file_put_contents($debugPath . '/php_error.log', $errMsg . "\n", FILE_APPEND);

    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
}