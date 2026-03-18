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

    // 4. Chọn Model (Dropdown)
    $models = [
        'llama3.2' => 'LLaMA 3.2 (Bản nhẹ, chạy nhanh trên Laptop)',
        'llama3.2:1b' => 'LLaMA 3.2 1B (Bản siêu nhẹ)',
        'llama3' => 'LLaMA 3 8B (Yêu cầu có Card đồ họa)',
        'qwen2.5' => 'Qwen 2.5 (Hỗ trợ tiếng Việt rất tốt)',
        'mistral' => 'Mistral 7B',
    ];

    $settings->add(new admin_setting_configselect(
        'block_ai_tutor/ollama_model',
        'Chọn Mô hình (Model)',
        'Chọn mô hình đã được pull về máy trong Ollama.',
        'llama3.2', 
        $models
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
}