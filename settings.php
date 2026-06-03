<?php
require_once($CFG->libdir . '/adminlib.php');
defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // 2. Thêm tiêu đề
    $settings->add(new admin_setting_heading(
        'block_ai_tutor/header',
        'Cấu hình Máy chủ Ollama (Local LLM)',
        'Thiết lập kết nối tới máy chủ AI cục bộ để đảm bảo bảo mật dữ liệu.'
    ));

    // 3. Server URL
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/ollama_url',
        'Ollama Server URL',
        'Nhập địa chỉ máy chủ Ollama (Mặc định: http://localhost:11434).',
        'http://localhost:11434',
        PARAM_URL
    ));

    // 4. Nhập Model (Dùng configtext để linh hoạt chạy các model trên Colab như qwen2.5:7b hoặc llama3:8b)
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/ollama_model',
        'Mô hình Ollama (Model)',
        'Nhập tên chính xác của mô hình Ollama (ví dụ: llama3.2, llama3, qwen2.5, qwen2.5:7b, mistral). Hãy đảm bảo mô hình này đã được pull về máy chủ Ollama.',
        'llama3.2',
        PARAM_TEXT
    ));


    // 5. Temperature
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/ollama_temperature',
        'Temperature (Độ sáng tạo)',
        'Giá trị từ 0.0 (chính xác) đến 1.0 (sáng tạo). Khuyên dùng: 0.7',
        '0.7',
        PARAM_TEXT
    ));

    // 6. Max Tokens
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/ollama_maxtokens',
        'Max Output Tokens',
        'Giới hạn độ dài câu trả lời. Ví dụ: 1000.',
        '1000',
        PARAM_INT
    ));

    // 7. CPU Threads
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/ollama_num_thread',
        'Ollama CPU Threads',
        'Số luồng xử lý CPU tối ưu cho Ollama (Mặc định: 6).',
        '6',
        PARAM_INT
    ));

    // 8. Context Window
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/ollama_num_ctx',
        'Ollama Context Window',
        'Kích thước Context Window cho mô hình (Mặc định: 2048).',
        '2048',
        PARAM_INT
    ));
}