<?php
/**
 * AI Tutor — Kiểm tra trạng thái lập chỉ mục tài liệu
 * Trả về JSON: {"ready": true/false, "courseid": <id>}
 */
define('AJAX_SCRIPT', true);
require_once('../../../../config.php');
require_login();

header('Content-Type: application/json');

$courseId = optional_param('course_id', 0, PARAM_INT);

if ($courseId === 0) {
    echo json_encode(['ready' => false, 'error' => 'Missing course_id']);
    exit;
}

$ready = $DB->record_exists('block_ai_tutor_chunks', ['courseid' => $courseId]);
echo json_encode(['ready' => $ready, 'courseid' => $courseId]);
