<?php
require_once($CFG->libdir . '/adminlib.php');
defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // 2. Thêm tiêu đề
    $settings->add(new admin_setting_heading(
        'block_ai_tutor/header',
        get_string('headerconfig', 'block_ai_tutor'),
        get_string('descconfig', 'block_ai_tutor')
    ));

    // 3. API Key (Giữ nguyên, đổi string)
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/gemini_apikey',
        get_string('setting_apikey', 'block_ai_tutor'),
        get_string('setting_apikey_desc', 'block_ai_tutor'),
        '',
        PARAM_TEXT
    ));

    // 4. Chọn Model (Dropdown) - FR1
    $models = [
        'gemini-1.5-flash' => 'Gemini 1.5 Flash (Khuyên dùng - Ổn định)',
        'gemini-1.5-pro' => 'Gemini 1.5 Pro (Ổn định)',
        'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro (Preview)',
        'gemini-3-flash-preview' => 'Gemini 3 Flash (Preview)',
        'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash Lite (Preview)',
        'gemini-2.5-flash' => 'Gemini 2.5 Flash',
        'gemini-2.5-pro' => 'Gemini 2.5 Pro',
    ];
    $settings->add(new admin_setting_configselect(
        'block_ai_tutor/gemini_model',
        get_string('setting_model', 'block_ai_tutor'),
        get_string('setting_model_desc', 'block_ai_tutor'),
        'gemini-1.5-flash', // Default
        $models
    ));

    // 5. Temperature (Text - Validate Float) - FR1
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/gemini_temperature',
        get_string('setting_temperature', 'block_ai_tutor'),
        get_string('setting_temperature_desc', 'block_ai_tutor'),
        '0.7',
        PARAM_TEXT
    ));

    // 6. Max Tokens (Text - Validate Int) - FR1
    $settings->add(new admin_setting_configtext(
        'block_ai_tutor/gemini_maxtokens',
        get_string('setting_maxtokens', 'block_ai_tutor'),
        get_string('setting_maxtokens_desc', 'block_ai_tutor'),
        '1000',
        PARAM_INT
    ));
}